<?php
/**
 * purges.php — Effacement des données dont la durée de conservation est écoulée (lot 7).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POURQUOI CE FICHIER EXISTE
 * Le RGPD n'impose pas seulement de protéger les données : il impose de ne pas
 * les garder plus longtemps que nécessaire. Une politique de confidentialité qui
 * annonce « conservé 30 jours » alors que rien n'efface jamais rien est une
 * fausse déclaration — et elle est vérifiable par n'importe qui.
 *
 * Chaque durée vient des réglages, jamais d'une constante figée : ce qui est
 * écrit dans la politique de confidentialité et ce que fait le code doivent
 * pouvoir être ajustés ensemble.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CE QUI N'EST JAMAIS PURGÉ, ET POURQUOI
 *   • Les INSCRIPTIONS (`registrations`, `registrations_AAAA`). L'association
 *     doit les conserver pour sa comptabilité, et les archives sont la mémoire
 *     de l'événement. Aucune purge ne les touche, jamais.
 *   • Les COMPTES coureurs actifs. Un compte n'est effacé que si la personne le
 *     demande (public/espace-coureur/compte.php), et même alors l'inscription
 *     survit : c'est l'ACCÈS qui disparaît, pas la participation.
 *
 * Ce qui est purgé, ce sont les données TECHNIQUES : codes d'authentification
 * consommés, jetons d'appareils révoqués, traces GPS anciennes, demandes de
 * transfert closes. Rien de tout cela n'a de valeur passé son délai.
 */

require_once __DIR__ . '/../core/config.php';

/** Réglages de conservation, avec des valeurs de repli sûres. */
function purge_settings(PDO $pdo): array
{
    /* traces_gps à 0 : conservation ILLIMITÉE par défaut. Choix de
       l'association — le but est de pouvoir revoir son parcours d'une année sur
       l'autre. Tenable parce que le suivi GPS exige un consentement explicite,
       et que le coureur peut supprimer ses traces lui-même à tout moment depuis
       « Mes résultats ». Les autres durées, elles, restent bornées. */
    $defauts = [
        'auth_codes_conservation_jours' => 30,
        'traces_gps_conservation_jours' => 0,
        'devices_revoques_jours'        => 90,
        'transferts_clos_jours'         => 365,
    ];
    try {
        $row = $pdo->query('SELECT auth_codes_conservation_jours, traces_gps_conservation_jours,
                                   devices_revoques_jours, transferts_clos_jours
                              FROM setting WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($defauts as $k => $v) {
            if (!isset($row[$k])) continue;
            $n = (int) $row[$k];
            /* ⚠️ 0 SIGNIFIE « CONSERVATION ILLIMITÉE », JAMAIS « effacer tout de
               suite ». La nuance est vitale : l'interprétation inverse viderait
               la table au premier passage de la purge. Le sens choisi va
               toujours dans le sens de la préservation — si quelqu'un se trompe
               de valeur, il garde trop, il ne perd rien.
               Une valeur négative est une erreur de saisie : on l'ignore. */
            if ($n > 0)                     $defauts[$k] = $n;
            elseif ($n === 0 && $k === 'traces_gps_conservation_jours') $defauts[$k] = 0;
        }
    } catch (\Throwable $e) {
        // Colonnes absentes (migration pas jouée) : on garde les défauts.
    }
    return $defauts;
}

/**
 * Exécute toutes les purges.
 *
 * ⚠️ Chaque suppression est indépendante et encadrée : si l'une échoue (table
 * absente sur une installation partiellement migrée), les autres se font quand
 * même. Une purge qui s'arrête à la première anomalie ne purge plus rien
 * pendant des mois sans que personne s'en aperçoive.
 *
 * @param  bool $simulation true = on COMPTE sans supprimer (bouton « Simuler »)
 * @return array{total:int, details:array<string,int>, erreurs:string[]}
 */
function purge_run(PDO $pdo, bool $simulation = false): array
{
    $s        = purge_settings($pdo);
    $details  = [];
    $erreurs  = [];

    /* Toutes les comparaisons de date se font EN SQL, par MySQL lui-même.
       Calculer une date limite en PHP puis la comparer en base obligerait les
       deux fuseaux à coïncider exactement — et un décalage effacerait des
       données trop tôt, sans le moindre message. */
    $taches = [
        // Codes à 6 chiffres : consommés ou périmés, ils ne servent plus à rien.
        // Ils ne contiennent aucune adresse en clair (seulement une empreinte),
        // mais ils tracent QUI a tenté de se connecter et QUAND.
        'codes_authentification' => [
            'compte'    => 'SELECT COUNT(*) FROM participant_auth_codes
                             WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            'supprime'  => 'DELETE FROM participant_auth_codes
                             WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            'jours'     => $s['auth_codes_conservation_jours'],
            'libelle'   => 'Codes de connexion périmés',
        ],

        // Appareils révoqués : la ligne ne sert plus qu'à l'historique. Le jeton
        // est déjà inutilisable (revoque_at renseigné) ; ce qui reste, c'est le
        // modèle du téléphone et l'IP de création — des données personnelles.
        'appareils_revoques' => [
            'compte'    => 'SELECT COUNT(*) FROM participant_devices
                             WHERE revoque_at IS NOT NULL
                               AND revoque_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            'supprime'  => 'DELETE FROM participant_devices
                             WHERE revoque_at IS NOT NULL
                               AND revoque_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            'jours'     => $s['devices_revoques_jours'],
            'libelle'   => 'Appareils révoqués',
        ],

        // Traces GPS : la donnée la plus sensible du site. Elle dit où une
        // personne se trouvait, minute par minute. 400 jours par défaut, pour
        // couvrir une édition entière PLUS la publication des résultats.
        'traces_gps' => [
            'compte'    => 'SELECT COUNT(*) FROM traces_gps
                             WHERE (purge_at IS NOT NULL AND purge_at < CURDATE())
                                OR (purge_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY))',
            'supprime'  => 'DELETE FROM traces_gps
                             WHERE (purge_at IS NOT NULL AND purge_at < CURDATE())
                                OR (purge_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY))',
            'jours'     => $s['traces_gps_conservation_jours'],
            'libelle'   => 'Traces GPS',
        ],

        // Demandes de transfert closes. Celles EN ATTENTE ne sont jamais
        // touchées : les effacer ferait disparaître une demande en cours.
        'transferts_clos' => [
            'compte'    => "SELECT COUNT(*) FROM registration_transfers
                             WHERE statut <> 'en_attente'
                               AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            'supprime'  => "DELETE FROM registration_transfers
                             WHERE statut <> 'en_attente'
                               AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            'jours'     => $s['transferts_clos_jours'],
            'libelle'   => 'Demandes de transfert closes',
        ],
    ];

    $total = 0;
    foreach ($taches as $cle => $t) {
        /* Durée à 0 = conservation illimitée : on ne purge pas, et on le DIT.
           Passer la tâche en silence laisserait croire qu'il n'y avait rien à
           effacer, alors que c'est un choix délibéré. */
        if ((int) $t['jours'] <= 0) {
            $details[$cle] = ['libelle' => $t['libelle'], 'jours' => 0, 'nombre' => 0,
                              'illimite' => true];
            continue;
        }
        try {
            $st = $pdo->prepare($t['compte']);
            $st->execute([$t['jours']]);
            $n = (int) $st->fetchColumn();

            if ($n > 0 && !$simulation) {
                $del = $pdo->prepare($t['supprime']);
                $del->execute([$t['jours']]);
                $n = $del->rowCount();
            }
            $details[$cle] = ['libelle' => $t['libelle'], 'jours' => $t['jours'], 'nombre' => $n];
            $total += $n;
        } catch (\Throwable $e) {
            // Table absente : ce n'est pas une erreur bloquante sur une
            // installation qui n'a pas encore tout migré.
            $erreurs[] = $t['libelle'] . ' : ' . $e->getMessage();
            $details[$cle] = ['libelle' => $t['libelle'], 'jours' => $t['jours'], 'nombre' => 0];
        }
    }

    if (!$simulation && $total > 0) {
        purge_journal($pdo, $total, $details);
    }

    return ['total' => $total, 'details' => $details, 'erreurs' => $erreurs];
}

/** Trace ce qui a été effacé — une purge non tracée est indémontrable. */
function purge_journal(PDO $pdo, int $total, array $details): void
{
    $lignes = [];
    foreach ($details as $d) {
        if ($d['nombre'] > 0) $lignes[] = $d['libelle'] . ' : ' . $d['nombre'];
    }
    $dir = dirname(__DIR__, 2) . '/storage/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents($dir . '/logs_purges_rgpd.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $total . ' ligne(s) effacée(s) — '
        . implode(' | ', $lignes) . "\n", FILE_APPEND);
}

/**
 * Purge opportuniste : au plus une fois par jour, déclenchée par le trafic.
 *
 * POURQUOI CE MÉCANISME PLUTÔT QU'UN CRON. Le site tourne chez un hébergeur
 * mutualisé ; exiger la configuration d'une tâche planifiée, c'est garantir
 * qu'elle ne sera pas faite, ou qu'elle sera perdue au prochain déménagement.
 * Ici, la première visite de la journée déclenche la purge. Le jour où un cron
 * existe, il peut appeler purge_run() directement : les deux cohabitent.
 *
 * Le verrou est posé AVANT le travail, pas après : sans cela, deux visiteurs
 * simultanés lanceraient deux purges en parallèle.
 */
function purge_quotidienne(PDO $pdo): void
{
    $marqueur = dirname(__DIR__, 2) . '/storage/logs/.purge-' . date('Y-m-d');
    if (file_exists($marqueur)) return;

    $dir = dirname($marqueur);
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

    // 'x' échoue si le fichier existe déjà : c'est un verrou atomique, deux
    // requêtes simultanées ne peuvent pas le créer toutes les deux.
    $fp = @fopen($marqueur, 'x');
    if ($fp === false) return;
    fclose($fp);

    // Ménage des marqueurs des jours précédents.
    foreach (glob($dir . '/.purge-*') ?: [] as $vieux) {
        if ($vieux !== $marqueur) @unlink($vieux);
    }

    try {
        purge_run($pdo, false);
    } catch (\Throwable $e) {
        error_log('[PURGE] ' . $e->getMessage());
    }
}
