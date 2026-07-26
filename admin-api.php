<?php
require __DIR__ . '/src/core/config.php';
require_once __DIR__ . '/src/security/csrf.php';
require_once __DIR__ . '/src/security/captcha.php';
header('Content-Type: application/json; charset=utf-8');

// ─── Garde-fou global : toute exception non attrapée renvoie du JSON propre ───
// Évite les réponses vides ou HTML qui causent "Erreur de communication" côté client.
set_exception_handler(function(\Throwable $e) {
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    error_log('[API uncaught] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'err' => 'Erreur interne du serveur. Si le problème persiste, exécutez update.php pour appliquer les migrations de base de données.']);
    exit;
});

$route = $_GET['route'] ?? '';

// ─── CSRF check for state-changing API requests (skip public/pre-auth routes) ───
// 🔒 [FIX-13] logout retiré des routes CSRF-exempt — force-logout via CSRF impossible (CWE-352)
$csrfExemptRoutes = ['login', 'login-check-email', 'validate-2fa', 'resend-2fa', 'validate-totp', 'webauthn-auth-verify', 'webauthn-direct-options', 'webauthn-direct-verify', 'switch-2fa-method', 'forgot-password', 'reset-password-confirm', 'partner-request', 'partner-captcha-init'];
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE']) && !in_array($route, $csrfExemptRoutes)) {
    if (!csrf_verify()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Invalid CSRF token']);
        exit;
    }
}

/* ───── Helper: log login attempt ───────────── */
function logLoginAttempt($pdo, $userId, $email, $success, $reason = null) {
    try {
        $pdo->prepare('INSERT INTO login_logs (user_id, email, ip_address, user_agent, success, reason) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $email, getClientIp(), mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500), $success ? 1 : 0, $reason]);
        // Keep only last 500 entries
        $pdo->exec('DELETE FROM login_logs WHERE id NOT IN (SELECT id FROM (SELECT id FROM login_logs ORDER BY created_at DESC LIMIT 500) AS t)');
    } catch (\Throwable $e) {} // Table may not exist yet
}

// 🔒 [FIX-U2] Ban IP avec support auto-ban temporaire (expires_at) et ban permanent (CWE-307)
/**
 * Détecte le nom de la colonne d'IP dans login_banned_ips.
 * Anciens schémas peuvent avoir 'ip_address' au lieu de 'ip'.
 */
function _bannedIpsCol($pdo): string {
    static $col = null;
    if ($col !== null) return $col;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM login_banned_ips")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('ip', $cols, true))         return $col = 'ip';
        if (in_array('ip_address', $cols, true)) return $col = 'ip_address';
    } catch (\Throwable $e) {}
    return $col = 'ip'; // fallback par défaut
}

function getIpBanInfo($pdo, $ip): ?array {
    $ipCol = _bannedIpsCol($pdo);
    try {
        $st = $pdo->prepare(
            "SELECT reason, expires_at FROM login_banned_ips
             WHERE `{$ipCol}` = ? AND (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1"
        );
        $st->execute([$ip]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        // Fallback si colonne expires_at absente
        try {
            $st = $pdo->prepare("SELECT reason FROM login_banned_ips WHERE `{$ipCol}` = ? LIMIT 1");
            $st->execute([$ip]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ? array_merge($row, ['expires_at' => null]) : null;
        } catch (\Throwable $e2) { return null; }
    }
}

function isIpBanned($pdo, $ip) {
    return getIpBanInfo($pdo, $ip) !== null;
}

function getClientIp(): string {
    // 🔒 [SEC-IP] IP fiable centralisée (config.php) : REMOTE_ADDR seul par
    // défaut ; en-têtes de forwarding honorés uniquement si REMOTE_ADDR est un
    // proxy déclaré dans TRUSTED_PROXIES. Empêche le spoofing d'IP (contournement
    // des bans/rate-limits et bannissement de victimes).
    if (function_exists('fer_client_ip')) {
        return fer_client_ip();
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function checkTrustedDevice($pdo, $userId) {
    try {
        $token = $_COOKIE['fer_trust'] ?? '';
        if (!$token) return false;
        // 🔒 [SEC-TRUST] Le token est stocké HACHÉ (SHA-256) en base : on compare le
        // haché du cookie. Une fuite de trusted_devices ne donne donc aucun cookie
        // d'appareil de confiance utilisable (contournement 2FA 30 j).
        $st = $pdo->prepare('SELECT 1 FROM trusted_devices WHERE user_id = ? AND token = ? AND expires_at > NOW() LIMIT 1');
        $st->execute([$userId, hash('sha256', $token)]);
        return (bool) $st->fetch();
    } catch (\Throwable $e) { return false; }
}

function createTrustedDevice($pdo, $userId) {
    try {
        $token = bin2hex(random_bytes(32));          // secret : vit uniquement dans le cookie
        $tokenHash = hash('sha256', $token);          // stocké en base (64 hex → VARCHAR(64))
        $ip = getClientIp();
        $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $pdo->prepare('INSERT INTO trusted_devices (user_id, token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))')
            ->execute([$userId, $tokenHash, $ip, $ua]);
        setcookie('fer_trust', $token, time() + 86400 * 30, '/', '', true, true);
    } catch (\Throwable $e) {}
}

function maybeQueueMfaHint($pdo, $uid): void {
    try {
        $r = $pdo->prepare('SELECT totp_enabled FROM users WHERE id = ?');
        $r->execute([$uid]); $row = $r->fetch();
        if (!empty($row['totp_enabled'])) return;
        $stPk = $pdo->prepare('SELECT COUNT(*) FROM user_passkeys WHERE user_id = ?');
        $stPk->execute([$uid]);
        if ((int)$stPk->fetchColumn() > 0) return;
        $_SESSION['toasts'][] = [
            'msg'   => 'Connectez-vous plus rapidement ! Ajoutez une <strong>clé d\'accès</strong> ou une <strong>app TOTP</strong> depuis votre <a href="#" data-action="open-profile" style="color:inherit;font-weight:700;text-decoration:underline;">profil</a> pour ne plus saisir de mot de passe.',
            'type'  => 'info',
            'delay' => 15000,
        ];
    } catch (\Throwable $e) {}
}

function isMailConfigured(): bool {
    try {
        global $pdo;
        $stmt = $pdo->prepare('SELECT client_id, client_secret, mail_provider, smtp_host, smtp_user, smtp_pass FROM setting WHERE id = 1 LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;

        $provider = $row['mail_provider'] ?? 'google';
        if ($provider === 'smtp') {
            return !empty($row['smtp_host']) && !empty($row['smtp_user']) && !empty($row['smtp_pass']);
        }
        // Google mode
        if (!file_exists(__DIR__ . '/config/token.json')) return false;
        return !empty(decrypt($row['client_id'] ?? null)) && !empty(decrypt($row['client_secret'] ?? null));
    } catch (\Throwable $e) { return false; }
}

function send2faCode($pdo, $user) {
    try {
        require_once __DIR__ . '/src/mail/googleMail.php';
    } catch (\Throwable $e) {
        error_log('send2faCode: impossible de charger googleMail.php — ' . $e->getMessage());
        return false;
    }
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    try {
        $pdo->prepare('UPDATE users SET twofa_code = ?, twofa_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?')
            ->execute([$code, $user['id']]);
    } catch (\Throwable $e) { return false; }
    try {
        sendMail(
            $user['email'],
            'Code de verification – Forbach en Rose',
            'Code de verification',
            '<p>Votre code de verification est :</p><p style="font-size:32px;font-weight:700;letter-spacing:8px;text-align:center;color:#F42182;margin:20px 0">' . $code . '</p><p>Ce code est valable 15 minutes.</p><p>Si vous n\'avez pas demande cette connexion, ignorez ce message.</p>',
            null, null, 'info', null, 'code'
        );
        return true;
    } catch (\Throwable $e) {
        error_log('2FA mail error: ' . $e->getMessage());
        return false;
    }
}

/* ───── IP rate-limit inter-comptes (CWE-307) ──────── */
function countRecentIpFailures($pdo, $ip, int $windowMinutes = 15): int {
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM login_logs
             WHERE ip_address = ? AND success = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $st->execute([$ip, $windowMinutes]);
        return (int) $st->fetchColumn();
    } catch (\Throwable $e) { return 0; }
}

function autoBanIpIfNeeded($pdo, $ip, int $threshold = 10, int $banMinutes = 1440): void {
    if (isIpBanned($pdo, $ip)) return;

    $failures = countRecentIpFailures($pdo, $ip, $banMinutes);
    if ($failures < $threshold) return;

    $reason = "Auto-ban : $failures echecs de connexion en 24h (multi-comptes)";
    $banSucceeded = false;
    $ipCol = _bannedIpsCol($pdo);

    /* La table login_banned_ips a (idéalement) une UNIQUE KEY sur la colonne IP.
     * On utilise INSERT ... ON DUPLICATE KEY UPDATE pour gérer le cas où une
     * ancienne ligne existe déjà (ban expiré ou actif). */
    try {
        $sql = "INSERT INTO login_banned_ips (`{$ipCol}`, reason, banned_at, expires_at)
                VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE))
                ON DUPLICATE KEY UPDATE
                    reason = VALUES(reason),
                    banned_at = NOW(),
                    expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)";
        $pdo->prepare($sql)->execute([$ip, $reason, $banMinutes, $banMinutes]);
        $banSucceeded = true;
    } catch (\Throwable $e) {
        error_log('autoBanIpIfNeeded INSERT (with expires_at) failed: ' . $e->getMessage());
        // Fallback 1 : sans expires_at (vieux schéma)
        try {
            $sql2 = "INSERT INTO login_banned_ips (`{$ipCol}`, reason, banned_at)
                     VALUES (?, ?, NOW())
                     ON DUPLICATE KEY UPDATE reason = VALUES(reason), banned_at = NOW()";
            $pdo->prepare($sql2)->execute([$ip, $reason]);
            $banSucceeded = true;
        } catch (\Throwable $e2) {
            error_log('autoBanIpIfNeeded fallback 1 INSERT failed: ' . $e2->getMessage());
            // Fallback 2 : sans ON DUPLICATE KEY (pas de UNIQUE constraint)
            try {
                $sql3 = "INSERT INTO login_banned_ips (`{$ipCol}`, reason, banned_at) VALUES (?, ?, NOW())";
                $pdo->prepare($sql3)->execute([$ip, $reason]);
                $banSucceeded = true;
            } catch (\Throwable $e3) {
                error_log('autoBanIpIfNeeded fallback 2 INSERT failed: ' . $e3->getMessage());
            }
        }
    }

    // Si l'INSERT a échoué, on n'envoie PAS de mail trompeur disant que l'IP est bannie
    if (!$banSucceeded) {
        error_log("autoBanIpIfNeeded: aucun ban inséré pour IP $ip — pas de notification envoyée");
        return;
    }

    // Notifier les admins du ban IP (uniquement si l'INSERT a réussi)
    try {
        require_once __DIR__ . '/src/mail/googleMail.php';
        if (isMailConfigured() && isNotifyEnabled($pdo, 'ip_ban')) {
            $admins = getNotifyRecipients($pdo);
            $durationH = round($banMinutes / 60);
            foreach ($admins as $adminEmail) {
                sendMail($adminEmail, 'Ban IP automatique – Forbach en Rose', 'IP bannie automatiquement',
                    '<p>L\'adresse IP <strong>' . htmlspecialchars($ip) . '</strong> a ete bannie automatiquement pour ' . $durationH . 'h.</p>'
                    . '<p><strong>Raison :</strong> ' . htmlspecialchars($reason) . '</p>'
                    . '<p>Le ban expirera automatiquement. Vous pouvez le lever manuellement depuis l\'espace d\'administration.</p>',
                    null, null, 'info', null, 'test');
            }
        }
    } catch (\Throwable $e) { error_log('IP ban notification mail error: ' . $e->getMessage()); }
}

/* ───── LOGIN ÉTAPE 1 — vérification email ──────── */
if ($route==='login-check-email' && $_SERVER['REQUEST_METHOD']==='POST'){
    $d = json_decode(file_get_contents('php://input'), true);
    $ip = getClientIp();

    $banInfo = getIpBanInfo($pdo, $ip);
    if ($banInfo) {
        http_response_code(403);
        echo json_encode(['ok'=>false, 'err'=>'Adresse IP bloquée.']); exit;
    }

    $email = trim($d['email'] ?? '');
    if (!$email) { http_response_code(400); echo json_encode(['ok'=>false, 'err'=>'Email requis.']); exit; }

    $st = $pdo->prepare('SELECT id, role, email, is_active, totp_enabled, default_2fa_method FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]); $u = $st->fetch();

    // Utilisateur inconnu ou inactif → mot de passe requis (échec différé)
    if (!$u || !$u['is_active']) {
        echo json_encode(['ok'=>true, 'needs_password'=>true]); exit;
    }

    // Vérifier méthodes fortes (TOTP / passkey)
    $totpEnabled  = !empty($u['totp_enabled']);
    $passkeyIds   = [];
    $defaultMethod = $u['default_2fa_method'] ?? 'email';

    try {
        $stPk = $pdo->prepare('SELECT credential_id FROM user_passkeys WHERE user_id = ?');
        $stPk->execute([$u['id']]);
        $passkeyIds = $stPk->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {}

    $hasStrongMethod = $totpEnabled || !empty($passkeyIds);

    // Pas de méthode forte → mot de passe requis
    if (!$hasStrongMethod) {
        echo json_encode(['ok'=>true, 'needs_password'=>true]); exit;
    }

    // Méthode forte disponible → stocker la session pending et retourner la méthode 2FA
    $mailOk = isMailConfigured() && filter_var($u['email'], FILTER_VALIDATE_EMAIL);
    $availableMethods = [];
    if ($totpEnabled)        $availableMethods[] = 'totp';
    if (!empty($passkeyIds)) $availableMethods[] = 'passkey';

    $_SESSION['pending_2fa_uid']     = $u['id'];
    $_SESSION['pending_2fa_role']    = $u['role'];
    $_SESSION['pending_2fa_email']   = $u['email'];
    $_SESSION['pending_2fa_methods'] = $availableMethods;
    $_SESSION['twofa_attempts']      = 0;

    if (!in_array($defaultMethod, $availableMethods)) $defaultMethod = $availableMethods[0];

    if (count($availableMethods) === 1) {
        if ($availableMethods[0] === 'totp') {
            echo json_encode(['ok'=>true, 'requires_2fa'=>true, 'method'=>'totp']); exit;
        }
        if ($availableMethods[0] === 'passkey') {
            require_once __DIR__ . '/src/security/webauthn.php';
            $wa   = new WebAuthn(getWebAuthnRpId());
            $opts = $wa->authOptions($passkeyIds);
            echo json_encode(['ok'=>true, 'requires_2fa'=>true, 'method'=>'passkey', 'options'=>$opts]); exit;
        }
    }

    $extra = [];
    if ($defaultMethod === 'passkey') {
        require_once __DIR__ . '/src/security/webauthn.php';
        $wa = new WebAuthn(getWebAuthnRpId());
        $extra['passkey_options'] = $wa->authOptions($passkeyIds);
    }
    echo json_encode(array_merge(['ok'=>true, 'requires_2fa'=>true, 'method'=>'select', 'methods'=>$availableMethods, 'default'=>$defaultMethod], $extra)); exit;
}

/* ───── LOGIN / LOGOUT ───────────────────────── */
if ($route==='login' && $_SERVER['REQUEST_METHOD']==='POST'){
    $d = json_decode(file_get_contents('php://input'), true);
    $ip = getClientIp();

    // Check IP ban (permanent ou auto-ban temporaire)
    $banInfo = getIpBanInfo($pdo, $ip);
    if ($banInfo) {
        http_response_code(403);
        $msg = $banInfo['expires_at']
            ? 'Trop de tentatives echouees. Votre adresse IP est temporairement bloquee. Reessayez plus tard.'
            : 'Votre adresse IP a ete bannie. Contactez un administrateur.';
        echo json_encode(['ok'=>false, 'err'=>$msg]); exit;
    }

    try {
        $st=$pdo->prepare('SELECT id,email,password_hash,role,must_change_password,is_active,failed_attempts,locked_at FROM users WHERE email=?');
        $st->execute([$d['email'] ?? '']); $u=$st->fetch();
    } catch (\Throwable $e) {
        // Fallback avant migration : colonnes optionnelles absentes
        try {
            $st=$pdo->prepare('SELECT id,email,password_hash,role,is_active FROM users WHERE email=?');
            $st->execute([$d['email'] ?? '']); $raw=$st->fetch();
            $u = $raw ? array_merge(['must_change_password'=>0,'failed_attempts'=>0,'locked_at'=>null], $raw) : false;
        } catch (\Throwable $e2) {
            echo json_encode(['ok'=>false,'err'=>'Erreur de base de données. Exécutez update.php pour mettre à jour le schéma.']); exit;
        }
    }

    // 🔒 [SEC-LOCK] Verrouillage TEMPOREL (au lieu d'une désactivation permanente) :
    // après 3 échecs, le compte est bloqué 15 min puis se débloque tout seul. Empêche
    // le déni de service (verrouiller n'importe quel compte en connaissant juste l'email)
    // tout en freinant le bruteforce. Ne touche pas aux comptes désactivés par un admin
    // (is_active = 0, locked_at NULL) qui restent bloqués.
    if ($u && !empty($u['locked_at']) && (int)($u['failed_attempts'] ?? 0) >= 3 && (int)($u['is_active'] ?? 1) === 1) {
        // ⚠️ Calcul du temps restant CÔTÉ MYSQL : locked_at est en heure serveur MySQL
        // (SET time_zone = '+02:00'), qui peut différer du fuseau de PHP. Comparer avec
        // time()/strtotime() en PHP produisait un décalage (ex. « 135 min » au lieu de 15).
        $rq = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(?, INTERVAL 15 MINUTE)) AS remaining");
        $rq->execute([$u['locked_at']]);
        $remaining = (int) $rq->fetchColumn();
        if ($remaining > 0) {
            $wait = max(1, (int) ceil($remaining / 60));
            logLoginAttempt($pdo, $u['id'], $u['email'], false, 'Compte temporairement verrouillé');
            http_response_code(429);
            echo json_encode(['ok'=>false, 'err'=>"Trop de tentatives. Réessayez dans $wait minute(s)."]); exit;
        }
        // Fenêtre écoulée → déblocage automatique.
        try { $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_at = NULL WHERE id = ?')->execute([$u['id']]); } catch (\Throwable $e) {}
        $u['failed_attempts'] = 0;
        $u['locked_at'] = null;
    }

    // Si l'utilisateur vient du flux passwordless (pending_2fa_uid déjà défini) et a choisi "Mot de passe",
    // il faut toujours vérifier le mot de passe, même s'il a TOTP/passkey.
    $pendingPasswordFlow = $u && !empty($_SESSION['pending_2fa_uid']) && (int)$_SESSION['pending_2fa_uid'] === (int)$u['id'];

    // Si TOTP ou passkey configuré → pas besoin de mot de passe (sauf si flux password explicite)
    $hasStrongMethod = false;
    if ($u && !$pendingPasswordFlow) {
        try {
            $r2 = $pdo->prepare('SELECT totp_enabled FROM users WHERE id = ?');
            $r2->execute([$u['id']]); $r2 = $r2->fetch();
            if (!empty($r2['totp_enabled'])) $hasStrongMethod = true;
        } catch (\Throwable $e) {}
        if (!$hasStrongMethod) {
            try {
                $stPk2 = $pdo->prepare('SELECT COUNT(*) FROM user_passkeys WHERE user_id = ?');
                $stPk2->execute([$u['id']]);
                if ((int)$stPk2->fetchColumn() > 0) $hasStrongMethod = true;
            } catch (\Throwable $e) {}
        }
    }

    // Vérification explicite du mot de passe (séparée pour ne réinitialiser failed_attempts que si vérifié)
    if (!$u) {
        // 🔒 [SEC-ENUM] Anti-énumération par timing : quand l'email est inconnu, on
        // exécute quand même un password_verify factice (coût bcrypt constant) pour que
        // la réponse prenne le même temps que pour un email existant (CWE-208/CWE-204).
        password_verify($d['password'] ?? '', '$2y$10$K.qltx1IGHbZ0lrWt.v36u0qjxBkBsnWGHAMvw.qjIAVc6SkjrDeO');
        $passwordVerified = false;
    } else {
        $passwordVerified = !$hasStrongMethod && password_verify($d['password'] ?? '', $u['password_hash']);
    }

    if($u && ($hasStrongMethod || $passwordVerified)){
        if(!$u['is_active']){
            $reason = $u['locked_at'] ? 'Compte verrouille (3 echecs)' : 'Compte desactive';
            logLoginAttempt($pdo, $u['id'], $u['email'], false, $reason);
            http_response_code(403);
            $msg = $u['locked_at']
                ? 'Compte verrouille suite a 3 tentatives echouees. Utilisez "Mot de passe oublie" ou contactez un administrateur.'
                : 'Compte desactive. Contactez un administrateur.';
            echo json_encode(['ok'=>false, 'err'=>$msg]); exit;
        }
        // Reset failed attempts uniquement si le mot de passe a été vérifié
        if ($passwordVerified && $u['failed_attempts'] > 0) {
            $pdo->prepare('UPDATE users SET failed_attempts = 0 WHERE id = ?')->execute([$u['id']]);
        }

        // Must change password — no 2FA needed
        if($u['must_change_password']){
            session_regenerate_id(true);
            $_SESSION['uid']=$u['id']; $_SESSION['role']=$u['role']; $_SESSION['email']=$u['email'];
            logLoginAttempt($pdo, $u['id'], $u['email'], true, 'Changement MDP requis');
            echo json_encode(['ok'=>true, 'role'=>$u['role'], 'must_change_password'=>true]); exit;
        }

        // Vérifier appareil de confiance — uniquement si connexion par mot de passe
        if (!$hasStrongMethod && checkTrustedDevice($pdo, $u['id'])) {
            session_regenerate_id(true);
            $_SESSION['uid']=$u['id']; $_SESSION['role']=$u['role']; $_SESSION['email']=$u['email'];
            maybeQueueMfaHint($pdo, $u['id']);
            logLoginAttempt($pdo, $u['id'], $u['email'], true, 'Appareil de confiance');
            echo json_encode(['ok'=>true, 'role'=>$u['role']]); exit;
        }

        // Collecter les méthodes 2FA disponibles pour cet utilisateur
        $mailOk       = isMailConfigured() && filter_var($u['email'], FILTER_VALIDATE_EMAIL);
        $totpEnabled  = false;
        $passkeyIds   = [];
        $defaultMethod = 'email';

        try {
            $row2 = $pdo->prepare('SELECT totp_enabled, default_2fa_method FROM users WHERE id = ?');
            $row2->execute([$u['id']]); $row2 = $row2->fetch();
            $totpEnabled   = !empty($row2['totp_enabled']);
            $defaultMethod = $row2['default_2fa_method'] ?? 'email';
        } catch (\Throwable $e) {}

        try {
            $stPk = $pdo->prepare('SELECT credential_id FROM user_passkeys WHERE user_id = ?');
            $stPk->execute([$u['id']]);
            $passkeyIds = $stPk->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {}

        $availableMethods = [];
        if ($pendingPasswordFlow) {
            // Flux password explicite : seul le code email est proposé en second facteur
            if ($mailOk) $availableMethods[] = 'email';
        } else {
            if ($mailOk)             $availableMethods[] = 'email';
            if ($totpEnabled)        $availableMethods[] = 'totp';
            if (!empty($passkeyIds)) $availableMethods[] = 'passkey';
        }

        // Aucune méthode 2FA → connexion directe
        if (empty($availableMethods)) {
            session_regenerate_id(true);
            $_SESSION['uid']=$u['id']; $_SESSION['role']=$u['role']; $_SESSION['email']=$u['email'];
            maybeQueueMfaHint($pdo, $u['id']);
            logLoginAttempt($pdo, $u['id'], $u['email'], true, 'Connexion directe');
            echo json_encode(['ok'=>true, 'role'=>$u['role']]); exit;
        }

        // Stocker en session pour les étapes suivantes
        $_SESSION['pending_2fa_uid']     = $u['id'];
        $_SESSION['pending_2fa_role']    = $u['role'];
        $_SESSION['pending_2fa_email']   = $u['email'];
        $_SESSION['pending_2fa_methods'] = $availableMethods;
        $_SESSION['twofa_attempts']      = 0;

        // Choisir la méthode par défaut effective (la méthode préférée si disponible, sinon première dispo)
        if (!in_array($defaultMethod, $availableMethods)) $defaultMethod = $availableMethods[0];

        // Une seule méthode disponible
        if (count($availableMethods) === 1) {
            if ($availableMethods[0] === 'email') {
                try { $sent = send2faCode($pdo, $u); } catch (\Throwable $e) { $sent = false; }
                if (!$sent) {
                    // Fallback : connexion directe + alerte admin
                    unset($_SESSION['pending_2fa_uid'], $_SESSION['pending_2fa_role'], $_SESSION['pending_2fa_email'], $_SESSION['pending_2fa_methods']);
                    logLoginAttempt($pdo, $u['id'], $u['email'], true, '2FA skip - envoi code impossible');
                    session_regenerate_id(true);
                    $_SESSION['uid']=$u['id']; $_SESSION['role']=$u['role']; $_SESSION['email']=$u['email'];
                    try {
                        $admins = isNotifyEnabled($pdo, 'twofa') ? getNotifyRecipients($pdo) : [];
                        if (!empty($admins) && function_exists('sendMail')) {
                            foreach ($admins as $adminEmail) {
                                @sendMail($adminEmail, 'Alerte 2FA - Forbach en Rose', 'Connexion sans 2FA',
                                    '<p>Le compte <strong>' . htmlspecialchars($u['email']) . '</strong> s\'est connecte sans verification 2FA car l\'envoi du code a echoue.</p>',
                                    null, null, 'info', null, 'test');
                            }
                        }
                    } catch (\Throwable $e) {}
                    echo json_encode(['ok'=>true, 'role'=>$u['role']]); exit;
                }
                echo json_encode(['ok'=>true, 'requires_2fa'=>true, 'method'=>'email']); exit;
            }
            if ($availableMethods[0] === 'totp') {
                echo json_encode(['ok'=>true, 'requires_2fa'=>true, 'method'=>'totp']); exit;
            }
            if ($availableMethods[0] === 'passkey') {
                try {
                    require_once __DIR__ . '/src/security/webauthn.php';
                    $wa = new WebAuthn(getWebAuthnRpId());
                    $opts = $wa->authOptions($passkeyIds);
                    echo json_encode(['ok'=>true, 'requires_2fa'=>true, 'method'=>'passkey', 'options'=>$opts]); exit;
                } catch (\Throwable $e) {
                    // Passkey non disponible → connexion directe
                    session_regenerate_id(true);
                    $_SESSION['uid']=$u['id']; $_SESSION['role']=$u['role']; $_SESSION['email']=$u['email'];
                    echo json_encode(['ok'=>true, 'role'=>$u['role']]); exit;
                }
            }
        }

        // Plusieurs méthodes → laisser l'utilisateur choisir (méthode par défaut présélectionnée)
        $extra = [];
        if ($defaultMethod === 'passkey') {
            try {
                require_once __DIR__ . '/src/security/webauthn.php';
                $wa = new WebAuthn(getWebAuthnRpId());
                $extra['passkey_options'] = $wa->authOptions($passkeyIds);
            } catch (\Throwable $e) {
                // Passkey non disponible → retirer de la liste et choisir la prochaine méthode
                $availableMethods = array_values(array_diff($availableMethods, ['passkey']));
                $defaultMethod = $availableMethods[0] ?? 'email';
            }
        }
        echo json_encode(array_merge(['ok'=>true, 'requires_2fa'=>true, 'method'=>'select', 'methods'=>$availableMethods, 'default'=>$defaultMethod], $extra)); exit;
    }

    // Failed login
    logLoginAttempt($pdo, $u['id'] ?? null, $d['email'] ?? '', false, $u ? 'Mot de passe incorrect' : 'Email inconnu');

    // 🔒 [FIX-U2] Auto-ban IP si trop d'échecs inter-comptes en 15 min (CWE-307)
    autoBanIpIfNeeded($pdo, $ip);

    if ($u) {
        $attempts = ($u['failed_attempts'] ?? 0) + 1;
        if ($attempts >= 3) {
            // 🔒 [SEC-LOCK] Verrouillage TEMPOREL : on pose locked_at (NOW) sans désactiver
            // le compte (is_active reste à 1). Le déblocage est automatique après 15 min
            // (cf. contrôle plus haut). Évite le DoS par verrouillage permanent.
            try {
                $pdo->prepare('UPDATE users SET failed_attempts = ?, locked_at = NOW() WHERE id = ?')
                    ->execute([$attempts, $u['id']]);
            } catch (\Throwable $e) {
                try { $pdo->prepare('UPDATE users SET failed_attempts = ? WHERE id = ?')->execute([$attempts, $u['id']]); } catch (\Throwable $e2) {}
            }
            try {
                require_once __DIR__ . '/src/mail/googleMail.php';
                if (isMailConfigured() && isNotifyEnabled($pdo, 'lock')) {
                    $admins = getNotifyRecipients($pdo);
                    foreach ($admins as $adminEmail) {
                        sendMail($adminEmail, 'Compte verrouille – Forbach en Rose', 'Compte verrouille apres 3 tentatives',
                            '<p>Le compte <strong>' . htmlspecialchars($u['email']) . '</strong> a ete verrouille temporairement (15 min) apres 3 tentatives de connexion echouees.</p>'
                            . '<p>IP : ' . htmlspecialchars($ip) . '</p>', null, null, 'info', null, 'test');
                    }
                }
            } catch (\Throwable $e) { error_log('Lock notification mail error: ' . $e->getMessage()); }
        } else {
            try { $pdo->prepare('UPDATE users SET failed_attempts = ? WHERE id = ?')->execute([$attempts, $u['id']]); } catch (\Throwable $e) {}
        }
    }

    // 🔒 [FIX-ENUM] Message STRICTEMENT uniforme quel que soit l'état (email inconnu,
    // mot de passe faux, ou compte verrouillé) → aucune énumération d'utilisateur (CWE-204).
    http_response_code(401); echo json_encode(['ok'=>false, 'err'=>'Identifiants incorrects.']); exit;
}

/* ───── VALIDATE 2FA ─────────────────────────── */
if ($route==='validate-2fa' && $_SERVER['REQUEST_METHOD']==='POST'){
    $d = json_decode(file_get_contents('php://input'), true);
    $code = trim($d['code'] ?? '');
    $trustDevice = !empty($d['trust_device']);

    if (!isset($_SESSION['pending_2fa_uid'])) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'err'=>'Session 2FA expiree. Reconnectez-vous.']); exit;
    }

    // ── Rate-limit : 5 tentatives max par session 2FA ────────────────────────
    $_SESSION['twofa_attempts'] = ($_SESSION['twofa_attempts'] ?? 0) + 1;
    if ($_SESSION['twofa_attempts'] > 5) {
        session_unset();
        session_destroy();
        http_response_code(429);
        echo json_encode(['ok'=>false, 'err'=>'Trop de tentatives. Veuillez vous reconnecter.']); exit;
    }
    // ─────────────────────────────────────────────────────────────────────────

    $uid = $_SESSION['pending_2fa_uid'];
    $st = $pdo->prepare('SELECT twofa_code, twofa_expires FROM users WHERE id = ?');
    $st->execute([$uid]); $u2 = $st->fetch();

    // 🔒 [FIX-2FA] Comparaison timing-safe du code 2FA (CWE-208)
    if (!$u2 || !hash_equals((string)($u2['twofa_code'] ?? ''), $code) || strtotime($u2['twofa_expires']) < time()) {
        // 🔒 [SEC-2FA] Échec journalisé (persistant par IP) + auto-ban : anti-bruteforce
        // du code 2FA au-delà du simple compteur de session.
        logLoginAttempt($pdo, $uid, $_SESSION['pending_2fa_email'] ?? null, false, 'Code 2FA invalide');
        autoBanIpIfNeeded($pdo, getClientIp());
        http_response_code(401);
        echo json_encode(['ok'=>false, 'err'=>'Code invalide ou expire.']); exit;
    }

    // 2FA OK — invalider le code immédiatement, puis créer la vraie session
    $pdo->prepare('UPDATE users SET twofa_code = NULL, twofa_expires = NULL WHERE id = ?')->execute([$uid]);
    unset($_SESSION['twofa_attempts']);
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    $_SESSION['role'] = $_SESSION['pending_2fa_role'];
    $_SESSION['email'] = $_SESSION['pending_2fa_email'];
    unset($_SESSION['pending_2fa_uid'], $_SESSION['pending_2fa_role'], $_SESSION['pending_2fa_email']);

    // Log success
    logLoginAttempt($pdo, $uid, $_SESSION['email'], true, 'Code 2FA valide');

    // Trust device if requested
    if ($trustDevice) {
        createTrustedDevice($pdo, $uid);
    }

    // Hint MFA : les utilisateurs qui passent par le code email n'ont pas de TOTP/passkey
    maybeQueueMfaHint($pdo, $uid);

    echo json_encode(['ok'=>true, 'role'=>$_SESSION['role'], 'csrf_token'=>csrf_token()]); exit;
}

/* ───── RESEND 2FA (sans mot de passe) ──────────── */
if ($route==='resend-2fa' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (!isset($_SESSION['pending_2fa_uid'])) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'err'=>'Session 2FA expirée. Reconnectez-vous.']); exit;
    }

    // Rate-limit : 1 renvoi par minute
    $lastSent = $_SESSION['twofa_last_sent'] ?? 0;
    if (time() - $lastSent < 60) {
        http_response_code(429);
        echo json_encode(['ok'=>false, 'err'=>'Patientez avant de renvoyer le code.']); exit;
    }

    $uid = $_SESSION['pending_2fa_uid'];
    $st = $pdo->prepare('SELECT id, email FROM users WHERE id = ?');
    $st->execute([$uid]); $u = $st->fetch();

    if (!$u) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'err'=>'Utilisateur introuvable.']); exit;
    }

    $_SESSION['twofa_last_sent'] = time();
    $_SESSION['twofa_attempts']  = 0; // reset du compteur de tentatives

    $sent = send2faCode($pdo, $u);
    if ($sent) {
        echo json_encode(['ok'=>true]); exit;
    }
    http_response_code(500);
    echo json_encode(['ok'=>false, 'err'=>'Erreur lors de l\'envoi du code.']); exit;
}

if ($route==='logout'){
    session_unset();
    session_regenerate_id(true);
    session_destroy();
    echo json_encode(['ok'=>true]);
    exit;
}

/* ───── HEARTBEAT (keep-alive session) ─────────
 * Utilisé par l'auto-déconnexion côté client. src/core/config.php a déjà, à ce stade,
 * rafraîchi last_activity si la session est encore valide, ou l'a détruite si expirée.
 * On renvoie simplement si l'utilisateur est toujours authentifié. */
if ($route==='heartbeat'){
    echo json_encode(['ok' => isset($_SESSION['uid'])]);
    exit;
}

/* ───── SWITCH 2FA METHOD (pré-auth) ─────────── */
if ($route==='switch-2fa-method' && $_SERVER['REQUEST_METHOD']==='POST'){
    $d = json_decode(file_get_contents('php://input'), true);
    $method = $d['method'] ?? '';

    if (!isset($_SESSION['pending_2fa_uid'])) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'err'=>'Session 2FA expirée. Reconnectez-vous.']); exit;
    }
    $availMethods = $_SESSION['pending_2fa_methods'] ?? [];
    if (!in_array($method, $availMethods, true)) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'err'=>'Méthode non disponible.']); exit;
    }

    $uid = $_SESSION['pending_2fa_uid'];
    if ($method === 'email') {
        $u = $pdo->prepare('SELECT id, email FROM users WHERE id = ?');
        $u->execute([$uid]); $u = $u->fetch();
        $sent = $u ? send2faCode($pdo, $u) : false;
        if (!$sent) { http_response_code(500); echo json_encode(['ok'=>false, 'err'=>'Erreur envoi code email.']); exit; }
        $_SESSION['twofa_attempts'] = 0;
        echo json_encode(['ok'=>true, 'method'=>'email']); exit;
    }
    if ($method === 'totp') {
        $_SESSION['twofa_attempts'] = 0;
        echo json_encode(['ok'=>true, 'method'=>'totp']); exit;
    }
    if ($method === 'passkey') {
        require_once __DIR__ . '/src/security/webauthn.php';
        $stPk = $pdo->prepare('SELECT credential_id FROM user_passkeys WHERE user_id = ?');
        $stPk->execute([$uid]);
        $ids = $stPk->fetchAll(PDO::FETCH_COLUMN);
        if (empty($ids)) { http_response_code(400); echo json_encode(['ok'=>false, 'err'=>'Aucune clé d\'accès enregistrée.']); exit; }
        $wa = new WebAuthn(getWebAuthnRpId());
        $opts = $wa->authOptions($ids);
        echo json_encode(['ok'=>true, 'method'=>'passkey', 'options'=>$opts]); exit;
    }
    http_response_code(400);
    echo json_encode(['ok'=>false, 'err'=>'Méthode inconnue.']); exit;
}

/* ───── VALIDATE TOTP (pré-auth) ─────────────── */
if ($route==='validate-totp' && $_SERVER['REQUEST_METHOD']==='POST'){
    $d = json_decode(file_get_contents('php://input'), true);
    $code        = trim($d['code'] ?? '');
    $trustDevice = !empty($d['trust_device']);

    if (!isset($_SESSION['pending_2fa_uid'])) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'err'=>'Session 2FA expirée. Reconnectez-vous.']); exit;
    }

    $_SESSION['twofa_attempts'] = ($_SESSION['twofa_attempts'] ?? 0) + 1;
    if ($_SESSION['twofa_attempts'] > 5) {
        session_unset(); session_destroy();
        http_response_code(429);
        echo json_encode(['ok'=>false, 'err'=>'Trop de tentatives. Veuillez vous reconnecter.']); exit;
    }

    $uid = $_SESSION['pending_2fa_uid'];
    require_once __DIR__ . '/src/security/totp.php';
    $row = $pdo->prepare('SELECT totp_secret FROM users WHERE id = ? AND totp_enabled = 1');
    $row->execute([$uid]); $row = $row->fetch();

    $matchedCounter = $row ? TOTP::verify($row['totp_secret'] ?? '', $code) : false;
    if ($matchedCounter === false) {
        // 🔒 [SEC-2FA] Journaliser l'échec (persistant par IP) + auto-ban : empêche
        // le brute-force du TOTP en repartant d'une session neuve (le compteur en
        // session seul ne suffit pas). Réutilise l'infra anti-bruteforce du login.
        logLoginAttempt($pdo, $uid, $_SESSION['pending_2fa_email'] ?? null, false, 'Code TOTP invalide');
        autoBanIpIfNeeded($pdo, getClientIp());
        http_response_code(401);
        echo json_encode(['ok'=>false, 'err'=>'Code invalide.']); exit;
    }
    // 🔒 Anti-replay : rejeter un code déjà utilisé dans cette session pending
    $lastUsedCounter = $_SESSION['totp_used_counter'] ?? PHP_INT_MIN;
    if ($matchedCounter <= $lastUsedCounter) {
        logLoginAttempt($pdo, $uid, $_SESSION['pending_2fa_email'] ?? null, false, 'Code TOTP rejoué');
        autoBanIpIfNeeded($pdo, getClientIp());
        http_response_code(401);
        echo json_encode(['ok'=>false, 'err'=>'Code invalide.']); exit;
    }
    $_SESSION['totp_used_counter'] = $matchedCounter;

    unset($_SESSION['twofa_attempts']);
    session_regenerate_id(true);
    $_SESSION['uid']   = $uid;
    $_SESSION['role']  = $_SESSION['pending_2fa_role'];
    $_SESSION['email'] = $_SESSION['pending_2fa_email'];
    unset($_SESSION['pending_2fa_uid'], $_SESSION['pending_2fa_role'], $_SESSION['pending_2fa_email'], $_SESSION['pending_2fa_methods']);

    logLoginAttempt($pdo, $uid, $_SESSION['email'], true, 'TOTP validé');
    echo json_encode(['ok'=>true, 'role'=>$_SESSION['role'], 'csrf_token'=>csrf_token()]); exit;
}

/* ───── WEBAUTHN AUTH VERIFY (pré-auth) ─────── */
if ($route==='webauthn-auth-verify' && $_SERVER['REQUEST_METHOD']==='POST'){
    $d = json_decode(file_get_contents('php://input'), true);
    $trustDevice = !empty($d['trust_device']);

    if (!isset($_SESSION['pending_2fa_uid'])) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'err'=>'Session 2FA expirée. Reconnectez-vous.']); exit;
    }

    // 🔒 Rate-limit : 5 tentatives max (identique TOTP / email 2FA)
    $_SESSION['twofa_attempts'] = ($_SESSION['twofa_attempts'] ?? 0) + 1;
    if ($_SESSION['twofa_attempts'] > 5) {
        session_unset(); session_destroy();
        http_response_code(429);
        echo json_encode(['ok'=>false, 'err'=>'Trop de tentatives. Veuillez vous reconnecter.']); exit;
    }

    $uid = $_SESSION['pending_2fa_uid'];
    $credIdB64 = $d['credential_id'] ?? '';

    require_once __DIR__ . '/src/security/webauthn.php';
    $stPk = $pdo->prepare('SELECT id, public_key, sign_count FROM user_passkeys WHERE user_id = ? AND credential_id = ?');
    $stPk->execute([$uid, $credIdB64]);
    $passkey = $stPk->fetch();

    if (!$passkey) {
        http_response_code(401);
        echo json_encode(['ok'=>false, 'err'=>'Clé d\'accès inconnue.']); exit;
    }

    try {
        $wa = new WebAuthn(getWebAuthnRpId());
        $newCount = $wa->verifyAuthentication($d, $passkey['public_key'], (int)$passkey['sign_count']);
        $pdo->prepare('UPDATE user_passkeys SET sign_count = ?, last_used = NOW() WHERE id = ?')
            ->execute([$newCount, $passkey['id']]);
    } catch (\Throwable $e) {
        http_response_code(401);
        echo json_encode(['ok'=>false, 'err'=>'Authentification par clé d\'accès échouée : ' . $e->getMessage()]); exit;
    }

    session_regenerate_id(true);
    $_SESSION['uid']   = $uid;
    $_SESSION['role']  = $_SESSION['pending_2fa_role'];
    $_SESSION['email'] = $_SESSION['pending_2fa_email'];
    unset($_SESSION['pending_2fa_uid'], $_SESSION['pending_2fa_role'], $_SESSION['pending_2fa_email'], $_SESSION['pending_2fa_methods'], $_SESSION['wa_auth_challenge']);

    logLoginAttempt($pdo, $uid, $_SESSION['email'], true, 'Clé d\'accès validée');
    echo json_encode(['ok'=>true, 'role'=>$_SESSION['role'], 'csrf_token'=>csrf_token()]); exit;
}

/* ───── PASSKEY DIRECTE — options (sans email, comme MERIDIAN) ───────────
 * allowCredentials vide : le navigateur propose n'importe quelle clé
 * découvrable (resident key) enregistrée pour ce site. L'utilisateur est
 * identifié par le credential présenté, pas par son email. */
if ($route==='webauthn-direct-options' && $_SERVER['REQUEST_METHOD']==='POST'){
    $ip = getClientIp();
    if (getIpBanInfo($pdo, $ip)) {
        http_response_code(403);
        echo json_encode(['ok'=>false, 'err'=>'Adresse IP bloquée.']); exit;
    }
    require_once __DIR__ . '/src/security/webauthn.php';
    $wa = new WebAuthn(getWebAuthnRpId());
    echo json_encode(['ok'=>true, 'options'=>$wa->authOptions([])]); exit;
}

/* ───── PASSKEY DIRECTE — vérification + connexion ─────── */
if ($route==='webauthn-direct-verify' && $_SERVER['REQUEST_METHOD']==='POST'){
    $d  = json_decode(file_get_contents('php://input'), true);
    $ip = getClientIp();

    if (getIpBanInfo($pdo, $ip)) {
        http_response_code(403);
        echo json_encode(['ok'=>false, 'err'=>'Adresse IP bloquée.']); exit;
    }

    // 🔒 Rate-limit : 5 tentatives max par session (même politique que la 2FA)
    $_SESSION['passkey_direct_attempts'] = ($_SESSION['passkey_direct_attempts'] ?? 0) + 1;
    if ($_SESSION['passkey_direct_attempts'] > 5) {
        http_response_code(429);
        echo json_encode(['ok'=>false, 'err'=>'Trop de tentatives. Rechargez la page.']); exit;
    }

    $credIdB64 = $d['credential_id'] ?? '';
    require_once __DIR__ . '/src/security/webauthn.php';

    // Identifier l'utilisateur par le credential présenté
    $passkey = null;
    try {
        $stPk = $pdo->prepare('SELECT id, user_id, public_key, sign_count FROM user_passkeys WHERE credential_id = ? LIMIT 1');
        $stPk->execute([$credIdB64]);
        $passkey = $stPk->fetch();
    } catch (\Throwable $e) {}

    if (!$passkey) {
        logLoginAttempt($pdo, null, '', false, 'Clé d\'accès directe inconnue');
        autoBanIpIfNeeded($pdo, $ip);
        http_response_code(401);
        echo json_encode(['ok'=>false, 'err'=>'Clé d\'accès inconnue.']); exit;
    }

    $stU = $pdo->prepare('SELECT id, email, role, is_active FROM users WHERE id = ? LIMIT 1');
    $stU->execute([$passkey['user_id']]);
    $u = $stU->fetch();

    if (!$u || !$u['is_active']) {
        logLoginAttempt($pdo, $u['id'] ?? null, $u['email'] ?? '', false, 'Clé d\'accès directe — compte indisponible');
        http_response_code(403);
        echo json_encode(['ok'=>false, 'err'=>'Compte désactivé. Contactez un administrateur.']); exit;
    }

    try {
        $wa = new WebAuthn(getWebAuthnRpId());
        $newCount = $wa->verifyAuthentication($d, $passkey['public_key'], (int)$passkey['sign_count']);
        $pdo->prepare('UPDATE user_passkeys SET sign_count = ?, last_used = NOW() WHERE id = ?')
            ->execute([$newCount, $passkey['id']]);
    } catch (\Throwable $e) {
        logLoginAttempt($pdo, $u['id'], $u['email'], false, 'Clé d\'accès directe — signature invalide');
        autoBanIpIfNeeded($pdo, $ip);
        http_response_code(401);
        echo json_encode(['ok'=>false, 'err'=>'Authentification par clé d\'accès échouée.']); exit;
    }

    // Signature valide → session authentifiée (la clé d'accès vaut connexion
    // complète, comme dans le flux existant par email : possession + biométrie)
    unset($_SESSION['passkey_direct_attempts'],
          $_SESSION['pending_2fa_uid'], $_SESSION['pending_2fa_role'],
          $_SESSION['pending_2fa_email'], $_SESSION['pending_2fa_methods'],
          $_SESSION['wa_auth_challenge']);
    session_regenerate_id(true);
    $_SESSION['uid']   = $u['id'];
    $_SESSION['role']  = $u['role'];
    $_SESSION['email'] = $u['email'];

    logLoginAttempt($pdo, $u['id'], $u['email'], true, 'Clé d\'accès directe validée');
    echo json_encode(['ok'=>true, 'role'=>$u['role'], 'csrf_token'=>csrf_token()]); exit;
}

/* ───── FORGOT PASSWORD (public) ────────────── */
if ($route==='forgot-password' && $_SERVER['REQUEST_METHOD']==='POST'){
    $d = json_decode(file_get_contents('php://input'), true);
    $email = trim($d['email'] ?? '');

    if (!$email) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'err' => 'Email requis']);
        exit;
    }

    // ── Rate-limit : 3 demandes max par heure par IP ──────────────────────────
    $ip = getClientIp();
    // 🔒 [SEC-16] SHA-256 au lieu de MD5 (CWE-916)
    $rlKey = substr(hash('sha256', 'fwdpwd_' . $ip), 0, 32);
    $rlFile = sys_get_temp_dir() . '/fer_' . $rlKey . '.json';
    $rlWindow = 3600; $rlMax = 3;
    $rlTimes = [];
    if (@file_exists($rlFile)) {
        $rlTimes = json_decode(@file_get_contents($rlFile), true) ?: [];
    }
    $now = time();
    $rlTimes = array_values(array_filter($rlTimes, function($t) use ($now, $rlWindow) { return $t > $now - $rlWindow; }));
    if (count($rlTimes) >= $rlMax) {
        // Réponse générique pour ne pas révéler le throttle
        echo json_encode(['ok' => true, 'message' => 'Si un compte existe avec cette adresse, un email de réinitialisation a été envoyé.']);
        exit;
    }
    $rlTimes[] = $now;
    @file_put_contents($rlFile, json_encode($rlTimes)); // @ : /tmp peut ne pas être accessible
    // ─────────────────────────────────────────────────────────────────────────

    $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $token   = bin2hex(random_bytes(32));

        // 🔒 [SEC-RESET] On stocke le HACHÉ du token (SHA-256) ; le token brut ne vit que
        // dans le lien e-mail. Une fuite de la table users ne révèle donc aucun token utilisable.
        $pdo->prepare('UPDATE users SET reset_token = ?, reset_token_expires = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ?')
            ->execute([hash('sha256', $token), $user['id']]);

        // Envoyer le mail si Gmail est configuré
        try {
            require_once __DIR__ . '/src/mail/googleMail.php';
            if (isMailConfigured()) {
                // 🔒 [SEC-01] getAppBaseUrl() au lieu de HTTP_HOST brut (CWE-644)
                $resetUrl = getAppBaseUrl()
                          . dirname(dirname($_SERVER['SCRIPT_NAME']))
                          . '/reset-password.php?token=' . $token;

                sendMail(
                    $user['email'],
                    'Réinitialisation de votre mot de passe – Forbach en Rose',
                    'Mot de passe oublié ?',
                    '<p>Vous avez demandé la réinitialisation de votre mot de passe.</p>'
                      . '<p>Cliquez sur le lien ci-dessous pour définir un nouveau mot de passe :</p>'
                      . '<p><a href="' . htmlspecialchars($resetUrl) . '">' . htmlspecialchars($resetUrl) . '</a></p>'
                      . '<p><em>Ce lien expire dans 30 minutes.</em></p>',
                    null, null, 'info', null, 'password_reset'
                );
            }
        } catch (Exception $e) {
            error_log('Forgot password mail error: ' . $e->getMessage());
        }
    }

    // Toujours retourner succès (ne pas révéler si email existe)
    echo json_encode(['ok' => true, 'message' => 'Si un compte existe avec cette adresse, un email de réinitialisation a été envoyé.']);
    exit;
}

/* ───── CHANGE PASSWORD (authentifié) ────────── */
if ($route==='change-password' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (!isset($_SESSION['uid'])) {
        http_response_code(401);
        echo json_encode(['ok' => false]); exit;
    }
    // 🔒 [SEC-PWD] Cette route (sans ancien mot de passe) est réservée au changement
    // FORCÉ à la première connexion. On revérifie must_change_password=1 en base : une
    // session normale ne peut donc pas redéfinir le mot de passe sans l'ancien (le
    // changement volontaire passe par profile-change-password, qui exige l'ancien).
    try {
        $mustChange = (int) $pdo->query('SELECT must_change_password FROM users WHERE id = ' . (int)$_SESSION['uid'])->fetchColumn();
    } catch (\Throwable $e) { $mustChange = 0; }
    if ($mustChange !== 1) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Opération non autorisée. Utilisez « Changer mon mot de passe » (ancien mot de passe requis).']); exit;
    }
    $d = json_decode(file_get_contents('php://input'), true);
    $password = $d['password'] ?? '';

    $errors = validatePasswordPolicy($password);
    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'errors' => $errors]); exit;
    }

    $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $_SESSION['uid']]);
    echo json_encode(['ok' => true]); exit;
}

/* ───── PROFILE: CHANGE PASSWORD (authentifié) ── */
if ($route==='profile-change-password' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (!isset($_SESSION['uid'])) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }
    $d = json_decode(file_get_contents('php://input'), true);
    $old     = $d['old_password']     ?? '';
    $new     = $d['new_password']     ?? '';
    $confirm = $d['confirm_password'] ?? '';

    $row = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $row->execute([$_SESSION['uid']]); $row = $row->fetch();

    if (!$row || !password_verify($old, $row['password_hash'])) {
        http_response_code(401);
        echo json_encode(['ok'=>false, 'err'=>'Ancien mot de passe incorrect.']); exit;
    }
    if ($new !== $confirm) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'err'=>'Les mots de passe ne correspondent pas.']); exit;
    }
    if (password_verify($new, $row['password_hash'])) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'err'=>'Le nouveau mot de passe doit être différent de l\'ancien.']); exit;
    }
    $errors = validatePasswordPolicy($new);
    if (!empty($errors)) { http_response_code(400); echo json_encode(['ok'=>false, 'errors'=>$errors]); exit; }

    $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['uid']]);
    echo json_encode(['ok'=>true]); exit;
}

/* ───── PROFILE: INFOS (authentifié) ─────────── */
if ($route==='profile-info' && $_SERVER['REQUEST_METHOD']==='GET'){
    if (!isset($_SESSION['uid'])) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }
    $row = $pdo->prepare('SELECT totp_enabled, default_2fa_method FROM users WHERE id = ?');
    $row->execute([$_SESSION['uid']]); $row = $row->fetch();

    $passkeys = [];
    try {
        $stPk = $pdo->prepare('SELECT id, name, created_at, last_used FROM user_passkeys WHERE user_id = ? ORDER BY created_at ASC');
        $stPk->execute([$_SESSION['uid']]);
        $passkeys = $stPk->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}

    echo json_encode([
        'ok'               => true,
        'totp_enabled'     => !empty($row['totp_enabled']),
        'default_2fa_method' => $row['default_2fa_method'] ?? 'email',
        'mail_available'   => isMailConfigured(),
        'passkeys'         => $passkeys,
    ]); exit;
}

/* ───── PRÉFÉRENCES D'INTERFACE (par utilisateur connecté) ──────────
 * Stocke un objet JSON libre dans users.ui_prefs (ex. ordre des colonnes
 * du tableau dashboard sous la clé `dashboard_col_order`).
 *  - GET  : renvoie l'objet de préférences (objet vide si rien / colonne absente).
 *  - POST : fusionne les clés reçues dans l'objet existant, puis enregistre.
 */
if ($route==='ui-prefs'){
    if (!isset($_SESSION['uid'])) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }

    // Lecture des prefs actuelles (tolère l'absence de colonne avant migration).
    $current = [];
    try {
        $st = $pdo->prepare('SELECT ui_prefs FROM users WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        $raw = $st->fetchColumn();
        if ($raw) { $decoded = json_decode($raw, true); if (is_array($decoded)) $current = $decoded; }
    } catch (\Throwable $e) { /* colonne absente → prefs vides */ }

    if ($_SERVER['REQUEST_METHOD']==='GET'){
        echo json_encode($current ?: new stdClass()); exit;
    }

    if ($_SERVER['REQUEST_METHOD']==='POST'){
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in)) { http_response_code(400); echo json_encode(['ok'=>false, 'err'=>'JSON invalide']); exit; }
        // Fusion : les clés reçues écrasent/complètent l'existant.
        $merged = array_merge($current, $in);
        try {
            $pdo->prepare('UPDATE users SET ui_prefs = ? WHERE id = ?')
                ->execute([json_encode($merged), $_SESSION['uid']]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok'=>false, 'err'=>'Préférences non enregistrées (exécutez update.php).']);
            exit;
        }
        echo json_encode(['ok'=>true]); exit;
    }

    http_response_code(405); echo json_encode(['ok'=>false]); exit;
}

/* ───── PROFILE: SETUP TOTP ────────────────────── */
if ($route==='profile-setup-totp' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (!isset($_SESSION['uid'])) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }
    require_once __DIR__ . '/src/security/totp.php';
    $secret = TOTP::generateSecret();
    $pdo->prepare('UPDATE users SET totp_pending_secret = ? WHERE id = ?')
        ->execute([$secret, $_SESSION['uid']]);
    $qrUri = TOTP::getQRUri($secret, $_SESSION['email'] ?? '');
    echo json_encode(['ok'=>true, 'secret'=>$secret, 'qr_uri'=>$qrUri]); exit;
}

/* ───── PROFILE: VERIFY TOTP SETUP ─────────────── */
if ($route==='profile-verify-totp' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (!isset($_SESSION['uid'])) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }
    $d = json_decode(file_get_contents('php://input'), true);
    $code = trim($d['code'] ?? '');
    require_once __DIR__ . '/src/security/totp.php';
    $row = $pdo->prepare('SELECT totp_pending_secret FROM users WHERE id = ?');
    $row->execute([$_SESSION['uid']]); $row = $row->fetch();
    if (!$row || empty($row['totp_pending_secret']) || !TOTP::verify($row['totp_pending_secret'], $code)) {
        http_response_code(401);
        echo json_encode(['ok'=>false, 'err'=>'Code invalide. Assurez-vous que votre application est bien synchronisée.']); exit;
    }
    $pdo->prepare('UPDATE users SET totp_secret = totp_pending_secret, totp_pending_secret = NULL, totp_enabled = 1 WHERE id = ?')
        ->execute([$_SESSION['uid']]);
    echo json_encode(['ok'=>true]); exit;
}

/* ───── PROFILE: DISABLE TOTP ───────────────────── */
if ($route==='profile-disable-totp' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (!isset($_SESSION['uid'])) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }
    $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_pending_secret = NULL, totp_enabled = 0 WHERE id = ?')
        ->execute([$_SESSION['uid']]);
    // Réinitialiser default si c'était totp
    $pdo->prepare("UPDATE users SET default_2fa_method = 'email' WHERE id = ? AND default_2fa_method = 'totp'")
        ->execute([$_SESSION['uid']]);
    echo json_encode(['ok'=>true]); exit;
}

/* ───── PROFILE: SET DEFAULT 2FA ────────────────── */
if ($route==='profile-set-default-2fa' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (!isset($_SESSION['uid'])) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }
    $d      = json_decode(file_get_contents('php://input'), true);
    $method = $d['method'] ?? '';
    $allowed = ['email','totp','passkey'];
    if (!in_array($method, $allowed, true)) { http_response_code(400); echo json_encode(['ok'=>false, 'err'=>'Méthode invalide.']); exit; }
    $pdo->prepare('UPDATE users SET default_2fa_method = ? WHERE id = ?')
        ->execute([$method, $_SESSION['uid']]);
    echo json_encode(['ok'=>true]); exit;
}

/* ───── WEBAUTHN: REGISTER OPTIONS ──────────────── */
if ($route==='webauthn-register-options' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (!isset($_SESSION['uid'])) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }
    require_once __DIR__ . '/src/security/webauthn.php';
    $existingIds = [];
    try {
        $stPk = $pdo->prepare('SELECT credential_id FROM user_passkeys WHERE user_id = ?');
        $stPk->execute([$_SESSION['uid']]);
        $existingIds = $stPk->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {}
    $wa   = new WebAuthn(getWebAuthnRpId());
    $opts = $wa->registrationOptions((int)$_SESSION['uid'], $_SESSION['email'] ?? '', $existingIds);
    echo json_encode(['ok'=>true, 'options'=>$opts]); exit;
}

/* ───── WEBAUTHN: REGISTER VERIFY ───────────────── */
if ($route==='webauthn-register-verify' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (!isset($_SESSION['uid'])) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }
    $d    = json_decode(file_get_contents('php://input'), true);
    $name = mb_substr(trim($d['name'] ?? 'Ma clé d\'accès'), 0, 100);
    require_once __DIR__ . '/src/security/webauthn.php';
    try {
        $wa   = new WebAuthn(getWebAuthnRpId());
        $cred = $wa->verifyRegistration($d['credential'] ?? []);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'err'=>'Enregistrement échoué : ' . $e->getMessage()]); exit;
    }
    try {
        $pdo->prepare('INSERT INTO user_passkeys (user_id, credential_id, public_key, sign_count, name) VALUES (?, ?, ?, ?, ?)')
            ->execute([$_SESSION['uid'], $cred['credential_id'], $cred['public_key'], $cred['sign_count'], $name]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok'=>false, 'err'=>'Erreur sauvegarde clé.']); exit;
    }
    echo json_encode(['ok'=>true]); exit;
}

/* ───── WEBAUTHN: DELETE PASSKEY ────────────────── */
if ($route==='webauthn-delete-passkey' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (!isset($_SESSION['uid'])) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }
    $d  = json_decode(file_get_contents('php://input'), true);
    $id = (int)($d['id'] ?? 0);
    try {
        $pdo->prepare('DELETE FROM user_passkeys WHERE id = ? AND user_id = ?')->execute([$id, $_SESSION['uid']]);
        // Si plus aucune passkey et default = passkey → reset
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM user_passkeys WHERE user_id = ?');
        $cnt->execute([$_SESSION['uid']]); $cnt = (int)$cnt->fetchColumn();
        if ($cnt === 0) {
            $pdo->prepare("UPDATE users SET default_2fa_method = 'email' WHERE id = ? AND default_2fa_method = 'passkey'")
                ->execute([$_SESSION['uid']]);
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok'=>false, 'err'=>'Erreur suppression.']); exit;
    }
    echo json_encode(['ok'=>true]); exit;
}

/* ───── RESET PASSWORD CONFIRM (token) ───────── */
if ($route==='reset-password-confirm' && $_SERVER['REQUEST_METHOD']==='POST'){
    $d = json_decode(file_get_contents('php://input'), true);
    $token    = $d['token'] ?? '';
    $password = $d['password'] ?? '';

    // 🔒 [SEC-RESET] Comparaison sur le haché (le token stocké est un SHA-256).
    $stmt = $pdo->prepare('SELECT id, is_active, locked_at FROM users WHERE reset_token = ? AND reset_token_expires > NOW()');
    $stmt->execute([hash('sha256', (string)$token)]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'err' => 'Token invalide ou expiré']); exit;
    }

    $errors = validatePasswordPolicy($password);
    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'errors' => $errors]); exit;
    }

    // Réactivation du compte après changement de mot de passe :
    //  - verrouillage automatique (3 échecs, locked_at non nul) → on réactive ;
    //  - compte déjà actif → reste actif ;
    //  - désactivé manuellement par un admin (is_active=0 ET locked_at NULL) → reste bloqué.
    // ⚠️ Calculé en PHP : dans un UPDATE MySQL, "is_active = IF(locked_at...)" lirait
    //    locked_at APRÈS son passage à NULL dans la même requête (faux négatif).
    $newIsActive = (!empty($user['locked_at']) || (int)$user['is_active'] === 1) ? 1 : 0;

    $pdo->prepare('UPDATE users SET
        password_hash       = ?,
        must_change_password = 0,
        reset_token         = NULL,
        reset_token_expires = NULL,
        failed_attempts     = 0,
        locked_at           = NULL,
        is_active           = ?
    WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $newIsActive, $user['id']]);
    echo json_encode(['ok' => true]); exit;
}

/* ───── ROLE PERMISSIONS (admin) — défauts par rôle modifiables depuis le tableau ───── */
if ($route === 'role-permissions') {
    requireRole(['admin']);

    // Détection de la colonne role_permissions
    $hasCol = false;
    try { $pdo->query('SELECT role_permissions FROM setting LIMIT 0'); $hasCol = true; }
    catch (PDOException $e) {}

    if (!$hasCol) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'err' => "La colonne 'role_permissions' n'existe pas. Lancez update.php."]);
        exit;
    }

    $catalog = permCatalog();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $row = $pdo->query("SELECT role_permissions FROM setting WHERE id = 1 LIMIT 1")->fetchColumn();
        $data = $row ? (json_decode($row, true) ?: []) : [];
        foreach (['user','viewer','saisie'] as $r) {
            if (empty($data[$r])) $data[$r] = hardcodedDefaultPermissions($r);
        }
        echo json_encode(['ok' => true, 'permissions' => $data]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !isset($input['role'], $input['type'], $input['key'], $input['value'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'Paramètres manquants.']);
            exit;
        }

        $role = $input['role'];
        if (!in_array($role, ['user','viewer','saisie'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'Seuls les rôles user, viewer et saisie sont modifiables (admin est toujours total).']);
            exit;
        }

        $type  = $input['type']; // 'pages' ou 'actions'
        $key   = $input['key'];
        $value = (bool)$input['value'];

        if (!in_array($type, ['pages','actions'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'Type invalide.']);
            exit;
        }

        // Validation de la clé selon le type (catalogue centralisé)
        $allowed = $catalog[$type] ?? [];
        if (!in_array($key, $allowed, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'Clé inconnue : ' . $key]);
            exit;
        }

        // Charger la conf actuelle
        $row = $pdo->query("SELECT role_permissions FROM setting WHERE id = 1 LIMIT 1")->fetchColumn();
        $data = $row ? (json_decode($row, true) ?: []) : [];

        if (empty($data[$role])) {
            $data[$role] = hardcodedDefaultPermissions($role);
        }
        if (!isset($data[$role]['pages']))   $data[$role]['pages']   = [];
        if (!isset($data[$role]['actions'])) $data[$role]['actions'] = [];

        // Toggle la permission
        $list = $data[$role][$type];
        if ($value && !in_array($key, $list, true)) {
            $list[] = $key;
        } elseif (!$value) {
            $list = array_values(array_filter($list, fn($v) => $v !== $key));
        }
        $data[$role][$type] = array_values(array_unique($list));

        $stmt = $pdo->prepare("UPDATE setting SET role_permissions = :rp WHERE id = 1");
        $stmt->execute(['rp' => json_encode($data)]);

        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'err' => 'Méthode non autorisée']);
    exit;
}

/* ───── USERS (admin) ────────────────────────── */
if ($route === 'users') {
    requireRole(['admin']);

    // 🔑 POST : reset mot de passe d'un compte
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset-password') {
        $id = $_POST['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'id manquant']);
            exit;
        }

        // Récupérer l'email + l'état du compte
        $userStmt = $pdo->prepare('SELECT email, is_active, locked_at FROM users WHERE id = ?');
        $userStmt->execute([$id]);
        $userInfo = $userStmt->fetch();
        if (!$userInfo || empty($userInfo['email'])) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'err' => 'Utilisateur introuvable']);
            exit;
        }
        $userEmail = $userInfo['email'];

        // Génère un lien de réinitialisation valable 30 minutes.
        // ⚠️ On ne touche PAS à is_active / locked_at ici : la réactivation
        // éventuelle se fait quand l'utilisateur change réellement son mot de
        // passe (route reset-password-confirm), et jamais pour un compte
        // désactivé manuellement par un admin.
        $token = bin2hex(random_bytes(32));
        // 🔒 [SEC-RESET] Haché au repos (SHA-256) ; le token brut ne circule que dans le lien e-mail.
        $pdo->prepare('UPDATE users SET reset_token = ?, reset_token_expires = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ?')
            ->execute([hash('sha256', $token), $id]);

        // Tente d'envoyer le lien de réinitialisation
        $emailSent = false;
        try {
            require_once __DIR__ . '/src/mail/googleMail.php';
            if (isMailConfigured()) {
                // 🔒 [SEC-01] getAppBaseUrl() au lieu de HTTP_HOST brut (CWE-644)
                $resetUrl = getAppBaseUrl()
                          . dirname(dirname($_SERVER['SCRIPT_NAME']))
                          . '/reset-password.php?token=' . $token;

                $emailSent = sendMail(
                    $userEmail,
                    'Réinitialisation de votre mot de passe – Forbach en Rose',
                    'Mot de passe oublié ?',
                    '<p>La réinitialisation de votre mot de passe a été demandée.</p>'
                      . '<p>Cliquez sur le lien ci-dessous pour définir un nouveau mot de passe :</p>'
                      . '<p><a href="' . htmlspecialchars($resetUrl) . '">' . htmlspecialchars($resetUrl) . '</a></p>'
                      . '<p><em>Ce lien expire dans 30 minutes.</em></p>',
                    null, null, 'info', null, 'password_reset'
                );
            }
        } catch (Exception $e) {
            error_log('Reset password mail error: ' . $e->getMessage());
        }

        // 🔒 Repli : si le mail n'a pas pu partir, on génère un mot de passe
        // temporaire affiché à l'admin et on annule le lien token.
        // Réactivation : seulement si le compte était verrouillé auto (locked_at
        // non nul) ou déjà actif. Un compte désactivé par l'admin reste bloqué.
        if (!$emailSent) {
            $tempPassword = generateTemporaryPassword();
            $newIsActive  = (!empty($userInfo['locked_at']) || (int)$userInfo['is_active'] === 1) ? 1 : 0;
            $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1, reset_token = NULL, reset_token_expires = NULL, failed_attempts = 0, locked_at = NULL, is_active = ? WHERE id = ?')
                ->execute([password_hash($tempPassword, PASSWORD_DEFAULT), $newIsActive, $id]);
            echo json_encode(['ok' => true, 'email_sent' => false, 'temp_password' => $tempPassword]);
            exit;
        }

        echo json_encode(['ok' => true, 'email_sent' => true]);
        exit;
    }

    // 🔐 POST : supprimer les authentifications fortes (TOTP + clés d'accès)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear-2fa') {
        $id = $_POST['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'id manquant']);
            exit;
        }

        // Vérifier que l'utilisateur existe
        $chk = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'err' => 'Utilisateur introuvable']);
            exit;
        }

        $totpCleared    = 0;
        $passkeysCleared = 0;

        // Désactiver l'application d'authentification (TOTP)
        try {
            $stmt = $pdo->prepare(
                'UPDATE users
                    SET totp_enabled = 0,
                        totp_secret = NULL,
                        totp_pending_secret = NULL,
                        default_2fa_method = \'email\'
                  WHERE id = ?'
            );
            $stmt->execute([$id]);
            $totpCleared = $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('clear-2fa totp error user id=' . $id . ' : ' . $e->getMessage());
        }

        // Supprimer les clés d'accès (passkeys)
        try {
            $stmt = $pdo->prepare('DELETE FROM user_passkeys WHERE user_id = ?');
            $stmt->execute([$id]);
            $passkeysCleared = $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('clear-2fa passkeys error user id=' . $id . ' : ' . $e->getMessage());
        }

        echo json_encode([
            'ok' => true,
            'passkeys_removed' => $passkeysCleared,
        ]);
        exit;
    }

    // 🔄 POST : activer/désactiver un compte
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle-active') {
        $id = $_POST['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'id manquant']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();

        if ($current === false) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'err' => 'Utilisateur introuvable']);
            exit;
        }

        $newState = $current ? 0 : 1;
        if ($newState === 1) {
            // Réactivation : remettre le compteur de tentatives à zéro
            $pdo->prepare('UPDATE users SET is_active = 1, failed_attempts = 0, locked_at = NULL WHERE id = ?')->execute([$id]);
        } else {
            $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);
        }
        echo json_encode(['ok' => true, 'is_active' => $newState]);
        exit;
    }

    // 🔁 POST : suppression d'un compte
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
        $id = $_POST['id'] ?? null;
        $force = $_POST['force'] ?? false;

        if (!$id) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'id manquant']);
            exit;
        }

        // Vérifier si des inscriptions sont liées à ce compte
        $count = $pdo->prepare('SELECT COUNT(*) FROM registrations WHERE created_by = ?');
        $count->execute([$id]);
        $nb = $count->fetchColumn();

        if ($nb > 0 && !$force) {
            echo json_encode([
                'ok' => false,
                'warning' => "⚠️ Ce compte est lié à $nb inscription(s). Supprimer ce compte entraînera aussi la suppression des inscriptions associées.",
                'requiresForce' => true
            ]);
            exit;
        }

        try {
            $pdo->beginTransaction();
            if ($nb > 0) {
                $pdo->prepare('DELETE FROM registrations WHERE created_by = ?')->execute([$id]);
            }
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $pdo->commit();
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Erreur suppression user id=' . $id . ' : ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'err' => 'Erreur interne du serveur.']);
        }
        exit;
    }


    // ✅ POST : création d'un compte (mot de passe temporaire auto-généré)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);

        // Validation du rôle
        $allowedRoles = ['admin', 'user', 'viewer', 'saisie'];
        if (!in_array($d['role'] ?? '', $allowedRoles, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'Rôle invalide.']);
            exit;
        }

        $tempPassword = generateTemporaryPassword();

        $stmt = $pdo->prepare(
            'INSERT INTO users(email,password_hash,role,organisation,must_change_password)
             VALUES(?,?,?,?,1)'
        );
        $stmt->execute([
            $d['email'],
            password_hash($tempPassword, PASSWORD_DEFAULT),
            $d['role'],
            $d['organisation'] ?: null
        ]);

        // Tenter l'envoi du mail avec le mot de passe temporaire
        $emailSent = false;
        try {
            require_once __DIR__ . '/src/mail/googleMail.php';
            if (isMailConfigured()) {
                $emailSent = sendMail(
                    $d['email'],
                    'Votre compte Forbach en Rose',
                    'Bienvenue sur Forbach en Rose',
                    '<p>Votre compte a été créé.</p>'
                      . '<p><strong>Email :</strong> ' . htmlspecialchars($d['email']) . '</p>'
                      . '<p><strong>Mot de passe temporaire :</strong> ' . htmlspecialchars($tempPassword) . '</p>'
                      . '<p>Vous devrez changer votre mot de passe lors de votre première connexion.</p>',
                    null, null, 'info', null, 'new_user'
                );
            }
        } catch (Exception $e) {
            error_log('Create user mail error: ' . $e->getMessage());
        }

        // 🔒 [FIX-PWD-EXPOSE] Ne retourner le MDP temporaire que si l'email n'a pas été envoyé (CWE-319)
        $response = ['ok' => true, 'email_sent' => $emailSent];
        if (!$emailSent) {
            $response['temp_password'] = $tempPassword;
        }
        echo json_encode($response);
        exit;
    }

    // GET : liste
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Détection de la colonne permissions (peut ne pas exister si update.php non exécuté)
        $hasPermsCol = false;
        try { $pdo->query('SELECT permissions FROM users LIMIT 0'); $hasPermsCol = true; }
        catch (PDOException $e) {}

        // Détection de la colonne totp_enabled (MFA application d'authentification)
        $hasTotpCol = false;
        try { $pdo->query('SELECT totp_enabled FROM users LIMIT 0'); $hasTotpCol = true; }
        catch (PDOException $e) {}

        $cols = 'id,email,role,organisation,is_active,created_at'
              . ($hasPermsCol ? ',permissions' : '')
              . ($hasTotpCol ? ',totp_enabled' : '');
        $rows = $pdo->query("SELECT $cols FROM users")->fetchAll();

        // Comptage des clés d'accès (passkeys) par utilisateur — table optionnelle
        $pkCounts = [];
        try {
            foreach ($pdo->query('SELECT user_id, COUNT(*) AS c FROM user_passkeys GROUP BY user_id') as $r) {
                $pkCounts[$r['user_id']] = (int)$r['c'];
            }
        } catch (PDOException $e) {}

        foreach ($rows as &$row) {
            $row['totp_enabled']  = (int)($row['totp_enabled'] ?? 0);
            $row['passkey_count'] = $pkCounts[$row['id']] ?? 0;
        }
        unset($row);

        echo json_encode($rows);
        exit;
    }

    // PUT : modification
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        parse_str(file_get_contents('php://input'), $d);
        if (!isset($d['id']) || !$d['id']) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'id manquant']);
            exit;
        }

        $allowed = ['email', 'role', 'organisation'];
        $fields = [];
        $params = [];

        foreach ($allowed as $key) {
            if (isset($d[$key])) {
                // 🔒 [FIX-11] Validation du rôle dans PUT /users (CWE-20)
                if ($key === 'role' && !in_array($d[$key], ['admin', 'user', 'viewer', 'saisie'], true)) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'err' => 'Rôle invalide.']);
                    exit;
                }
                $fields[] = "$key = :$key";
                $params[$key] = $d[$key];
            }
        }

        // Gestion des permissions personnalisées (JSON) — uniquement si la colonne existe
        $hasPermsCol = false;
        try { $pdo->query('SELECT permissions FROM users LIMIT 0'); $hasPermsCol = true; }
        catch (PDOException $e) {}

        if (isset($d['permissions']) && $hasPermsCol) {
            $permsRaw = $d['permissions'];
            if ($permsRaw === '' || $permsRaw === 'null') {
                // Réinitialisation aux permissions par défaut du rôle
                $fields[] = 'permissions = NULL';
            } else {
                $decoded = json_decode($permsRaw, true);
                if (!is_array($decoded) || !isset($decoded['pages']) || !isset($decoded['actions'])
                    || !is_array($decoded['pages']) || !is_array($decoded['actions'])) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'err' => 'Format de permissions invalide.']);
                    exit;
                }
                // Whitelist depuis le catalogue centralisé (config.php) — inclut content.* en backward compat
                $catalog = permCatalog();
                $allowedPages   = $catalog['pages'];
                $allowedActions = array_merge(
                    $catalog['actions'],
                    // backward compat : on accepte de stocker les anciens content.* si jamais fournis
                    ['content.create','content.edit','content.delete']
                );
                $cleanPages   = array_values(array_intersect($decoded['pages'], $allowedPages));
                $cleanActions = array_values(array_intersect($decoded['actions'], $allowedActions));
                $fields[] = 'permissions = :permissions';
                $params['permissions'] = json_encode([
                    'pages'   => $cleanPages,
                    'actions' => $cleanActions,
                ]);
            }
        }

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'aucune donnée à modifier']);
            exit;
        }

        $params['id'] = $d['id'];
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
        $pdo->prepare($sql)->execute($params);
        echo json_encode(['ok' => true]);
        exit;
    }
}

/* Portée « saisie » : ids des utilisateurs de la MÊME organisation que l'utilisateur
 * courant (lui inclus). Sert à filtrer le tableau et les actions (un saisie voit/gère
 * toutes les inscriptions de son organisation). Repli : son seul id s'il n'a pas
 * d'organisation renseignée (évite toute fuite vers d'autres comptes sans orga). */
function saisieAllowedCreatorIds(PDO $pdo): array {
    $uid = (int) currentUserId();
    $s = $pdo->prepare('SELECT organisation FROM users WHERE id = ?');
    $s->execute([$uid]);
    $org = $s->fetchColumn();
    if ($org === false || $org === null || $org === '') return [$uid];
    $st = $pdo->prepare('SELECT id FROM users WHERE organisation = ?');
    $st->execute([$org]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array($uid, $ids, true)) $ids[] = $uid;
    return $ids ?: [$uid];
}

/* ───── REGISTRATIONS ────────────────────────── */
if ($route==='registrations'){
    /* GET : accessible si l'utilisateur a accès au dashboard */
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if (!canAccessPage('dashboard')) {
            http_response_code(403);
            echo json_encode(['error' => 'Accès refusé']);
            exit;
        }
        // Le rôle "saisie" ne voit que les inscriptions de SON organisation
        if (currentRole() === 'saisie') {
            $allowed = saisieAllowedCreatorIds($pdo);
            $in = implode(',', array_fill(0, count($allowed), '?'));
            $st = $pdo->prepare("SELECT * FROM registrations WHERE created_by IN ($in) ORDER BY CAST(REPLACE(REPLACE(inscription_no, 'S', ''), 'E', '') AS UNSIGNED) DESC");
            $st->execute($allowed);
            $rows = $st->fetchAll();
        } else {
            $rows = $pdo->query("SELECT * FROM registrations ORDER BY CAST(REPLACE(REPLACE(inscription_no, 'S', ''), 'E', '') AS UNSIGNED) DESC")->fetchAll();
        }
        echo json_encode(decryptRows($rows)); exit;
    }

    /* POST : public (anonyme) OU utilisateur authentifié avec permission create_registration */
    if($_SERVER['REQUEST_METHOD']==='POST'){
      try {
        $d = json_decode(file_get_contents('php://input'), true);
        if (!$d) { $d = $_POST; } // fallback form-data

        // Si utilisateur authentifié, vérifier la permission
        if (currentUserId() && !canDoAction('dashboard.create_registration')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'err' => 'Action non autorisée']);
            exit;
        }

        // 🔒 [FIX-08] Rate limiting sur les inscriptions publiques non authentifiées (CWE-770)
        if (!currentUserId()) {
            $ip = getClientIp();
            // 🔒 [SEC-16] SHA-256 au lieu de MD5 (CWE-916)
            $rlKey  = substr(hash('sha256', 'reg_' . $ip), 0, 32);
            $rlFile = sys_get_temp_dir() . '/fer_' . $rlKey . '.json';
            $rlTimes = [];
            if (@file_exists($rlFile)) { $rlTimes = json_decode(@file_get_contents($rlFile), true) ?: []; }
            $now = time();
            $rlTimes = array_values(array_filter($rlTimes, fn($t) => $t > $now - 3600));
            if (count($rlTimes) >= 10) {
                http_response_code(429);
                echo json_encode(['ok' => false, 'err' => 'Trop de tentatives. Réessayez dans une heure.']);
                exit;
            }
            $rlTimes[] = $now;
            @file_put_contents($rlFile, json_encode($rlTimes));
        }

        // 🔒 [SEC-CAPTCHA] Vérification anti-robot obligatoire pour les inscriptions
        // publiques (non authentifiées). Sans ça, cette route contournait entièrement
        // le captcha du formulaire public (register.php) → spam de masse + injection
        // de données arbitraires (vecteur XSS stocké). Les créations authentifiées
        // (admin/staff via le dashboard) ne sont pas concernées.
        if (!currentUserId() && !verifyPublicCaptcha($d, $pdo)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'Vérification anti-robot échouée.']);
            exit;
        }

        // 🔒 [FIX-VALIDATION] Validation et assainissement des champs d'inscription (CWE-20)
        $allowedSexe = ['H', 'F', 'Autre'];
        $d['sexe']    = in_array($d['sexe'] ?? '', $allowedSexe, true) ? $d['sexe'] : 'Autre';
        $d['nom']     = mb_substr(trim($d['nom'] ?? ''), 0, 255);
        $d['prenom']  = mb_substr(trim($d['prenom'] ?? ''), 0, 255);
        $d['tel']     = mb_substr(trim($d['tel'] ?? ''), 0, 50);
        $d['ville']   = mb_substr(trim($d['ville'] ?? ''), 0, 255);
        $d['entreprise'] = mb_substr(trim($d['entreprise'] ?? ''), 0, 255);
        if (array_key_exists('commentaire', $d)) $d['commentaire'] = mb_substr((string) $d['commentaire'], 0, 2000);
        $d['paiement_mode'] = mb_substr(trim($d['paiement_mode'] ?? ''), 0, 50);
        $allowedTshirt = ['-', 'XS', 'S', 'M', 'L', 'XL', 'XXL'];
        $d['tshirt_size'] = in_array($d['tshirt_size'] ?? '', $allowedTshirt, true) ? $d['tshirt_size'] : '-';

        /* numéro d'inscription suivant — compteur atomique (CWE-362) */
        $counterExists = false;
        try {
            $pdo->query('SELECT next_no FROM inscription_counter LIMIT 0');
            $counterExists = true;
        } catch (PDOException $e) {}

        $pdo->beginTransaction();
        if ($counterExists) {
            // Atomique : incrémente et retourne la nouvelle valeur en une seule opération
            $pdo->exec('UPDATE inscription_counter SET next_no = LAST_INSERT_ID(next_no + 1) WHERE id = 1');
            $no = 'S' . (int)$pdo->lastInsertId();
        } else {
            // Fallback si la migration n'a pas encore été jouée
            $no = 'S' . ((int)($pdo->query("SELECT MAX(CAST(REPLACE(REPLACE(inscription_no, 'S', ''), 'E', '') AS UNSIGNED)) FROM registrations")->fetchColumn() ?: 0) + 1);
        }

        /* origine : orga de l'utilisateur connecté (si existe), sinon valeur front, sinon "en ligne"  */
        $myOrg = null;
        if (currentUserId()){
            $s=$pdo->prepare('SELECT organisation FROM users WHERE id=?');
            $s->execute([currentUserId()]);
            $myOrg=$s->fetchColumn() ?: null;
        }
        $origine = $myOrg ?: ($d['origine'] ?? 'en ligne');

        // Construction dynamique de l'INSERT basé sur la table forms
        require_once __DIR__ . '/src/content/form_fields.php';
        require_once __DIR__ . '/src/content/registrations_core.php'; // regcore_naissanceToAge()
        $fieldCols = getAllActiveFieldColumns($pdo);

        $cols = ['inscription_no'];
        $phs  = ['?'];
        $vals = [$no];

        foreach ($fieldCols as $col => $meta) {
            $raw = $d[$col] ?? '';
            // Champ « naissance » : on ne stocke QUE l'âge (année/date → âge).
            if ($col === 'naissance' && $raw !== '') {
                $age = regcore_naissanceToAge((string) $raw);
                $raw = ($age !== null) ? $age : '';
            }
            $cols[] = "`{$col}`";
            $phs[]  = '?';
            $vals[] = $meta['encrypted'] ? encrypt($raw !== '' ? $raw : '') : ($raw !== '' ? $raw : '');
        }

        // Calcul du montant dû : 0 pour gratuit/-12 ans, sinon le tarif d'inscription configuré.
        // Le client peut aussi forcer une valeur explicite via `montant_du` (utile pour les corrections).
        $paiement = $d['paiement_mode'] ?? '';
        if (array_key_exists('montant_du', $d) && is_numeric($d['montant_du'])) {
            $montantDu = (float) $d['montant_du'];
        } elseif (strcasecmp($paiement, 'gratuit') === 0) {
            $montantDu = 0.0;
        } else {
            $registrationFee = (float) ($pdo->query('SELECT registration_fee FROM setting WHERE id = 1 LIMIT 1')->fetchColumn() ?: 0);
            $montantDu = $registrationFee;
        }

        // Champs système
        $cols[] = 'origine';      $phs[] = '?'; $vals[] = $origine;
        $cols[] = 'paiement_mode';$phs[] = '?'; $vals[] = storedPaiementMode($d['paiement_mode'] ?? null);
        $cols[] = 'prestation';   $phs[] = '?'; $vals[] = prestationFromPaiement($d['paiement_mode'] ?? null);
        $cols[] = 'montant_du';   $phs[] = '?'; $vals[] = $montantDu;
        $cols[] = 'created_by';   $phs[] = '?'; $vals[] = currentUserId();

        /* Lot 1 — rattachement de l'inscription (colonnes ajoutées seulement si la
         * migration est passée) :
         *   edition_id      : sans lui, l'inscription n'appartient à aucune édition
         *                     et l'unicité (edition_id, inscription_no) est inopérante ;
         *   email_normalise : empreinte HMAC de l'adresse (fer_emailHash), clé de
         *                     rattachement au compte coureur — `email` étant chiffré,
         *                     elle ne peut pas être recalculée en SQL. */
        $editionIdIns = fer_activeEditionId($pdo);
        if ($editionIdIns !== null) {
            $cols[] = 'edition_id'; $phs[] = '?'; $vals[] = $editionIdIns;
        }
        if (fer_hasColumn($pdo, 'registrations', 'email_normalise')) {
            $cols[] = 'email_normalise'; $phs[] = '?'; $vals[] = fer_emailHash($d['email'] ?? null);
        }
        // Année de naissance déduite de la valeur BRUTE saisie (avant conversion en âge).
        require_once __DIR__ . '/src/content/registrations_core.php';
        foreach (regcore_naissanceColumns($pdo, $d['naissance'] ?? '', $editionIdIns) as $c => $v) {
            $cols[] = $c; $phs[] = '?'; $vals[] = $v;
        }

        // Date d'inscription (date_inscription) : antidatable UNIQUEMENT pour un admin
        // connecté (un inscrit public ne doit jamais pouvoir s'antidater). Vide → DEFAULT
        // du jour. NB : created_at (date d'ajout) reste auto via le DEFAULT de la colonne.
        $rawDateInsc = currentUserId() ? trim((string) ($d['date_inscription'] ?? '')) : '';
        if ($rawDateInsc !== '') {
            require_once __DIR__ . '/src/content/registrations_core.php';
            $cols[] = 'date_inscription'; $phs[] = '?'; $vals[] = regcore_convertExcelDate($rawDateInsc);
        }

        $colStr = implode(',', $cols);
        $phStr  = implode(',', $phs);
        $st = $pdo->prepare("INSERT INTO registrations ({$colStr}) VALUES ({$phStr})");
        $st->execute($vals);
        $pdo->commit();

        // Envoyer mail de confirmation si email renseigné
        // (Même logique que public/register.php : appel direct sans isMailConfigured(),
        //  les erreurs éventuelles sont loguées dans storage/logs/logs_*_mails.log par sendMail/sendMailSmtp.)
        $inscEmail = trim($d['email'] ?? '');
        if ($inscEmail !== '') {
            try {
                require_once __DIR__ . '/src/mail/googleMail.php';
                sendMail(
                    $inscEmail,
                    'Inscription enregistrée - Forbach en Rose',
                    null, null,
                    $d['nom'] ?? '', $d['prenom'] ?? '',
                    'inscription', $no
                );
            } catch (\Throwable $e) {
                error_log('[REGISTRATIONS][MAIL] Exception envoi inscription ' . $no . ' : ' . $e->getMessage());
                // Mail failure should not block inscription
            }
        }

        echo json_encode(['ok'=>true,'inscription_no'=>$no]); exit;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        error_log('[REGISTRATIONS] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        echo json_encode(['ok'=>false, 'error'=>'Une erreur est survenue, veuillez réessayer.']);
        exit;
      }
    }

    /* DELETE (permission dashboard.delete_registration) */
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        requireAction('dashboard.delete_registration');
        parse_str(file_get_contents('php://input'), $d);    // ← on lit ici, uniquement pour DELETE
        // Le rôle "saisie" ne peut supprimer que les inscriptions de son organisation
        if (currentRole() === 'saisie') {
            $own = $pdo->prepare('SELECT created_by FROM registrations WHERE id=?');
            $own->execute([$d['id']]);
            $createdBy = $own->fetchColumn();
            if (!in_array((int)$createdBy, saisieAllowedCreatorIds($pdo), true)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'err' => 'Vous ne pouvez supprimer que les inscriptions de votre organisation.']);
                exit;
            }
        }
        $pdo->prepare('DELETE FROM registrations WHERE id=?')->execute([$d['id']]);
        echo json_encode(['ok'=>true]); exit;
    }

    /* ---------- PUT (mise à jour) ---------- */
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        /* 1. Récupérer le corps de requête (JSON ou x-www-form-urlencoded) */
        $raw = file_get_contents('php://input');
        $ct  = $_SERVER['CONTENT_TYPE'] ?? '';

        if (stripos($ct, 'application/json') === 0) {
            $d = json_decode($raw, true) ?: [];
        } else {
            parse_str($raw, $d);                         // compatibilité ancienne version
        }

        /* Permission : edit_registration en général,
         *   mais si la requête met à jour UNIQUEMENT tshirt_size, on accepte aussi scan_qr
         *   (mode "Remise T-shirts" : bénévole avec QR scanner sans plein droit d'édition) */
        $putKeys = array_diff(array_keys($d), ['id']);
        $isOnlyTshirt = (count($putKeys) === 1 && in_array('tshirt_size', $putKeys, true));
        if ($isOnlyTshirt) {
            if (!canDoAction('dashboard.edit_registration') && !canDoAction('dashboard.scan_qr')) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'err' => 'Action non autorisée']);
                exit;
            }
        } else {
            requireAction('dashboard.edit_registration');
        }

        /* 2. Vérifier l'id */
        $d['id'] = isset($d['id']) ? (int)$d['id'] : 0;
        if (!$d['id']) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'id manquant']);
            exit;
        }

        /* 3. Le rôle "saisie" ne peut modifier que les inscriptions de son organisation */
        if (currentRole() === 'saisie') {
            $own = $pdo->prepare('SELECT created_by FROM registrations WHERE id=?');
            $own->execute([$d['id']]);
            $createdBy = $own->fetchColumn();
            if (!in_array((int)$createdBy, saisieAllowedCreatorIds($pdo), true)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'err' => 'Vous ne pouvez modifier que les inscriptions de votre organisation.']);
                exit;
            }
        }

        /* 4. Champs autorisés dynamiques depuis la table forms + champs système */
        require_once __DIR__ . '/src/content/form_fields.php';
        $fieldCols = getAllActiveFieldColumns($pdo);
        $systemCols = ['origine', 'paiement_mode', 'prestation', 'montant_du'];

        $params = ['id' => $d['id']];
        $setParts = [];
        $emailEnClair   = null; // lot 1 : email en clair réellement écrit, s'il l'est
        $naissanceBrute = null; // lot 1 : valeur de naissance saisie, avant conversion en âge

        if (array_key_exists('commentaire', $d) && $d['commentaire'] !== null) {
            $d['commentaire'] = mb_substr((string) $d['commentaire'], 0, 2000);
        }

        foreach ($fieldCols as $col => $meta) {
            if (!array_key_exists($col, $d)) continue;
            $raw = $d[$col];
            // Cohérence avec POST : on stocke une chaîne vide plutôt que null
            // (les colonnes NOT NULL acceptent '' mais pas null)
            if ($raw === null) $raw = '';
            // Naissance : on ne stocke QUE l'âge (année/date → âge) dans `naissance`.
            // Lot 1 : la valeur BRUTE est conservée pour en déduire l'année de
            // naissance, qui elle ne se périme pas.
            if ($col === 'naissance' && $raw !== '') {
                require_once __DIR__ . '/src/content/registrations_core.php';
                $naissanceBrute = (string) $raw;
                $age = regcore_naissanceToAge((string) $raw);
                $raw = ($age !== null) ? $age : '';
            }
            // Colonnes ENUM assainies (évite « Data truncated » sur valeur vide/invalide).
            if ($col === 'tshirt_size') { $raw = in_array($raw, ['-','XS','S','M','L','XL','XXL'], true) ? $raw : '-'; }
            if ($col === 'sexe')        { $raw = in_array($raw, ['H','F','Autre'], true) ? $raw : 'Autre'; }
            // Lot 1 : on retient la valeur EN CLAIR de l'email effectivement écrite,
            // pour recalculer l'empreinte de rattachement plus bas.
            if ($col === 'email') $emailEnClair = (string) $raw;
            $params[$col] = $meta['encrypted'] ? encrypt($raw !== '' ? $raw : '') : $raw;
            $setParts[] = "`{$col}` = :{$col}";
        }

        // Si le mode de paiement change mais que le montant n'est pas explicitement fourni,
        // on recalcule automatiquement (gratuit → 0, autre → tarif).
        if (array_key_exists('paiement_mode', $d) && !array_key_exists('montant_du', $d)) {
            if (strcasecmp((string) $d['paiement_mode'], 'gratuit') === 0) {
                $d['montant_du'] = 0;
            } else {
                $d['montant_du'] = (float) ($pdo->query('SELECT registration_fee FROM setting WHERE id = 1 LIMIT 1')->fetchColumn() ?: 0);
            }
        }

        // Si le mode de paiement change, on resynchronise la catégorie (prestation)
        // puis on normalise le mode stocké (enfant_tshirt → en ligne (CB)).
        if (array_key_exists('paiement_mode', $d)) {
            if (!array_key_exists('prestation', $d)) {
                $d['prestation'] = prestationFromPaiement((string) $d['paiement_mode']);
            }
            $d['paiement_mode'] = storedPaiementMode((string) $d['paiement_mode']);
        }

        foreach ($systemCols as $sc) {
            if (!array_key_exists($sc, $d)) continue;
            $params[$sc] = $sc === 'montant_du' ? (float) $d[$sc] : $d[$sc];
            $setParts[] = "`{$sc}` = :{$sc}";
        }

        // Date d'inscription (date_inscription) : corrigeable à l'édition (déjà gated
        // dashboard.edit_registration). Valeur vide ignorée. On ne met à jour QUE si le
        // JOUR change réellement → une édition qui ne touche pas la date préserve
        // l'horodatage d'origine (utile au classement QR). created_at (date d'ajout) n'est
        // jamais modifié ici.
        if (array_key_exists('date_inscription', $d) && trim((string) $d['date_inscription']) !== '') {
            require_once __DIR__ . '/src/content/registrations_core.php';
            $newDateInsc = regcore_convertExcelDate(trim((string) $d['date_inscription']));
            $curStmt = $pdo->prepare('SELECT date_inscription FROM registrations WHERE id = ?');
            $curStmt->execute([$d['id']]);
            $curDateInsc = (string) $curStmt->fetchColumn();
            if (substr($curDateInsc, 0, 10) !== substr($newDateInsc, 0, 10)) {
                $params['date_inscription'] = $newDateInsc;
                $setParts[] = "`date_inscription` = :date_inscription";
            }
        }

        /* Lot 1 — si l'adresse email change, l'empreinte de rattachement doit
         * suivre DANS LA MÊME requête. Sinon l'inscription resterait rattachée à
         * l'ancien compte coureur (et disparaîtrait du nouveau) sans que rien ne
         * le signale : `email` est chiffré, l'incohérence est invisible en base. */
        if ($emailEnClair !== null && fer_hasColumn($pdo, 'registrations', 'email_normalise')) {
            $params['email_normalise'] = fer_emailHash($emailEnClair);
            $setParts[] = "`email_normalise` = :email_normalise";
        }

        /* Lot 1 — si la naissance change, l'année de naissance suit. Elle est
         * calculée sur l'année de l'édition DE CETTE INSCRIPTION (une ligne d'une
         * édition passée ne doit pas être datée sur l'année courante). */
        if ($naissanceBrute !== null && fer_hasColumn($pdo, 'registrations', 'annee_naissance')) {
            $edStmt = $pdo->prepare('SELECT edition_id FROM registrations WHERE id = ?');
            $edStmt->execute([$d['id']]);
            $edRow = $edStmt->fetchColumn();
            foreach (regcore_naissanceColumns($pdo, $naissanceBrute, $edRow ? (int) $edRow : null) as $c => $v) {
                $params[$c]  = $v;
                $setParts[] = "`$c` = :$c";
            }
        }

        if (empty($setParts)) {
            echo json_encode(['ok' => true]); exit;
        }

        $set = implode(',', $setParts);
        $pdo->prepare("UPDATE registrations SET $set WHERE id = :id")->execute($params);
        echo json_encode(['ok'=>true]);
        exit;
    }
}

/* ───── ACTIONS GROUPÉES SUR LES INSCRIPTIONS ──────────────────────────────
 * Modification ou suppression EN MASSE d'inscriptions sélectionnées dans le
 * dashboard (cases à cocher). Réutilise la même logique que la route
 * `registrations` (chiffrement, recalcul du montant, synchro prestation) mais
 * appliquée à une liste d'ids.
 *   - PUT    : { ids:[...], fields:{ col: val, ... } } → applique les champs cochés
 *              à toutes les inscriptions de la liste. Permission edit_registration.
 *   - DELETE : { ids:[...] } → supprime toutes les inscriptions de la liste.
 *              Permission delete_registration.
 * Le rôle « saisie » est restreint aux inscriptions de son organisation
 * (filtre sur les créateurs de la même organisation, cf. saisieAllowedCreatorIds()).
 */
if ($route === 'registrations-bulk') {
    $raw = file_get_contents('php://input');
    $d   = json_decode($raw, true);
    if (!is_array($d)) { http_response_code(400); echo json_encode(['ok'=>false,'err'=>'JSON invalide']); exit; }

    // Liste d'ids assainie (entiers positifs uniques)
    $ids = array_values(array_unique(array_filter(
        array_map('intval', (array) ($d['ids'] ?? [])),
        fn($x) => $x > 0
    )));
    if (!$ids) { http_response_code(400); echo json_encode(['ok'=>false,'err'=>'Aucune inscription sélectionnée']); exit; }

    // Le rôle « saisie » est restreint aux inscriptions de SON organisation.
    $onlyOrg = currentRole() === 'saisie';

    /* ---------- Suppression groupée ---------- */
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        requireAction('dashboard.delete_registration');
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($onlyOrg) {
            $allowed = saisieAllowedCreatorIds($pdo);
            $inOrg = implode(',', array_fill(0, count($allowed), '?'));
            $stmt = $pdo->prepare("DELETE FROM registrations WHERE id IN ($in) AND created_by IN ($inOrg)");
            $stmt->execute(array_merge($ids, $allowed));
        } else {
            $stmt = $pdo->prepare("DELETE FROM registrations WHERE id IN ($in)");
            $stmt->execute($ids);
        }
        echo json_encode(['ok'=>true, 'deleted'=>$stmt->rowCount()]); exit;
    }

    /* ---------- Modification groupée ---------- */
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        requireAction('dashboard.edit_registration');
        $fields = is_array($d['fields'] ?? null) ? $d['fields'] : [];

        // Rôle « saisie » : on restreint la liste d'ids aux inscriptions de son
        // organisation (filtrage en amont → l'UPDATE n'a plus besoin de clause).
        if ($onlyOrg) {
            $allowed = saisieAllowedCreatorIds($pdo);
            $inIds = implode(',', array_fill(0, count($ids), '?'));
            $inOrg = implode(',', array_fill(0, count($allowed), '?'));
            $chk = $pdo->prepare("SELECT id FROM registrations WHERE id IN ($inIds) AND created_by IN ($inOrg)");
            $chk->execute(array_merge($ids, $allowed));
            $ids = array_map('intval', $chk->fetchAll(PDO::FETCH_COLUMN));
            if (!$ids) { echo json_encode(['ok'=>true, 'updated'=>0]); exit; }
        }

        require_once __DIR__ . '/src/content/form_fields.php';
        $fieldCols = getAllActiveFieldColumns($pdo);

        // Construction du SET commun à tous les ids (les mêmes valeurs sont
        // appliquées à chaque inscription sélectionnée).
        $setParts   = [];
        $baseParams = [];
        $naissanceBulkBrute = null;   // lot 1 : naissance saisie, avant conversion en âge
        foreach ($fieldCols as $col => $meta) {
            if (!array_key_exists($col, $fields)) continue;
            // nom / prénom ne sont jamais modifiables en masse (jamais communs).
            if ($col === 'nom' || $col === 'prenom') continue;
            $val = $fields[$col];
            if ($val === null) $val = '';
            if ($col === 'commentaire') $val = mb_substr((string) $val, 0, 2000);
            // Naissance → âge (année/date convertie). ENUM assainis (anti « Data truncated »).
            // Lot 1 : valeur brute conservée pour recalculer l'année de naissance par ligne.
            if ($col === 'naissance' && $val !== '') {
                require_once __DIR__ . '/src/content/registrations_core.php';
                $naissanceBulkBrute = (string) $val;
                $age = regcore_naissanceToAge((string) $val);
                $val = ($age !== null) ? $age : '';
            }
            if ($col === 'tshirt_size') { $val = in_array($val, ['-','XS','S','M','L','XL','XXL'], true) ? $val : '-'; }
            if ($col === 'sexe')        { $val = in_array($val, ['H','F','Autre'], true) ? $val : 'Autre'; }
            $baseParams[$col] = $meta['encrypted'] ? encrypt($val !== '' ? $val : '') : $val;
            $setParts[] = "`{$col}` = :{$col}";
            // Lot 1 : l'empreinte de rattachement suit l'email dans la MÊME requête.
            // (Modification en masse de l'email : rare, mais elle existe.)
            if ($col === 'email' && fer_hasColumn($pdo, 'registrations', 'email_normalise')) {
                $baseParams['email_normalise'] = fer_emailHash((string) $val);
                $setParts[] = "`email_normalise` = :email_normalise";
            }
        }

        // Paiement : recalcule le montant dû + synchronise la prestation + normalise
        // le mode stocké, exactement comme la mise à jour unitaire (PUT registrations).
        if (array_key_exists('paiement_mode', $fields)) {
            $pm = (string) $fields['paiement_mode'];
            if (strcasecmp($pm, 'gratuit') === 0) {
                $baseParams['montant_du'] = 0;
            } else {
                $baseParams['montant_du'] = (float) ($pdo->query('SELECT registration_fee FROM setting WHERE id = 1 LIMIT 1')->fetchColumn() ?: 0);
            }
            $setParts[] = "`montant_du` = :montant_du";
            $baseParams['prestation'] = prestationFromPaiement($pm);
            $setParts[] = "`prestation` = :prestation";
            $baseParams['paiement_mode'] = storedPaiementMode($pm);
            $setParts[] = "`paiement_mode` = :paiement_mode";
        }

        /* Lot 1 — l'année de naissance suit la naissance saisie. Elle est recalculée
         * LIGNE PAR LIGNE, sur l'année de l'édition de chaque inscription : une
         * sélection peut mélanger plusieurs éditions, et une valeur commune y
         * fausserait silencieusement les lignes des éditions passées. */
        $bulkNaissance = ($naissanceBulkBrute !== null && fer_hasColumn($pdo, 'registrations', 'annee_naissance'));
        if ($bulkNaissance) {
            foreach (['annee_naissance', 'date_naissance', 'naissance_source'] as $c) {
                $setParts[] = "`$c` = :$c";
            }
        }

        if (!$setParts) { echo json_encode(['ok'=>true, 'updated'=>0]); exit; }

        $set  = implode(',', $setParts);
        // La portée « saisie » est déjà appliquée en filtrant $ids ci-dessus.
        $sql  = "UPDATE registrations SET $set WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $edStmt = $bulkNaissance ? $pdo->prepare('SELECT edition_id FROM registrations WHERE id = ?') : null;

        $updated = 0;
        $pdo->beginTransaction();
        try {
            foreach ($ids as $id) {
                $p = $baseParams;
                $p['id'] = $id;
                if ($bulkNaissance) {
                    $edStmt->execute([$id]);
                    $ed = $edStmt->fetchColumn();
                    $p += regcore_naissanceColumns($pdo, $naissanceBulkBrute, $ed ? (int) $ed : null);
                }
                $stmt->execute($p);
                $updated += $stmt->rowCount();
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            error_log('[REGISTRATIONS-BULK] ' . $e->getMessage());
            echo json_encode(['ok'=>false, 'err'=>'Erreur lors de la modification groupée']);
            exit;
        }
        echo json_encode(['ok'=>true, 'updated'=>$updated]); exit;
    }

    http_response_code(405); echo json_encode(['ok'=>false]); exit;
}

/* ───── STATS GLOBALES INSCRIPTIONS (toutes organisations confondues) ────── */
if ($route === 'registrations-stats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!canAccessPage('dashboard')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Accès refusé']);
        exit;
    }
    try {
        // Compteurs sur TOUTES les inscriptions, sans filtre d'organisation
        $total  = (int) $pdo->query('SELECT COUNT(*) FROM registrations')->fetchColumn();
        // T-shirt récupéré = taille renseignée (différente de vide et de "-")
        $tshirt = (int) $pdo->query(
            "SELECT COUNT(*) FROM registrations
              WHERE tshirt_size IS NOT NULL AND tshirt_size <> '' AND tshirt_size <> '-'"
        )->fetchColumn();
        echo json_encode(['ok' => true, 'total' => $total, 'tshirt_recovered' => $tshirt]);
    } catch (Throwable $e) {
        error_log('[REGISTRATIONS-STATS] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Erreur lors du calcul des statistiques']);
    }
    exit;
}

/* ───── BULK CREATE (permission dashboard.bulk_create) ────────────────────
 * Saisie en lot : N inscrits d'une même entreprise créés en une seule requête.
 * Champs partagés (entreprise, paiement_mode) saisis 1×, puis liste de
 * personnes avec leurs champs propres — DONT l'email, propre à chacun.
 * Chaque inscrit ayant un email valide reçoit son PROPRE mail de confirmation
 * (avec QR Code selon le réglage global, identique à la saisie classique).
 * Une ligne sans email est inscrite normalement, sans envoi de mail.
 */
if ($route === 'bulk-create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAction('dashboard.bulk_create');

    // Vérifie que la migration BDD a été appliquée (update.php).
    try { $pdo->query('SELECT visible_saisie_multiple FROM forms LIMIT 0'); }
    catch (\PDOException $e) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => "Migration BDD manquante : lancez update.php pour activer l'ajout multiple"]);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload) || !isset($payload['shared']) || !isset($payload['rows'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Format invalide']);
        exit;
    }

    $shared = $payload['shared'];
    $rows   = $payload['rows'];

    // Validation des champs partagés. Seul le paiement est réellement commun.
    // `entreprise` n'est plus qu'une valeur COMMUNE FACULTATIVE servant à remplir
    // les lignes sans entreprise propre (l'entreprise est saisie par personne).
    $sharedEntreprise   = mb_substr(trim($shared['entreprise'] ?? ''), 0, 255);
    $sharedPaiement     = mb_substr(trim($shared['paiement_mode'] ?? ''), 0, 50);
    $sharedOrigine      = mb_substr(trim($shared['origine'] ?? 'Admin'), 0, 100);

    // Mode d'envoi des mails : 'individual' (1 mail/personne) ou 'recap' (1 mail groupé).
    $mailMode  = ($shared['mail_mode'] ?? 'individual') === 'recap' ? 'recap' : 'individual';
    $recapEmail = trim($shared['recap_email'] ?? '');

    if ($sharedPaiement === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Le mode de paiement est obligatoire']);
        exit;
    }
    if ($mailMode === 'recap' && !filter_var($recapEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Email de contact invalide pour le récap groupé']);
        exit;
    }
    if (!is_array($rows) || count($rows) === 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Aucune personne à inscrire']);
        exit;
    }
    if (count($rows) > 50) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Limite : 50 personnes maximum par lot']);
        exit;
    }

    try {
        require_once __DIR__ . '/src/content/form_fields.php';
        require_once __DIR__ . '/src/content/registrations_core.php'; // regcore_convertExcelDate() pour la date d'inscription

        // Champs requis en mode bulk (depuis forms.required_saisie_multiple)
        $bulkFields = $pdo->query(
            "SELECT bdd_column, encrypted, required_saisie_multiple, field_type
               FROM forms
              WHERE active = 1 AND visible_saisie_multiple = 1
              ORDER BY sort_order ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $requiredBulk = [];
        foreach ($bulkFields as $bf) {
            if ((int)($bf['required_saisie_multiple'] ?? 0) === 1) {
                $requiredBulk[] = $bf['bdd_column'];
            }
        }

        // Colonnes BDD à insérer dynamiquement (toutes les colonnes formulaire actives)
        $allCols = getAllActiveFieldColumns($pdo);

        // Tarif global (utilisé si le client n'envoie pas de montant) + réglages
        // « tarif enfant selon l'âge ». Les 3 colonnes child_* peuvent ne pas exister
        // avant update.php → repli silencieux sur le comportement habituel.
        $registrationFee = (float) ($pdo->query('SELECT registration_fee FROM setting WHERE id = 1 LIMIT 1')->fetchColumn() ?: 0);
        $childEnabled = false; $childAgeThreshold = 12; $childAmount = 0.0;
        try {
            $cfg = $pdo->query('SELECT child_pricing_enabled, child_age_threshold, child_amount FROM setting WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if ($cfg) {
                $childEnabled      = !empty($cfg['child_pricing_enabled']);
                $childAgeThreshold = (int) $cfg['child_age_threshold'];
                $childAmount       = (float) $cfg['child_amount'];
            }
        } catch (\Throwable $e) { /* colonnes absentes → tarif enfant désactivé */ }
        if ($childEnabled) require_once __DIR__ . '/src/content/registrations_core.php'; // regcore_ageFromNaissance()

        // Compteur d'inscription
        $counterExists = false;
        try {
            $pdo->query('SELECT next_no FROM inscription_counter LIMIT 0');
            $counterExists = true;
        } catch (PDOException $e) {}

        $created = 0;
        $errors  = [];
        $createdRegistrants = []; // pour le mail récap

        // Identifiant de GROUPE : les inscrits d'un même lot (≥ 2) partagent un
        // `group_id`. Il alimente le QR « groupé » (encode « G:<group_id> ») qui, au
        // scan, affiche tous les membres du groupe. Guardé par l'existence de la colonne
        // (migration update.php) pour ne jamais casser l'ajout multiple avant migration.
        $hasGroupIdCol = ((int) $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registrations' AND COLUMN_NAME = 'group_id'"
        )->fetchColumn()) > 0;
        $groupId = ($hasGroupIdCol && is_array($rows) && count($rows) >= 2)
            ? bin2hex(random_bytes(12)) : null; // token opaque ; le QR encode « G:<group_id> »

        $pdo->beginTransaction();

        foreach ($rows as $idx => $row) {
            if (!is_array($row)) {
                $errors[] = ['index' => $idx, 'reason' => 'Format de ligne invalide'];
                continue;
            }

            // Paiement commun forcé sur chaque ligne. L'entreprise reste propre à
            // la ligne ; si vide, on retombe sur l'« entreprise commune » facultative
            // (qui peut elle-même être vide = particulier).
            $row['paiement_mode'] = $sharedPaiement;
            $rowEntreprise = trim((string)($row['entreprise'] ?? ''));
            $row['entreprise'] = $rowEntreprise !== '' ? $rowEntreprise : $sharedEntreprise;

            // Email par personne : facultatif, mais si renseigné il doit être valide.
            $rowEmail = trim((string)($row['email'] ?? ''));
            if ($rowEmail !== '' && !filter_var($rowEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ['index' => $idx, 'reason' => 'Email invalide : ' . $rowEmail];
                continue;
            }
            $row['email'] = $rowEmail;

            // Validation des champs requis bulk
            $missing = [];
            foreach ($requiredBulk as $req) {
                if (trim((string)($row[$req] ?? '')) === '') $missing[] = $req;
            }
            // Nom + prénom toujours requis pour une inscription valide
            if (trim((string)($row['nom'] ?? '')) === '')    $missing[] = 'nom';
            if (trim((string)($row['prenom'] ?? '')) === '') $missing[] = 'prenom';

            if ($missing) {
                $errors[] = ['index' => $idx, 'reason' => 'Champ(s) manquant(s) : ' . implode(', ', array_unique($missing))];
                continue;
            }

            // Numéro d'inscription
            if ($counterExists) {
                $pdo->exec('UPDATE inscription_counter SET next_no = LAST_INSERT_ID(next_no + 1) WHERE id = 1');
                $no = 'S' . (int)$pdo->lastInsertId();
            } else {
                $no = 'S' . ((int)($pdo->query("SELECT MAX(CAST(REPLACE(REPLACE(inscription_no, 'S', ''), 'E', '') AS UNSIGNED)) FROM registrations")->fetchColumn() ?: 0) + 1);
            }

            // Construction de l'INSERT dynamique
            $cols = ['inscription_no'];
            $phs  = ['?'];
            $vals = [$no];

            foreach ($allCols as $col => $meta) {
                $raw = (string) ($row[$col] ?? '');
                // Naissance → âge (année/date convertie) : défense en profondeur, en plus
                // de la normalisation côté client.
                if ($col === 'naissance' && trim($raw) !== '') {
                    require_once __DIR__ . '/src/content/registrations_core.php';
                    $age = regcore_naissanceToAge($raw);
                    $raw = ($age !== null) ? $age : '';
                }
                // ENUM assainis (défaut si vide/invalide → jamais « Data truncated »).
                if ($col === 'sexe')        { $raw = in_array($raw, ['H','F','Autre'], true) ? $raw : 'Autre'; }
                if ($col === 'tshirt_size') { $raw = in_array($raw, ['-','XS','S','M','L','XL','XXL'], true) ? $raw : '-'; }
                $cols[] = "`{$col}`";
                $phs[]  = '?';
                $vals[] = $meta['encrypted'] ? encrypt($raw !== '' ? $raw : '') : ($raw !== '' ? $raw : '');
            }

            // Tarif enfant selon l'âge (prioritaire) : si activé et que l'âge renseigné
            // est sous le seuil, le montant devient le « montant enfant » configuré,
            // quel que soit le montant_du pré-rempli de la ligne.
            $childApplied = false;
            if ($childEnabled && isset($row['naissance'])) {
                $age = regcore_ageFromNaissance((string) $row['naissance']);
                if ($age !== null && $age < $childAgeThreshold) {
                    $montantDu = $childAmount;
                    $childApplied = true;
                }
            }
            // Sinon : valeur du formulaire si présente, sinon 0 si gratuit, sinon tarif global
            if (!$childApplied) {
                $montantDu = $registrationFee;
                if (isset($row['montant_du']) && is_numeric($row['montant_du'])) {
                    $montantDu = (float) $row['montant_du'];
                } elseif (strcasecmp($sharedPaiement, 'gratuit') === 0) {
                    $montantDu = 0.0;
                }
            }

            $cols[] = 'origine';      $phs[] = '?'; $vals[] = $sharedOrigine;
            $cols[] = 'paiement_mode';$phs[] = '?'; $vals[] = storedPaiementMode($sharedPaiement);
            $cols[] = 'prestation';   $phs[] = '?'; $vals[] = prestationFromPaiement($sharedPaiement);
            $cols[] = 'montant_du';   $phs[] = '?'; $vals[] = $montantDu;
            $cols[] = 'created_by';   $phs[] = '?'; $vals[] = currentUserId();
            if ($groupId !== null) { $cols[] = 'group_id'; $phs[] = '?'; $vals[] = $groupId; }

            /* Lot 1 — rattachement de l'inscription (cf. route « nouvel inscrit »).
             * C'est ici le cas classique de l'inscription groupée : sans
             * `email_normalise`, aucune des personnes du lot n'apparaîtrait dans
             * l'espace coureur du titulaire de l'adresse. */
            $editionIdBulk = fer_activeEditionId($pdo);
            if ($editionIdBulk !== null) {
                $cols[] = 'edition_id'; $phs[] = '?'; $vals[] = $editionIdBulk;
            }
            if (fer_hasColumn($pdo, 'registrations', 'email_normalise')) {
                $cols[] = 'email_normalise'; $phs[] = '?'; $vals[] = fer_emailHash($row['email'] ?? null);
            }
            foreach (regcore_naissanceColumns($pdo, $row['naissance'] ?? '', $editionIdBulk) as $c => $v) {
                $cols[] = $c; $phs[] = '?'; $vals[] = $v;
            }

            // Date d'inscription (date_inscription). Fournie par personne (champ
            // « Date d'inscription ») ou mappée depuis l'Excel : enregistrée telle quelle
            // (date antérieure possible). Vide → on N'AJOUTE PAS la colonne pour laisser
            // le DEFAULT du jour. created_at (date d'ajout) reste auto.
            $rawDateInsc = trim((string) ($row['date_inscription'] ?? ''));
            if ($rawDateInsc !== '') {
                $cols[] = 'date_inscription'; $phs[] = '?'; $vals[] = regcore_convertExcelDate($rawDateInsc);
            }

            $sql = "INSERT INTO registrations (" . implode(',', $cols) . ") VALUES (" . implode(',', $phs) . ")";
            try {
                $pdo->prepare($sql)->execute($vals);
                $created++;
                $createdRegistrants[] = [
                    'inscription_no' => $no,
                    'nom'            => $row['nom'] ?? '',
                    'prenom'         => $row['prenom'] ?? '',
                    'email'          => $row['email'] ?? '',
                    'entreprise'     => $row['entreprise'] ?? '',
                    'montant_du'     => $montantDu,
                ];
            } catch (\Throwable $insErr) {
                $errors[] = ['index' => $idx, 'reason' => 'Erreur BDD : ' . $insErr->getMessage()];
            }
        }

        $pdo->commit();

        // Envoi des mails (hors transaction), selon le mode choisi.
        $mailsSent    = 0;
        $mailsSkipped = 0;
        $mailErrors   = [];
        $recapSent    = false;
        $recapError   = null;

        if ($created > 0) {
            require_once __DIR__ . '/src/mail/googleMail.php';

            if ($mailMode === 'individual') {
                // ── INDIVIDUEL : 1 mail par personne, exactement comme un import
                // AssoConnect (template 'inscription', QR selon réglage global).
                // Une ligne sans email est inscrite sans envoi.
                foreach ($createdRegistrants as $r) {
                    $inscEmail = trim((string) ($r['email'] ?? ''));
                    if ($inscEmail === '') { $mailsSkipped++; continue; }
                    try {
                        // (Le QR est décidé en interne par sendMail via shouldIncludeQrCode.)
                        $ok = sendMail(
                            $inscEmail,
                            'Inscription enregistrée - Forbach en Rose',
                            null, null,
                            $r['nom'] ?? '', $r['prenom'] ?? '',
                            'inscription', $r['inscription_no']
                        );
                        if ($ok !== false) {
                            $mailsSent++;
                        } else {
                            global $lastMailError;
                            $mailErrors[] = ['email' => $inscEmail, 'reason' => $lastMailError ?? 'Échec inconnu'];
                        }
                    } catch (\Throwable $mailErr) {
                        error_log('[BULK-CREATE][MAIL] ' . $r['inscription_no'] . ' : ' . $mailErr->getMessage());
                        $mailErrors[] = ['email' => $inscEmail, 'reason' => $mailErr->getMessage()];
                    }
                }
            } else {
                // ── RÉCAP GROUPÉ : 1 seul mail listant tous les inscrits, envoyé à
                // l'adresse de contact. Un UNIQUE QR « groupé » (encode « G:<group_id> »)
                // est joint selon la config (shouldIncludeGroupQr) : au scan, il affiche
                // TOUS les membres pour valider les tailles d'un coup.
                try {
                    $totalDu = array_sum(array_column($createdRegistrants, 'montant_du'));

                    // QR groupé : inclus si la config l'autorise pour au moins un membre.
                    $groupQrOverride = null;
                    if ($groupId !== null) {
                        $memberNos = array_column($createdRegistrants, 'inscription_no');
                        if (function_exists('shouldIncludeGroupQr') && shouldIncludeGroupQr($memberNos)) {
                            $groupQrOverride = 'G:' . $groupId;
                        }
                    }

                    $listHtml = '<table style="width:100%;border-collapse:collapse;margin-top:12px;font-size:14px;">'
                              . '<thead><tr style="background:#fdf2f6;">'
                              . '<th style="text-align:left;padding:8px;border:1px solid #fbcfe8;">N° inscription</th>'
                              . '<th style="text-align:left;padding:8px;border:1px solid #fbcfe8;">Nom</th>'
                              . '<th style="text-align:left;padding:8px;border:1px solid #fbcfe8;">Prénom</th>'
                              . '</tr></thead><tbody>';
                    foreach ($createdRegistrants as $r) {
                        $listHtml .= '<tr>'
                                  . '<td style="padding:8px;border:1px solid #fbcfe8;font-family:monospace;">' . htmlspecialchars($r['inscription_no']) . '</td>'
                                  . '<td style="padding:8px;border:1px solid #fbcfe8;">' . htmlspecialchars($r['nom']) . '</td>'
                                  . '<td style="padding:8px;border:1px solid #fbcfe8;">' . htmlspecialchars($r['prenom']) . '</td>'
                                  . '</tr>';
                    }
                    $listHtml .= '</tbody></table>';

                    $qrNote = $groupQrOverride
                        ? 'Le jour de la course, <strong>présentez le QR code ci-dessous</strong> pour récupérer les dossards et les t-shirts de tout le groupe (il liste l\'ensemble des inscrits).'
                        : 'Le jour de la course, <strong>présentez ce mail à l\'accueil</strong> pour récupérer les dossards.';
                    $description = '<p style="font-size:15px;line-height:1.6;color:#334155;">'
                                 . 'Bonjour,<br><br>'
                                 . 'Nous confirmons l\'inscription des <strong>' . $created . '</strong> personne(s) ci-dessous '
                                 . 'à la course Forbach en Rose.<br><br>'
                                 . $qrNote
                                 . '</p>'
                                 . $listHtml;

                    $ok = sendMail(
                        $recapEmail,
                        'Inscriptions enregistrées - Forbach en Rose',
                        'Inscriptions Forbach en Rose',
                        $description,
                        null, null,
                        'info', null,
                        'bulk_recap',
                        [],
                        $groupQrOverride   // QR groupé (ou null) → section QR du template
                    );
                    $recapSent = ($ok !== false);
                    if (!$recapSent) {
                        global $lastMailError;
                        $recapError = $lastMailError ?? 'Échec inconnu';
                    }
                } catch (\Throwable $mailErr) {
                    error_log('[BULK-CREATE][RECAP] ' . $mailErr->getMessage());
                    $recapError = $mailErr->getMessage();
                }
            }
        }

        echo json_encode([
            'ok'           => true,
            'created'      => $created,
            'errors'       => $errors,
            'mail_mode'    => $mailMode,
            'mails_sent'   => $mailsSent,
            'mails_skipped'=> $mailsSkipped,
            'mail_errors'  => $mailErrors,
            'recap_sent'   => $recapSent,
            'recap_email'  => $mailMode === 'recap' ? $recapEmail : null,
            'recap_error'  => $recapError,
        ]);
        exit;

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[BULK-CREATE] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Une erreur est survenue : ' . $e->getMessage()]);
        exit;
    }
}

/* ───── BULK PARSE EXCEL (permission dashboard.bulk_create) ───────────────
 * Lit un fichier Excel ARBITRAIRE (colonnes nommées librement, ordre quelconque)
 * et renvoie ses en-têtes + ses lignes, SANS rien insérer en base. Le mapping
 * colonne→champ (glisser-déposer) et le pré-remplissage des cards « Ajout
 * multiple » se font côté client (inc/dashboard.php). La création réelle passe
 * ensuite par la route `bulk-create` : aucune logique d'insertion n'est dupliquée
 * ici, donc aucun risque de régression sur le mail récap groupé.
 */
if ($route === 'bulk-parse-excel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAction('dashboard.bulk_create');

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Erreur lors du téléchargement du fichier.']);
        exit;
    }

    $filePath     = $_FILES['file']['tmp_name'];
    $originalName = $_FILES['file']['name'];

    // Contrôle de format (extension + signature binaire), comme l'import canonique.
    $ext   = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $sig   = (string) @file_get_contents($filePath, false, null, 0, 8);
    $isZip = strncmp($sig, "PK\x03\x04", 4) === 0;                       // .xlsx
    $isOle = strncmp($sig, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0; // .xls
    if (!in_array($ext, ['xlsx', 'xls'], true) || !($isZip || $isOle)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Format invalide. Utilisez un fichier Excel (.xlsx ou .xls).']);
        exit;
    }

    require_once __DIR__ . '/vendor/autoload.php';

    try {
        $ws        = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath)->getActiveSheet();
        $formatted = $ws->toArray(null, true, true, true); // valeurs affichées (dates lisibles)
    } catch (\Throwable $e) {
        error_log('[BULK-PARSE-EXCEL] ' . $e->getMessage());
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Lecture du fichier impossible.']);
        exit;
    }

    if (empty($formatted) || count($formatted) < 2) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Le fichier semble vide (en-tête + au moins une ligne attendus).']);
        exit;
    }

    // En-têtes = 1re ligne : on ne garde que les colonnes réellement nommées.
    $columns = []; // [ ['col' => 'A', 'label' => 'Nom'], ... ]
    foreach ($formatted[1] as $colLetter => $label) {
        $label = trim((string) $label);
        if ($label !== '') $columns[] = ['col' => $colLetter, 'label' => $label];
    }
    if (count($columns) === 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Aucune colonne nommée trouvée en première ligne.']);
        exit;
    }

    // Lignes de données : valeurs alignées sur l'ordre de $columns, lignes vides ignorées.
    $rows = [];
    foreach ($formatted as $idx => $row) {
        if ($idx === 1) continue; // en-tête
        $cells = [];
        $hasValue = false;
        foreach ($columns as $c) {
            $val = trim((string) ($row[$c['col']] ?? ''));
            if ($val !== '') $hasValue = true;
            $cells[] = $val;
        }
        if ($hasValue) $rows[] = $cells;
    }

    if (count($rows) === 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Aucune ligne de données exploitable dans le fichier.']);
        exit;
    }
    // Blocage au-delà de la limite serveur du bulk-create (50 personnes / lot).
    if (count($rows) > 50) {
        http_response_code(400);
        echo json_encode(['ok' => false,
            'error' => 'Le fichier contient ' . count($rows) . ' lignes de données : la limite est de 50 personnes par lot. Réduisez le fichier puis réessayez.']);
        exit;
    }

    echo json_encode([
        'ok'      => true,
        'columns' => array_column($columns, 'label'),
        'rows'    => $rows,
        'total'   => count($rows),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ───── IMPORT EXCEL (permission dashboard.import_excel) ─────────────────── */
if ($route === 'import-excel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAction('dashboard.import_excel');

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Erreur lors du téléchargement du fichier']);
        exit;
    }

    require_once __DIR__ . '/src/content/registrations_core.php';

    // Émetteur de progression : bascule la réponse en flux NDJSON dès le 1er
    // événement (post-commit). Une erreur AVANT le streaming (format invalide,
    // colonnes manquantes…) n'émet rien → réponse JSON classique : le contrat
    // attendu par le JS du dashboard reste strictement identique.
    $emitted = false;
    $emit = function (array $evt) use (&$emitted) {
        if (!$emitted) {
            header('Content-Type: application/x-ndjson; charset=utf-8');
            header('X-Accel-Buffering: no');
            if (ob_get_level()) ob_end_flush();
            $emitted = true;
        }
        echo json_encode($evt, JSON_UNESCAPED_UNICODE) . "\n";
        flush();
    };

    // Import via la fonction CANONIQUE partagée (même logique que l'API externe
    // et l'import automatique). created_by = utilisateur courant, comme avant.
    $res = importInscritsExcel(
        $pdo,
        $_FILES['file']['tmp_name'],
        $_FILES['file']['name'],
        ['send_mail' => isset($_POST['send_mails']), 'created_by' => currentUserId(), 'origine' => 'AssoConnect'],
        $emit
    );

    // Échec survenu AVANT tout streaming → réponse JSON d'erreur (le JS lit j.error).
    if (empty($res['ok']) && !$emitted) {
        http_response_code($res['http'] ?? 500);
        echo json_encode([
            'error'   => $res['message'] ?? "Erreur lors de l'import.",
            'missing' => $res['missing'] ?? null,
        ]);
    }
    exit;
}

// ═══ Vérification doublons avant import ═══
if ($route === 'check-duplicates' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAction('dashboard.import_excel');

    $input = json_decode(file_get_contents('php://input'), true);
    $tickets = $input['tickets'] ?? [];

    if (!is_array($tickets) || empty($tickets)) {
        echo json_encode(['duplicates' => []]);
        exit;
    }

    // Ajouter le préfixe E pour comparer avec la BDD
    $tickets = array_map(function($t) { return 'E' . trim($t); }, array_filter($tickets));

    if (empty($tickets)) {
        echo json_encode(['duplicates' => []]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($tickets), '?'));
    $stmt = $pdo->prepare("SELECT inscription_no FROM registrations WHERE inscription_no IN ($placeholders)");
    $stmt->execute($tickets);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // Retourner sans le préfixe E pour le front
    echo json_encode(['duplicates' => array_map(function($e) { return ltrim($e, 'E'); }, $existing)]);
    exit;
}

/* ---------- Petites fonctions utilitaires ---------- */
function normaliseLabel(string $label): string {
    // Translittération déterministe des accents (UTF-8 -> ASCII).
    // NB : on n'utilise PAS iconv('ASCII//TRANSLIT') car son résultat dépend
    // de la locale du serveur : sur un serveur en locale C/POSIX, les lettres
    // accentuées sont purement supprimées ("Numéro" -> "Numro"), ce qui faisait
    // échouer la correspondance des colonnes Excel sur le serveur de production.
    $accents = [
        'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','ā'=>'a','ą'=>'a',
        'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ē'=>'e','ę'=>'e',
        'È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ī'=>'i',
        'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I',
        'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ō'=>'o',
        'Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ū'=>'u',
        'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U',
        'ç'=>'c','Ç'=>'C','ñ'=>'n','Ñ'=>'N',
        'ý'=>'y','ÿ'=>'y','Ý'=>'Y','š'=>'s','ž'=>'z',
    ];
    $label = strtr($label, $accents);
    $label = preg_replace('/[^a-zA-Z0-9 ]/', '', $label);
    return strtolower(trim(preg_replace('/\s+/', ' ', $label)));
}

function normaliseSexe(?string $val): ?string {
    $v = strtoupper(trim($val ?? ''));
    return match ($v) {
        'H', 'M', 'HOMME', 'MALE'  => 'H',
        'F', 'FEMME', 'FEMALE'     => 'F',
        ''                         => null,
        default                    => 'Autre'
    };
}

function normalisePaiementMode(?string $val): string {
    // Mappe les libellés Excel (AssoConnect) vers les valeurs stockées en BDD.
    // Import AssoConnect = paiement en ligne, donc carte → 'en ligne (CB)'
    // (distinct du 'CB' de la saisie manuelle, qui désigne un paiement carte
    // en présentiel). Fallback : 'en ligne (CB)' pour toute valeur inconnue.
    $v = strtolower(trim($val ?? ''));
    if ($v === '') return 'en ligne (CB)';
    if (str_contains($v, 'gratuit'))                              return 'gratuit';
    if (str_contains($v, 'cheque') || str_contains($v, 'chèque')) return 'cheque';
    if (str_contains($v, 'espece') || str_contains($v, 'espèce')) return 'espece';
    if (str_contains($v, 'carte') || str_contains($v, 'cb') || str_contains($v, 'bancaire')) return 'en ligne (CB)';
    return 'en ligne (CB)';
}

/**
 * Catégorie d'inscrit (« prestation ») déduite du mode de paiement.
 *   gratuit        → enfant_gratuit  (enfant -12 ans, ne compte pas pour le QR)
 *   enfant_tshirt  → enfant_tshirt   (enfant -12 ans AVEC t-shirt, payant, compte pour le QR)
 *   tout le reste  → tarif_unique    (adulte / inscription normale)
 * NB : le couple (paiement_mode, montant_du) reste l'indicateur d'éligibilité QR (montant > 0).
 */
function prestationFromPaiement(?string $paiementMode): string {
    require_once __DIR__ . '/src/content/registrations_core.php';
    return regcore_prestationFromPaiement($paiementMode); // source unique (évite le doublon)
}

/**
 * Mode de paiement à STOCKER à partir du choix du menu.
 * « enfant_tshirt » n'est pas un vrai moyen de paiement : l'enfant -12 ans avec
 * t-shirt a payé, on stocke donc « en ligne (CB) » (la catégorie va dans `prestation`).
 * « gratuit » (vraiment gratuit) et les moyens réels (CB/espece/cheque/...) sont conservés.
 */
function storedPaiementMode(?string $choice): ?string {
    require_once __DIR__ . '/src/content/registrations_core.php';
    return regcore_storedPaiementMode($choice); // source unique (évite le doublon)
}

function convertExcelDate($value): ?string {
    require_once __DIR__ . '/src/content/registrations_core.php';
    return regcore_convertExcelDate($value); // source unique (évite le doublon)
}

function logImportError(array $data, string $filename = 'import_errors.log') {
    // Écriture dans config/ (protégé par .htaccess), jamais dans le webroot
    $safePath = __DIR__ . '/storage/logs/' . basename($filename);
    $entry = date('Y-m-d H:i:s') . " | " . json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($safePath, $entry, FILE_APPEND);
}


/* ───── EXPORT EXCEL (permission dashboard.export_excel) ─────────────────── */
if ($route === 'export-excel' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    requireAction('dashboard.export_excel');

    require_once __DIR__ . '/vendor/autoload.php';
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    /* 1. Entêtes */
    $headers = ['No', 'Nom', 'Prénom', 'Tel', 'Email', 'Naissance',
                'Sexe', 'T-shirt', 'Ville', 'Entreprise', 'Origine',
                'Paiement', 'Prestation', 'Montant dû', 'Date d\'inscription', 'Date ajout', 'Créé par'];
    $sheet->fromArray($headers, null, 'A1');

    /* 2. Données (déchiffrer les PII). Le rôle « saisie » n'exporte que les
       inscriptions de son organisation (cohérent avec le tableau). */
    $selectCols =
        "SELECT r.inscription_no, r.nom, r.prenom, r.tel, r.email, r.naissance,
                r.sexe, r.tshirt_size, r.ville, r.entreprise, r.origine,
                r.paiement_mode, r.prestation, r.montant_du,
                r.date_inscription, r.created_at, COALESCE(u.email, r.created_by) AS created_by
         FROM registrations r
         LEFT JOIN users u ON r.created_by = u.id";
    $orderBy = " ORDER BY CAST(REPLACE(REPLACE(r.inscription_no, 'S', ''), 'E', '') AS UNSIGNED)";
    if (currentRole() === 'saisie') {
        $allowed = saisieAllowedCreatorIds($pdo);
        $in = implode(',', array_fill(0, count($allowed), '?'));
        $st = $pdo->prepare($selectCols . " WHERE r.created_by IN ($in)" . $orderBy);
        $st->execute($allowed);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $pdo->query($selectCols . $orderBy)->fetchAll(PDO::FETCH_ASSOC);
    }
    $rows = decryptRows($rows);

    // Âge seuil « enfant » (paramétrable) pour les libellés « -N ans ».
    $childAge = 12;
    try { $childAge = (int) ($pdo->query('SELECT child_age_threshold FROM setting WHERE id = 1 LIMIT 1')->fetchColumn() ?: 12); } catch (\Throwable $e) {}

    // Libellés lisibles pour la colonne Paiement et la colonne Prestation (catégorie).
    $paiementLabels = [
        'gratuit'       => "Gratuit / -{$childAge} ans",
        'enfant_tshirt' => 'en ligne (CB)',
        'espece'        => 'Espèce',
        'cheque'        => 'Chèque',
    ];
    $prestationLabels = [
        'tarif_unique'   => 'Tarif unique',
        'enfant_gratuit' => "Enfant -{$childAge} ans (gratuit sans t-shirt)",
        'enfant_tshirt'  => "Enfant -{$childAge} ans (avec t-shirt)",
    ];
    foreach ($rows as &$expRow) {
        $pm = strtolower((string) ($expRow['paiement_mode'] ?? ''));
        $expRow['paiement_mode'] = $paiementLabels[$pm] ?? ($expRow['paiement_mode'] ?? '');
        $pr = strtolower((string) ($expRow['prestation'] ?? ''));
        // NULL/vide (anciens inscrits) considéré comme « Tarif unique ».
        $expRow['prestation'] = $prestationLabels[$pr] ?? ($pr === '' ? 'Tarif unique' : ($expRow['prestation'] ?? ''));
        // Dates au format jour seul (sans heure), pour les 2 colonnes.
        $expRow['date_inscription'] = !empty($expRow['date_inscription']) ? date('d/m/Y', strtotime((string) $expRow['date_inscription'])) : '';
        $expRow['created_at']       = !empty($expRow['created_at'])       ? date('d/m/Y', strtotime((string) $expRow['created_at']))       : '';
    }
    unset($expRow);

    $rows = array_map('array_values', $rows); // Convertir en tableau numérique pour fromArray

    $sheet->fromArray($rows, null, 'A2');

    /* 3. Style minimal */
    $sheet->getStyle('A1:Q1')->getFont()->setBold(true);
    foreach (range('A', 'Q') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

    /* 4. Téléchargement */
    $filename = 'inscriptions_'.date('Ymd_His').'.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header('Cache-Control: max-age=0');

    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

/* ───── ARCHIVE CURRENT YEAR (permission dashboard.archive) ─────────────────── */
if ($route === 'archive-current' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAction('dashboard.archive');

    $year         = (int) date('Y');                // année en cours
    $tableArchive = "registrations_$year";

    /* 0) s'il n'y a rien à archiver, on sort proprement */
    $nbActives = $pdo->query('SELECT COUNT(*) FROM registrations')->fetchColumn();
    if (!$nbActives) { echo json_encode(['ok'=>true,'archived'=>0]); exit; }

    /* 1) Créer la table archive si nécessaire */
    $pdo->exec("CREATE TABLE IF NOT EXISTS `$tableArchive` LIKE registrations");
    // Si l'archive a été créée par une version antérieure (sans `prestation`),
    // on aligne son schéma pour que `INSERT ... SELECT *` ne casse pas.
    try { $pdo->exec("ALTER TABLE `$tableArchive` ADD COLUMN `prestation` VARCHAR(30) DEFAULT NULL"); }
    catch (\Throwable $e) { /* colonne déjà présente : rien à faire */ }
    // Idem pour date_inscription (archive créée par une version antérieure).
    try { $pdo->exec("ALTER TABLE `$tableArchive` ADD COLUMN `date_inscription` DATETIME DEFAULT NULL"); }
    catch (\Throwable $e) { /* colonne déjà présente : rien à faire */ }

    /* 2) Copier toutes les lignes */
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO `$tableArchive` SELECT * FROM registrations");

    /* 3) Statistiques de base (tshirt non chiffré = OK en SQL, le reste en PHP) */
    $s = $pdo->query("
        SELECT COUNT(*)                           AS total,
               SUM(tshirt_size='XS')              AS xs,
               SUM(tshirt_size='S')               AS s,
               SUM(tshirt_size='M')               AS m,
               SUM(tshirt_size='L')               AS l,
               SUM(tshirt_size='XL')              AS xl,
               SUM(tshirt_size='XXL')             AS xxl
        FROM `$tableArchive`
    ")->fetch(PDO::FETCH_ASSOC);

    foreach (['xs','s','m','l','xl','xxl'] as $k) $s[$k] = (int)($s[$k] ?? 0);

    /* Charger toutes les lignes et déchiffrer pour les stats PII */
    $allRows = $pdo->query("SELECT nom, prenom, naissance, sexe, ville, entreprise FROM `$tableArchive`")->fetchAll(PDO::FETCH_ASSOC);
    $allRows = decryptRows($allRows);

    /* Age moyen — via la fonction centralisée (gère l'âge déjà stocké comme les
     * données héritées année/date). L'année de l'archive sert de référence pour les
     * valeurs au format année/date, afin que l'âge reflète l'année de l'événement. */
    require_once __DIR__ . '/src/content/registrations_core.php';
    $ages = [];
    foreach ($allRows as $r) {
        $age = regcore_ageFromNaissance((string) ($r['naissance'] ?? ''), $year);
        if ($age !== null) $ages[] = $age;
    }
    $s['age_moyen'] = count($ages) ? round(array_sum($ages) / count($ages), 1) : null;

    /* 4) Ville la plus représentée */
    $villeCounts = [];
    foreach ($allRows as $r) {
        $v = trim($r['ville'] ?? '');
        if ($v !== '') $villeCounts[$v] = ($villeCounts[$v] ?? 0) + 1;
    }
    arsort($villeCounts);
    $ville_top = $villeCounts ? array_key_first($villeCounts) : null;

    /* 5) Entreprise la plus représentée */
    $entrCounts = [];
    foreach ($allRows as $r) {
        $e = trim($r['entreprise'] ?? '');
        if ($e !== '') $entrCounts[$e] = ($entrCounts[$e] ?? 0) + 1;
    }
    arsort($entrCounts);
    $entreprise_top = $entrCounts ? array_key_first($entrCounts) : null;

    /* 6) Plus vieille personne masculine (âge le plus élevé) */
    $plus_vieux_h = null;
    $oldestH = null;
    foreach ($allRows as $r) {
        if ($r['sexe'] !== 'H') continue;
        $age = regcore_ageFromNaissance((string) ($r['naissance'] ?? ''), $year);
        if ($age === null) continue;
        if ($oldestH === null || $age > $oldestH) {
            $oldestH = $age;
            $plus_vieux_h = trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? ''));
        }
    }

    /* 7) Plus vieille personne féminine (âge le plus élevé) */
    $plus_vieille_f = null;
    $oldestF = null;
    foreach ($allRows as $r) {
        if ($r['sexe'] !== 'F') continue;
        $age = regcore_ageFromNaissance((string) ($r['naissance'] ?? ''), $year);
        if ($age === null) continue;
        if ($oldestF === null || $age > $oldestF) {
            $oldestF = $age;
            $plus_vieille_f = trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? ''));
        }
    }

    /* 8) Insérer/Mettre à jour les statistiques */
    $pdo->prepare("
        INSERT INTO registrations_stats
          (year, total_inscrits, tshirt_xs, tshirt_s, tshirt_m,
           tshirt_l, tshirt_xl, tshirt_xxl, age_moyen, table_name,
           ville_top, entreprise_top, plus_vieux_h, plus_vieille_f)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
           total_inscrits = VALUES(total_inscrits),
           tshirt_xs      = VALUES(tshirt_xs),
           tshirt_s       = VALUES(tshirt_s),
           tshirt_m       = VALUES(tshirt_m),
           tshirt_l       = VALUES(tshirt_l),
           tshirt_xl      = VALUES(tshirt_xl),
           tshirt_xxl     = VALUES(tshirt_xxl),
           age_moyen      = VALUES(age_moyen),
           table_name     = VALUES(table_name),
           ville_top      = VALUES(ville_top),
           entreprise_top = VALUES(entreprise_top),
           plus_vieux_h   = VALUES(plus_vieux_h),
           plus_vieille_f = VALUES(plus_vieille_f)
    ")->execute([
        $year, $s['total'], $s['xs'], $s['s'], $s['m'], $s['l'], $s['xl'], $s['xxl'],
        $s['age_moyen'], $tableArchive, $ville_top, $entreprise_top, 
        $plus_vieux_h, $plus_vieille_f
    ]);

    $pdo->commit();

    /* 9) On vide la table active pour la nouvelle saison.
     *    Hors transaction : TRUNCATE est du DDL en MySQL et provoque un commit
     *    implicite ; le laisser avant commit() faisait lever "There is no active
     *    transaction" alors que l'archivage avait réussi. */
    $pdo->exec('TRUNCATE TABLE registrations');

    /* 10) Vider le journal des remises de T-shirts : l'archivage marque la fin de
     *     la saison, les remises de l'année écoulée ne servent plus (l'an prochain
     *     = de nouveaux inscrits). Aucune perte pour l'archive : la taille de chaque
     *     inscrit (tshirt_size) est déjà copiée dans registrations_$year à l'étape 1.
     *     En try/catch : ne doit jamais faire échouer l'archivage. */
    try { $pdo->exec('TRUNCATE TABLE tshirt_handout_log'); } catch (\Throwable $e) { /* table absente / non critique */ }

    echo json_encode([
        'ok' => true,
        'archived' => $s['total'],
        'year' => $year,
        'table_name' => $tableArchive
    ]);
    exit;
}

// Dans votre api.php, section registrations-archive
if ($route === 'registrations-archive') {
    // Lecture des archives : accessible si l'utilisateur a accès au dashboard
    if (!canAccessPage('dashboard')) {
        http_response_code(403);
        echo json_encode(['error' => 'Accès refusé']);
        exit;
    }

    $year = (int) ($_GET['year'] ?? date('Y'));
    $tableArchive = "registrations_$year";

    try {
        // Vérifier l'existence via INFORMATION_SCHEMA (plus fiable que SHOW TABLES LIKE avec PDO)
        $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
        $checkStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
        );
        $checkStmt->execute([$dbName, $tableArchive]);
        if ((int)$checkStmt->fetchColumn() === 0) {
            echo json_encode([]);
            exit;
        }

        // Certaines colonnes n'existent pas dans les archives antérieures à leur
        // introduction : on les inclut seulement si présentes, sinon NULL. La liste
        // des colonnes réellement présentes est lue une seule fois. `entreprise`,
        // `montant_du` et `paiement_mode` alimentent les cartes/modal de stats côté
        // client (mêmes indicateurs que le dashboard, via js/reg-stats.js).
        $archCols = $pdo->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tableArchive'"
        )->fetchAll(PDO::FETCH_COLUMN, 0);
        $colOrNull = function (string $c) use ($archCols): string {
            return in_array($c, $archCols, true) ? "`$c`" : "NULL AS `$c`";
        };
        $prestaSelect = $colOrNull('prestation')
            . ',' . $colOrNull('entreprise')
            . ',' . $colOrNull('montant_du')
            . ',' . $colOrNull('paiement_mode');

        // Rôle « saisie » : restreint aux inscriptions de SON organisation (cohérent
        // avec le tableau live). Une archive sans colonne `created_by` (ancienne) ne
        // peut pas être filtrée → on ne renvoie rien à un saisie plutôt que de fuiter.
        $saisieWhere = ''; $saisieParams = [];
        if (currentRole() === 'saisie') {
            $hasCreatedBy = ((int) $pdo->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tableArchive' AND COLUMN_NAME = 'created_by'"
            )->fetchColumn()) > 0;
            if (!$hasCreatedBy) { echo json_encode([]); exit; }
            $allowed = saisieAllowedCreatorIds($pdo);
            $saisieWhere = ' WHERE created_by IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
            $saisieParams = $allowed;
        }

        $stArch = $pdo->prepare(
            "SELECT inscription_no,nom,prenom,tel,email,naissance,sexe,ville,tshirt_size,$prestaSelect
             FROM `$tableArchive`$saisieWhere
             ORDER BY CAST(REPLACE(REPLACE(inscription_no, 'S', ''), 'E', '') AS UNSIGNED) DESC"
        );
        $stArch->execute($saisieParams);
        $registrations = $stArch->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(decryptRows($registrations));
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Gestion des QR Codes
/**
 * Normalise une date de fermeture de QR code (obligatoire).
 * Accepte le format datetime-local (YYYY-MM-DDTHH:MM) ou DATETIME MySQL et
 * renvoie une chaîne « YYYY-MM-DD HH:MM:SS », ou null si vide/invalide.
 */
function normalizeQrExpiresAt($raw): ?string {
    $s = trim((string)$raw);
    if ($s === '') return null;
    $s = str_replace('T', ' ', $s);
    // Ajoute les secondes si le navigateur ne les a pas fournies.
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) {
        $s .= ':00';
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $s);
    if (!$dt || $dt->format('Y-m-d H:i:s') !== $s) return null;
    return $s;
}

if ($route === 'qrcodes') {
    if (!canAccessPage('qr_code')) {
        http_response_code(403);
        echo json_encode(['error' => 'Accès refusé']);
        exit;
    }
    // Lecture seule si pas de droit d'écriture
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !canDoAction('qrcode.write')) {
        http_response_code(403);
        echo json_encode(['error' => 'Action non autorisée (lecture seule)']);
        exit;
    }

    // Auto-migration idempotente : garantit la présence des colonnes ajoutées par
    // update.php (onsite_mode, payment_label, expires_at, send_qrcode). Évite l'erreur
    // « Unknown column 'onsite_mode' » sur une base qui n'a pas encore été migrée.
    try {
        $hasOnsite = (int) $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qrcodes' AND COLUMN_NAME = 'onsite_mode'"
        )->fetchColumn();
        if (!$hasOnsite) {
            foreach ([
                "ALTER TABLE `qrcodes` ADD COLUMN `onsite_mode` TINYINT(1) NOT NULL DEFAULT 0",
                "ALTER TABLE `qrcodes` ADD COLUMN `payment_label` VARCHAR(50) DEFAULT 'retrait t-shirt'",
                "ALTER TABLE `qrcodes` ADD COLUMN `expires_at` DATETIME DEFAULT NULL",
                "ALTER TABLE `qrcodes` ADD COLUMN `send_qrcode` TINYINT(1) NOT NULL DEFAULT 1",
            ] as $ddl) {
                try { $pdo->exec($ddl); } catch (\Throwable $e) { /* colonne déjà présente : ignore */ }
            }
        }
    } catch (\Throwable $e) { /* INFORMATION_SCHEMA inaccessible : on continue */ }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Récupération des QR codes - avec gestion d'erreurs
        try {
            $stmt = $pdo->prepare('SELECT * FROM qrcodes ORDER BY created_at DESC');
            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($result);
        } catch (Exception $e) {
            error_log('Erreur lors de la récupération des QR codes: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Erreur lors de la récupération des données']);
        }
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Création d'un nouveau QR code
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        // Fallback si JSON decode échoue
        if (!$data) {
            $data = $_POST;
        }
        
        // Validation
        if (empty($data['organisation']) || empty($data['base_url'])) {
            error_log('Données manquantes - Organisation: ' . ($data['organisation'] ?? 'vide') . ', Base URL: ' . ($data['base_url'] ?? 'vide'));
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Organisation et URL requis']);
            exit;
        }
        
        // Génération d'un token unique
        $maxAttempts = 10;
        $attempt = 0;
        do {
            $attempt++;
            $token = bin2hex(random_bytes(32));
            
            try {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM qrcodes WHERE token = ?');
                $stmt->execute([$token]);
                $exists = $stmt->fetchColumn() > 0;
            } catch (Exception $e) {
                error_log('Erreur lors de la vérification du token: ' . $e->getMessage());
                $exists = false; // Continue avec ce token
            }
            
            if ($attempt >= $maxAttempts) {
                error_log('Impossible de générer un token unique après ' . $maxAttempts . ' tentatives');
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Erreur lors de la génération du token']);
                exit;
            }
            
        } while ($exists);
        
        // Construction de l'URL finale
        $separator = strpos($data['base_url'], '?') !== false ? '&' : '?';
        $qr_url = $data['base_url'] . $separator . 'token=' . $token;
        
        try {
            // Vérification que la table existe
            $checkTable = $pdo->query("SHOW TABLES LIKE 'qrcodes'")->rowCount();
            if ($checkTable == 0) {
                error_log('Table qrcodes n\'existe pas');
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Table qrcodes non trouvée']);
                exit;
            }
            
            // Inscription « sur place » : mode prestation + méthode de paiement masquée.
            $onsiteMode   = !empty($data['onsite_mode']) ? 1 : 0;
            $paymentLabel = mb_substr(trim((string)($data['payment_label'] ?? 'retrait t-shirt')), 0, 50);
            if ($paymentLabel === '') $paymentLabel = 'retrait t-shirt';

            // Date de fermeture propre au QR (obligatoire). Accepte le format
            // datetime-local (YYYY-MM-DDTHH:MM) et le normalise en DATETIME MySQL.
            $expiresAt = normalizeQrExpiresAt($data['expires_at'] ?? '');
            if ($expiresAt === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Date de fermeture invalide ou manquante']);
                exit;
            }

            // Inclure le QR code dans le mail de confirmation (1) ou jamais (0). Par
            // défaut on suit la config du site (1). Absent = 1 (rétro-compat).
            $sendQrcode = array_key_exists('send_qrcode', $data) ? (!empty($data['send_qrcode']) ? 1 : 0) : 1;

            $stmt = $pdo->prepare(
                'INSERT INTO qrcodes (organisation, token, qr_url, description, onsite_mode, payment_label, expires_at, send_qrcode, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $result = $stmt->execute([
                $data['organisation'],
                $token,
                $qr_url,
                $data['description'] ?? null,
                $onsiteMode,
                $paymentLabel,
                $expiresAt,
                $sendQrcode,
                currentUserId() // Ajout de l'utilisateur créateur
            ]);
            
            if ($result) {
                $insertId = $pdo->lastInsertId();
                
                echo json_encode([
                    'success' => true,
                    'id' => $insertId,
                    'token' => $token,
                    'qr_url' => $qr_url,
                    'message' => 'QR Code créé avec succès'
                ]);
            } else {
                error_log('Échec de l\'insertion en base');
                echo json_encode(['success' => false, 'message' => 'Échec de l\'insertion']);
            }
            
        } catch (Exception $e) {
            error_log('Erreur lors de la création du QR code: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erreur base de données.']);
        }
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        // Modification d'un QR code
        parse_str(file_get_contents('php://input'), $data);
        
        if (empty($data['id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID requis']);
            exit;
        }
        
        $updates = [];
        $params = [];

        if (isset($data['is_active'])) {
            $updates[] = 'is_active = ?';
            $params[] = (int)$data['is_active'];
        }

        if (isset($data['description'])) {
            $updates[] = 'description = ?';
            $params[] = $data['description'];
        }

        if (isset($data['organisation'])) {
            $org = trim((string)$data['organisation']);
            if ($org === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Organisation requise']);
                exit;
            }
            $updates[] = 'organisation = ?';
            $params[] = mb_substr($org, 0, 255);
        }

        if (isset($data['onsite_mode'])) {
            $updates[] = 'onsite_mode = ?';
            $params[] = !empty($data['onsite_mode']) ? 1 : 0;
        }

        if (isset($data['send_qrcode'])) {
            $updates[] = 'send_qrcode = ?';
            $params[] = !empty($data['send_qrcode']) ? 1 : 0;
        }

        if (isset($data['payment_label'])) {
            $pl = mb_substr(trim((string)$data['payment_label']), 0, 50);
            if ($pl === '') $pl = 'retrait t-shirt';
            $updates[] = 'payment_label = ?';
            $params[] = $pl;
        }

        if (isset($data['expires_at'])) {
            $exp = normalizeQrExpiresAt($data['expires_at']);
            if ($exp === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Date de fermeture invalide']);
                exit;
            }
            $updates[] = 'expires_at = ?';
            $params[] = $exp;
        }

        if (!empty($updates)) {
            $params[] = $data['id'];
            $sql = 'UPDATE qrcodes SET ' . implode(', ', $updates) . ' WHERE id = ?';
            
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                error_log('Erreur lors de la mise à jour du QR code: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Aucune donnée à modifier']);
        }
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        // Déjà filtré plus haut : sans qrcode.write, on a été bloqué
        if (!canDoAction('qrcode.write')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Action non autorisée']);
            exit;
        }
        
        parse_str(file_get_contents('php://input'), $data);
        
        if (empty($data['id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID requis']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare('DELETE FROM qrcodes WHERE id = ?');
            $stmt->execute([$data['id']]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log('Erreur lors de la suppression du QR code: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Fonction pour valider un token QR code
if ($route === 'validate-qr-token') {
    $token = $_GET['token'] ?? '';
    
    if (empty($token)) {
        echo json_encode(['valid' => false, 'message' => 'Token manquant']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare(
            'SELECT organisation, description, is_active, created_at 
             FROM qrcodes 
             WHERE token = ? AND is_active = 1'
        );
        $stmt->execute([$token]);
        $qrData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($qrData) {
            echo json_encode([
                'valid' => true,
                'organisation' => $qrData['organisation'],
                'description' => $qrData['description']
            ]);
        } else {
            echo json_encode(['valid' => false, 'message' => 'Token invalide ou inactif']);
        }
    } catch (Exception $e) {
        error_log('Erreur lors de la validation du token: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['valid' => false, 'message' => 'Erreur serveur']);
    }
    exit;
}

/* ───── CAPTCHA — init (public, partagé entre formulaires) ─────────────────── */
if ($route === 'partner-captcha-init') {
    $fallback = !empty($_GET['fallback']);
    echo json_encode(issuePublicCaptcha($pdo, $fallback));
    exit;
}

/* ───── TURNSTILE — test interactif des 2 clés (admin) ────────────────────── */
if ($route === 'turnstile-test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!canAccessPage('setting') || !canDoAction('settings.write')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'forbidden', 'msg' => 'Accès refusé']);
        exit;
    }
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $token  = trim((string)($d['token']  ?? ''));
    // Secret prioritaire : ce qui est saisi dans le form ; sinon celui en BDD
    $secret = trim((string)($d['secret'] ?? ''));
    if ($secret === '') {
        $cfg = getTurnstileConfig($pdo);
        $secret = $cfg['secret'];
    }
    if ($secret === '') { echo json_encode(['ok' => false, 'err' => 'no_secret', 'msg' => 'Aucune clé secrète disponible.']); exit; }
    if ($token  === '') { echo json_encode(['ok' => false, 'err' => 'no_token',  'msg' => 'Token manquant — complétez le widget.']); exit; }

    // Appel direct siteverify pour récupérer les error-codes détaillés
    $payload = http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => getClientIp()]);
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $payload,
        'timeout' => 8,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
    if ($body === false) { echo json_encode(['ok' => false, 'err' => 'unreachable', 'msg' => 'Cloudflare injoignable.']); exit; }
    $j = json_decode($body, true) ?: [];

    if (!empty($j['success'])) {
        $host = $j['hostname'] ?? '';
        echo json_encode(['ok' => true, 'msg' => 'Site Key et Secret valides — Cloudflare a confirmé' . ($host ? " (hostname: $host)" : '') . '.']);
        exit;
    }
    $codes = $j['error-codes'] ?? [];
    $msg = 'Vérification refusée par Cloudflare';
    if (in_array('invalid-input-secret', $codes, true))  $msg = 'Clé secrète invalide.';
    elseif (in_array('invalid-input-response', $codes, true)) $msg = 'Token invalide (sitekey/domaine incompatible ?).';
    elseif (in_array('timeout-or-duplicate', $codes, true))   $msg = 'Token expiré ou déjà utilisé — rechargez et réessayez.';
    elseif (!empty($codes)) $msg .= ' (' . implode(', ', $codes) . ')';
    echo json_encode(['ok' => false, 'err' => 'invalid', 'msg' => $msg, 'codes' => $codes]);
    exit;
}

/* ───── DEMANDE PARTENARIAT (public) ─────────────────────── */
if ($route === 'partner-request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true);
    $email = trim($d['email'] ?? '');

    // Validation basique
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'err' => 'Adresse email invalide.']);
        exit;
    }

    // ───── Vérification anti-bot (Turnstile prioritaire, sinon maths) ─────
    if (!verifyPublicCaptcha($d, $pdo)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'err' => 'captcha']);
        exit;
    }

    // Validation email professionnel (refus des boîtes personnelles courantes)
    $confirmed = !empty($d['confirmed']);
    $freeDomains = [
        'gmail.com','googlemail.com','yahoo.com','yahoo.fr','yahoo.be','yahoo.co.uk',
        'hotmail.com','hotmail.fr','hotmail.be','hotmail.co.uk',
        'outlook.com','outlook.fr','outlook.be','live.com','live.fr','live.be',
        'msn.com','icloud.com','me.com','mac.com','aol.com',
        'free.fr','sfr.fr','orange.fr','wanadoo.fr','laposte.net',
        'bbox.fr','numericable.fr','club-internet.fr','alice.fr',
        'protonmail.com','proton.me','tutanota.com','tutamail.com',
        'yopmail.com','mailinator.com','guerrillamail.com','tempmail.com',
    ];
    $domain = strtolower(substr($email, strpos($email, '@') + 1));
    if (!$confirmed && in_array($domain, $freeDomains, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'err' => 'non_pro']);
        exit;
    }

    // Rate-limit : 3 demandes max par heure par IP
    $ip = getClientIp();
    $rlKey = md5('partner_' . $ip);
    $rlFile = sys_get_temp_dir() . '/fer_' . $rlKey . '.json';
    $rlWindow = 3600; $rlMax = 10;
    $rlTimes = [];
    if (@file_exists($rlFile)) {
        $rlTimes = json_decode(@file_get_contents($rlFile), true) ?: [];
    }
    $now = time();
    $rlTimes = array_values(array_filter($rlTimes, function($t) use ($now, $rlWindow) { return $t > $now - $rlWindow; }));
    if (count($rlTimes) >= $rlMax) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'err' => 'Trop de demandes. Réessayez dans une heure.']);
        exit;
    }
    $rlTimes[] = $now;
    @file_put_contents($rlFile, json_encode($rlTimes));

    // Envoi du mail aux administrateurs
    try {
        require_once __DIR__ . '/src/mail/googleMail.php';

        // Provider-agnostic : isMailConfigured() gère Google OAuth ET SMTP direct
        if (isMailConfigured() && isNotifyEnabled($pdo, 'partner')) {
            $admins = getNotifyRecipients($pdo);

            $subject = 'Nouvelle demande de partenariat – Forbach en Rose';
            $body  = '<h2>Nouvelle demande de partenariat</h2>';
            $body .= '<p>Une entreprise souhaite devenir partenaire de Forbach en Rose.</p>';
            $body .= '<p><strong>Email :</strong> ' . htmlspecialchars($email) . '</p>';
            $body .= '<p><strong>Domaine :</strong> ' . htmlspecialchars($domain) . '</p>';
            $body .= '<p><strong>Date :</strong> ' . date('d/m/Y à H:i') . '</p>';
            $body .= '<hr><p style="color:#888;font-size:12px">Message automatique – Forbach en Rose</p>';

            foreach ($admins as $adminEmail) {
                if ($adminEmail && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                    sendMail($adminEmail, $subject, 'Nouvelle demande de partenariat', $body, null, null, 'info', null, 'test');
                }
            }
        } else {
            error_log('Partner request from ' . $email . ': mail not configured or notification disabled, admin notification skipped.');
        }
    } catch (\Throwable $e) {
        error_log('Partner request mail error: ' . $e->getMessage());
        // On ne bloque pas la réponse si le mail échoue
    }

    echo json_encode(['ok' => true, 'message' => 'Votre demande a bien été envoyée ! Nous vous recontacterons rapidement.']);
    exit;
}

/* ---------- Toggle débogage ---------- */
if ($route === 'toggle-debogage' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!canAccessPage('setting') || !canDoAction('settings.write')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Accès refusé']);
        exit;
    }
    $d = json_decode(file_get_contents('php://input'), true);
    $val = !empty($d['debogage']) ? 1 : 0;
    $pdo->prepare('UPDATE setting SET debogage = ? WHERE id = 1')->execute([$val]);
    echo json_encode(['ok' => true, 'debogage' => $val]);
    exit;
}

/* ───── IP GEOLOCATION (admin/connexions) ─────────────────────────
 * Route serveur qui géolocalise une liste d'IPs avec cache fichier 30 jours.
 * Utilisée par inc/connexions.php pour afficher (Ville, Pays) à côté
 * des adresses IP des logs / IPs bannies / appareils.
 *
 * Sécurité : nécessite l'accès à la page connexions.
 * Body JSON : { "ips": ["1.2.3.4", "5.6.7.8", ...] }
 * Réponse   : { "ok": true, "geo": { "1.2.3.4": {"city":"Paris","country":"France"}, ... } }
 * ──────────────────────────────────────────────────────────────── */
if ($route === 'ip-geo') {
    if (!canAccessPage('connexions')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Accès refusé']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'err' => 'Méthode non autorisée']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $ips   = $input['ips'] ?? [];
    if (!is_array($ips)) $ips = [];
    // Limite anti-abus : max 200 IPs par requête
    $ips = array_slice(array_unique(array_filter($ips, 'is_string')), 0, 200);

    // Cache fichier (30 jours)
    $cacheFile = __DIR__ . '/storage/logs/ip_geo_cache.json';
    $cacheTtl  = 30 * 86400;
    $cache = [];
    if (file_exists($cacheFile)) {
        $cache = json_decode(@file_get_contents($cacheFile), true) ?: [];
    }
    $now = time();
    $result = [];
    $toFetch = [];

    foreach ($ips as $ip) {
        // IP privée / locale → pas de lookup
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            $result[$ip] = ['city' => null, 'country' => '__local__'];
            continue;
        }
        // Cache hit
        if (isset($cache[$ip]) && isset($cache[$ip]['t']) && ($now - $cache[$ip]['t']) < $cacheTtl) {
            $result[$ip] = ['city' => $cache[$ip]['city'] ?? null, 'country' => $cache[$ip]['country'] ?? null];
            continue;
        }
        $toFetch[] = $ip;
    }

    // Lookups réseau pour les IPs non cachées
    // Chaîne de fallback : ipwho.is → freeipapi.com → ipapi.co
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 2,
            'method'  => 'GET',
            'header'  => "User-Agent: FER-Admin/1.0\r\nAccept: application/json\r\n",
        ],
    ]);

    foreach ($toFetch as $ip) {
        $geo = null;

        // Tentative 1 : ipwho.is (riche en données, gratuit, HTTPS sans clé)
        $raw = @file_get_contents("https://ipwho.is/" . urlencode($ip), false, $ctx);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data['success'])) {
                $geo = [
                    'city'    => $data['city']    ?? null,
                    'country' => $data['country'] ?? null,
                ];
            }
        }

        // Tentative 2 : freeipapi.com
        if ($geo === null || (empty($geo['city']) && empty($geo['country']))) {
            $raw = @file_get_contents("https://freeipapi.com/api/json/" . urlencode($ip), false, $ctx);
            if ($raw !== false) {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $geo = [
                        'city'    => $data['cityName']    ?? null,
                        'country' => $data['countryName'] ?? null,
                    ];
                }
            }
        }

        // Tentative 3 : ipapi.co
        if ($geo === null || (empty($geo['city']) && empty($geo['country']))) {
            $raw = @file_get_contents("https://ipapi.co/{$ip}/json/", false, $ctx);
            if ($raw !== false) {
                $data = json_decode($raw, true);
                if (is_array($data) && empty($data['error'])) {
                    $geo = [
                        'city'    => $data['city']         ?? null,
                        'country' => $data['country_name'] ?? null,
                    ];
                }
            }
        }

        if ($geo === null) $geo = ['city' => null, 'country' => null];

        $cache[$ip] = ['city' => $geo['city'], 'country' => $geo['country'], 't' => $now];
        $result[$ip] = $geo;
    }

    // Sauvegarder le cache (purge des entrées trop vieilles tant qu'on y est)
    if (!empty($toFetch)) {
        foreach ($cache as $k => $v) {
            if (empty($v['t']) || ($now - $v['t']) > $cacheTtl) unset($cache[$k]);
        }
        @file_put_contents($cacheFile, json_encode($cache), LOCK_EX);
    }

    echo json_encode(['ok' => true, 'geo' => $result]);
    exit;
}

/* ═══════════════════════════════════════════════════════════════════════════
 * ACCÈS « REMISE T-SHIRTS » POUR BÉNÉVOLES (sans compte)
 * ---------------------------------------------------------------------------
 * Un bénévole ouvre public/remise-tshirts.php (QR ou lien), saisit son nom et
 * demande l'accès. Un admin (ou un utilisateur ayant la page `tshirt_access`)
 * valide. La session est liée à un cookie d'appareil (fer_tshirt) + au token de
 * campagne courant : régénérer le token invalide toutes les sessions.
 *
 * 🔒 SÉCURITÉ DES DONNÉES : la session bénévole ne peut atteindre QUE tshirt-lookup
 *    (un inscrit à la fois, champs minimaux — jamais email/tel/montant) et
 *    tshirt-assign (enregistre une taille). Elle n'atteint jamais la liste complète.
 * ═══════════════════════════════════════════════════════════════════════════ */

/** Config à ligne unique (id=1). */
function _tshirtCfg(PDO $pdo): array {
    try {
        $c = $pdo->query("SELECT * FROM tshirt_access WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$c) {
            $pdo->exec("INSERT IGNORE INTO tshirt_access (id) VALUES (1)");
            $c = $pdo->query("SELECT * FROM tshirt_access WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        return $c;
    } catch (\Throwable $e) { return []; }
}

/** L'accès est-il ouvert (activé et non expiré) ? */
function _tshirtOpen(array $cfg): bool {
    if (empty($cfg['enabled'])) return false;
    if (!empty($cfg['expires_at']) && strtotime($cfg['expires_at']) < time()) return false;
    return !empty($cfg['campaign_token']);
}

/** Identifiant d'appareil (cookie). */
function _tshirtDeviceId(): string {
    return (string)($_COOKIE['fer_tshirt'] ?? '');
}

/** Session bénévole approuvée et valide pour l'appareil courant, ou null. */
function _tshirtSession(PDO $pdo, array $cfg): ?array {
    if (!_tshirtOpen($cfg)) return null;
    $device = _tshirtDeviceId();
    if ($device === '') return null;
    try {
        $st = $pdo->prepare("SELECT * FROM tshirt_access_sessions
                              WHERE device_id = ? AND campaign_token = ? AND status = 'approved' LIMIT 1");
        $st->execute([$device, $cfg['campaign_token']]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { return null; }
    if (!$s) return null;
    if (!empty($s['expires_at']) && strtotime($s['expires_at']) < time()) return null;
    return $s;
}

/**
 * Autorisation de scan : soit un bénévole validé (cookie appareil + campagne ouverte),
 * soit un ADMIN connecté disposant du droit dashboard.scan_qr — accès direct au scanner
 * sans passer par le circuit de validation bénévole ni exiger une campagne ouverte.
 * Renvoie une pseudo-session pour l'admin (id 0), sinon la session bénévole (ou null).
 */
function _tshirtScanAuth(PDO $pdo, array $cfg): ?array {
    if (function_exists('canDoAction') && canDoAction('dashboard.scan_qr')) {
        $name = 'Admin';
        try {
            $uid = currentUserId();
            if ($uid) {
                $st = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
                $st->execute([$uid]);
                $em = $st->fetchColumn();
                if ($em) $name = (string)$em;
            }
        } catch (\Throwable $e) { /* label par défaut */ }
        return ['id' => 0, 'volunteer_name' => $name, 'device_id' => 'admin:' . (int)(currentUserId() ?? 0), 'is_admin' => true];
    }
    return _tshirtSession($pdo, $cfg);
}

/**
 * Construit le jeu de données déchiffré + rangs de paiement (éligibilité T-shirt).
 * Déchiffrement côté serveur uniquement — jamais renvoyé en bloc au bénévole.
 */
function _tshirtDataset(PDO $pdo): array {
    $setting = $pdo->query("SELECT qrcode_mail_mode, qrcode_mail_limit FROM setting WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $highlightLimit = (($setting['qrcode_mail_mode'] ?? '') === 'first_x' && (int)($setting['qrcode_mail_limit'] ?? 0) > 0)
        ? (int)$setting['qrcode_mail_limit'] : 0;

    // `group_id` inclus si la colonne existe (repli silencieux avant migration).
    try {
        $rows = $pdo->query("SELECT id, inscription_no, nom, prenom, email, ville, tshirt_size, montant_du, created_at, group_id FROM registrations")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        $rows = $pdo->query("SELECT id, inscription_no, nom, prenom, email, ville, tshirt_size, montant_du, created_at FROM registrations")->fetchAll(PDO::FETCH_ASSOC);
    }
    $rows = decryptRows($rows);
    usort($rows, function ($a, $b) {
        $c = strcmp((string)$a['created_at'], (string)$b['created_at']);
        return $c !== 0 ? $c : ($a['id'] <=> $b['id']);
    });
    $paidCount = 0;
    foreach ($rows as &$r) {
        $paid = (float)$r['montant_du'] > 0;
        if ($paid) $paidCount++;
        $r['_paid']      = $paid;
        $r['_paid_rank'] = $paid ? $paidCount : -1;
        $r['_eligible']  = $paid && ($highlightLimit === 0 || $r['_paid_rank'] <= $highlightLimit);
    }
    unset($r);
    return [$rows, $highlightLimit];
}

/** Réduit un inscrit aux champs strictement nécessaires à la remise (anti-fuite). */
function _tshirtPublicPerson(array $r, int $highlightLimit): array {
    return [
        'id'              => (int)$r['id'],
        'inscription_no'  => (string)$r['inscription_no'],
        'prenom'          => (string)$r['prenom'],
        'nom'             => (string)$r['nom'],
        'ville'           => (string)$r['ville'],
        'tshirt_size'     => ($r['tshirt_size'] ?? '') !== '' ? $r['tshirt_size'] : '-',
        'paid'            => (bool)$r['_paid'],
        'paid_rank'       => (int)$r['_paid_rank'],
        'eligible'        => (bool)$r['_eligible'],
        'highlight_limit' => $highlightLimit,
    ];
}

/* ---------- Bénévole : demander l'accès ---------- */
if ($route === 'tshirt-access-request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg = _tshirtCfg($pdo);
    if (!_tshirtOpen($cfg)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'status' => 'closed', 'err' => 'Accès fermé']);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $name = mb_substr(trim((string)($body['name'] ?? '')), 0, 120);
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'err' => 'Nom requis']);
        exit;
    }

    // Rate limit (par IP) sur les demandes d'accès anonymes.
    $ip = getClientIp();
    $rlFile = sys_get_temp_dir() . '/fer_tsa_' . substr(hash('sha256', $ip), 0, 32) . '.json';
    $rlTimes = @file_exists($rlFile) ? (json_decode(@file_get_contents($rlFile), true) ?: []) : [];
    $now = time();
    $rlTimes = array_values(array_filter($rlTimes, fn($t) => $t > $now - 3600));
    if (count($rlTimes) >= 20) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'err' => 'Trop de tentatives. Réessayez plus tard.']);
        exit;
    }
    $rlTimes[] = $now;
    @file_put_contents($rlFile, json_encode($rlTimes));

    $device = _tshirtDeviceId();
    if ($device === '') {
        $device = bin2hex(random_bytes(32));
        setcookie('fer_tshirt', $device, [
            'expires'  => time() + 7 * 86400,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $token = $cfg['campaign_token'];
    $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    // Session déjà approuvée pour cet appareil → on la renvoie telle quelle.
    $st = $pdo->prepare("SELECT status FROM tshirt_access_sessions WHERE device_id = ? AND campaign_token = ? LIMIT 1");
    $st->execute([$device, $token]);
    $existing = $st->fetchColumn();
    if ($existing === 'approved') {
        echo json_encode(['ok' => true, 'status' => 'approved']);
        exit;
    }

    // Sinon : (ré)initialise une demande en attente pour cet appareil.
    $pdo->prepare(
        "INSERT INTO tshirt_access_sessions (campaign_token, device_id, volunteer_name, status, created_at, ip, user_agent)
         VALUES (?, ?, ?, 'pending', NOW(), ?, ?)
         ON DUPLICATE KEY UPDATE volunteer_name = VALUES(volunteer_name), status = 'pending',
                                 created_at = NOW(), ip = VALUES(ip), user_agent = VALUES(user_agent)"
    )->execute([$token, $device, $name, $ip, $ua]);

    echo json_encode(['ok' => true, 'status' => 'pending']);
    exit;
}

/* ---------- Bénévole : statut de sa demande (polling) ---------- */
if ($route === 'tshirt-access-status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $cfg = _tshirtCfg($pdo);
    $open = _tshirtOpen($cfg);
    $out = ['ok' => true, 'open' => $open, 'status' => 'none'];
    // Admin connecté (droit scan_qr) : toujours « approuvé », campagne réputée ouverte.
    if (function_exists('canDoAction') && canDoAction('dashboard.scan_qr')) {
        $opName = 'Admin';
        try {
            $uid = currentUserId();
            if ($uid) { $st = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1'); $st->execute([$uid]); $em = $st->fetchColumn(); if ($em) $opName = (string)$em; }
        } catch (\Throwable $e) { /* label par défaut */ }
        $out['open'] = true; $out['status'] = 'approved'; $out['admin'] = true; $out['operator'] = $opName;
        echo json_encode($out);
        exit;
    }
    $device = _tshirtDeviceId();
    if ($open && $device !== '') {
        $st = $pdo->prepare("SELECT status, expires_at, volunteer_name FROM tshirt_access_sessions WHERE device_id = ? AND campaign_token = ? LIMIT 1");
        $st->execute([$device, $cfg['campaign_token']]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if ($s) {
            if ($s['status'] === 'approved' && !empty($s['expires_at']) && strtotime($s['expires_at']) < time()) {
                $out['status'] = 'expired';
            } else {
                $out['status'] = $s['status'];
            }
            if ($out['status'] === 'approved') $out['operator'] = $s['volunteer_name'] ?? '';
        }
    }
    echo json_encode($out);
    exit;
}

/* ---------- Bénévole : rechercher un inscrit (QR / nom / prénom / email / N°) ---------- */
if ($route === 'tshirt-lookup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg = _tshirtCfg($pdo);
    $session = _tshirtScanAuth($pdo, $cfg);
    if (!$session) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Accès non validé']);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $q = trim((string)($body['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        echo json_encode(['ok' => true, 'results' => []]);
        exit;
    }

    // touch last_seen
    $pdo->prepare("UPDATE tshirt_access_sessions SET last_seen = NOW() WHERE id = ?")->execute([$session['id']]);

    [$rows, $highlightLimit] = _tshirtDataset($pdo);
    $ql = mb_strtolower($q);

    // 0) QR « groupé » (G:<group_id>) → renvoie TOUS les membres du groupe.
    if (preg_match('/^G:(.+)$/', $q, $gm)) {
        $gid = $gm[1];
        $members = [];
        foreach ($rows as $r) {
            if (!empty($r['group_id']) && (string)$r['group_id'] === $gid) {
                $members[] = _tshirtPublicPerson($r, $highlightLimit);
            }
        }
        echo json_encode(['ok' => true, 'group' => true, 'results' => $members]);
        exit;
    }

    // 1) Correspondance exacte par numéro d'inscription (QR ou saisie du N°).
    foreach ($rows as $r) {
        $no = mb_strtolower((string)$r['inscription_no']);
        if ($no === $ql || $no === 'e' . $ql || $no === 's' . $ql) {
            echo json_encode(['ok' => true, 'results' => [_tshirtPublicPerson($r, $highlightLimit)]]);
            exit;
        }
    }

    // 2) Recherche par sous-chaîne sur prénom / nom / email / numéro.
    $matches = [];
    foreach ($rows as $r) {
        $hay = mb_strtolower(
            (string)$r['inscription_no'] . ' ' . (string)$r['prenom'] . ' ' .
            (string)$r['nom'] . ' ' . (string)$r['email']
        );
        if (mb_strpos($hay, $ql) !== false) {
            $matches[] = _tshirtPublicPerson($r, $highlightLimit);
            if (count($matches) >= 12) break;
        }
    }
    echo json_encode(['ok' => true, 'results' => $matches]);
    exit;
}

/* ---------- Bénévole : enregistrer la taille (confirmer la remise) ---------- */
if ($route === 'tshirt-assign' && in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'])) {
    $cfg = _tshirtCfg($pdo);
    $session = _tshirtScanAuth($pdo, $cfg);
    if (!$session) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Accès non validé']);
        exit;
    }
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) { parse_str($raw, $body); }
    $id   = (int)($body['id'] ?? 0);
    $size = (string)($body['size'] ?? '');
    $allowed = ['-', 'XS', 'S', 'M', 'L', 'XL', 'XXL'];
    if (!$id || !in_array($size, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'err' => 'Paramètres invalides']);
        exit;
    }
    $st = $pdo->prepare("SELECT inscription_no, montant_du FROM registrations WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'err' => 'Inscrit introuvable']);
        exit;
    }
    $no = $row['inscription_no'];
    // 🔒 [SEC-TSHIRT] Un inscrit non payé (montant_du = 0) n'est jamais éligible au
    // t-shirt : on refuse l'attribution d'une taille réelle par un bénévole. Un admin
    // conserve la possibilité de forcer (override légitime, ex. régularisation).
    if ($size !== '-' && (float)($row['montant_du'] ?? 0) <= 0 && empty($session['is_admin'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Inscrit non payé : non éligible au t-shirt. Contactez un administrateur.']);
        exit;
    }
    $pdo->prepare("UPDATE registrations SET tshirt_size = ? WHERE id = ?")->execute([$size, $id]);
    try {
        $pdo->prepare(
            "INSERT INTO tshirt_handout_log (registration_id, inscription_no, size, volunteer_name, device_id)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$id, $no, $size, $session['volunteer_name'] ?? null, $session['device_id'] ?? null]);
    } catch (\Throwable $e) { /* le log ne doit jamais bloquer la remise */ }
    $pdo->prepare("UPDATE tshirt_access_sessions SET last_seen = NOW() WHERE id = ?")->execute([$session['id']]);
    echo json_encode(['ok' => true]);
    exit;
}

/* ---------- Admin : gestion de l'accès bénévoles ---------- */
if ($route === 'tshirt-admin') {
    if (!canAccessPage('tshirt_access')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Accès refusé']);
        exit;
    }
    $cfg = _tshirtCfg($pdo);

    // Droits granulaires de la page « Accès bénévoles ».
    $tsCanManage  = canDoAction('tshirt_access.manage');
    $tsCanApprove = canDoAction('tshirt_access.approve');
    $tsCanRevoke  = canDoAction('tshirt_access.devices_revoke');
    // « Révoquer » implique « voir les connectés ».
    $tsCanDevices = canDoAction('tshirt_access.devices_view') || $tsCanRevoke;

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['export'] ?? '') === 'handouts') {
        // Export CSV des remises (UTF-8 BOM + séparateur « ; » → ouverture directe
        // dans Excel FR). Accessible à quiconque a accès à la page.
        $rows = $pdo->query("SELECT h.inscription_no, r.nom, r.prenom, h.size, h.volunteer_name, h.created_at
                               FROM tshirt_handout_log h
                               LEFT JOIN registrations r ON r.inscription_no = h.inscription_no
                              ORDER BY h.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        // 🔒 [FIX-PII] nom/prenom sont chiffrés (AES-GCM) en base : déchiffrer avant export.
        $rows = decryptRows($rows);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="remises-tshirts.csv"');
        echo "\xEF\xBB\xBF"; // BOM UTF-8 pour Excel
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['N° inscription', 'Nom', 'Prénom', 'Taille', 'Bénévole', 'Date'], ';');
        foreach ($rows as $r) {
            fputcsv($fp, [$r['inscription_no'], $r['nom'] ?? '', $r['prenom'] ?? '', $r['size'], $r['volunteer_name'] ?? '', $r['created_at']], ';');
        }
        fclose($fp);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // La lecture de base (page access) donne uniquement « Dernières remises ».
        // Chaque bloc supplémentaire est renvoyé seulement si le droit correspondant
        // est accordé (évite d'exposer token / demandes / appareils sans permission).
        // nom/prénom joints depuis registrations (LEFT JOIN : une remise reste
        // affichée même si l'inscrit a été supprimé entre-temps).
        // 🔒 [FIX-PII] nom/prenom chiffrés (AES-GCM) en base → déchiffrer avant affichage.
        $handouts = $pdo->query("SELECT h.inscription_no, h.size, h.volunteer_name, h.created_at, r.nom, r.prenom
                                   FROM tshirt_handout_log h
                                   LEFT JOIN registrations r ON r.inscription_no = h.inscription_no
                                  ORDER BY h.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        $handouts = decryptRows($handouts);
        $out = [
            'ok'    => true,
            'perms' => [
                'manage'         => $tsCanManage,
                'approve'        => $tsCanApprove,
                'devices_view'   => $tsCanDevices,
                'devices_revoke' => $tsCanRevoke,
            ],
            'handouts' => $handouts,
        ];
        if ($tsCanManage) {
            $out['config'] = [
                'enabled'    => (int)($cfg['enabled'] ?? 0),
                'token'      => $cfg['campaign_token'] ?? null,
                'opened_at'  => $cfg['opened_at'] ?? null,
                'expires_at' => $cfg['expires_at'] ?? null,
                'open'       => _tshirtOpen($cfg),
            ];
        }
        if ($tsCanApprove) {
            $st = $pdo->prepare("SELECT id, volunteer_name, created_at, ip FROM tshirt_access_sessions
                                  WHERE campaign_token = ? AND status = 'pending' ORDER BY created_at ASC");
            $st->execute([$cfg['campaign_token'] ?? '']);
            $out['pending'] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($tsCanDevices) {
            $st = $pdo->prepare("SELECT id, volunteer_name, approved_at, last_seen, expires_at FROM tshirt_access_sessions
                                  WHERE campaign_token = ? AND status = 'approved' ORDER BY approved_at DESC");
            $st->execute([$cfg['campaign_token'] ?? '']);
            $out['active'] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode($out);
        exit;
    }

    // POST : actions
    $body   = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = (string)($body['action'] ?? '');

    // Chaque action exige son droit dédié.
    $tsDeny = function () {
        http_response_code(403);
        echo json_encode(['ok' => false, 'err' => 'Action non autorisée']);
        exit;
    };
    if (in_array($action, ['toggle', 'regen', 'set_expiry', 'clear_handouts'], true) && !$tsCanManage) $tsDeny();
    if (in_array($action, ['approve', 'refuse'], true) && !$tsCanApprove)             $tsDeny();
    if ($action === 'revoke' && !$tsCanRevoke)                                        $tsDeny();

    if ($action === 'clear_handouts') {
        // Vide le journal des remises (ex. avant une nouvelle session). TRUNCATE si
        // possible, repli DELETE si une contrainte l'empêche.
        try { $pdo->exec('TRUNCATE TABLE tshirt_handout_log'); }
        catch (\Throwable $e) { $pdo->exec('DELETE FROM tshirt_handout_log'); }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'toggle') {
        $enable = !empty($body['enabled']);
        if ($enable) {
            // Activer : réutilise le token courant si la fenêtre est encore valide
            // (reprise après une coupure), mais en génère un nouveau si aucun token
            // ou si la fenêtre précédente a expiré (= nouvelle campagne, anciens
            // appareils invalidés).
            $expired = !empty($cfg['expires_at']) && strtotime($cfg['expires_at']) < time();
            $token   = (empty($cfg['campaign_token']) || $expired) ? bin2hex(random_bytes(24)) : $cfg['campaign_token'];
            $days    = max(1, min(31, (int)($body['days'] ?? 7)));
            $pdo->prepare("UPDATE tshirt_access SET enabled = 1, campaign_token = ?, opened_at = NOW(),
                            expires_at = DATE_ADD(NOW(), INTERVAL {$days} DAY) WHERE id = 1")->execute([$token]);
        } else {
            $pdo->prepare("UPDATE tshirt_access SET enabled = 0 WHERE id = 1")->execute();
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'regen') {
        // Nouveau token = toutes les anciennes sessions deviennent invalides.
        $token = bin2hex(random_bytes(24));
        $days  = max(1, min(31, (int)($body['days'] ?? 7)));
        $pdo->prepare("UPDATE tshirt_access SET enabled = 1, campaign_token = ?, opened_at = NOW(),
                        expires_at = DATE_ADD(NOW(), INTERVAL {$days} DAY) WHERE id = 1")->execute([$token]);
        echo json_encode(['ok' => true, 'token' => $token]);
        exit;
    }

    if ($action === 'set_expiry') {
        $days = max(1, min(31, (int)($body['days'] ?? 7)));
        $pdo->prepare("UPDATE tshirt_access SET expires_at = DATE_ADD(NOW(), INTERVAL {$days} DAY) WHERE id = 1")->execute();
        echo json_encode(['ok' => true]);
        exit;
    }

    if (in_array($action, ['approve', 'refuse', 'revoke'], true)) {
        $sid = (int)($body['id'] ?? 0);
        if (!$sid) { http_response_code(400); echo json_encode(['ok' => false, 'err' => 'id manquant']); exit; }
        if ($action === 'approve') {
            $pdo->prepare("UPDATE tshirt_access_sessions
                            SET status = 'approved', approved_at = NOW(), approved_by = ?, expires_at = ?
                          WHERE id = ? AND campaign_token = ?")
                ->execute([currentUserId(), $cfg['expires_at'] ?? null, $sid, $cfg['campaign_token'] ?? '']);
        } else {
            $pdo->prepare("UPDATE tshirt_access_sessions SET status = 'refused'
                          WHERE id = ? AND campaign_token = ?")
                ->execute([$sid, $cfg['campaign_token'] ?? '']);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'err' => 'Action inconnue']);
    exit;
}

http_response_code(404);
echo json_encode(['error'=>'route inconnue']);
