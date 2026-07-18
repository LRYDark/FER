<?php
/**
 * api.php — API publique de Forbach en Rose
 * ==========================================
 * Point d'entrée unique permettant à des applications externes de se connecter
 * au site de manière sécurisée et authentifiée (identifiant + token).
 *
 * Activation et identifiants : Réglages → onglet « API ».
 * Documentation complète : inc/api-doc.php (bouton « Voir la documentation »).
 *
 * Sécurité : l'API n'accepte QUE les connexions HTTPS (le HTTP est refusé),
 * sauf en local (127.0.0.1 / ::1) pour permettre les tests de développement.
 *
 * Authentification — 2 méthodes acceptées (au choix) :
 *   1. En-têtes : X-Api-User: <identifiant>  +  X-Api-Token: <token>   (le plus simple)
 *   2. Bearer   : Authorization: Bearer <token>                        (standard)
 *
 * Endpoints :
 *   GET  ?endpoint=ping                    Test de connexion / d'authentification
 *   POST ?endpoint=import                  Phase 1 — import d'un fichier Excel
 *   POST ?endpoint=registration            Phase 2 — ajout d'un seul inscrit
 *   GET  ?endpoint=registration            Phase 3 — recherche d'un inscrit
 *   GET  ?endpoint=registrations           Phase 3 — liste des inscrits
 *   GET  ?endpoint=stats                   Phase 3 — statistiques
 *   GET  ?endpoint=years                   Phase 3 — années archivées disponibles
 */

require __DIR__ . '/src/core/config.php';   // $pdo, encrypt(), decrypt(), decryptRows()

/* ─── En-têtes JSON + CORS ─────────────────────────────────────────────── */
// 🔒 [SEC-CORS] Origin '*' volontaire et SANS danger ici : l'authentification se
// fait EXCLUSIVEMENT par secret en en-tête (X-Api-Token / Authorization: Bearer),
// jamais par cookie, et on n'émet PAS 'Access-Control-Allow-Credentials'. Un site
// tiers ne peut donc pas forcer le navigateur d'une victime à joindre le token.
// Restreindre l'origine casserait des intégrations navigateur sans gain de sécurité.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Api-User, X-Api-Token');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ─── Garde-fou : toute exception non attrapée renvoie du JSON propre ──── */
set_exception_handler(function (\Throwable $e) {
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    error_log('[API uncaught] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'error'   => 'server_error',
        'message' => 'Erreur interne du serveur.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

/* ─── Helpers de réponse ───────────────────────────────────────────────── */
$GLOBALS['__api_endpoint'] = $_GET['endpoint'] ?? $_GET['route'] ?? '';

function api_client_ip(): string
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/**
 * Journalise l'appel dans le fichier storage/logs/api.log
 * (au même endroit que php-error.log et logs_google_mails.log).
 * Consultable et videable depuis inc/logs.php. Ne bloque jamais la réponse.
 */
function api_log(int $status, string $message = ''): void
{
    $dir = __DIR__ . '/storage/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

    $line = sprintf(
        "%s | %s | %s %s | HTTP %d | %s%s",
        date('Y-m-d H:i:s'),
        api_client_ip() ?: '-',
        $_SERVER['REQUEST_METHOD'] ?? '-',
        ((string) $GLOBALS['__api_endpoint']) !== '' ? $GLOBALS['__api_endpoint'] : '-',
        $status,
        str_replace(["\r", "\n"], ' ', $message),
        PHP_EOL
    );
    @file_put_contents($dir . '/api.log', $line, FILE_APPEND | LOCK_EX);
}

/** Renvoie une réponse JSON puis termine le script. */
function api_respond(int $code, array $payload): void
{
    http_response_code($code);
    api_log($code, $payload['message'] ?? ($payload['ok'] ? 'ok' : 'error'));
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Renvoie une erreur JSON normalisée. */
function api_fail(int $code, string $error, string $message, array $extra = []): void
{
    api_respond($code, array_merge(['ok' => false, 'error' => $error, 'message' => $message], $extra));
}

/* ─── Sécurité : HTTPS obligatoire ─────────────────────────────────────── */
/**
 * Détecte si la connexion est chiffrée (HTTPS), y compris derrière un
 * reverse-proxy ou Cloudflare.
 */
function api_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]) === 'https') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])
        && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') return true;
    if (!empty($_SERVER['HTTP_CF_VISITOR'])
        && stripos((string) $_SERVER['HTTP_CF_VISITOR'], 'https') !== false) return true;
    return false;
}

// On bloque le HTTP, sauf en local (boucle locale) pour les tests de dev :
// le trafic loopback ne quitte jamais la machine, il n'y a aucun risque
// d'interception.
$__apiIp = api_client_ip();
$__isLoopback = in_array($__apiIp, ['127.0.0.1', '::1', ''], true);
if (!api_is_https() && !$__isLoopback) {
    api_fail(403, 'https_required',
        "L'API n'accepte que les connexions sécurisées HTTPS. Utilisez une URL en https://");
}

/* ─── Vérification de l'activation + chargement des identifiants ───────── */
try {
    $apiCfg = $pdo->query('SELECT api_enabled, api_user, api_token FROM setting WHERE id = 1 LIMIT 1')
                  ->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (\PDOException $e) {
    api_fail(503, 'not_installed',
        "L'API n'est pas encore installée. L'administrateur doit exécuter update.php.");
}

if (empty($apiCfg['api_enabled'])) {
    api_fail(403, 'api_disabled',
        "L'API est désactivée. Activez-la dans Réglages → onglet API.");
}
if (empty($apiCfg['api_user']) || empty($apiCfg['api_token'])) {
    api_fail(403, 'api_disabled',
        "Les identifiants de l'API ne sont pas configurés.");
}

/* ─── Extraction des identifiants fournis par le client ───────────────── */
/**
 * Deux méthodes acceptées : en-têtes X-Api-User / X-Api-Token, ou
 * Authorization: Bearer <token>. La méthode HTTP Basic n'est pas supportée
 * (encodage base64 trivial, mise en cache par les clients/navigateurs).
 */
function api_extract_credentials(): array
{
    $user  = '';
    $token = '';

    // Méthode 2 — Authorization: Bearer <token> (l'en-tête peut être masqué
    // par certains serveurs : on tente plusieurs sources)
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $auth = $v; break; }
        }
    }
    if (stripos($auth, 'Bearer ') === 0) {
        $token = trim(substr($auth, 7));
    }

    // Méthode 1 — en-têtes dédiés X-Api-User / X-Api-Token (prioritaires)
    if (!empty($_SERVER['HTTP_X_API_USER']))  $user  = $_SERVER['HTTP_X_API_USER'];
    if (!empty($_SERVER['HTTP_X_API_TOKEN'])) $token = $_SERVER['HTTP_X_API_TOKEN'];

    return [trim((string) $user), trim((string) $token)];
}

[$clientUser, $clientToken] = api_extract_credentials();

if ($clientToken === '') {
    api_fail(401, 'unauthorized',
        "Token manquant. Fournissez vos identifiants via l'en-tête Authorization ou X-Api-Token.");
}

$realToken = (string) decrypt($apiCfg['api_token']);
$tokenOk   = hash_equals($realToken, $clientToken);
// L'identifiant est facultatif (Bearer/token seul accepté) mais, s'il est
// fourni, il doit correspondre.
$userOk = ($clientUser === '') || hash_equals((string) $apiCfg['api_user'], $clientUser);

if (!$tokenOk || !$userOk) {
    api_fail(401, 'unauthorized', 'Identifiant ou token invalide.');
}

/* ════════════════════════════ ROUTAGE ════════════════════════════════ */
$endpoint = $GLOBALS['__api_endpoint'];
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** Lit le corps de requête JSON (avec repli sur les données de formulaire). */
function api_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $json = json_decode($raw, true);
        if (is_array($json)) return $json;
    }
    return $_POST;
}

switch ($endpoint) {

    /* ─── Test de connexion ───────────────────────────────────────────── */
    case 'ping':
        api_respond(200, [
            'ok'       => true,
            'message'  => 'API opérationnelle. Authentification réussie.',
            'api_user' => $apiCfg['api_user'],
            'time'     => date('c'),
        ]);
        break;

    /* ─── PHASE 1 : import d'un fichier Excel ─────────────────────────── */
    case 'import':
        if ($method !== 'POST') {
            api_fail(405, 'method_not_allowed', 'Utilisez POST pour importer un fichier.');
        }
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            api_fail(400, 'no_file',
                "Aucun fichier reçu. Envoyez le fichier Excel dans le champ « file » (multipart/form-data).");
        }
        // Envoi des mails : ?send_mails=1 ou champ POST send_mails (comme la case du dashboard)
        $sendMails = !empty($_POST['send_mails']) || !empty($_GET['send_mails']);

        require_once __DIR__ . '/src/content/registrations_core.php';
        $result = regcore_importExcel(
            $pdo,
            $_FILES['file']['tmp_name'],
            $_FILES['file']['name'],
            $sendMails
        );

        if (empty($result['ok'])) {
            api_fail(422, $result['error'] ?? 'import_error',
                $result['message'] ?? "Échec de l'import.",
                isset($result['missing']) ? ['missing' => $result['missing']] : []);
        }
        api_respond(200, array_merge(
            ['ok' => true, 'message' => 'Import terminé.', 'mails_demandes' => $sendMails],
            $result
        ));
        break;

    /* ─── PHASE 2 : ajout d'un seul inscrit ───────────────────────────── */
    case 'registration':
        if ($method === 'POST') {
            $d = api_input();
            // Envoi du mail de confirmation : ACTIVÉ par défaut (comme le bouton
            // « Nouvel inscrit » du dashboard). Pour NE PAS envoyer de mail,
            // passez send_mails=0 (ou send_mail=0 / false). Les deux noms et les
            // valeurs 1/0, true/false, yes/no sont acceptés.
            $sendMailRaw = $d['send_mails']    ?? $d['send_mail']
                        ?? $_GET['send_mails'] ?? $_GET['send_mail'] ?? null;
            $sendMail = ($sendMailRaw === null)
                || filter_var($sendMailRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;

            require_once __DIR__ . '/src/content/registrations_core.php';
            $res = regcore_createRegistration($pdo, $d, $sendMail, $d['origine'] ?? 'API');

            if (empty($res['ok'])) {
                api_fail(400, $res['error'] ?? 'invalid', $res['message'] ?? 'Données invalides.');
            }
            api_respond(201, array_merge(
                ['message' => 'Inscrit ajouté avec succès.', 'mail_demande' => $sendMail],
                $res
            ));
        }

        /* ─── PHASE 3 : recherche d'un inscrit ────────────────────────── */
        if ($method === 'GET') {
            $critNom    = trim((string) ($_GET['nom']    ?? ''));
            $critPrenom = trim((string) ($_GET['prenom'] ?? ''));
            $critEmail  = trim((string) ($_GET['email']  ?? ''));

            if ($critNom === '' && $critPrenom === '' && $critEmail === '') {
                api_fail(400, 'missing_criteria',
                    'Fournissez au moins un critère de recherche : nom, prenom ou email.');
            }

            // Les champs personnels sont chiffrés en base : on déchiffre puis on filtre en PHP.
            $year  = (int) ($_GET['year'] ?? 0);
            $table = $year > 0 ? "registrations_$year" : 'registrations';
            if ($year > 0 && !api_archive_exists($pdo, $year)) {
                api_fail(404, 'year_not_found', "Aucune archive pour l'année $year.");
            }

            $rows = decryptRows($pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC));
            $eq = fn($a, $b) => mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));

            $matches = array_values(array_filter($rows, function ($r) use ($critNom, $critPrenom, $critEmail, $eq) {
                if ($critNom    !== '' && !$eq($r['nom']    ?? '', $critNom))    return false;
                if ($critPrenom !== '' && !$eq($r['prenom'] ?? '', $critPrenom)) return false;
                if ($critEmail  !== '' && !$eq($r['email']  ?? '', $critEmail))  return false;
                return true;
            }));

            if (!$matches) {
                api_fail(404, 'not_found', 'Aucun inscrit ne correspond à ces critères.');
            }
            api_respond(200, [
                'ok'      => true,
                'message' => count($matches) . ' inscrit(s) trouvé(s).',
                'count'   => count($matches),
                'results' => $matches,
            ]);
        }

        api_fail(405, 'method_not_allowed', 'Méthode non supportée pour cet endpoint.');
        break;

    /* ─── PHASE 3 : liste des inscrits (année en cours ou archivée) ───── */
    case 'registrations':
        if ($method !== 'GET') {
            api_fail(405, 'method_not_allowed', 'Utilisez GET pour lister les inscrits.');
        }
        $year = (int) ($_GET['year'] ?? 0);
        if ($year > 0) {
            if (!api_archive_exists($pdo, $year)) {
                api_fail(404, 'year_not_found', "Aucune archive pour l'année $year.");
            }
            $rows = $pdo->query("SELECT * FROM `registrations_$year`")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = $pdo->query(
                "SELECT * FROM registrations
                 ORDER BY CAST(REPLACE(REPLACE(inscription_no, 'S', ''), 'E', '') AS UNSIGNED) DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
        }
        $rows = decryptRows($rows);

        // Filtres optionnels : sexe, tshirt_size, ville, entreprise, origine,
        // nom, prenom, email, naissance, tel. Exemple : ?tshirt_size=M&sexe=F
        [$rows, $appliedFilters] = api_apply_filters($rows);

        $message = count($rows) . ' inscrit(s)'
                 . ($appliedFilters ? ' correspondant aux filtres' : '') . '.';
        api_respond(200, [
            'ok'      => true,
            'message' => $message,
            'year'    => $year > 0 ? $year : (int) date('Y'),
            'archived'=> $year > 0,
            'filters' => $appliedFilters,
            'count'   => count($rows),
            'results' => $rows,
        ]);
        break;

    /* ─── PHASE 3 : statistiques ──────────────────────────────────────── */
    case 'stats':
        if ($method !== 'GET') {
            api_fail(405, 'method_not_allowed', 'Utilisez GET pour consulter les statistiques.');
        }
        $year = (int) ($_GET['year'] ?? 0);

        // Statistiques d'une année archivée précise
        if ($year > 0) {
            $st = $pdo->prepare('SELECT * FROM registrations_stats WHERE year = ? LIMIT 1');
            $st->execute([$year]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                api_fail(404, 'year_not_found', "Aucune statistique archivée pour l'année $year.");
            }
            api_respond(200, ['ok' => true, 'message' => "Statistiques $year.", 'year' => $year, 'stats' => $row]);
        }

        // Vue d'ensemble : année en cours + moyennes globales + détail par année
        $allYears = $pdo->query(
            'SELECT year, total_inscrits, tshirt_xs, tshirt_s, tshirt_m, tshirt_l, tshirt_xl,
                    tshirt_xxl, age_moyen, ville_top, entreprise_top, plus_vieux_h, plus_vieille_f
               FROM registrations_stats ORDER BY year'
        )->fetchAll(PDO::FETCH_ASSOC);

        $nbYr = count($allYears);
        $sumTotal = 0;
        $sumAge   = 0;
        $nbAge    = 0;
        foreach ($allYears as $s) {
            $sumTotal += (int) $s['total_inscrits'];
            if ($s['age_moyen'] !== null) { $sumAge += (float) $s['age_moyen']; $nbAge++; }
        }

        $currentTotal = (int) $pdo->query('SELECT COUNT(*) FROM registrations')->fetchColumn();
        $tshirtRecovered = (int) $pdo->query(
            "SELECT COUNT(*) FROM registrations
              WHERE tshirt_size IS NOT NULL AND tshirt_size <> '' AND tshirt_size <> '-'"
        )->fetchColumn();

        api_respond(200, [
            'ok'      => true,
            'message' => "Statistiques générales.",
            'annee_en_cours' => [
                'year'             => (int) date('Y'),
                'total_inscrits'   => $currentTotal,
                'tshirt_recuperes' => $tshirtRecovered,
            ],
            'moyennes' => [
                'inscrits_par_an' => $nbYr ? round($sumTotal / $nbYr, 1) : 0,
                'age_moyen_global'=> $nbAge ? round($sumAge / $nbAge, 1) : null,
                'annees_archivees'=> $nbYr,
            ],
            'detail_par_annee' => $allYears,
        ]);
        break;

    /* ─── PHASE 3 : années archivées disponibles ──────────────────────── */
    case 'years':
        if ($method !== 'GET') {
            api_fail(405, 'method_not_allowed', 'Utilisez GET.');
        }
        $years = $pdo->query('SELECT year FROM registrations_stats ORDER BY year DESC')
                     ->fetchAll(PDO::FETCH_COLUMN);
        api_respond(200, [
            'ok'      => true,
            'message' => count($years) . ' année(s) archivée(s).',
            'years'   => array_map('intval', $years),
        ]);
        break;

    /* ─── Endpoint inconnu ────────────────────────────────────────────── */
    case '':
        api_fail(400, 'missing_endpoint',
            "Paramètre « endpoint » manquant. Endpoints : ping, import, registration, registrations, stats, years.");
        break;

    default:
        api_fail(404, 'unknown_endpoint',
            "Endpoint « $endpoint » inconnu. Endpoints : ping, import, registration, registrations, stats, years.");
}

/* ─── Helper : vérifie l'existence d'une table d'archive ───────────────── */
function api_archive_exists(PDO $pdo, int $year): bool
{
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $st->execute([$dbName, "registrations_$year"]);
    return (int) $st->fetchColumn() > 0;
}

/**
 * Filtre une liste d'inscrits (déjà déchiffrés) selon les paramètres GET.
 * Champs filtrables : sexe, tshirt_size, ville, entreprise, origine,
 * paiement_mode, nom, prenom, email, tel, naissance, inscription_no.
 *
 * Chaque filtre accepte une ou plusieurs valeurs séparées par une virgule
 * (ex. ?tshirt_size=M,L) — un inscrit correspond s'il matche l'une d'elles.
 * La comparaison est exacte, insensible à la casse et aux espaces.
 *
 * @return array [rows filtrées, filtres appliqués ['champ'=>'valeur']]
 */
function api_apply_filters(array $rows): array
{
    $filterable = [
        'sexe', 'tshirt_size', 'ville', 'entreprise', 'origine', 'paiement_mode',
        'nom', 'prenom', 'email', 'tel', 'naissance', 'inscription_no',
    ];

    $applied = [];
    foreach ($filterable as $field) {
        if (!isset($_GET[$field])) continue;
        $val = trim((string) $_GET[$field]);
        if ($val === '') continue;
        $applied[$field] = $val;
    }
    if (!$applied) return [$rows, []];

    $norm = fn($v) => mb_strtolower(trim((string) $v));

    $filtered = array_values(array_filter($rows, function ($r) use ($applied, $norm) {
        foreach ($applied as $field => $val) {
            // Plusieurs valeurs possibles séparées par une virgule
            $accepted = array_map($norm, explode(',', $val));
            if (!in_array($norm($r[$field] ?? ''), $accepted, true)) {
                return false;
            }
        }
        return true;
    }));

    return [$filtered, $applied];
}
