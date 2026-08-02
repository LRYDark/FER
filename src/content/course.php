<?php
/**
 * course.php — Les informations de course, en un seul endroit.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * LE PROBLÈME QUE CE FICHIER RÉSOUT
 *
 * La date, la distance et le point de départ étaient saisis à trois endroits
 * différents de l'administration, et stockés dans DEUX tables :
 *
 *   • `setting`  — ce que voit le site public (accueil, inscription, chatbot) ;
 *   • `editions` — ce que lit le chronométrage et l'API mobile.
 *
 * `update.php` copiait la date de l'une vers l'autre À LA CRÉATION de la table,
 * puis plus jamais. Résultat : on corrigeait la date sur l'accueil, et le
 * chronométrage continuait de travailler avec l'ancienne. Pire, `heure_depart`
 * et les coordonnées des lignes n'étaient saisissables NULLE PART — elles
 * restaient nulles, et sans elles aucun temps ne peut être calculé.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * CE QU'ON FAIT — ET CE QU'ON NE FAIT PAS
 *
 * ⚠️ ON NE BLOQUE AUCUN CHAMP. La date reste modifiable depuis Réglages →
 * Accueil, la distance depuis Réglages → Inscription, comme avant. Écrire d'un
 * côté écrit de l'autre : c'est le sens de course_pousserDepuisSetting() et de
 * course_enregistrer(), qui sont les deux directions du même pont.
 *
 * ⚠️ ON NE CRÉE PAS DE COLONNES MIROIR. Seuls TROIS champs existent réellement
 * en double aujourd'hui — date, distance, coordonnées de départ. Les autres
 * n'ont qu'un seul foyer et y restent :
 *
 *   editions uniquement : heure_depart, lignes d'arrivée, temps minimum
 *                         plausible, date limite de transfert. Ce sont des
 *                         faits PROPRES À UNE ANNÉE : l'édition 2024 garde les
 *                         siens quand 2026 change les siens.
 *   setting uniquement  : lieu de rendez-vous, horaires du village, retrait des
 *                         T-shirts, inscriptions sur place. Textes libres, qui
 *                         décrivent l'édition en cours et n'ont pas à être
 *                         recopiés dans chaque archive.
 *
 * Dupliquer les autres reviendrait à recréer, en plus gros, le problème qu'on
 * est en train de corriger.
 */

require_once __DIR__ . '/../core/config.php';

/** Les trois seuls champs réellement présents dans les deux tables. */
const COURSE_PAIRES = [
    // setting            => editions
    'date_course'         => 'date_course',
    'course_km'           => 'distance_km',
    'start_point_coords'  => 'lat_depart/lon_depart',
];

/**
 * Année de l'édition en cours.
 *
 * `is_active = 1` fait foi. À défaut — base fraîchement migrée, ou personne n'a
 * encore désigné l'édition courante — on retombe sur l'année civile plutôt que
 * de renvoyer null : tous les appelants ont besoin d'une année, et la deviner
 * ici évite d'éparpiller le même repli dans six fichiers.
 */
function course_anneeActive(PDO $pdo): int
{
    try {
        $a = $pdo->query('SELECT annee FROM editions WHERE is_active = 1
                           ORDER BY annee DESC LIMIT 1')->fetchColumn();
        if ($a !== false && $a !== null) return (int) $a;
    } catch (\Throwable $e) { /* table absente : repli */ }
    return (int) date('Y');
}

/**
 * Ligne d'édition, créée si elle manque.
 *
 * ⚠️ LA CRÉATION EST INDISPENSABLE. Sans elle, le premier enregistrement depuis
 * l'onglet Course sur une base où `editions` est vide ne ferait rien du tout —
 * un UPDATE sans ligne n'échoue pas, il n'affecte simplement personne. On
 * afficherait « enregistré » sans rien avoir enregistré.
 */
function course_edition(PDO $pdo, ?int $annee = null): ?array
{
    $annee ??= course_anneeActive($pdo);
    try {
        $st = $pdo->prepare('SELECT * FROM editions WHERE annee = ? LIMIT 1');
        $st->execute([$annee]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if ($e) return $e;

        $pdo->prepare('INSERT IGNORE INTO editions (annee, libelle, is_active)
                       VALUES (?, ?, ?)')
            ->execute([$annee, 'Forbach en Rose ' . $annee, $annee === (int) date('Y') ? 1 : 0]);
        $st->execute([$annee]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        error_log('[COURSE] edition : ' . $e->getMessage());
        return null;
    }
}

/**
 * Vue fusionnée des informations de course, telle que l'affichent l'onglet
 * Course, l'API mobile et le chatbot.
 *
 * En cas de désaccord entre les deux tables — cas d'un site migré avant ce
 * module — c'est `editions` qui gagne pour les champs qu'elle porte, parce que
 * c'est elle que lit le chronométrage : afficher une date que le chrono
 * n'utilise pas serait exactement le piège d'avant.
 */
function course_lire(PDO $pdo, ?int $annee = null): array
{
    $e = course_edition($pdo, $annee) ?? [];
    $s = [];
    try {
        $s = $pdo->query('SELECT date_course, course_km, start_point_address,
                                 start_point_coords, course_rdv, course_horaires,
                                 tshirt_retrait_info, registration_onsite_info,
                                 registration_auto_open, registration_auto_close
                            FROM setting WHERE id = 1 LIMIT 1')
                 ->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $ex) { /* colonnes absentes : repli sur editions */ }

    $coords = null;
    if (isset($e['lat_depart'], $e['lon_depart'])
        && $e['lat_depart'] !== null && $e['lon_depart'] !== null) {
        $coords = $e['lat_depart'] . ',' . $e['lon_depart'];
    } elseif (!empty($s['start_point_coords'])) {
        $coords = (string) $s['start_point_coords'];
    }

    return [
        'annee'        => (int) ($e['annee'] ?? course_anneeActive($pdo)),
        'libelle'      => $e['libelle'] ?? null,
        // Date : `editions` d'abord, `setting` en repli. Les deux sont tenues
        // à jour par le pont, elles ne devraient différer que le temps d'une
        // migration.
        'date_course'  => $e['date_course'] ?? (isset($s['date_course'])
                            ? substr((string) $s['date_course'], 0, 10) : null),
        'distance_km'  => isset($e['distance_km']) && $e['distance_km'] !== null
                            ? (float) $e['distance_km']
                            : (isset($s['course_km']) ? (float) $s['course_km'] : null),
        // ⏱️ STOCKÉE EN UTC. Toute lecture qui l'affiche doit la convertir en
        // heure locale, et toute saisie doit faire l'inverse. L'oublier décale
        // tous les chronos de deux heures, sans le moindre message d'erreur.
        'heure_depart' => $e['heure_depart'] ?? null,
        // ⏱️ Le top RÉEL, vide tant que personne n'a appuyé. C'est lui qui fait
        // foi pour les temps ; `heure_depart` reste la prévision et le filet.
        'depart_reel'  => $e['depart_reel_at'] ?? null,
        'lat_depart'   => isset($e['lat_depart']) ? (float) $e['lat_depart'] : null,
        'lon_depart'   => isset($e['lon_depart']) ? (float) $e['lon_depart'] : null,
        'lat_arrivee'  => isset($e['lat_arrivee']) ? (float) $e['lat_arrivee'] : null,
        'lon_arrivee'  => isset($e['lon_arrivee']) ? (float) $e['lon_arrivee'] : null,
        'temps_min_plausible_s' => isset($e['temps_min_plausible_s'])
                            ? (int) $e['temps_min_plausible_s'] : null,
        'transferts_deadline'   => $e['transferts_deadline'] ?? null,
        'coords_depart'         => $coords,
        // Propres à `setting` : textes libres décrivant l'édition en cours.
        'lieu_adresse'          => $s['start_point_address'] ?? null,
        'lieu_rdv'              => $s['course_rdv'] ?? null,
        'horaires'              => $s['course_horaires'] ?? null,
        'retrait_tshirt'        => $s['tshirt_retrait_info'] ?? null,
        'inscription_sur_place' => $s['registration_onsite_info'] ?? null,
        'inscriptions_ouvrent'  => $s['registration_auto_open'] ?? null,
        'inscriptions_ferment'  => $s['registration_auto_close'] ?? null,
    ];
}

/**
 * ─────────────────────────── PONT, SENS 1 ────────────────────────────────
 * Un écran a écrit dans `setting` — on répercute dans `editions`.
 *
 * À appeler JUSTE APRÈS l'UPDATE, dans les écrans qui existaient avant ce
 * module (Réglages → Accueil, → Inscription). Ils gardent leur code, leur
 * formulaire et leurs champs : on ajoute une ligne, on n'en retire aucune.
 *
 * @param string[] $champs noms de colonnes `setting` qui viennent de changer ;
 *                 tableau vide = tous les champs appariés.
 */
function course_pousserDepuisSetting(PDO $pdo, array $champs = []): void
{
    $tous = $champs === [];
    try {
        $s = $pdo->query('SELECT date_course, course_km, start_point_coords
                            FROM setting WHERE id = 1 LIMIT 1')
                 ->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$s) return;

        $e = course_edition($pdo);
        if ($e === null) return;
        $annee = (int) $e['annee'];

        $maj = [];
        $args = [];

        if (($tous || in_array('date_course', $champs, true))
            && !empty($s['date_course'])) {
            // ⚠️ On ne recopie QUE si la date tombe sur l'année de l'édition.
            // Une date de l'an dernier laissée dans `setting` ne décrit pas
            // l'édition courante, et l'y écrire ferait mentir le chronométrage.
            $d = substr((string) $s['date_course'], 0, 10);
            if ((int) substr($d, 0, 4) === $annee) {
                $maj[] = 'date_course = ?';
                $args[] = $d;
            }
        }

        if (($tous || in_array('course_km', $champs, true))
            && isset($s['course_km']) && (float) $s['course_km'] > 0) {
            $maj[] = 'distance_km = ?';
            $args[] = (float) $s['course_km'];
        }

        if (($tous || in_array('start_point_coords', $champs, true))
            && !empty($s['start_point_coords'])) {
            $c = course_coordonnees((string) $s['start_point_coords']);
            if ($c !== null) {
                $maj[] = 'lat_depart = ?';
                $maj[] = 'lon_depart = ?';
                $args[] = $c[0];
                $args[] = $c[1];
            }
        }

        if (!$maj) return;
        $args[] = $annee;
        $pdo->prepare('UPDATE editions SET ' . implode(', ', $maj) . ' WHERE annee = ?')
            ->execute($args);
    } catch (\Throwable $ex) {
        // ⚠️ ON NE FAIT PAS ÉCHOUER L'ENREGISTREMENT D'ORIGINE. Si `editions`
        // manque (migration non jouée), l'écran d'accueil doit continuer de
        // fonctionner comme avant. La synchronisation reprendra après update.php.
        error_log('[COURSE] poussee setting->editions : ' . $ex->getMessage());
    }
}

/**
 * ─────────────────────────── PONT, SENS 2 ────────────────────────────────
 * Enregistre depuis l'onglet Course — écrit `editions` ET `setting`.
 *
 * C'est l'entrée unique de l'onglet Course. Les deux tables sont écrites dans
 * la même transaction : une panne au milieu laisserait sinon la date corrigée
 * pour le chronométrage mais pas sur l'accueil, et personne ne saurait laquelle
 * est la bonne.
 *
 * @param array $v clés de course_lire() ; seules les clés présentes sont écrites.
 * @return array{ok: bool, erreur?: string}
 */
function course_enregistrer(PDO $pdo, array $v, ?int $annee = null): array
{
    $e = course_edition($pdo, $annee);
    if ($e === null) {
        return ['ok' => false, 'erreur' => "Table des éditions absente. Lancez update.php."];
    }
    $annee = (int) $e['annee'];

    /* Validations. Elles portent sur ce qui rendrait un chrono FAUX, pas sur la
       forme : une coordonnée hors bornes ou une heure illisible ne se voit pas
       à l'écran, elle se voit le jour de la course. */
    if (isset($v['lat_depart'], $v['lon_depart'])
        && !course_coordValide($v['lat_depart'], $v['lon_depart'])) {
        return ['ok' => false, 'erreur' => 'Coordonnées de départ hors limites.'];
    }
    if (isset($v['lat_arrivee'], $v['lon_arrivee'])
        && !course_coordValide($v['lat_arrivee'], $v['lon_arrivee'])) {
        return ['ok' => false, 'erreur' => "Coordonnées d'arrivée hors limites."];
    }

    $champsEdition = [
        'libelle'               => 'libelle',
        'date_course'           => 'date_course',
        'distance_km'           => 'distance_km',
        'heure_depart'          => 'heure_depart',
        'lat_depart'            => 'lat_depart',
        'lon_depart'            => 'lon_depart',
        'lat_arrivee'           => 'lat_arrivee',
        'lon_arrivee'           => 'lon_arrivee',
        'temps_min_plausible_s' => 'temps_min_plausible_s',
        'transferts_deadline'   => 'transferts_deadline',
    ];
    $champsSetting = [
        'lieu_adresse'          => 'start_point_address',
        'lieu_rdv'              => 'course_rdv',
        'horaires'              => 'course_horaires',
        'retrait_tshirt'        => 'tshirt_retrait_info',
        'inscription_sur_place' => 'registration_onsite_info',
        'inscriptions_ouvrent'  => 'registration_auto_open',
        'inscriptions_ferment'  => 'registration_auto_close',
    ];

    try {
        $pdo->beginTransaction();

        $maj = [];
        $args = [];
        foreach ($champsEdition as $cle => $colonne) {
            if (!array_key_exists($cle, $v)) continue;
            $maj[] = "`$colonne` = ?";
            $args[] = $v[$cle] === '' ? null : $v[$cle];
        }
        if ($maj) {
            $args[] = $annee;
            $pdo->prepare('UPDATE editions SET ' . implode(', ', $maj) . ' WHERE annee = ?')
                ->execute($args);
        }

        $majS = [];
        $argsS = [];
        foreach ($champsSetting as $cle => $colonne) {
            if (!array_key_exists($cle, $v)) continue;
            $majS[] = "`$colonne` = ?";
            $argsS[] = $v[$cle] === '' ? null : $v[$cle];
        }

        /* ⚠️ LE RETOUR VERS `setting` EST LE CŒUR DE LA DEMANDE : corriger la
           date ici doit la corriger sur l'accueil, comme corriger la date sur
           l'accueil la corrige ici. Sans ces trois lignes, l'onglet Course
           deviendrait une quatrième copie au lieu d'être le pont. */
        if (array_key_exists('date_course', $v) && !empty($v['date_course'])) {
            $majS[] = '`date_course` = ?';
            $argsS[] = substr((string) $v['date_course'], 0, 10) . ' 00:00:00';
        }
        if (array_key_exists('distance_km', $v) && (float) $v['distance_km'] > 0) {
            $majS[] = '`course_km` = ?';
            $argsS[] = (int) round((float) $v['distance_km']);
        }
        if (array_key_exists('lat_depart', $v) && array_key_exists('lon_depart', $v)
            && $v['lat_depart'] !== null && $v['lon_depart'] !== null
            && $v['lat_depart'] !== '' && $v['lon_depart'] !== '') {
            $majS[] = '`start_point_coords` = ?';
            $argsS[] = $v['lat_depart'] . ',' . $v['lon_depart'];
        }

        if ($majS) {
            $pdo->prepare('UPDATE setting SET ' . implode(', ', $majS) . ' WHERE id = 1')
                ->execute($argsS);
        }

        $pdo->commit();
        return ['ok' => true];
    } catch (\Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[COURSE] enregistrement : ' . $ex->getMessage());
        return ['ok' => false, 'erreur' => "L'enregistrement a échoué : " . $ex->getMessage()];
    }
}

/* ═══════════════════════════ Petits outils ═══════════════════════════════ */

/** « 49.1897, 6.8987 » → [49.1897, 6.8987], ou null si illisible. */
function course_coordonnees(string $texte): ?array
{
    if (!preg_match('/^\s*(-?\d+(?:[.,]\d+)?)\s*[,;]\s*(-?\d+(?:[.,]\d+)?)\s*$/', $texte, $m)) {
        return null;
    }
    $lat = (float) str_replace(',', '.', $m[1]);
    $lon = (float) str_replace(',', '.', $m[2]);
    return course_coordValide($lat, $lon) ? [$lat, $lon] : null;
}

function course_coordValide(mixed $lat, mixed $lon): bool
{
    if ($lat === null || $lon === null || $lat === '' || $lon === '') return true; // effacement
    $lat = (float) $lat;
    $lon = (float) $lon;
    return $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180;
}

/**
 * L'heure de départ, en heure LOCALE, pour l'affichage et les formulaires.
 *
 * ⚠️ LA COLONNE EST EN UTC. La lire telle quelle et l'afficher donnerait
 * 08:00 pour un départ à 10:00 — et personne ne s'en apercevrait avant le jour
 * de la course, quand les chronos seraient tous faux de deux heures.
 */
function course_heureDepartLocale(?string $utc): ?DateTimeImmutable
{
    if ($utc === null || $utc === '' || str_starts_with($utc, '0000')) return null;
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Paris'));
    } catch (\Throwable $e) {
        return null;
    }
}

/** L'inverse : une saisie locale « 2026-10-04 10:00 » vers l'UTC stocké. */
function course_heureDepartUtc(?string $local): ?string
{
    if ($local === null || trim($local) === '') return null;
    try {
        return (new DateTimeImmutable($local, new DateTimeZone('Europe/Paris')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Ce qui manque encore pour que le chronométrage puisse fonctionner.
 *
 * Rendu tel quel à l'écran : un interrupteur « chronométrage activé » posé sur
 * une édition sans heure de départ ni ligne d'arrivée ne produirait aucun temps,
 * et personne ne saurait pourquoi. Mieux vaut le dire avant la course.
 *
 * @return string[] liste de manques, vide si tout est là.
 */
function course_manques(PDO $pdo, ?int $annee = null): array
{
    $c = course_lire($pdo, $annee);
    $m = [];
    if (empty($c['date_course']))  $m[] = 'la date de la course';
    if (empty($c['heure_depart'])) $m[] = "l'heure de départ";
    if ($c['lat_depart'] === null) $m[] = 'les coordonnées de la ligne de départ';
    if ($c['lat_arrivee'] === null) $m[] = "les coordonnées de la ligne d'arrivée";
    return $m;
}
