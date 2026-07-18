<?php
/**
 * sync_assoconnect.php — Helpers de l'import automatique AssoConnect.
 *
 * Centralise : lecture/écriture de la config (table `sync_assoconnect`),
 * chiffrement du mot de passe AssoConnect, et la validation du token partagé
 * des endpoints internes.
 *
 * CHIFFREMENT — on réutilise le chiffrement du site (encrypt()/ENCRYPTION_KEY,
 * AES-256-GCM), le même que pour les autres données sensibles en base. L'import
 * tourne entièrement en PHP sur le site (voir sync_run_import + assoconnect_client.php) :
 * aucun worker externe, le mot de passe est déchiffré localement le temps de la requête.
 *
 * TOKEN — il vit DANS LA BASE (sync_assoconnect.worker_token) : auto-généré au premier
 * affichage des réglages. Il sécurise l'URL du cron (inc/import_auto_cron.php?token=…).
 * Repli rétrocompat : si la colonne est vide mais qu'un SYNC_WORKER_TOKEN existe dans
 * config/.env, il est encore honoré.
 */

require_once __DIR__ . '/../core/config.php'; // $pdo, $_ENV, encrypt() chargés

/**
 * Token partagé des endpoints internes (lecture seule).
 * Source : sync_assoconnect.worker_token en base. Repli rétrocompat sur l'ancien
 * SYNC_WORKER_TOKEN de config/.env si la colonne est vide / pas encore migrée.
 */
function sync_worker_token(PDO $pdo): string
{
    try {
        $tok = $pdo->query('SELECT worker_token FROM sync_assoconnect WHERE id = 1 LIMIT 1')->fetchColumn();
        if (is_string($tok) && $tok !== '') {
            return $tok;
        }
    } catch (\Throwable $e) {
        // Colonne pas encore migrée → on tente le repli .env ci-dessous.
    }
    $env = $_ENV['SYNC_WORKER_TOKEN'] ?? getenv('SYNC_WORKER_TOKEN');
    return is_string($env) ? trim($env) : '';
}

/**
 * Retourne le token, en le GÉNÉRANT et le persistant en base s'il est absent.
 * Réservé aux contextes admin (page Réglages) — jamais appelé par les endpoints
 * publics, qui ne doivent pas écrire. @return string '' si écriture impossible.
 */
function sync_get_or_create_token(PDO $pdo): string
{
    $tok = sync_worker_token($pdo); // base, sinon repli .env
    if ($tok !== '') {
        // Cas repli .env non encore persisté : on l'ancre en base (idempotent).
        try {
            $inDb = $pdo->query('SELECT worker_token FROM sync_assoconnect WHERE id = 1')->fetchColumn();
            if (empty($inDb)) {
                $pdo->prepare('UPDATE sync_assoconnect SET worker_token = ? WHERE id = 1')->execute([$tok]);
            }
        } catch (\Throwable $e) {
            // colonne absente : on renvoie quand même le token (repli .env)
        }
        return $tok;
    }
    $new = bin2hex(random_bytes(32)); // 64 hex, comme l'API
    try {
        $pdo->prepare('UPDATE sync_assoconnect SET worker_token = ? WHERE id = 1')->execute([$new]);
    } catch (\Throwable $e) {
        return '';
    }
    return $new;
}

/** Régénère le token (invalide l'ancien). @return string le nouveau token. */
function sync_regenerate_token(PDO $pdo): string
{
    $new = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE sync_assoconnect SET worker_token = ? WHERE id = 1')->execute([$new]);
    return $new;
}

/**
 * Validation du token en TEMPS CONSTANT (anti timing-attack, CWE-208).
 * Refuse si aucun token n'est configuré côté serveur. Lecture seule (pas d'écriture
 * depuis un endpoint public).
 */
function sync_token_valid(PDO $pdo, ?string $provided): bool
{
    $expected = sync_worker_token($pdo);
    if ($expected === '' || !is_string($provided) || $provided === '') {
        return false;
    }
    return hash_equals($expected, $provided);
}

/**
 * Chiffre le mot de passe AssoConnect avec le chiffrement du site (AES-256-GCM,
 * encrypt()/ENCRYPTION_KEY) — identique aux autres données sensibles. Le worker
 * déchiffre avec la même clé. @return string|null chiffré, ou null si entrée vide.
 */
function sync_encrypt_password(string $plain): ?string
{
    if ($plain === '') return null;
    return encrypt($plain); // défini dans config.php (AES-256-GCM)
}

/** Lit la configuration (ligne unique id=1). Retourne [] si absente. */
function sync_get_config(PDO $pdo): array
{
    try {
        $row = $pdo->query('SELECT * FROM sync_assoconnect WHERE id = 1 LIMIT 1')
                   ->fetch(PDO::FETCH_ASSOC);
        return $row ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Décide si le worker doit travailler, et dans quel mode.
 * La cadence réelle = interval_min (la borne « due » est calculée côté SQL pour
 * rester cohérente avec le fuseau de last_run_at / NOW()).
 *
 * @return array ['should_run'=>bool, 'mode'=>'test'|'run']
 */
function sync_should_run(PDO $pdo): array
{
    $cfg = $pdo->query(
        'SELECT enabled, run_requested, test_requested,
                (last_run_at IS NULL
                 OR last_run_at <= (NOW() - INTERVAL interval_min MINUTE)) AS due
           FROM sync_assoconnect WHERE id = 1 LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);

    if (!$cfg) return ['should_run' => false, 'mode' => 'run'];

    if ((int) $cfg['test_requested'] === 1) {
        return ['should_run' => true, 'mode' => 'test'];
    }
    // « Lancer maintenant » (run_requested) est un déclenchement MANUEL : il est
    // honoré même si l'import auto est désactivé (sinon le bouton resterait sans
    // effet alors que le toast annonce un lancement).
    if ((int) $cfg['run_requested'] === 1) {
        return ['should_run' => true, 'mode' => 'run'];
    }
    if ((int) $cfg['enabled'] === 1 && (int) $cfg['due'] === 1) {
        return ['should_run' => true, 'mode' => 'run'];
    }
    return ['should_run' => false, 'mode' => 'run'];
}

/** Marque le démarrage d'une exécution (statut « running »). */
function sync_mark_running(PDO $pdo): void
{
    $pdo->prepare("UPDATE sync_assoconnect SET last_status = 'running' WHERE id = 1")->execute();
}

/**
 * Clôture une exécution : horodatage, statut, message, lignes, et remise à zéro
 * des drapeaux de déclenchement (évite les doubles exécutions).
 *
 * @param string $status 'ok' | 'error' | 'idle'
 */
function sync_finish(PDO $pdo, string $status, string $message, int $rows = 0): void
{
    $status = in_array($status, ['ok', 'error', 'running', 'idle'], true) ? $status : 'idle';
    $pdo->prepare(
        'UPDATE sync_assoconnect
            SET last_run_at    = NOW(),
                last_status    = ?,
                last_message   = ?,
                last_rows      = ?,
                run_requested  = 0,
                test_requested = 0
          WHERE id = 1'
    )->execute([$status, mb_substr($message, 0, 1000), $rows]);
}

/**
 * Exécute un cycle d'import auto EN PHP (sans worker externe) :
 *   - lit la config (table sync_assoconnect), déchiffre le mot de passe ;
 *   - se connecte à AssoConnect et télécharge le .xlsx (AssoConnectClient) ;
 *   - mode 'test' : on s'arrête au téléchargement (vérifie la connexion) ;
 *   - mode 'run'  : on importe via importInscritsExcel() — EXACTEMENT comme l'import
 *     manuel, avec l'option « Envoyer les mails » de la config (QR selon réglage global).
 *
 * Met à jour le statut (sync_finish) et NE PROPAGE JAMAIS d'exception. Le mot de
 * passe en clair n'est gardé en mémoire que le temps de la requête.
 *
 * @param string $mode 'test' | 'run'
 * @return array ['ok' => bool, 'message' => string, 'rows' => int]
 */
function sync_run_import(PDO $pdo, string $mode): array
{
    $mode = $mode === 'test' ? 'test' : 'run';
    $cfg  = sync_get_config($pdo);

    $login   = trim((string) ($cfg['ac_login_url'] ?? ''));
    $regUrl  = trim((string) ($cfg['ac_registrants_url'] ?? ''));
    $email   = trim((string) ($cfg['ac_email'] ?? ''));
    $encPass = (string) ($cfg['ac_password_enc'] ?? '');

    if ($login === '' || $regUrl === '' || $email === '' || $encPass === '') {
        $msg = "Configuration incomplète : URL de connexion, URL de la liste, email et mot de passe sont requis.";
        sync_finish($pdo, 'error', $msg);
        return ['ok' => false, 'message' => $msg, 'rows' => 0];
    }

    try {
        $password = decrypt($encPass); // config.php (AES-256-GCM, ENCRYPTION_KEY)
    } catch (\Throwable $e) {
        $msg = 'Impossible de déchiffrer le mot de passe AssoConnect.';
        sync_finish($pdo, 'error', $msg);
        return ['ok' => false, 'message' => $msg, 'rows' => 0];
    }

    sync_mark_running($pdo);

    // 1. Connexion + export (client PHP, sans navigateur)
    require_once __DIR__ . '/assoconnect_client.php';
    try {
        $client = new AssoConnectClient();
        $xlsx   = $client->exportXlsx($login, $regUrl, $email, $password);
    } catch (\Throwable $e) {
        $msg = 'Export AssoConnect : ' . $e->getMessage();
        sync_finish($pdo, 'error', $msg);
        return ['ok' => false, 'message' => $msg, 'rows' => 0];
    } finally {
        if (isset($password) && is_string($password)) { $password = str_repeat('x', strlen($password)); }
        unset($password);
    }

    // 2. Mode test : on s'arrête là (connexion + export validés).
    if ($mode === 'test') {
        $msg = sprintf('Test réussi : connexion + export AssoConnect OK (%s octets), sans import.', number_format(strlen($xlsx)));
        sync_finish($pdo, 'ok', $msg, 0);
        return ['ok' => true, 'message' => $msg, 'rows' => 0];
    }

    // 3. Mode run : import via la logique manuelle partagée (importInscritsExcel).
    require_once __DIR__ . '/registrations_core.php';
    $tmp = (string) tempnam(sys_get_temp_dir(), 'acx');
    file_put_contents($tmp, $xlsx);
    $sendMail = (int) ($cfg['import_send_mail'] ?? 1) === 1;
    try {
        $res = importInscritsExcel(
            $pdo,
            $tmp,
            'export.xlsx',
            ['send_mail' => $sendMail, 'created_by' => null, 'origine' => 'AssoConnect'],
            null
        );
    } catch (\Throwable $e) {
        @unlink($tmp);
        $msg = "Échec de l'import : " . $e->getMessage();
        sync_finish($pdo, 'error', $msg);
        return ['ok' => false, 'message' => $msg, 'rows' => 0];
    }
    @unlink($tmp);

    if (empty($res['ok'])) {
        $msg = $res['message'] ?? "Échec de l'import.";
        sync_finish($pdo, 'error', $msg);
        return ['ok' => false, 'message' => $msg, 'rows' => 0];
    }

    $rows = (int) ($res['rows'] ?? 0);
    $msg  = sprintf(
        'Import auto : %d ajout(s), %d ignoré(s), %d doublon(s), %d mail(s).',
        $rows,
        (int) ($res['skipped'] ?? 0),
        (int) ($res['duplicates'] ?? 0),
        (int) ($res['mails_sent'] ?? 0)
    );
    sync_finish($pdo, 'ok', $msg, $rows);
    return ['ok' => true, 'message' => $msg, 'rows' => $rows];
}
