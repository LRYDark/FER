<?php
require 'config.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/captcha.php';
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
$csrfExemptRoutes = ['login', 'login-check-email', 'validate-2fa', 'resend-2fa', 'validate-totp', 'webauthn-auth-verify', 'switch-2fa-method', 'forgot-password', 'reset-password-confirm', 'partner-request', 'partner-captcha-init'];
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
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function checkTrustedDevice($pdo, $userId) {
    try {
        $token = $_COOKIE['fer_trust'] ?? '';
        if (!$token) return false;
        $st = $pdo->prepare('SELECT 1 FROM trusted_devices WHERE user_id = ? AND token = ? AND expires_at > NOW() LIMIT 1');
        $st->execute([$userId, $token]);
        return (bool) $st->fetch();
    } catch (\Throwable $e) { return false; }
}

function createTrustedDevice($pdo, $userId) {
    try {
        $token = bin2hex(random_bytes(32));
        $ip = getClientIp();
        $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $pdo->prepare('INSERT INTO trusted_devices (user_id, token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))')
            ->execute([$userId, $token, $ip, $ua]);
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
        if (!file_exists(__DIR__ . '/token.json')) return false;
        return !empty(decrypt($row['client_id'] ?? null)) && !empty(decrypt($row['client_secret'] ?? null));
    } catch (\Throwable $e) { return false; }
}

function send2faCode($pdo, $user) {
    try {
        require_once __DIR__ . '/googleMail.php';
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
        require_once __DIR__ . '/googleMail.php';
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
            require_once __DIR__ . '/webauthn.php';
            $wa   = new WebAuthn(getWebAuthnRpId());
            $opts = $wa->authOptions($passkeyIds);
            echo json_encode(['ok'=>true, 'requires_2fa'=>true, 'method'=>'passkey', 'options'=>$opts]); exit;
        }
    }

    $extra = [];
    if ($defaultMethod === 'passkey') {
        require_once __DIR__ . '/webauthn.php';
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
    $passwordVerified = !$hasStrongMethod && password_verify($d['password'] ?? '', $u['password_hash']);

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
                    require_once __DIR__ . '/webauthn.php';
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
                require_once __DIR__ . '/webauthn.php';
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
            try {
                $pdo->prepare('UPDATE users SET failed_attempts = ?, is_active = 0, locked_at = NOW() WHERE id = ?')
                    ->execute([$attempts, $u['id']]);
            } catch (\Throwable $e) {
                try { $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$u['id']]); } catch (\Throwable $e2) {}
            }
            try {
                require_once __DIR__ . '/googleMail.php';
                if (isMailConfigured() && isNotifyEnabled($pdo, 'lock')) {
                    $admins = getNotifyRecipients($pdo);
                    foreach ($admins as $adminEmail) {
                        sendMail($adminEmail, 'Compte verrouille – Forbach en Rose', 'Compte verrouille apres 3 tentatives',
                            '<p>Le compte <strong>' . htmlspecialchars($u['email']) . '</strong> a ete verrouille automatiquement apres 3 tentatives de connexion echouees.</p>'
                            . '<p>IP : ' . htmlspecialchars($ip) . '</p>', null, null, 'info', null, 'test');
                    }
                }
            } catch (\Throwable $e) { error_log('Lock notification mail error: ' . $e->getMessage()); }
            http_response_code(403);
            echo json_encode(['ok'=>false, 'err'=>'Compte verrouille apres 3 tentatives echouees. Utilisez "Mot de passe oublie" pour reactiver votre compte.']); exit;
        } else {
            try { $pdo->prepare('UPDATE users SET failed_attempts = ? WHERE id = ?')->execute([$attempts, $u['id']]); } catch (\Throwable $e) {}
            $remaining = 3 - $attempts;
            http_response_code(401);
            echo json_encode(['ok'=>false, 'err'=>'Identifiants incorrects. Il vous reste ' . $remaining . ' tentative(s) avant blocage du compte.']); exit;
        }
    }

    // 🔒 [FIX-ENUM] Message uniforme — ne pas révéler si l'email existe (CWE-204)
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
        require_once __DIR__ . '/webauthn.php';
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
    require_once __DIR__ . '/totp.php';
    $row = $pdo->prepare('SELECT totp_secret FROM users WHERE id = ? AND totp_enabled = 1');
    $row->execute([$uid]); $row = $row->fetch();

    $matchedCounter = $row ? TOTP::verify($row['totp_secret'] ?? '', $code) : false;
    if ($matchedCounter === false) {
        http_response_code(401);
        echo json_encode(['ok'=>false, 'err'=>'Code invalide.']); exit;
    }
    // 🔒 Anti-replay : rejeter un code déjà utilisé dans cette session pending
    $lastUsedCounter = $_SESSION['totp_used_counter'] ?? PHP_INT_MIN;
    if ($matchedCounter <= $lastUsedCounter) {
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

    require_once __DIR__ . '/webauthn.php';
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

        $pdo->prepare('UPDATE users SET reset_token = ?, reset_token_expires = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?')
            ->execute([$token, $user['id']]);

        // Envoyer le mail si Gmail est configuré
        try {
            require_once __DIR__ . '/googleMail.php';
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
                      . '<p><em>Ce lien expire dans 10 minutes.</em></p>',
                    null, null, 'info'
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

/* ───── PROFILE: SETUP TOTP ────────────────────── */
if ($route==='profile-setup-totp' && $_SERVER['REQUEST_METHOD']==='POST'){
    if (!isset($_SESSION['uid'])) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }
    require_once __DIR__ . '/totp.php';
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
    require_once __DIR__ . '/totp.php';
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
    require_once __DIR__ . '/webauthn.php';
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
    require_once __DIR__ . '/webauthn.php';
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

    $stmt = $pdo->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > NOW()');
    $stmt->execute([$token]);
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

    // Réactiver le compte uniquement s'il était verrouillé auto (locked_at non nul),
    // pas s'il a été désactivé manuellement par un admin (is_active=0, locked_at=NULL).
    $pdo->prepare('UPDATE users SET
        password_hash       = ?,
        must_change_password = 0,
        reset_token         = NULL,
        reset_token_expires = NULL,
        failed_attempts     = 0,
        locked_at           = NULL,
        is_active           = IF(locked_at IS NOT NULL, 1, is_active)
    WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
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

        $tempPassword = generateTemporaryPassword();

        $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1, is_active = 1, failed_attempts = 0, locked_at = NULL WHERE id = ?')
            ->execute([password_hash($tempPassword, PASSWORD_DEFAULT), $id]);

        // Récupérer l'email pour envoi
        $userStmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $userStmt->execute([$id]);
        $userEmail = $userStmt->fetchColumn();

        $emailSent = false;
        if ($userEmail) {
            try {
                require_once __DIR__ . '/googleMail.php';
                if (isMailConfigured()) {
                    $emailSent = sendMail(
                        $userEmail,
                        'Réinitialisation de votre mot de passe – Forbach en Rose',
                        'Mot de passe réinitialisé',
                        '<p>Votre mot de passe a été réinitialisé.</p>'
                          . '<p><strong>Nouveau mot de passe temporaire :</strong> ' . htmlspecialchars($tempPassword) . '</p>'
                          . '<p>Vous devrez changer votre mot de passe lors de votre prochaine connexion.</p>',
                        null, null, 'info', null, 'new_user'
                    );
                }
            } catch (Exception $e) {
                error_log('Reset password mail error: ' . $e->getMessage());
            }
        }

        // 🔒 [FIX-PWD-EXPOSE] Ne retourner le MDP temporaire que si l'email n'a pas été envoyé (CWE-319)
        $response = ['ok' => true, 'email_sent' => $emailSent];
        if (!$emailSent) {
            $response['temp_password'] = $tempPassword;
        }
        echo json_encode($response);
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
            require_once __DIR__ . '/googleMail.php';
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

/* ───── REGISTRATIONS ────────────────────────── */
if ($route==='registrations'){
    /* GET : accessible si l'utilisateur a accès au dashboard */
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if (!canAccessPage('dashboard')) {
            http_response_code(403);
            echo json_encode(['error' => 'Accès refusé']);
            exit;
        }
        // Le rôle "saisie" ne voit que ses propres inscriptions
        if (currentRole() === 'saisie') {
            $st = $pdo->prepare("SELECT * FROM registrations WHERE created_by = ? ORDER BY CAST(REPLACE(REPLACE(inscription_no, 'S', ''), 'E', '') AS UNSIGNED) DESC");
            $st->execute([currentUserId()]);
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

        // 🔒 [FIX-VALIDATION] Validation et assainissement des champs d'inscription (CWE-20)
        $allowedSexe = ['H', 'F', 'Autre'];
        $d['sexe']    = in_array($d['sexe'] ?? '', $allowedSexe, true) ? $d['sexe'] : 'H';
        $d['nom']     = mb_substr(trim($d['nom'] ?? ''), 0, 255);
        $d['prenom']  = mb_substr(trim($d['prenom'] ?? ''), 0, 255);
        $d['tel']     = mb_substr(trim($d['tel'] ?? ''), 0, 50);
        $d['ville']   = mb_substr(trim($d['ville'] ?? ''), 0, 255);
        $d['entreprise'] = mb_substr(trim($d['entreprise'] ?? ''), 0, 255);
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
        require_once __DIR__ . '/form_fields.php';
        $fieldCols = getAllActiveFieldColumns($pdo);

        $cols = ['inscription_no'];
        $phs  = ['?'];
        $vals = [$no];

        foreach ($fieldCols as $col => $meta) {
            $raw = $d[$col] ?? '';
            $cols[] = "`{$col}`";
            $phs[]  = '?';
            $vals[] = $meta['encrypted'] ? encrypt($raw !== '' ? $raw : '') : ($raw !== '' ? $raw : '');
        }

        // Champs système
        $cols[] = 'origine';      $phs[] = '?'; $vals[] = $origine;
        $cols[] = 'paiement_mode';$phs[] = '?'; $vals[] = $d['paiement_mode'] ?? null;
        $cols[] = 'created_by';   $phs[] = '?'; $vals[] = currentUserId();

        $colStr = implode(',', $cols);
        $phStr  = implode(',', $phs);
        $st = $pdo->prepare("INSERT INTO registrations ({$colStr}) VALUES ({$phStr})");
        $st->execute($vals);
        $pdo->commit();

        // Envoyer mail de confirmation si email renseigné
        // (Même logique que public/register.php : appel direct sans isMailConfigured(),
        //  les erreurs éventuelles sont loguées dans config/logs/logs_*_mails.log par sendMail/sendMailSmtp.)
        $inscEmail = trim($d['email'] ?? '');
        if ($inscEmail !== '') {
            try {
                require_once __DIR__ . '/googleMail.php';
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
        // Le rôle "saisie" ne peut supprimer que ses propres inscriptions
        if (currentRole() === 'saisie') {
            $own = $pdo->prepare('SELECT created_by FROM registrations WHERE id=?');
            $own->execute([$d['id']]);
            $createdBy = $own->fetchColumn();
            if ((int)$createdBy !== (int)currentUserId()) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'err' => 'Vous ne pouvez supprimer que vos propres inscriptions.']);
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

        /* 3. Le rôle "saisie" ne peut modifier que ses propres inscriptions */
        if (currentRole() === 'saisie') {
            $own = $pdo->prepare('SELECT created_by FROM registrations WHERE id=?');
            $own->execute([$d['id']]);
            $createdBy = $own->fetchColumn();
            if ((int)$createdBy !== (int)currentUserId()) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'err' => 'Vous ne pouvez modifier que vos propres inscriptions.']);
                exit;
            }
        }

        /* 4. Champs autorisés dynamiques depuis la table forms + champs système */
        require_once __DIR__ . '/form_fields.php';
        $fieldCols = getAllActiveFieldColumns($pdo);
        $systemCols = ['origine', 'paiement_mode'];

        $params = ['id' => $d['id']];
        $setParts = [];

        foreach ($fieldCols as $col => $meta) {
            if (!array_key_exists($col, $d)) continue;
            $raw = $d[$col];
            // Cohérence avec POST : on stocke une chaîne vide plutôt que null
            // (les colonnes NOT NULL acceptent '' mais pas null)
            if ($raw === null) $raw = '';
            $params[$col] = $meta['encrypted'] ? encrypt($raw !== '' ? $raw : '') : $raw;
            $setParts[] = "`{$col}` = :{$col}";
        }

        foreach ($systemCols as $sc) {
            if (!array_key_exists($sc, $d)) continue;
            $params[$sc] = $d[$sc];
            $setParts[] = "`{$sc}` = :{$sc}";
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

/* ───── IMPORT EXCEL (permission dashboard.import_excel) ─────────────────── */
if ($route === 'import-excel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAction('dashboard.import_excel');

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Erreur lors du téléchargement du fichier']);
        exit;
    }

    // Validation extension + MIME
    $xlsExt  = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    $xlsMime = mime_content_type($_FILES['file']['tmp_name']);
    $allowedXlsExts  = ['xlsx', 'xls'];
    $allowedXlsMimes = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
        'application/vnd.ms-excel',                                           // xls
        'application/zip',                                                    // xlsx détecté comme zip sur certains serveurs
        'text/xml',                                                            // xls exporté en XML (AssoConnect)
        'application/xml',                                                     // variante XML
        'text/html',                                                           // xls exporté en HTML (certains exports)
    ];
    // Détection par signature binaire : selon le serveur, mime_content_type()
    // peut renvoyer application/octet-stream (ou autre) pour un .xlsx pourtant
    // valide. On vérifie alors les octets magiques du fichier :
    //   - xlsx (= archive ZIP)       -> "PK\x03\x04"
    //   - xls binaire (OLE Compound) -> "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"
    $xlsSig = (string) file_get_contents($_FILES['file']['tmp_name'], false, null, 0, 8);
    $isXlsZip = strncmp($xlsSig, "PK\x03\x04", 4) === 0;
    $isXlsOle = strncmp($xlsSig, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0;
    $xlsMimeOk = in_array($xlsMime, $allowedXlsMimes, true) || $isXlsZip || $isXlsOle;

    if (!in_array($xlsExt, $allowedXlsExts, true) || !$xlsMimeOk) {
        http_response_code(400);
        echo json_encode(['error' => 'Format invalide. Utilisez un fichier Excel (.xlsx ou .xls)']);
        exit;
    }

    try {
        require_once __DIR__ . '/../vendor/autoload.php';

        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['file']['tmp_name'])
                     ->getActiveSheet()
                     ->toArray(null, true, true, true); // A, B, C...

        if (empty($sheet) || count($sheet) < 2) {
            http_response_code(400);
            echo json_encode(['error' => 'Le fichier Excel semble vide']);
            exit;
        }

        // 1. Récupération des correspondances depuis la BDD
        $mapFields = []; // ['numero billet' => 'inscription_no']
        $stmt = $pdo->query('SELECT fields_bdd, fields_excel FROM import');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $mapFields[ normaliseLabel($row['fields_excel']) ] = $row['fields_bdd'];
        }

        // 2. Mapping des entêtes Excel
        $headerMap = []; // ['numero billet' => 'A']
        foreach ($sheet[1] as $col => $label) {
            if (!$label) continue;
            $headerMap[ normaliseLabel($label) ] = $col;
        }

        // 3. Vérification des colonnes requises
        //    "pays" est optionnelle : si la colonne est absente du fichier,
        //    le champ `origine` reçoit "France" par défaut (voir insertion BDD).
        $optionalLabels = [ normaliseLabel('pays') ];
        $required = array_diff(array_keys($mapFields), $optionalLabels);
        $missing  = array_diff($required, array_keys($headerMap));

        // Log de debug supprimé (ne pas écrire dans le webroot)

        if ($missing) {
            logImportError([
                'type' => 'colonnes manquantes',
                'missing' => array_values($missing),
                'headerMap' => array_keys($headerMap),
                'required' => $required
            ]);
            http_response_code(422);
            echo json_encode([
                'error'   => 'Colonnes manquantes',
                'missing' => array_values($missing)
            ]);
            exit;
        }

        // 4. Tickets déjà existants
        $existingTickets = $pdo->query('SELECT inscription_no FROM registrations')
                               ->fetchAll(PDO::FETCH_COLUMN, 0);

        // 5. Préparation de la requête
        $stmt = $pdo->prepare(
            'INSERT INTO registrations
             (inscription_no, nom, prenom, tel, email, naissance, sexe,
              tshirt_size, ville, entreprise, origine, paiement_mode,
              created_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );

        // 6. Parsing de toutes les lignes
        $parsedRows = [];
        $skipped = 0;
        $duplicates = $errors = [];

        foreach ($sheet as $idx => $row) {
            if ($idx === 1) continue;

            $values = [];
            foreach ($mapFields as $excelLabel => $bddField) {
                $col = $headerMap[$excelLabel] ?? null;
                $value = $col ? trim($row[$col]) : null;

                if ($bddField === 'inscription_no') {
                    $value = 'E' . trim($value);
                } elseif ($bddField === 'naissance') {
                    $value = (is_numeric($value) && $value >= 1900 && $value <= date('Y')) ? $value : null;
                } elseif ($bddField === 'created_at') {
                    $value = convertExcelDate($value);
                } elseif ($bddField === 'sexe') {
                    $value = normaliseSexe($value);
                }

                $values[$bddField] = $value ?: null;
            }

            if (!$values['inscription_no'] || !$values['nom'] || !$values['prenom']) {
                $skipped++;
                $errors[] = ['ligne' => $idx, 'erreur' => 'Données manquantes'];
                logImportError([
                    'type' => 'ligne ignorée',
                    'ligne' => $idx,
                    'raison' => 'Données manquantes',
                    'valeurs' => $values
                ]);
                continue;
            }

            if (in_array($values['inscription_no'], $existingTickets, true)) {
                $skipped++;
                $duplicates[] = ['ligne' => $idx, 'ticket' => $values['inscription_no']];
                logImportError([
                    'type' => 'doublon',
                    'ligne' => $idx,
                    'ticket' => $values['inscription_no']
                ]);
                continue;
            }

            $existingTickets[] = $values['inscription_no'];
            $parsedRows[] = ['ligne' => $idx, 'values' => $values];
        }

        // 7. Tri par date de création (du plus ancien au plus récent)
        usort($parsedRows, function ($a, $b) {
            $dateA = $a['values']['created_at'] ?? '9999-12-31';
            $dateB = $b['values']['created_at'] ?? '9999-12-31';
            return strcmp($dateA, $dateB);
        });

        // 8. Insertion en BDD dans l'ordre chronologique
        $pdo->beginTransaction();
        $added = 0;
        $newRegistrants = [];

        foreach ($parsedRows as $parsed) {
            $values = $parsed['values'];

            $stmt->execute([
                $values['inscription_no'], encrypt($values['nom']), encrypt($values['prenom']),
                encrypt($values['tel']), encrypt($values['email']), encrypt($values['naissance']), $values['sexe'],
                '-', encrypt($values['ville']), encrypt($values['entreprise']),
                ($values['origine'] ?? null) ?: 'AssoConnect',
                'en ligne (CB)', $values['created_at'], currentUserId()
            ]);

            $added++;

            // Collecter les nouveaux inscrits pour envoi mail après commit (ordre chronologique)
            if (!empty($values['email'])) {
                $newRegistrants[] = [
                    'email'          => $values['email'],
                    'nom'            => $values['nom'],
                    'prenom'         => $values['prenom'],
                    'inscription_no' => $values['inscription_no'],
                ];
            }
        }

        $pdo->commit();

        // Streaming : passer en NDJSON pour le temps réel
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('X-Accel-Buffering: no');
        if (ob_get_level()) ob_end_flush();

        $sendEvent = function($data) {
            echo json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
            flush();
        };

        // Événement : import terminé
        $sendEvent(['type' => 'import_ok', 'count' => $added]);
        if ($skipped > 0) {
            $sendEvent(['type' => 'import_skip', 'count' => $skipped, 'duplicates' => count($duplicates)]);
        }

        // Envoi des mails de confirmation aux nouveaux inscrits (hors transaction)
        $mailsSent = 0;
        $sendMails = isset($_POST['send_mails']);

        if ($sendMails && !empty($newRegistrants)) {
            require_once __DIR__ . '/googleMail.php';
            $subject = 'Inscription enregistrée - Forbach en Rose';
            foreach ($newRegistrants as $reg) {
                try {
                    $hasQr = shouldIncludeQrCode($reg['inscription_no']);
                    sendMail(
                        $reg['email'],
                        $subject,
                        null,
                        null,
                        $reg['nom'],
                        $reg['prenom'],
                        'inscription',
                        $reg['inscription_no']
                    );
                    $mailsSent++;
                    $sendEvent(['type' => 'mail_sent', 'inscription_no' => $reg['inscription_no'], 'qrcode' => $hasQr]);
                } catch (\Throwable $mailErr) {
                    writeLog("⚠️ Mail import échoué pour {$reg['email']} : " . $mailErr->getMessage());
                    $sendEvent(['type' => 'mail_error', 'inscription_no' => $reg['inscription_no'], 'error' => $mailErr->getMessage()]);
                }
            }
        } elseif (!$sendMails) {
            $sendEvent(['type' => 'mail_skip']);
        }

        // Récap final
        $sendEvent([
            'type'          => 'done',
            'rows_added'    => $added,
            'rows_skipped'  => $skipped,
            'mails_sent'    => $mailsSent,
            'duplicates'    => count($duplicates),
        ]);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        logImportError([
            'type' => 'exception',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        error_log('Import Excel : '.$e->getMessage());
        http_response_code(500);
        echo json_encode([
            'ok'     => false,
            'error'  => 'import_error',
            'detail' => $e->getMessage()
        ]);
        exit;
    }
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

function convertExcelDate($value): ?string {
    if (is_numeric($value)) {
        return date('Y-m-d H:i:s', \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($value));
    } else {
        $formats = ['d/m/Y H:i:s', 'd/m/Y', 'Y-m-d'];
        foreach ($formats as $f) {
            $dt = DateTime::createFromFormat($f, $value);
            if ($dt) return $dt->format('Y-m-d H:i:s');
        }
    }
    return date('Y-m-d H:i:s');
}

function logImportError(array $data, string $filename = 'import_errors.log') {
    // Écriture dans config/ (protégé par .htaccess), jamais dans le webroot
    $safePath = __DIR__ . '/logs/' . basename($filename);
    $entry = date('Y-m-d H:i:s') . " | " . json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($safePath, $entry, FILE_APPEND);
}


/* ───── EXPORT EXCEL (permission dashboard.export_excel) ─────────────────── */
if ($route === 'export-excel' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    requireAction('dashboard.export_excel');

    require_once __DIR__.'/../vendor/autoload.php';
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    /* 1. Entêtes */
    $headers = ['No', 'Nom', 'Prénom', 'Tel', 'Email', 'Naissance',
                'Sexe', 'T-shirt', 'Ville', 'Entreprise', 'Origine',
                'Paiement', 'Créé le', 'Créé par'];
    $sheet->fromArray($headers, null, 'A1');

    /* 2. Données (déchiffrer les PII) */
    $rows = $pdo->query(
        "SELECT r.inscription_no, r.nom, r.prenom, r.tel, r.email, r.naissance,
                r.sexe, r.tshirt_size, r.ville, r.entreprise, r.origine,
                r.paiement_mode, r.created_at, COALESCE(u.email, r.created_by) AS created_by
         FROM registrations r
         LEFT JOIN users u ON r.created_by = u.id
         ORDER BY CAST(REPLACE(REPLACE(r.inscription_no, 'S', ''), 'E', '') AS UNSIGNED)"
    )->fetchAll(PDO::FETCH_ASSOC);
    $rows = decryptRows($rows);
    $rows = array_map('array_values', $rows); // Convertir en tableau numérique pour fromArray

    $sheet->fromArray($rows, null, 'A2');

    /* 3. Style minimal */
    $sheet->getStyle('A1:N1')->getFont()->setBold(true);
    foreach (range('A', 'N') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

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

    /* Age moyen */
    $ages = [];
    foreach ($allRows as $r) {
        $n = $r['naissance'];
        if ($n && is_numeric($n)) $ages[] = (int)date('Y') - (int)$n;
        elseif ($n && preg_match('/^\d{4}-\d{2}-\d{2}$/', $n)) $ages[] = (int)date('Y') - (int)substr($n, 0, 4);
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

    /* 6) Plus vieille personne masculine */
    $plus_vieux_h = null;
    $oldestH = null;
    foreach ($allRows as $r) {
        if ($r['sexe'] !== 'H' || !$r['naissance']) continue;
        $n = is_numeric($r['naissance']) ? (int)$r['naissance'] : (int)substr($r['naissance'], 0, 4);
        if ($oldestH === null || $n < $oldestH) {
            $oldestH = $n;
            $plus_vieux_h = trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? ''));
        }
    }

    /* 7) Plus vieille personne féminine */
    $plus_vieille_f = null;
    $oldestF = null;
    foreach ($allRows as $r) {
        if ($r['sexe'] !== 'F' || !$r['naissance']) continue;
        $n = is_numeric($r['naissance']) ? (int)$r['naissance'] : (int)substr($r['naissance'], 0, 4);
        if ($oldestF === null || $n < $oldestF) {
            $oldestF = $n;
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

    /* 9) On vide la table active pour la nouvelle saison */
    $pdo->exec('TRUNCATE TABLE registrations');
    $pdo->commit();

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

        $registrations = $pdo->query(
            "SELECT inscription_no,nom,prenom,tel,email,naissance,sexe,ville,tshirt_size
             FROM `$tableArchive`
             ORDER BY CAST(REPLACE(REPLACE(inscription_no, 'S', ''), 'E', '') AS UNSIGNED) DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(decryptRows($registrations));
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Gestion des QR Codes
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
            
            $stmt = $pdo->prepare(
                'INSERT INTO qrcodes (organisation, token, qr_url, description, created_by) 
                 VALUES (?, ?, ?, ?, ?)'
            );
            
            $result = $stmt->execute([
                $data['organisation'],
                $token,
                $qr_url,
                $data['description'] ?? null,
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
        require_once __DIR__ . '/googleMail.php';

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
    $cacheFile = __DIR__ . '/logs/ip_geo_cache.json';
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

http_response_code(404);
echo json_encode(['error'=>'route inconnue']);
