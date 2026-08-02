<?php
/**
 * chrono.php — Réception des données de course et calcul des temps.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * LE PRINCIPE : DEUX SOURCES, UN ARBITRE
 *
 * Chaque passage (départ, arrivée) peut être détecté de deux façons :
 *   • une BALISE Bluetooth posée sur la ligne — précise à la seconde ;
 *   • le FRANCHISSEMENT GPS d'une zone autour de la ligne — moins précis, mais
 *     il fonctionne quand la balise est en panne, à plat, ou hors de portée.
 *
 * Les deux sont enregistrées, toujours. Si l'une manque, l'autre donne le
 * temps ; si les deux sont là, c'est la balise qui fait foi. C'est le seul
 * moyen de ne pas se retrouver, le jour de la course, avec des participants
 * sans chrono parce qu'un boîtier a lâché.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * LE SERVEUR CALCULE, LE TÉLÉPHONE NE FAIT QU'OBSERVER
 *
 * L'application n'envoie JAMAIS un temps. Elle envoie des observations
 * horodatées — « j'ai vu la balise à 9 h 42 min 17,3 s ». Le temps est calculé
 * ici, à partir de ces observations. Une application qui enverrait « j'ai fait
 * 42 minutes » ferait une déclaration, pas une mesure : on la croit ou on la
 * truque, et le premier classement contesté serait indéfendable.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * LE RÉSEAU TOMBERA PENDANT LA COURSE — CE N'EST PAS UNE HYPOTHÈSE
 *
 * D'où deux horodatages distincts dans `detections` :
 *   • `detecte_at` — l'instant vu par le téléphone. C'est LUI qui compte.
 *   • `recu_at`    — l'instant où le serveur l'a reçu. Purement informatif.
 * Une détection reçue trois heures après la course reste valable et se range à
 * sa place. Utiliser l'heure de réception donnerait des temps absurdes.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * ⚠️ RIEN N'EST JAMAIS ÉCRASÉ EN SILENCE
 * Une détection déjà reçue n'est pas dupliquée ; un résultat validé par un
 * officiel n'est pas recalculé par l'arrivée tardive d'un point GPS.
 */

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/registrations_resolver.php';

/**
 * Le chronométrage est-il ouvert sur ce site ?
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * UN SEUL INTERRUPTEUR, LU PARTOUT — C'EST TOUT L'INTÉRÊT.
 *
 * Le chronométrage n'est utile qu'autour du jour de la course. Le reste de
 * l'année, l'espace coureur sert aux inscriptions : afficher « Mes résultats »,
 * une demande d'autorisation GPS et un chrono vide onze mois sur douze donne
 * l'impression d'un site à moitié fini, et fait poser des questions auxquelles
 * personne n'a envie de répondre.
 *
 * Cette fonction est la SEULE source de vérité. Le menu de l'espace coureur, la
 * page des résultats et l'API mobile la lisent tous : il ne peut pas y avoir de
 * site où le menu propose une page que l'API refuse de servir.
 *
 * ⚠️ FERMÉ EN CAS DE DOUTE. Colonne absente (migration non jouée), base
 * inaccessible : on répond « désactivé ». L'inverse ouvrirait la collecte de
 * positions GPS sur un site que personne n'a configuré pour ça.
 *
 * ⚠️ CE QUI EST DÉJÀ ENREGISTRÉ N'EST PAS TOUCHÉ. Désactiver ferme les écrans
 * et refuse les nouveaux envois ; les temps et les traces restent en base et
 * réapparaissent à l'identique dès la réactivation. Pour effacer, il y a les
 * purges (src/content/purges.php) et le bouton du coureur — pas cet
 * interrupteur, qui serait alors une trappe à perte de données.
 *
 * Le cache statique évite une requête par appel : le menu, la page et le pied
 * de page interrogeraient sinon `setting` trois fois pour la même réponse.
 */
function chrono_actif(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        $v = $pdo->query('SELECT chrono_enabled FROM setting WHERE id = 1 LIMIT 1')->fetchColumn();
        $cache = ($v !== false && $v !== null) && (int) $v === 1;
    } catch (\Throwable $e) {
        $cache = false;
    }
    return $cache;
}

/** Types de détection, du plus fiable au moins fiable. L'ORDRE EST LA RÈGLE. */
const CHRONO_PRIORITE = ['manuel', 'beacon', 'geofence', 'gps_ligne'];

/**
 * Précision indicative de chaque source, en secondes. Publiée avec le résultat :
 * un temps à ±1 s et un temps à ±30 s ne se comparent pas, et présenter les deux
 * de la même façon serait mentir par omission.
 */
const CHRONO_PRECISION = [
    'manuel'   => 1,    // un officiel a tranché : c'est la référence
    'beacon'   => 2,    // portée Bluetooth de quelques mètres
    'geofence' => 15,   // rayon de la zone + latence du GPS
    'gps_ligne'=> 30,   // reconstruction a posteriori d'un franchissement
];

/** Méthode inscrite dans `resultats` selon la source retenue à l'arrivée. */
const CHRONO_METHODE = [
    'manuel'    => 'manuel',
    'beacon'    => 'beacon',
    'geofence'  => 'gps_ligne',
    'gps_ligne' => 'gps_ligne',
];

/* ═══════════════════════ Réception des détections ═══════════════════════ */

/**
 * Enregistre une détection envoyée par l'application.
 *
 * IDEMPOTENT : la même détection renvoyée dix fois n'en crée qu'une. L'index
 * unique (annee, inscription_no, type, point, detecte_at) s'en charge — et non
 * un SELECT préalable, qui laisserait passer deux envois simultanés.
 *
 * @param  array $d type, point, detecte_at (ISO-8601), rssi_pic?, beacon_minor?
 * @return array{ok: bool, nouvelle?: bool, erreur?: string}
 */
function chrono_ingestDetection(PDO $pdo, int $annee, string $inscriptionNo, array $d): array
{
    $type  = (string) ($d['type']  ?? '');
    $point = (string) ($d['point'] ?? '');
    if (!in_array($type, CHRONO_PRIORITE, true)) {
        return ['ok' => false, 'erreur' => "Type de détection inconnu : « $type »."];
    }
    if (!in_array($point, ['depart', 'arrivee'], true)) {
        return ['ok' => false, 'erreur' => "Point inconnu : « $point » (depart ou arrivee)."];
    }

    // L'horodatage DOIT porter un décalage explicite. Une date nue serait
    // interprétée dans le fuseau du serveur — deux heures d'écart sur un chrono,
    // sans le moindre message d'erreur.
    $brut = trim((string) ($d['detecte_at'] ?? ''));
    if (!preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $brut)) {
        return ['ok' => false, 'erreur' => 'detecte_at doit être en ISO-8601 avec décalage horaire.'];
    }
    try {
        $quand = (new DateTimeImmutable($brut))->setTimezone(new DateTimeZone('UTC'));
    } catch (\Throwable $e) {
        return ['ok' => false, 'erreur' => 'detecte_at illisible.'];
    }

    // Une détection dans le futur ne peut pas être une observation. Tolérance de
    // 5 minutes : l'horloge d'un téléphone dérive.
    if ($quand->getTimestamp() > time() + 300) {
        return ['ok' => false, 'erreur' => 'detecte_at est dans le futur.'];
    }

    $sql = 'INSERT INTO detections (annee, inscription_no, device_id, type, point,
                                    detecte_at, recu_at, rssi_pic, beacon_minor, confiance)
            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3), ?, ?, ?)
            ON DUPLICATE KEY UPDATE recu_at = recu_at';   // no-op : on ne réécrit rien
    try {
        $st = $pdo->prepare($sql);
        $st->execute([
            $annee, $inscriptionNo,
            isset($d['device_id']) ? (int) $d['device_id'] : null,
            $type, $point,
            $quand->format('Y-m-d H:i:s.v'),
            isset($d['rssi_pic'])     ? (int) $d['rssi_pic']     : null,
            isset($d['beacon_minor']) ? (int) $d['beacon_minor'] : null,
            chrono_confiance($type, $d),
        ]);
        // rowCount : 1 = insérée, 0 = déjà connue (le no-op n'affecte rien).
        return ['ok' => true, 'nouvelle' => $st->rowCount() === 1];
    } catch (\Throwable $e) {
        error_log('[CHRONO] detection : ' . $e->getMessage());
        return ['ok' => false, 'erreur' => "La détection n'a pas pu être enregistrée."];
    }
}

/**
 * Indice de confiance 0-100. Sert à départager DEUX détections du même type —
 * pas à départager les types entre eux, ce que fait CHRONO_PRIORITE.
 *
 * Pour une balise, la puissance du signal reçu (RSSI) dit à quel point on est
 * passé près : -50 dBm, on est à côté ; -95 dBm, on l'a captée de loin, peut-être
 * même sans franchir la ligne.
 */
function chrono_confiance(string $type, array $d): int
{
    if ($type === 'manuel')   return 100;
    if ($type === 'beacon') {
        $rssi = isset($d['rssi_pic']) ? (int) $d['rssi_pic'] : null;
        if ($rssi === null) return 70;
        // -50 dBm ou mieux → 95 ; -100 dBm → 45. Linéaire entre les deux.
        return max(30, min(95, (int) round(95 - (abs($rssi) - 50))));
    }
    return $type === 'geofence' ? 60 : 40;
}

/* ══════════════════════════ Réception des traces ════════════════════════ */

/**
 * Ajoute un lot de points GPS.
 *
 * IDEMPOTENT PAR CONSTRUCTION : seuls les points POSTÉRIEURS au dernier point
 * déjà connu sont conservés. Renvoyer un lot déjà reçu n'ajoute donc rien, sans
 * qu'il faille comparer les points un à un — ce qui serait ruineux sur plusieurs
 * milliers de positions.
 *
 * ⚠️ Le CONSENTEMENT est vérifié par l'appelant, pas ici : une trace GPS dit où
 * une personne se trouvait minute par minute, c'est la donnée la plus sensible
 * du site. Aucune ligne ne s'écrit sans accord explicite.
 *
 * @param  array $points [{lat, lon, at (ISO-8601), alt?, precision_m?}, …]
 * @return array{ok: bool, ajoutes?: int, ignores?: int, erreur?: string}
 */
function chrono_ingestTrace(PDO $pdo, int $annee, string $inscriptionNo,
                            ?int $deviceId, array $points): array
{
    if (!$points) return ['ok' => true, 'ajoutes' => 0, 'ignores' => 0];
    if (count($points) > 5000) {
        return ['ok' => false, 'erreur' => 'Lot trop volumineux (5000 points maximum).'];
    }

    try {
        $st = $pdo->prepare('SELECT id, points, nb_points, debut_at, fin_at FROM traces_gps
                              WHERE annee = ? AND inscription_no = ? AND source = ? LIMIT 1');
        $st->execute([$annee, $inscriptionNo, 'app']);
        $trace = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return ['ok' => false, 'erreur' => 'Table des traces indisponible.'];
    }

    $connus  = ($trace && $trace['points']) ? (json_decode((string) $trace['points'], true) ?: []) : [];
    $dernier = $trace['fin_at'] ?? null;
    $seuil   = $dernier !== null ? strtotime($dernier . ' UTC') : 0;

    $nouveaux = [];
    $ignores  = 0;
    foreach ($points as $p) {
        if (!isset($p['lat'], $p['lon'], $p['at'])) { $ignores++; continue; }
        $lat = (float) $p['lat'];
        $lon = (float) $p['lon'];
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) { $ignores++; continue; }

        $brut = trim((string) $p['at']);
        if (!preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $brut)) { $ignores++; continue; }
        try { $t = (new DateTimeImmutable($brut))->setTimezone(new DateTimeZone('UTC')); }
        catch (\Throwable $e) { $ignores++; continue; }

        // Le cœur de l'idempotence : rien d'antérieur à ce qu'on a déjà.
        if ($t->getTimestamp() <= $seuil) { $ignores++; continue; }
        if ($t->getTimestamp() > time() + 300) { $ignores++; continue; }

        $nouveaux[] = [
            'lat' => round($lat, 6),
            'lon' => round($lon, 6),
            'at'  => $t->format('Y-m-d\TH:i:s.v\Z'),
            'alt' => isset($p['alt']) ? round((float) $p['alt'], 1) : null,
            'p'   => isset($p['precision_m']) ? (int) $p['precision_m'] : null,
        ];
    }

    if (!$nouveaux) return ['ok' => true, 'ajoutes' => 0, 'ignores' => $ignores];

    usort($nouveaux, fn($a, $b) => strcmp($a['at'], $b['at']));
    $tous   = array_merge($connus, $nouveaux);
    $debut  = $trace['debut_at'] ?? str_replace(['T', 'Z'], [' ', ''], $tous[0]['at']);
    $fin    = str_replace(['T', 'Z'], [' ', ''], end($tous)['at']);

    /* purge_at : la date d'effacement automatique est posée dès l'écriture. La
       calculer plus tard supposerait qu'une tâche pense à le faire.
       ⚠️ 0 = conservation illimitée → purge_at reste NULL. Sans ce cas, une
       date serait quand même inscrite et la purge effacerait la trace, alors
       que le réglage dit exactement le contraire. */
    $jours = (int) $pdo->query('SELECT traces_gps_conservation_jours FROM setting WHERE id = 1')
                       ->fetchColumn();
    $purgeAt = $jours > 0 ? date('Y-m-d', strtotime('+' . $jours . ' days')) : null;

    try {
        if ($trace) {
            $pdo->prepare('UPDATE traces_gps SET points = ?, nb_points = ?, debut_at = ?, fin_at = ?,
                                                 purge_at = ?
                            WHERE id = ?')
                ->execute([json_encode($tous), count($tous), $debut, $fin, $purgeAt, (int) $trace['id']]);
        } else {
            $pdo->prepare('INSERT INTO traces_gps (annee, inscription_no, device_id, source, points,
                                                   nb_points, debut_at, fin_at, purge_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$annee, $inscriptionNo, $deviceId, 'app', json_encode($tous),
                           count($tous), $debut, $fin, $purgeAt]);
        }
    } catch (\Throwable $e) {
        error_log('[CHRONO] trace : ' . $e->getMessage());
        return ['ok' => false, 'erreur' => "La trace n'a pas pu être enregistrée."];
    }

    return ['ok' => true, 'ajoutes' => count($nouveaux), 'ignores' => $ignores];
}

/* ════════════════════════════ Arbitrage ═════════════════════════════════ */

/**
 * Choisit LA détection qui fait foi pour un point, parmi toutes celles reçues.
 *
 * Deux règles, et deux seulement — parce qu'un résultat contesté doit pouvoir
 * s'expliquer en une phrase :
 *   1. le type le plus fiable gagne (manuel > balise > zone GPS > reconstruction) ;
 *   2. à type égal : au DÉPART, la dernière (c'est en la quittant qu'on part) ;
 *      à l'ARRIVÉE, la première (c'est en la franchissant qu'on finit).
 *
 * @return array|null la détection retenue
 */
function chrono_arbitrer(PDO $pdo, int $annee, string $inscriptionNo, string $point): ?array
{
    $st = $pdo->prepare('SELECT * FROM detections
                          WHERE annee = ? AND inscription_no = ? AND point = ?');
    $st->execute([$annee, $inscriptionNo, $point]);
    $lignes = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$lignes) return null;

    foreach (CHRONO_PRIORITE as $type) {
        $duType = array_values(array_filter($lignes, fn($l) => $l['type'] === $type));
        if (!$duType) continue;

        usort($duType, function ($a, $b) use ($point) {
            $cmp = strcmp((string) $a['detecte_at'], (string) $b['detecte_at']);
            // Départ : la plus tardive d'abord. Arrivée : la plus précoce.
            return $point === 'depart' ? -$cmp : $cmp;
        });

        // À horodatage identique, la plus confiante l'emporte.
        $meilleure = $duType[0];
        foreach ($duType as $l) {
            if ($l['detecte_at'] === $meilleure['detecte_at']
                && (int) $l['confiance'] > (int) $meilleure['confiance']) {
                $meilleure = $l;
            }
        }
        return $meilleure;
    }
    return null;
}

/**
 * (Re)calcule le résultat d'une inscription à partir des détections reçues.
 *
 * ⚠️ NE TOUCHE JAMAIS À UN RÉSULTAT VALIDÉ PAR UN OFFICIEL (`valide_par` non
 * nul), sauf demande explicite. Sans cette garde, une détection GPS arrivée
 * tardivement écraserait la décision d'un humain — et personne ne comprendrait
 * pourquoi le classement a changé tout seul pendant la nuit.
 *
 * @return array{ok: bool, statut?: string, temps_s?: float|null, methode?: string|null,
 *               message?: string}
 */
function chrono_recompute(PDO $pdo, int $annee, string $inscriptionNo, bool $forcer = false): array
{
    $st = $pdo->prepare('SELECT * FROM resultats WHERE annee = ? AND inscription_no = ? LIMIT 1');
    $st->execute([$annee, $inscriptionNo]);
    $existant = $st->fetch(PDO::FETCH_ASSOC);

    if ($existant && $existant['valide_par'] !== null && !$forcer) {
        return ['ok' => true, 'statut' => $existant['statut'],
                'message' => 'Résultat validé par un officiel : inchangé.'];
    }

    $dep = chrono_arbitrer($pdo, $annee, $inscriptionNo, 'depart');
    $arr = chrono_arbitrer($pdo, $annee, $inscriptionNo, 'arrivee');

    /* ═══════════════ D'OÙ VIENT L'HEURE DE DÉPART — QUATRE NIVEAUX ═══════════
     *
     * Dans l'ordre, le premier qui existe l'emporte :
     *
     *   1. LA DÉTECTION DU COUREUR (balise ou GPS) — déjà dans $dep. Quelqu'un
     *      parti dix minutes après les autres garde SON temps, pas celui du
     *      peloton.
     *   2. LE TOP RÉEL (`depart_reel_at`) — l'instant où l'organisation a appuyé
     *      sur le bouton. C'est la vérité d'un départ en masse.
     *   3. L'HEURE PRÉVUE (`heure_depart`), mais SEULEMENT une fois le délai de
     *      grâce écoulé. C'est le filet : bouton oublié, personne ne repart sans
     *      chrono.
     *   4. RIEN — et on ne publie pas de temps.
     *
     * ⚠️ LE POINT 4 EST AUSSI IMPORTANT QUE LES AUTRES. Sans le délai de grâce,
     * une arrivée enregistrée à 11 h 02 pour un départ prévu à 11 h 00 mais pas
     * encore donné produirait un temps de deux minutes — publié, et faux. Mieux
     * vaut « en course » qu'un chiffre auquel personne ne croira.
     * ──────────────────────────────────────────────────────────────────────── */
    $sourceDep = 'detection';
    if ($dep === null) {
        $e = $pdo->prepare('SELECT heure_depart, depart_reel_at FROM editions
                             WHERE annee = ? LIMIT 1');
        $e->execute([$annee]);
        $ed = $e->fetch(PDO::FETCH_ASSOC) ?: [];

        if (!empty($ed['depart_reel_at'])) {
            // Le top donné par l'organisation. `manuel` : c'est un acte humain,
            // et il prime sur toute reconstruction.
            $dep = ['detecte_at' => $ed['depart_reel_at'], 'type' => 'manuel',
                    'confiance' => 90];
            $sourceDep = 'top';
        } elseif (!empty($ed['heure_depart'])) {
            $grace = (int) ($pdo->query('SELECT depart_grace_min FROM setting WHERE id = 1')
                                ->fetchColumn() ?: 10);
            $prevu = strtotime((string) $ed['heure_depart'] . ' UTC');

            // Le filet ne se déclenche qu'APRÈS le délai : avant, le départ peut
            // encore être donné d'une seconde à l'autre.
            if ($prevu !== false && time() >= $prevu + $grace * 60) {
                $dep = ['detecte_at' => $ed['heure_depart'], 'type' => 'manuel',
                        'confiance' => 70];
                $sourceDep = 'prevu';
            }
        }
    }

    $sets = [];
    if ($dep === null && $arr === null) {
        // Aucune donnée : on n'invente rien, et on n'écrase pas non plus.
        return ['ok' => true, 'statut' => $existant['statut'] ?? 'en_course',
                'message' => 'Aucune détection reçue.'];
    }

    $statut  = 'en_course';
    $temps   = null;
    $methode = null;
    $prec    = null;
    $comment = null;

    if ($arr !== null) {
        $methode = CHRONO_METHODE[$arr['type']] ?? 'gps_ligne';
        // La précision annoncée est celle de la source la MOINS bonne des deux :
        // un temps ne peut pas être plus précis que sa borne la plus floue.
        $prec = max(CHRONO_PRECISION[$arr['type']] ?? 30,
                    $dep !== null ? (CHRONO_PRECISION[$dep['type']] ?? 30) : 30);

        if ($dep !== null) {
            $t0 = strtotime((string) $dep['detecte_at'] . ' UTC');
            $t1 = strtotime((string) $arr['detecte_at'] . ' UTC');
            $temps = (float) ($t1 - $t0);

            $mini = (int) ($pdo->query('SELECT temps_min_plausible_s FROM editions
                                         WHERE annee = ' . (int) $annee . ' LIMIT 1')->fetchColumn() ?: 0);
            if ($temps <= 0) {
                // Arrivée avant le départ : impossible. On garde la trace du
                // problème plutôt que de publier une aberration.
                $statut  = 'invalide';
                $comment = 'Arrivée antérieure au départ';
                $temps   = null;
            } elseif ($mini > 0 && $temps < $mini) {
                $statut  = 'invalide';
                $comment = 'Temps sous le minimum plausible (' . $mini . ' s)';
            } else {
                $statut = 'termine';
            }
        } else {
            $statut  = 'invalide';
            $comment = 'Arrivée détectée sans départ';
        }
        /* La PROVENANCE du départ est écrite dans le résultat. Le jour où
           quelqu'un conteste, l'écran d'administration doit pouvoir dire d'où
           vient son heure de départ — sinon on ne peut répondre que « c'est ce
           que dit la machine », ce qui n'a jamais convaincu personne. */
        if ($statut === 'termine') {
            $comment = match ($sourceDep) {
                'top'   => 'Départ pris sur le top officiel',
                'prevu' => 'Départ pris sur l\'heure prévue (top non donné)',
                default => $comment,
            };
        }
    }

    /* Distance et dénivelé, depuis la trace GPS reçue. Ces deux colonnes
       existaient depuis le lot 7 et n'étaient jamais remplies : l'application
       affichait ses propres chiffres, sans que rien ne les recoupe. */
    $mesures = chrono_mesuresTrace($pdo, $annee, $inscriptionNo);

    $sql = 'INSERT INTO resultats (annee, inscription_no, depart_at, arrivee_at, temps_s,
                                   methode, precision_s, statut, commentaire,
                                   distance_m, denivele_positif_m)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              depart_at = VALUES(depart_at), arrivee_at = VALUES(arrivee_at),
              temps_s = VALUES(temps_s), methode = VALUES(methode),
              precision_s = VALUES(precision_s), statut = VALUES(statut),
              commentaire = VALUES(commentaire),
              -- ⚠️ COALESCE : une trace absente (application fermée, GPS refusé)
              -- ne doit pas EFFACER une distance déjà calculée. Sans cela, un
              -- recalcul après la course viderait les kilomètres de tous ceux
              -- dont la trace a été purgée.
              distance_m = COALESCE(VALUES(distance_m), distance_m),
              denivele_positif_m = COALESCE(VALUES(denivele_positif_m), denivele_positif_m)';
    try {
        $pdo->prepare($sql)->execute([
            $annee, $inscriptionNo,
            $dep !== null ? substr((string) $dep['detecte_at'], 0, 23) : null,
            $arr !== null ? substr((string) $arr['detecte_at'], 0, 23) : null,
            $temps, $methode, $prec, $statut, $comment,
            $mesures['distance_m'], $mesures['denivele_m'],
        ]);
    } catch (\Throwable $e) {
        error_log('[CHRONO] resultat : ' . $e->getMessage());
        return ['ok' => false, 'message' => "Le résultat n'a pas pu être enregistré."];
    }

    // La détection retenue est marquée : le jour d'une contestation, on doit
    // pouvoir montrer LAQUELLE a servi, pas seulement le résultat.
    $pdo->prepare('UPDATE detections SET retenue = 0 WHERE annee = ? AND inscription_no = ?')
        ->execute([$annee, $inscriptionNo]);
    foreach ([$dep, $arr] as $d) {
        if ($d !== null && isset($d['id'])) {
            $pdo->prepare('UPDATE detections SET retenue = 1 WHERE id = ?')->execute([(int) $d['id']]);
        }
    }

    return ['ok' => true, 'statut' => $statut, 'temps_s' => $temps, 'methode' => $methode];
}

/**
 * Distance et dénivelé calculés depuis la trace GPS reçue.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * LE SERVEUR CALCULE, L'APPLICATION OBSERVE — ICI AUSSI.
 *
 * L'application affiche sa propre distance pendant la course, c'est un confort.
 * Mais le chiffre qui compte est calculé ici, sur la trace complète : deux
 * téléphones ne doivent pas annoncer deux distances différentes pour le même
 * parcours, et un coureur qui a fermé son application en route ne doit pas
 * perdre les kilomètres déjà envoyés.
 *
 * ⚠️ LE DÉNIVELÉ EST FILTRÉ, ET CE N'EST PAS FACULTATIF. L'altitude GPS oscille
 * de ±10 à 20 m en permanence, même à l'arrêt : additionner les écarts bruts
 * annoncerait 300 m de dénivelé sur un parcours plat. Deux garde-fous — une
 * moyenne glissante, puis un seuil de 4 m — les mêmes que côté application,
 * pour que les deux chiffres se ressemblent.
 *
 * @return array{distance_m: ?int, denivele_m: ?int}
 */
function chrono_mesuresTrace(PDO $pdo, int $annee, string $inscriptionNo): array
{
    try {
        $st = $pdo->prepare('SELECT points FROM traces_gps
                              WHERE annee = ? AND inscription_no = ? LIMIT 1');
        $st->execute([$annee, $inscriptionNo]);
        $brut = $st->fetchColumn();
    } catch (\Throwable $e) {
        return ['distance_m' => null, 'denivele_m' => null];
    }
    if (empty($brut)) return ['distance_m' => null, 'denivele_m' => null];

    $points = json_decode((string) $brut, true);
    if (!is_array($points) || count($points) < 2) {
        return ['distance_m' => null, 'denivele_m' => null];
    }

    $distance = 0.0;
    $positif  = 0.0;
    $fenetre  = [];
    $reference = null;
    $precedent = null;

    foreach ($points as $p) {
        if (!isset($p['lat'], $p['lon'])) continue;

        if ($precedent !== null) {
            $pas = chrono_haversine(
                (float) $precedent['lat'], (float) $precedent['lon'],
                (float) $p['lat'], (float) $p['lon']
            );
            // Un saut de plus de 200 m entre deux points est un décrochage GPS,
            // pas un déplacement. Le compter gonflerait la distance de plusieurs
            // kilomètres sur un parcours en ville.
            if ($pas <= 200) $distance += $pas;
        }
        $precedent = $p;

        // Dénivelé : moyenne glissante sur 5 points, puis seuil de 4 m.
        $alt = isset($p['alt']) ? (float) $p['alt'] : null;
        if ($alt === null || $alt === 0.0) continue;

        $fenetre[] = $alt;
        if (count($fenetre) > 5) array_shift($fenetre);
        if (count($fenetre) < 5) continue;

        $lisse = array_sum($fenetre) / count($fenetre);
        if ($reference === null) { $reference = $lisse; continue; }

        $ecart = $lisse - $reference;
        if ($ecart >= 4)      { $positif += $ecart; $reference = $lisse; }
        elseif ($ecart <= -4) { $reference = $lisse; }
    }

    return [
        'distance_m' => $distance > 0 ? (int) round($distance) : null,
        'denivele_m' => $positif  > 0 ? (int) round($positif)  : null,
    ];
}

/** Distance entre deux points, en mètres. */
function chrono_haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $R = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/* ═══════════════════════ LE TOP DE DÉPART ════════════════════════════════
 *
 * Une course part rarement à l'heure. Le bouton donne l'instant réel ; l'heure
 * prévue reste ce qu'elle est — une prévision, et un filet.
 * ───────────────────────────────────────────────────────────────────────── */

/**
 * État du départ pour une édition : prévu, réel, et ce qu'on peut en faire.
 *
 * @return array{annee: int, prevu: ?string, reel: ?string, grace_min: int,
 *               parti: bool, filet_actif: bool}
 */
function chrono_etatDepart(PDO $pdo, ?int $annee = null): array
{
    require_once __DIR__ . '/course.php';
    $annee ??= course_anneeActive($pdo);

    $prevu = null;
    $reel  = null;
    try {
        $st = $pdo->prepare('SELECT heure_depart, depart_reel_at FROM editions
                              WHERE annee = ? LIMIT 1');
        $st->execute([$annee]);
        $e = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $prevu = $e['heure_depart'] ?: null;
        $reel  = $e['depart_reel_at'] ?: null;
    } catch (\Throwable $ex) { /* colonnes absentes */ }

    $grace = 10;
    try {
        $g = $pdo->query('SELECT depart_grace_min FROM setting WHERE id = 1')->fetchColumn();
        if ($g !== false && $g !== null) $grace = (int) $g;
    } catch (\Throwable $ex) { /* colonne absente */ }

    // Le filet est-il déjà en train de servir ? Utile à l'écran : il explique
    // pourquoi des temps apparaissent alors que personne n'a appuyé.
    $filet = false;
    if ($reel === null && $prevu !== null) {
        $t = strtotime((string) $prevu . ' UTC');
        $filet = $t !== false && time() >= $t + $grace * 60;
    }

    return ['annee' => $annee, 'prevu' => $prevu, 'reel' => $reel,
            'grace_min' => $grace, 'parti' => $reel !== null, 'filet_actif' => $filet];
}

/**
 * Donne le départ — ou le corrige.
 *
 * ⚠️ LE RECALCUL EST INSÉPARABLE DU TOP. Poser l'heure sans recalculer
 * laisserait les arrivées déjà traitées sur l'ancienne base : on aurait deux
 * groupes de coureurs chronométrés différemment sur la même course, et rien à
 * l'écran ne le montrerait.
 *
 * @param string|null $instant UTC « Y-m-d H:i:s(.v) ». null = maintenant.
 * @return array{ok: bool, instant?: string, recalcules?: int, erreur?: string}
 */
function chrono_donnerDepart(PDO $pdo, ?int $annee = null, ?string $instant = null): array
{
    require_once __DIR__ . '/course.php';
    $annee ??= course_anneeActive($pdo);

    if ($instant === null) {
        // Milliseconde comprise : sur une arrivée à la seconde près, arrondir le
        // départ coûterait jusqu'à une seconde à tout le monde.
        $instant = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                   ->format('Y-m-d H:i:s.v');
    }

    try {
        $ok = $pdo->prepare('UPDATE editions SET depart_reel_at = ? WHERE annee = ?')
                  ->execute([$instant, $annee]);
        if (!$ok) return ['ok' => false, 'erreur' => "Édition introuvable."];
    } catch (\Throwable $e) {
        return ['ok' => false,
                'erreur' => 'Colonne absente : lancez update.php avant la course.'];
    }

    $n = chrono_recomputeEdition($pdo, $annee);
    chrono_journal("depart donne pour $annee a $instant UTC ($n recalcule(s))");

    return ['ok' => true, 'instant' => $instant, 'recalcules' => $n];
}

/**
 * Annule le top de départ.
 *
 * ⚠️ INDISPENSABLE, ET PAS UN CONFORT. Un appui accidentel quarante minutes
 * trop tôt fausserait tous les temps de la course. Sans annulation, il faudrait
 * corriger chaque résultat un par un — le jour J, ce n'est pas faisable.
 */
function chrono_annulerDepart(PDO $pdo, ?int $annee = null): array
{
    require_once __DIR__ . '/course.php';
    $annee ??= course_anneeActive($pdo);

    try {
        $pdo->prepare('UPDATE editions SET depart_reel_at = NULL WHERE annee = ?')
            ->execute([$annee]);
    } catch (\Throwable $e) {
        return ['ok' => false, 'erreur' => 'Colonne absente : lancez update.php.'];
    }

    $n = chrono_recomputeEdition($pdo, $annee);
    chrono_journal("depart ANNULE pour $annee ($n recalcule(s))");
    return ['ok' => true, 'recalcules' => $n];
}

/**
 * Recalcule tous les résultats d'une édition.
 *
 * ⚠️ LES RÉSULTATS VALIDÉS À LA MAIN NE SONT PAS TOUCHÉS. `chrono_recompute()`
 * sans `forcer` s'en charge : une décision d'officiel ne se défait pas parce
 * qu'on a corrigé l'heure de départ. C'est la garantie qui rend le bouton
 * « recalculer » utilisable sans crainte.
 *
 * @return int nombre de résultats effectivement recalculés.
 */
function chrono_recomputeEdition(PDO $pdo, int $annee): int
{
    try {
        // On repart des DÉTECTIONS et non de `resultats` : un coureur dont
        // l'arrivée est arrivée pendant qu'on corrigeait l'heure n'a peut-être
        // pas encore de ligne de résultat.
        $st = $pdo->prepare('SELECT DISTINCT inscription_no FROM detections WHERE annee = ?
                             UNION
                             SELECT DISTINCT inscription_no FROM resultats WHERE annee = ?');
        $st->execute([$annee, $annee]);
        $nos = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        error_log('[CHRONO] recompute edition : ' . $e->getMessage());
        return 0;
    }

    $n = 0;
    foreach ($nos as $no) {
        $r = chrono_recompute($pdo, $annee, (string) $no);
        if (!empty($r['ok'])) $n++;
    }
    return $n;
}

/**
 * Décale l'heure PRÉVUE de quelques minutes — le raccourci « on part en retard ».
 *
 * Ne touche pas au top réel : si le départ a déjà été donné, décaler la
 * prévision n'a aucun effet sur les temps, et c'est voulu.
 */
function chrono_decalerPrevu(PDO $pdo, int $minutes, ?int $annee = null): array
{
    require_once __DIR__ . '/course.php';
    $annee ??= course_anneeActive($pdo);
    if ($minutes === 0) return ['ok' => false, 'erreur' => 'Aucun décalage demandé.'];

    try {
        $st = $pdo->prepare('SELECT heure_depart FROM editions WHERE annee = ? LIMIT 1');
        $st->execute([$annee]);
        $h = $st->fetchColumn();
        if (empty($h)) {
            return ['ok' => false, 'erreur' => "Aucune heure de départ n'est renseignée."];
        }
        $neuve = (new DateTimeImmutable((string) $h, new DateTimeZone('UTC')))
                 ->modify(($minutes > 0 ? '+' : '') . $minutes . ' minutes');
        $pdo->prepare('UPDATE editions SET heure_depart = ? WHERE annee = ?')
            ->execute([$neuve->format('Y-m-d H:i:s'), $annee]);
    } catch (\Throwable $e) {
        return ['ok' => false, 'erreur' => 'Décalage impossible : ' . $e->getMessage()];
    }

    // Le filet a pu servir entre-temps : on recalcule pour que les temps déjà
    // publiés suivent la nouvelle heure.
    $n = chrono_recomputeEdition($pdo, $annee);
    chrono_journal("heure prevue decalee de $minutes min pour $annee ($n recalcule(s))");

    return ['ok' => true, 'heure' => $neuve->format('Y-m-d H:i:s'), 'recalcules' => $n];
}

/** Journal du chronométrage — les gestes du jour J doivent laisser une trace. */
function chrono_journal(string $message): void
{
    $dir = dirname(__DIR__, 2) . '/storage/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents($dir . '/logs_chrono.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND);
}

/** Libellé lisible d'une méthode — jamais un code brut à l'écran. */
function chrono_libelleMethode(?string $m): string
{
    return match ($m) {
        'beacon'        => 'Balise (précis)',
        'gps_ligne'     => 'GPS',
        'gps_extrapole' => 'GPS estimé',
        'gps_distance'  => 'GPS (distance)',
        'manuel'        => 'Saisi par l\'organisation',
        'declaratif'    => 'Déclaré',
        default         => '—',
    };
}

/** Formate une durée en h/min/s. */
function chrono_formatTemps(?float $s): string
{
    if ($s === null || $s <= 0) return '—';
    $s = (int) round($s);
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); $r = $s % 60;
    return $h > 0 ? sprintf('%dh %02dmin %02ds', $h, $m, $r) : sprintf('%dmin %02ds', $m, $r);
}
