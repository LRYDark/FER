<?php
/**
 * api/v1/index.php — API de l'application mobile (lot 5).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ⚠️ api.php (API machine-to-machine, token global) RESTE STRICTEMENT INCHANGÉ.
 * Cette API vit dans son propre espace de noms, avec sa propre authentification.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DEUX NIVEAUX DE JETON, ET POURQUOI
 *   • JETON D'APPAREIL — longue durée, sans expiration. Émis une fois, après
 *     validation du code à 6 chiffres. C'est le secret que l'application range
 *     dans le trousseau du téléphone. Il n'est jamais envoyé à chaque appel :
 *     moins il circule, moins il fuit.
 *   • JETON D'ACCÈS — une heure, en `Authorization: Bearer`. Dérivé du premier,
 *     renouvelé silencieusement par l'application.
 *
 * Le jeton d'accès est SIGNÉ, pas stocké : aucune table de sessions à purger.
 * Mais chaque appel revérifie en base que l'appareil n'a pas été révoqué — sans
 * quoi un jeton d'accès resterait valable jusqu'à une heure après une
 * révocation depuis « Mes appareils ». La révocation coupe donc les deux
 * niveaux immédiatement.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CONVENTIONS
 *   • Réponse : { "ok": bool, "data": …, "error": { "code": …, "message": … } }
 *   • Dates : ISO-8601 avec décalage explicite, jamais une date nue.
 *   • Aucune donnée personnelle en paramètre d'URL : les adresses passent par
 *     le corps de la requête, jamais par la barre d'adresse (les URL sont
 *     journalisées par les serveurs et les proxys).
 *   • CORS : aucune en-tête par défaut. Une application native n'en a pas
 *     besoin, et un « * » ouvrirait l'API à toutes les pages web du monde.
 */

// L'authentification est par jeton : aucune session PHP à ouvrir, aucun cookie
// à renvoyer. Doit précéder l'inclusion de config.php.
define('FER_NO_SESSION', true);

require_once __DIR__ . '/../../src/core/config.php';
require_once __DIR__ . '/../../src/core/registrations_resolver.php';
require_once __DIR__ . '/../../src/core/qrcode.php';
require_once __DIR__ . '/../../src/auth/participant_auth.php';
require_once __DIR__ . '/../../src/auth/participant_profile.php';
require_once __DIR__ . '/../../src/content/transfers.php';

/* ═══════════════════════════ Sortie JSON ════════════════════════════════ */

function api_json(int $code, array $charge): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($charge, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_ok($data = null, int $code = 200): never
{
    api_json($code, ['ok' => true, 'data' => $data, 'error' => null]);
}

function api_err(int $http, string $code, string $message): never
{
    api_json($http, ['ok' => false, 'data' => null,
                     'error' => ['code' => $code, 'message' => $message]]);
}

/** Date ISO-8601 avec décalage explicite, ou null. */
function api_date(?string $sqlDate): ?string
{
    if ($sqlDate === null || $sqlDate === '' || str_starts_with($sqlDate, '0000')) return null;
    try {
        return (new DateTimeImmutable($sqlDate))->format('c');
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * URL absolue d'une page du site, à partir d'un chemin relatif à sa racine.
 *
 * ⚠️ getAppBaseUrl() ne rend QUE le schéma et le domaine : si le site est
 * installé dans un sous-répertoire, le concaténer directement produit un lien
 * mort. La racine se déduit de SCRIPT_NAME en retirant « /api/v1 » — pas de
 * DOCUMENT_ROOT à interroger, et le projet reste déplaçable.
 */
function api_siteUrl(string $chemin): string
{
    $base = function_exists('getAppBaseUrl') ? rtrim(getAppBaseUrl(), '/') : '';
    $dir  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if (str_ends_with($dir, '/api/v1')) $dir = substr($dir, 0, -strlen('/api/v1'));
    return $base . $dir . '/' . ltrim($chemin, '/');
}

/** Journal dédié — apparaît automatiquement dans Journaux système. */
function api_log(string $message): void
{
    $dir = dirname(__DIR__, 2) . '/storage/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents($dir . '/logs_api_mobile.log',
        '[' . date('Y-m-d H:i:s') . '] ' . fer_client_ip() . ' ' . $message . "\n", FILE_APPEND);
}

/* ═══════════════════ Contrôle d'entrée de l'API ═════════════════════════
 *
 * TROIS BARRIÈRES QUI NE FONT PAS LE MÊME TRAVAIL — ne pas les confondre :
 *
 *   1. HTTPS        → protège les DONNÉES. La seule des trois qui empêche une
 *                     fuite : en clair, le jeton du coureur traverse le réseau
 *                     lisible par quiconque partage le même wifi.
 *   2. Interrupteur → protège contre l'IMPRÉVU. Un robinet qu'on ferme : faille,
 *                     abus, version de l'application qui déraille.
 *   3. Version min. → protège contre les VIEILLES VERSIONS, refusées par le
 *                     serveur et non par la bonne volonté du client.
 *
 * ⚠️ IL N'Y A DÉLIBÉRÉMENT AUCUNE « CLÉ D'APPLICATION » GLOBALE.
 * Elle serait livrée dans l'application installée sur chaque téléphone, donc
 * lisible par quiconque décompile le fichier — un secret publié n'est pas un
 * secret, et prétendre le contraire fait baisser la garde ailleurs. Ce qui
 * protège les données personnelles, c'est le jeton PERSONNEL de chaque coureur
 * (plus bas). Ce qui protège les envois de mail de /auth/request-code, c'est la
 * limitation de débit par adresse et par IP, dans participant_auth.php.
 * ───────────────────────────────────────────────────────────────────────── */

/** La connexion est-elle chiffrée ? Même détection que api.php, proxys compris. */
function api_isHttps(): bool
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

/**
 * Barrières 1 à 4, avant tout routage.
 *
 * @param string[] $route chemin découpé — sert à laisser passer /app/config
 */
function api_gate(PDO $pdo, array $route): void
{
    // 1. HTTPS. Toléré en boucle locale : ce trafic ne quitte pas la machine,
    //    et sans cette exception aucun développement local n'est possible.
    if (!api_isHttps() && !in_array(fer_client_ip(), ['127.0.0.1', '::1', ''], true)) {
        api_err(403, 'https_required', "L'API n'accepte que les connexions sécurisées HTTPS.");
    }

    try {
        $cfg = $pdo->query('SELECT api_v1_enabled, app_version_minimale
                              FROM setting WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        // Colonnes absentes : la migration n'a pas été jouée. On refuse plutôt
        // que d'ouvrir une API que personne n'a encore configurée.
        api_err(503, 'not_installed', 'Mise à jour de la base requise (update.php).');
    }

    // 2. Interrupteur.
    if (empty($cfg['api_v1_enabled'])) {
        api_err(503, 'api_disabled', "L'API mobile est désactivée. Activez-la dans Réglages → API.");
    }

    /* 3. VERSION MINIMALE — IMPOSÉE PAR LE SERVEUR, PAS SUGGÉRÉE.
     *
     * `app_version_minimale` était jusqu'ici une simple information servie par
     * /app/config : l'application devait être assez consciencieuse pour la lire
     * et se bloquer elle-même. Une version défectueuse qui l'ignore n'était donc
     * arrêtée par rien. C'est ici que le refus devient effectif.
     *
     * /app/config reste TOUJOURS joignable — c'est précisément là que
     * l'application périmée apprend qu'elle doit se mettre à jour, et où elle
     * trouve le lien du store. La lui fermer serait lui dire « tu es trop
     * vieille » sans jamais lui dire comment cesser de l'être.
     */
    if (($route[0] ?? '') === 'app' && ($route[1] ?? '') === 'config') return;

    $minimale = trim((string) ($cfg['app_version_minimale'] ?? '1.0.0'));
    $version  = trim((string) ($_SERVER['HTTP_X_APP_VERSION'] ?? ''));

    if ($version === '') {
        api_err(400, 'missing_app_version',
            "En-tête X-App-Version absent. Toute application doit annoncer sa version.");
    }
    // 426 Upgrade Required : le code HTTP prévu exactement pour ça. Un 403
    // laisserait croire à un problème de droits, et l'application afficherait
    // « accès refusé » au lieu de « mettez-moi à jour ».
    if ($minimale !== '' && version_compare($version, $minimale, '<')) {
        api_log("version $version refusee (minimum $minimale)");
        api_json(426, ['ok' => false, 'data' => null, 'error' => [
            'code'    => 'app_outdated',
            'message' => "Cette version de l'application n'est plus acceptée. Mettez-la à jour.",
            // Servis DANS l'erreur : l'application n'a pas à faire un second
            // appel pour savoir vers quoi diriger la personne.
            'version_minimale' => $minimale,
            'config_url'       => api_siteUrl('api/v1/app/config'),
        ]]);
    }
}

/* ══════════════════════════ Jetons d'accès ══════════════════════════════ */

/**
 * Clé de signature des jetons d'accès, dérivée d'ENCRYPTION_KEY par un contexte
 * dédié — la même mécanique de séparation de clés que fer_emailHmac().
 */
function api_signKey(): string
{
    static $k = null;
    if ($k === null) $k = hash_hmac('sha256', 'fer:api:access:v1', CIPHER_KEY, true);
    return $k;
}

/** Fabrique un jeton d'accès signé pour un appareil. */
function api_makeAccessToken(int $deviceId, int $ttlMinutes): array
{
    $exp     = time() + max(1, $ttlMinutes) * 60;
    $charge  = base64_encode(json_encode(['d' => $deviceId, 'e' => $exp]));
    $charge  = rtrim(strtr($charge, '+/', '-_'), '=');
    $sig     = rtrim(strtr(base64_encode(hash_hmac('sha256', $charge, api_signKey(), true)), '+/', '-_'), '=');
    return ['token' => $charge . '.' . $sig, 'expire_le' => $exp];
}

/** Vérifie la signature et l'expiration ; renvoie l'identifiant d'appareil. */
function api_readAccessToken(string $token): ?int
{
    $p = explode('.', $token);
    if (count($p) !== 2) return null;

    $attendu = rtrim(strtr(base64_encode(hash_hmac('sha256', $p[0], api_signKey(), true)), '+/', '-_'), '=');
    // hash_equals : comparaison à durée constante, pour ne pas laisser deviner
    // la signature octet par octet.
    if (!hash_equals($attendu, $p[1])) return null;

    $data = json_decode(base64_decode(strtr($p[0], '-_', '+/')), true);
    if (!is_array($data) || empty($data['d']) || empty($data['e'])) return null;
    if ((int) $data['e'] < time()) return null;

    return (int) $data['d'];
}

/**
 * Appareil authentifié pour cette requête, ou arrêt en 401.
 *
 * ⚠️ La vérification en base n'est PAS redondante avec la signature : un jeton
 * d'accès reste cryptographiquement valide jusqu'à son expiration, même après
 * une révocation. C'est ce contrôle qui rend la révocation immédiate.
 */
function api_requireDevice(PDO $pdo): array
{
    $entete = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(\S+)$/i', trim($entete), $m)) {
        api_err(401, 'missing_token', "Jeton d'accès absent.");
    }
    $deviceId = api_readAccessToken($m[1]);
    if ($deviceId === null) {
        api_err(401, 'invalid_token', "Jeton d'accès invalide ou expiré.");
    }

    $st = $pdo->prepare(
        'SELECT d.id, d.participant_id, d.type, p.is_active, p.email_chiffre, p.nom, p.prenom,
                p.rgpd_consent_at, p.derniere_connexion, p.created_at
           FROM participant_devices d
           JOIN participants p ON p.id = d.participant_id
          WHERE d.id = ? AND d.revoque_at IS NULL
            AND (d.expires_at IS NULL OR d.expires_at > NOW())
          LIMIT 1'
    );
    $st->execute([$deviceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row)                          api_err(401, 'device_revoked', 'Cet appareil a été révoqué.');
    if ((int) $row['is_active'] !== 1)  api_err(403, 'account_disabled', 'Ce compte est désactivé.');

    $pdo->prepare('UPDATE participant_devices SET derniere_utilisation = NOW() WHERE id = ?')
        ->execute([$deviceId]);

    return $row;
}

/* ═════════════════════════════ Routage ══════════════════════════════════ */

/* Chemin demandé, relatif au dossier de l'API. On repart de REQUEST_URI et non
   de PATH_INFO : celui-ci n'est pas renseigné par toutes les configurations. */
$racine  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$chemin  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($racine !== '' && str_starts_with($chemin, $racine)) {
    $chemin = substr($chemin, strlen($racine));
}
$route   = array_values(array_filter(explode('/', trim($chemin, '/')), fn($s) => $s !== ''));
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// AVANT tout traitement : HTTPS, interrupteur, version minimale. Placé ici et
// non dans chaque route : un point d'entrée ajouté plus tard est protégé
// d'office, on ne peut pas oublier le contrôle sur une route neuve.
api_gate($pdo, $route);

/* Corps JSON. Les données personnelles y passent, jamais par l'URL. */
$corps = [];
if (in_array($methode, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $brut = file_get_contents('php://input') ?: '';
    if ($brut !== '') {
        $j = json_decode($brut, true);
        if (is_array($j)) $corps = $j;
        elseif (json_last_error() !== JSON_ERROR_NONE) {
            api_err(400, 'invalid_json', 'Corps de requête JSON invalide.');
        }
    }
}

$settings = pauth_settings($pdo);
$ttlAcces = (int) ($pdo->query('SELECT app_access_token_ttl_min FROM setting WHERE id = 1')->fetchColumn() ?: 60);

/* Représentation d'une inscription, commune à tous les points d'entrée. */
$formatInscription = function (array $r): array {
    return [
        'annee'          => (int) $r['annee'],
        'inscription_no' => (string) $r['inscription_no'],
        'nom'            => $r['nom'],
        'prenom'         => $r['prenom'],
        'ville'          => $r['ville'],
        'sexe'           => $r['sexe'],
        'age'            => regres_age($r),
        'tshirt'         => ($r['tshirt_size'] ?? '-') !== '-' ? $r['tshirt_size'] : null,
        'equipe'         => $r['entreprise'] ?? null,
        'montant_du'     => isset($r['montant_du']) ? (float) $r['montant_du'] : null,
        'paiement_mode'  => $r['paiement_mode'] ?? null,
        'group_id'       => $r['group_id'] ?? null,
        'inscrit_le'     => api_date($r['date_inscription'] ?? null),
    ];
};

/* ─────────────────────────── /auth/* ─────────────────────────────────────
 * Routes exposées à des appareils non fiables : limitation de débit stricte et
 * journalisation systématique, succès comme échecs.
 * ──────────────────────────────────────────────────────────────────────── */
if (($route[0] ?? '') === 'auth') {
    $action = $route[1] ?? '';

    if ($action === 'request-code' && $methode === 'POST') {
        $email = fer_normalizeEmail((string) ($corps['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            api_err(422, 'invalid_email', 'Adresse email invalide.');
        }

        pauth_purgeCodes($pdo);
        if (!pauth_rateLimitOk($pdo, $email, fer_client_ip())) {
            api_log("request-code REFUSE (debit) $email");
            api_err(429, 'rate_limited', 'Trop de demandes. Réessayez dans quelques minutes.');
        }

        // Le code ne part que si une inscription correspond, mais la réponse est
        // IDENTIQUE dans tous les cas : l'API ne doit pas révéler quelles
        // adresses sont inscrites.
        if (regres_findByEmail($pdo, $email) || pauth_findByEmail($pdo, $email)) {
            pauth_sendCodeMail($pdo, $email, pauth_issueCode($pdo, $email, 'app', fer_client_ip()));
        }
        api_log("request-code $email");
        api_ok(['message' => "Si un compte correspond à cette adresse, un code vient d'être envoyé.",
                'ttl_minutes' => (int) $settings['participant_code_ttl_min']]);
    }

    if ($action === 'verify-code' && $methode === 'POST') {
        $email = fer_normalizeEmail((string) ($corps['email'] ?? ''));
        $code  = preg_replace('/\D+/', '', (string) ($corps['code'] ?? ''));
        if ($email === '' || $code === '') {
            api_err(422, 'missing_fields', 'Adresse et code sont obligatoires.');
        }

        $v = pauth_verifyCode($pdo, $email, $code);
        if (!$v['ok']) {
            api_log("verify-code ECHEC ({$v['raison']}) $email");
            $http = $v['raison'] === 'trop_de_tentatives' ? 429 : 401;
            api_err($http, 'invalid_code', match ($v['raison']) {
                'expire'             => 'Ce code a expiré.',
                'trop_de_tentatives' => 'Trop de tentatives. Demandez un nouveau code.',
                'aucun'              => 'Aucun code en attente.',
                default              => 'Code incorrect.',
            });
        }

        $participant = pauth_findByEmail($pdo, $email) ?? pauth_createFromRegistrations($pdo, $email);
        if ($participant === null) {
            api_err(403, 'no_registration', "Aucune inscription n'est associée à cette adresse.");
        }
        if ((int) $participant['is_active'] !== 1) {
            api_err(403, 'account_disabled', 'Ce compte est désactivé.');
        }
        pauth_syncRegistrations($pdo, (int) $participant['id'], $email);

        // Appareil de type « app » : sans expiration, l'application reste connectée.
        $deviceToken = pauth_rememberDevice($pdo, (int) $participant['id'], 'app');

        // Libellé et plateforme fournis par l'application, s'ils sont là.
        $infos = is_array($corps['device_info'] ?? null) ? $corps['device_info'] : [];
        if ($infos) {
            $pdo->prepare('UPDATE participant_devices SET libelle = ?, plateforme = ?, modele = ?
                            WHERE token_hash = ?')
                ->execute([
                    mb_substr(trim((string) ($infos['libelle'] ?? 'Application mobile')), 0, 120),
                    mb_substr(trim((string) ($infos['plateforme'] ?? '')), 0, 60),
                    mb_substr(trim((string) ($infos['modele'] ?? '')), 0, 120),
                    hash('sha256', $deviceToken),
                ]);
        }

        $st = $pdo->prepare('SELECT id FROM participant_devices WHERE token_hash = ?');
        $st->execute([hash('sha256', $deviceToken)]);
        $deviceId = (int) $st->fetchColumn();

        $acces = api_makeAccessToken($deviceId, $ttlAcces);
        api_log("verify-code OK $email (appareil $deviceId)");
        api_ok([
            'device_token'      => $deviceToken,
            'access_token'      => $acces['token'],
            'expires_at'        => api_date(date('Y-m-d H:i:s', $acces['expire_le'])),
            'rgpd_consent_requis' => empty($participant['rgpd_consent_at']),
        ]);
    }

    if ($action === 'refresh' && $methode === 'POST') {
        $deviceToken = (string) ($corps['device_token'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $deviceToken)) {
            api_err(422, 'invalid_device_token', "Jeton d'appareil malformé.");
        }
        $st = $pdo->prepare(
            'SELECT d.id, p.is_active FROM participant_devices d
               JOIN participants p ON p.id = d.participant_id
              WHERE d.token_hash = ? AND d.revoque_at IS NULL
                AND (d.expires_at IS NULL OR d.expires_at > NOW()) LIMIT 1'
        );
        $st->execute([hash('sha256', $deviceToken)]);
        $d = $st->fetch(PDO::FETCH_ASSOC);

        if (!$d)                          { api_log('refresh REFUSE (appareil inconnu ou révoqué)');
                                            api_err(401, 'device_revoked', 'Cet appareil a été révoqué.'); }
        if ((int) $d['is_active'] !== 1)  api_err(403, 'account_disabled', 'Ce compte est désactivé.');

        $acces = api_makeAccessToken((int) $d['id'], $ttlAcces);
        api_ok(['access_token' => $acces['token'],
                'expires_at'   => api_date(date('Y-m-d H:i:s', $acces['expire_le']))]);
    }

    if ($action === 'logout' && $methode === 'POST') {
        $device = api_requireDevice($pdo);
        $pdo->prepare('UPDATE participant_devices SET revoque_at = NOW() WHERE id = ?')
            ->execute([(int) $device['id']]);
        api_log('logout appareil ' . (int) $device['id']);
        api_ok(['message' => 'Appareil déconnecté.']);
    }

    api_err(404, 'unknown_endpoint', 'Point d\'entrée inconnu.');
}

/* ─────────────────────────── /app/config ─────────────────────────────────
 * Public, sans authentification : une application qui doit se mettre à jour
 * n'a précisément pas de jeton valide.
 *
 * ⚠️ CE POINT D'ENTRÉE PARAÎT INUTILE AUJOURD'HUI, IL NE L'EST PAS. Une
 * application publiée reste installée des années. Le jour où le contrat d'API
 * changera, il faut pouvoir dire aux vieilles versions « mets-toi à jour »
 * plutôt que de les voir échouer sans explication — et l'ajouter après coup est
 * impossible, les anciennes versions ne sauraient pas l'interroger.
 * ──────────────────────────────────────────────────────────────────────── */
if (($route[0] ?? '') === 'app' && ($route[1] ?? '') === 'config' && $methode === 'GET') {
    $s = $pdo->query('SELECT app_version_minimale, app_store_url_ios, app_store_url_android,
                             traces_gps_conservation_jours
                        FROM setting WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    api_ok([
        'version_minimale'   => $s['app_version_minimale'] ?? '1.0.0',
        'store_ios'          => $s['app_store_url_ios'] ?? null,
        'store_android'      => $s['app_store_url_android'] ?? null,
        'url_confidentialite' => api_siteUrl('public/politique-confidentialite.php'),
        'url_faq'            => api_siteUrl('public/faq.php'),
        'code_ttl_minutes'   => (int) $settings['participant_code_ttl_min'],
        'traces_conservation_jours' => (int) ($s['traces_gps_conservation_jours'] ?? 400),
        // Textes modifiables sans republier l'application.
        'messages' => [
            'connexion_aide' => "Saisissez l'adresse email utilisée lors de votre inscription. "
                              . "Un code à 6 chiffres vous sera envoyé.",
        ],
    ]);
}

/* ─────────────────────────── /editions ─────────────────────────────────── */
if (($route[0] ?? '') === 'editions') {
    api_requireDevice($pdo);

    $formatEdition = fn(array $e): array => [
        'id'                 => (int) $e['id'],
        'annee'              => (int) $e['annee'],
        'libelle'            => $e['libelle'],
        'date_course'        => $e['date_course'],
        'distance_km'        => $e['distance_km'] !== null ? (float) $e['distance_km'] : null,
        // ⏱️ Stockée en UTC : renvoyée en ISO-8601 avec décalage explicite.
        'heure_depart'       => api_date($e['heure_depart']),
        'depart'             => $e['lat_depart']  !== null
            ? ['lat' => (float) $e['lat_depart'],  'lon' => (float) $e['lon_depart']]  : null,
        'arrivee'            => $e['lat_arrivee'] !== null
            ? ['lat' => (float) $e['lat_arrivee'], 'lon' => (float) $e['lon_arrivee']] : null,
        'temps_min_plausible_s' => $e['temps_min_plausible_s'] !== null ? (int) $e['temps_min_plausible_s'] : null,
        'transferts_deadline'   => api_date($e['transferts_deadline']),
        'active'                => (int) $e['is_active'] === 1,
    ];

    if (!isset($route[1]) && $methode === 'GET') {
        $rows = $pdo->query('SELECT * FROM editions ORDER BY annee DESC')->fetchAll(PDO::FETCH_ASSOC);
        api_ok(array_map($formatEdition, $rows));
    }
    if (isset($route[1]) && $methode === 'GET') {
        $st = $pdo->prepare('SELECT * FROM editions WHERE id = ? LIMIT 1');
        $st->execute([(int) $route[1]]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if (!$e) api_err(404, 'not_found', 'Édition introuvable.');
        api_ok($formatEdition($e));
    }
    api_err(405, 'method_not_allowed', 'Méthode non autorisée.');
}

/* ─────────────────────────────── /me/* ─────────────────────────────────── */
if (($route[0] ?? '') === 'me') {
    $device        = api_requireDevice($pdo);
    $participantId = (int) $device['participant_id'];
    $sousRoute     = $route[1] ?? '';

    /* GET /me — profil */
    if ($sousRoute === '' && $methode === 'GET') {
        api_ok([
            'email'              => decrypt($device['email_chiffre']),
            'nom'                => $device['nom'],
            'prenom'             => $device['prenom'],
            'rgpd_accepte'       => !empty($device['rgpd_consent_at']),
            'derniere_connexion' => api_date($device['derniere_connexion']),
            'compte_cree_le'     => api_date($device['created_at']),
        ]);
    }

    /* PATCH /me — nom et prénom.
       Répercutés sur l'inscription de l'édition en cours par le module partagé :
       une correction faite dans l'application doit se retrouver sur le dossard. */
    if ($sousRoute === '' && $methode === 'PATCH') {
        $r = pprofile_majIdentite($pdo, $participantId,
            $corps['nom'] ?? null, $corps['prenom'] ?? null);
        if (!$r['ok']) api_err(422, 'invalid_input', $r['erreur']);
        api_log("identite modifiee (compte $participantId)");
        api_ok(['message' => $r['message']]);
    }

    /* Changement d'adresse email — DEUX APPELS, et c'est délibéré.
       Le premier envoie un code à la NOUVELLE adresse, le second le vérifie.
       En un seul appel, une faute de frappe enfermerait le coureur dehors :
       l'adresse du compte est son seul moyen de se reconnecter. */
    if ($sousRoute === 'email' && ($route[2] ?? '') === 'request-change' && $methode === 'POST') {
        $r = pprofile_demanderEmail($pdo, $participantId, (string) ($corps['email'] ?? ''), 'app');
        if (!$r['ok']) api_err(422, 'email_change_refused', $r['erreur']);
        api_log("changement email demande (compte $participantId)");
        api_ok(['message' => $r['message']]);
    }

    if ($sousRoute === 'email' && ($route[2] ?? '') === 'confirm' && $methode === 'POST') {
        $r = pprofile_confirmerEmail($pdo, $participantId,
            (string) ($corps['email'] ?? ''),
            preg_replace('/\D+/', '', (string) ($corps['code'] ?? '')));
        if (!$r['ok']) api_err(422, 'email_change_refused', $r['erreur']);
        api_log("changement email confirme (compte $participantId)");
        api_ok(['message' => $r['message']]);
    }

    /* /me/registrations[/{annee}/{no}][/qrcode]
       Identifiées par la CLÉ MÉTIER : les id techniques changent de table à
       chaque archivage annuel. */
    if ($sousRoute === 'registrations') {
        if (!isset($route[2]) && $methode === 'GET') {
            api_ok(array_map($formatInscription, pauth_registrations($pdo, $participantId)));
        }

        $annee = (int) ($route[2] ?? 0);
        $no    = (string) ($route[3] ?? '');
        if ($annee <= 0 || $no === '') {
            api_err(422, 'invalid_key', 'Année et numéro d\'inscription obligatoires.');
        }
        if (!pauth_owns($pdo, $participantId, $annee, $no)) {
            // 403 et non 404 : la clé est peut-être valide, mais elle ne vous
            // appartient pas. On ne dit pas laquelle des deux.
            api_err(403, 'forbidden', "Cette inscription n'est pas rattachée à votre compte.");
        }
        $r = regres_find($pdo, $annee, $no);
        if ($r === null) api_err(404, 'not_found', 'Inscription introuvable.');

        if (($route[4] ?? '') === 'qrcode' && $methode === 'GET') {
            // Même générateur que le mail et l'espace web : un seul QR possible.
            $png = fer_qrCodePngBytes($r['inscription_no']);
            if ($png === null) api_err(500, 'qr_error', 'QR code indisponible.');
            api_ok(['inscription_no' => $r['inscription_no'],
                    'png_base64'     => base64_encode($png)]);
        }

        /* PATCH — sexe et âge, les deux seuls champs que le coureur corrige
           lui-même. Le module partagé refuse les éditions archivées et les
           modifications après le départ ; l'API n'a pas sa propre version de
           ces règles, sinon elle deviendrait le chemin qui les contourne. */
        if (!isset($route[4]) && $methode === 'PATCH') {
            $maj = pprofile_majInscription($pdo, $participantId, $annee, $no,
                isset($corps['sexe']) ? (string) $corps['sexe'] : null,
                isset($corps['age'])  ? (string) $corps['age']  : null);
            if (!$maj['ok']) api_err(422, 'invalid_input', $maj['erreur']);
            api_log("inscription $annee/$no modifiee (compte $participantId)");
            api_ok(['message' => $maj['message'], 'inscription' => $formatInscription(
                regres_find($pdo, $annee, $no) ?? $r)]);
        }

        if ($methode === 'GET') api_ok($formatInscription($r));
        api_err(405, 'method_not_allowed', 'Méthode non autorisée.');
    }

    /* /me/devices[/{id}] */
    if ($sousRoute === 'devices') {
        if (!isset($route[2]) && $methode === 'GET') {
            $st = $pdo->prepare(
                'SELECT id, type, libelle, plateforme, modele, derniere_utilisation, expires_at, created_at
                   FROM participant_devices
                  WHERE participant_id = ? AND revoque_at IS NULL
                  ORDER BY derniere_utilisation DESC, id DESC'
            );
            $st->execute([$participantId]);
            api_ok(array_map(fn($d) => [
                'id'                   => (int) $d['id'],
                'type'                 => $d['type'],
                'libelle'              => $d['libelle'],
                'plateforme'           => $d['plateforme'],
                'modele'               => $d['modele'],
                'derniere_utilisation' => api_date($d['derniere_utilisation']),
                'expire_le'            => api_date($d['expires_at']),
                'cree_le'              => api_date($d['created_at']),
                'courant'              => (int) $d['id'] === (int) $device['id'],
            ], $st->fetchAll(PDO::FETCH_ASSOC)));
        }

        if (isset($route[2]) && $methode === 'DELETE') {
            // Le WHERE porte AUSSI sur participant_id : un identifiant deviné ne
            // révoque pas l'appareil de quelqu'un d'autre.
            $st = $pdo->prepare('UPDATE participant_devices SET revoque_at = NOW()
                                  WHERE id = ? AND participant_id = ? AND revoque_at IS NULL');
            $st->execute([(int) $route[2], $participantId]);
            if ($st->rowCount() === 0) api_err(404, 'not_found', 'Appareil introuvable ou déjà révoqué.');
            api_ok(['message' => 'Appareil révoqué.']);
        }
        api_err(405, 'method_not_allowed', 'Méthode non autorisée.');
    }

    /* /me/transfers[/{id}] */
    if ($sousRoute === 'transfers') {
        xfer_purge($pdo);

        if (!isset($route[2]) && $methode === 'GET') {
            $st = $pdo->prepare('SELECT * FROM registration_transfers WHERE demande_par = ? ORDER BY id DESC');
            $st->execute([$participantId]);
            api_ok(array_map(fn($t) => [
                'id'             => (int) $t['id'],
                'annee'          => (int) $t['annee'],
                'inscription_no' => $t['inscription_no'],
                'email_cible'    => decrypt($t['email_cible']),
                'statut'         => $t['statut'],
                'expire_le'      => api_date($t['expires_at']),
                'demande_le'     => api_date($t['created_at']),
                'accepte_le'     => api_date($t['accepte_at']),
            ], $st->fetchAll(PDO::FETCH_ASSOC)));
        }

        if (!isset($route[2]) && $methode === 'POST') {
            $annee = (int) ($corps['annee'] ?? 0);
            $no    = trim((string) ($corps['inscription_no'] ?? ''));
            $cible = (string) ($corps['email_cible'] ?? '');
            if ($annee <= 0 || $no === '' || $cible === '') {
                api_err(422, 'missing_fields', 'annee, inscription_no et email_cible sont obligatoires.');
            }
            $r = xfer_creer($pdo, $participantId, $annee, $no, $cible);
            if (!$r['ok']) api_err(422, 'transfer_refused', $r['erreur']);

            $t = xfer_parToken($pdo, $r['token']);
            // Le lien du mail pointe vers la page web : c'est là que la cible
            // confirme, elle n'a pas forcément l'application. On ne peut pas
            // utiliser xfer_lienBase(), qui déduit le dossier de SCRIPT_NAME —
            // il vaudrait « /api/v1 » ici.
            xfer_mailCible($pdo, $t, $r['token'], api_siteUrl('public/espace-coureur/transfert.php'));
            xfer_mailSource($pdo, $t);
            api_ok(['id' => $r['id'], 'statut' => 'en_attente'], 201);
        }

        if (isset($route[2]) && $methode === 'DELETE') {
            $r = xfer_annuler($pdo, (int) $route[2], $participantId);
            if (!$r['ok']) api_err(404, 'not_found', $r['erreur']);
            api_ok(['message' => 'Demande de transfert annulée.']);
        }
        api_err(405, 'method_not_allowed', 'Méthode non autorisée.');
    }

    /* GET /me/results
       Renvoie une liste VIDE aujourd'hui : les tables existent mais ne sont pas
       alimentées. Le définir maintenant évite de casser le contrat d'API quand
       la v2 de l'application arrivera.
       `methode` et `precision_s` sont TOUJOURS exposés : un temps extrapolé au
       GPS ne doit jamais être présenté comme équivalent à un temps beacon. */
    if ($sousRoute === 'results' && $methode === 'GET') {
        $inscriptions = pauth_registrations($pdo, $participantId);
        if (!$inscriptions) api_ok([]);

        $conds = [];
        $args  = [];
        foreach ($inscriptions as $r) {
            $conds[] = '(annee = ? AND inscription_no = ?)';
            $args[]  = (int) $r['annee'];
            $args[]  = (string) $r['inscription_no'];
        }
        try {
            $st = $pdo->prepare('SELECT * FROM resultats WHERE ' . implode(' OR ', $conds)
                              . ' ORDER BY annee DESC');
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $rows = [];
        }

        api_ok(array_map(fn($x) => [
            'annee'          => (int) $x['annee'],
            'inscription_no' => $x['inscription_no'],
            'statut'         => $x['statut'],
            'depart_at'      => api_date($x['depart_at']),
            'arrivee_at'     => api_date($x['arrivee_at']),
            'temps_s'        => $x['temps_s'] !== null ? (float) $x['temps_s'] : null,
            'methode'        => $x['methode'],
            'precision_s'    => $x['precision_s'] !== null ? (int) $x['precision_s'] : null,
            'distance_m'     => $x['distance_m'] !== null ? (int) $x['distance_m'] : null,
            'denivele_positif_m' => $x['denivele_positif_m'] !== null ? (int) $x['denivele_positif_m'] : null,
        ], $rows));
    }

    api_err(404, 'unknown_endpoint', 'Point d\'entrée inconnu.');
}

api_err(404, 'unknown_endpoint', "Point d'entrée inconnu.");
