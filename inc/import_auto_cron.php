<?php
/**
 * import_auto_cron.php — Déclencheur de l'import automatique AssoConnect.
 *
 * Appelé périodiquement par le cron de l'hébergeur (PlanetHoster N0C), de deux façons :
 *   • en ligne de commande (recommandé) :
 *       php -q /home/<user>/.../inc/import_auto_cron.php >/dev/null 2>&1
 *   • via une URL (wget), protégé par token :
 *       wget -O - -q 'https://votre-site/inc/import_auto_cron.php?token=XXXX' --user-agent="CRON" >/dev/null 2>&1
 *
 * Le cron tourne souvent (ex. toutes les 5 min) ; le worker s'AUTO-RÉGULE : il ne
 * travaille que si l'échéance (interval_min) est atteinte, ou si « Lancer maintenant »
 * a été demandé depuis l'UI. La fréquence réelle se règle dans l'UI, pas dans le cron.
 *
 * En CLI : pas de token requis (exécution locale par le compte d'hébergement).
 * En HTTP : token obligatoire (sync_token_valid, comparaison en temps constant).
 */

@set_time_limit(0); // l'import peut être long (téléchargement + emails)
ignore_user_abort(true);

require_once __DIR__ . '/../src/content/sync_assoconnect.php'; // charge config.php → $pdo, encrypt()/decrypt()

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    if (!sync_token_valid($pdo, $_GET['token'] ?? null)) {
        http_response_code(403);
        echo "forbidden\n";
        exit;
    }
}

// Anti-concurrence : un verrou fichier évite deux imports simultanés (cron qui
// se chevauche si un cycle dépasse l'intervalle). Non bloquant : on sort si occupé.
$lockPath = sys_get_temp_dir() . '/fer_import_auto.lock';
$lock = fopen($lockPath, 'c');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "déjà en cours — sortie.\n";
    exit;
}

// L'UI décide : doit-on tourner maintenant, et dans quel mode ?
$decision = sync_should_run($pdo);
if (!$decision['should_run']) {
    echo "rien à faire (désactivé ou échéance non atteinte).\n";
    exit;
}

$res = sync_run_import($pdo, $decision['mode']);
echo (($res['ok'] ?? false) ? 'OK : ' : 'ERREUR : ') . ($res['message'] ?? '') . "\n";

if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
