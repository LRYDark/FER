<?php
require 'config.php';
require_once __DIR__ . '/csrf.php';
header('Content-Type: application/json; charset=utf-8');

$route = $_GET['route'] ?? '';

// ─── CSRF check for state-changing API requests (skip public/pre-auth routes) ───
// 🔒 [FIX-13] logout retiré des routes CSRF-exempt — force-logout via CSRF impossible (CWE-352)
$csrfExemptRoutes = ['login', 'validate-2fa', 'forgot-password', 'reset-password-confirm', 'partner-request'];
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

function isIpBanned($pdo, $ip) {
    try {
        // 🔒 [FIX-03] Colonne alignée avec INSERT de connexions.php : `ip` (CWE-284)
        // ⚠️ [À VÉRIFIER] Confirmer via SHOW COLUMNS FROM login_banned_ips
        $st = $pdo->prepare('SELECT 1 FROM login_banned_ips WHERE ip = ? LIMIT 1');
        $st->execute([$ip]);
        return (bool) $st->fetch();
    } catch (\Throwable $e) { return false; }
}

function getClientIp() {
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

function isMailConfigured(): bool {
    try {
        if (!file_exists(__DIR__ . '/../token.json')) return false;
        global $pdo;
        $stmt = $pdo->prepare('SELECT client_id, client_secret FROM setting WHERE id = 1 LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        return !empty(decrypt($row['client_id'] ?? null)) && !empty(decrypt($row['client_secret'] ?? null));
    } catch (\Throwable $e) { return false; }
}

function send2faCode($pdo, $user) {
    require_once __DIR__ . '/googleMail.php';
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE users SET twofa_code = ?, twofa_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?')
        ->execute([$code, $user['id']]);
    try {
        sendMail(
            $user['email'],
            'Code de verification – Forbach en Rose',
            'Code de verification',
            '<p>Votre code de verification est :</p><p style="font-size:32px;font-weight:700;letter-spacing:8px;text-align:center;color:#ec4899;margin:20px 0">' . $code . '</p><p>Ce code est valable 15 minutes.</p><p>Si vous n\'avez pas demande cette connexion, ignorez ce message.</p>',
            null, null, 'info'
        );
        return true;
    } catch (\Throwable $e) {
        error_log('2FA mail error: ' . $e->getMessage());
        return false;
    }
}

/* ───── LOGIN / LOGOUT ───────────────────────── */
if ($route==='login' && $_SERVER['REQUEST_METHOD']==='POST'){
    $d = json_decode(file_get_contents('php://input'), true);
    $ip = getClientIp();

    // Check IP ban
    if (isIpBanned($pdo, $ip)) {
        http_response_code(403);
        echo json_encode(['ok'=>false, 'err'=>'Votre adresse IP a ete bannie. Contactez un administrateur.']); exit;
    }

    $st=$pdo->prepare('SELECT id,email,password_hash,role,must_change_password,is_active,failed_attempts,locked_at FROM users WHERE email=?');
    $st->execute([$d['email'] ?? '']); $u=$st->fetch();

    if($u && password_verify($d['password'] ?? '',$u['password_hash'])){
        if(!$u['is_active']){
            $reason = $u['locked_at'] ? 'Compte verrouille (3 echecs)' : 'Compte desactive';
            logLoginAttempt($pdo, $u['id'], $u['email'], false, $reason);
            http_response_code(403);
            $msg = $u['locked_at']
                ? 'Compte verrouille suite a 3 tentatives echouees. Utilisez "Mot de passe oublie" ou contactez un administrateur.'
                : 'Compte desactive. Contactez un administrateur.';
            echo json_encode(['ok'=>false, 'err'=>$msg]); exit;
        }
        // Reset failed attempts
        if ($u['failed_attempts'] > 0) {
            $pdo->prepare('UPDATE users SET failed_attempts = 0 WHERE id = ?')->execute([$u['id']]);
        }

        // Must change password — no 2FA needed
        if($u['must_change_password']){
            session_regenerate_id(true);
            $_SESSION['uid']=$u['id']; $_SESSION['role']=$u['role']; $_SESSION['email']=$u['email'];
            logLoginAttempt($pdo, $u['id'], $u['email'], true, 'Changement MDP requis');
            echo json_encode(['ok'=>true, 'role'=>$u['role'], 'must_change_password'=>true]); exit;
        }

        // Check if 2FA is needed (only if user email is a valid email)
        $mailOk = isMailConfigured();
        $has2faCols = true;
        try { $pdo->query('SELECT twofa_code FROM users LIMIT 0'); } catch (\Throwable $e) { $has2faCols = false; }
        $userHasEmail = filter_var($u['email'], FILTER_VALIDATE_EMAIL);

        if ($mailOk && $has2faCols && $userHasEmail) {
            // Check trusted device
            if (checkTrustedDevice($pdo, $u['id'])) {
                // Trusted → login direct
                session_regenerate_id(true);
                $_SESSION['uid']=$u['id']; $_SESSION['role']=$u['role']; $_SESSION['email']=$u['email'];
                logLoginAttempt($pdo, $u['id'], $u['email'], true, 'Appareil de confiance');
                echo json_encode(['ok'=>true, 'role'=>$u['role']]); exit;
            }

            // Send 2FA code
            $sent = send2faCode($pdo, $u);
            if ($sent) {
                $_SESSION['pending_2fa_uid'] = $u['id'];
                $_SESSION['pending_2fa_role'] = $u['role'];
                $_SESSION['pending_2fa_email'] = $u['email'];
                echo json_encode(['ok'=>true, 'requires_2fa'=>true]); exit;
            }
            // Mail send failed → login direct (failsafe)
        }

        // No 2FA needed or mail not configured → login direct
        session_regenerate_id(true);
        $_SESSION['uid']=$u['id']; $_SESSION['role']=$u['role']; $_SESSION['email']=$u['email'];
        logLoginAttempt($pdo, $u['id'], $u['email'], true, 'Connexion directe');
        echo json_encode(['ok'=>true, 'role'=>$u['role']]); exit;
    }

    // Failed login
    logLoginAttempt($pdo, $u['id'] ?? null, $d['email'] ?? '', false, $u ? 'Mot de passe incorrect' : 'Email inconnu');

    if ($u) {
        $attempts = $u['failed_attempts'] + 1;
        if ($attempts >= 3) {
            $pdo->prepare('UPDATE users SET failed_attempts = ?, is_active = 0, locked_at = NOW() WHERE id = ?')
                ->execute([$attempts, $u['id']]);
            try {
                require_once __DIR__ . '/googleMail.php';
                if (isGoogleConnectionValid()) {
                    $admins = $pdo->query("SELECT email FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($admins as $adminEmail) {
                        sendMail($adminEmail, 'Compte verrouille – Forbach en Rose', 'Compte verrouille apres 3 tentatives',
                            '<p>Le compte <strong>' . htmlspecialchars($u['email']) . '</strong> a ete verrouille automatiquement apres 3 tentatives de connexion echouees.</p>'
                            . '<p>IP : ' . htmlspecialchars($ip) . '</p>', null, null, 'warning');
                    }
                }
            } catch (\Throwable $e) { error_log('Lock notification mail error: ' . $e->getMessage()); }
            http_response_code(403);
            echo json_encode(['ok'=>false, 'err'=>'Compte verrouille apres 3 tentatives echouees.']); exit;
        } else {
            $pdo->prepare('UPDATE users SET failed_attempts = ? WHERE id = ?')->execute([$attempts, $u['id']]);
            $remaining = 3 - $attempts;
            http_response_code(401);
            echo json_encode(['ok'=>false, 'err'=>"Identifiants incorrects. $remaining tentative(s) restante(s)."]); exit;
        }
    }

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

    echo json_encode(['ok'=>true, 'role'=>$_SESSION['role']]); exit;
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
            if (isGoogleConnectionValid()) {
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
                if (isGoogleConnectionValid()) {
                    $emailSent = sendMail(
                        $userEmail,
                        'Réinitialisation de votre mot de passe – Forbach en Rose',
                        'Mot de passe réinitialisé',
                        '<p>Votre mot de passe a été réinitialisé.</p>'
                          . '<p><strong>Nouveau mot de passe temporaire :</strong> ' . htmlspecialchars($tempPassword) . '</p>'
                          . '<p>Vous devrez changer votre mot de passe lors de votre prochaine connexion.</p>',
                        null, null, 'info'
                    );
                }
            } catch (Exception $e) {
                error_log('Reset password mail error: ' . $e->getMessage());
            }
        }

        echo json_encode(['ok' => true, 'temp_password' => $tempPassword, 'email_sent' => $emailSent]);
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
            if (isGoogleConnectionValid()) {
                $emailSent = sendMail(
                    $d['email'],
                    'Votre compte Forbach en Rose',
                    'Bienvenue sur Forbach en Rose',
                    '<p>Votre compte a été créé.</p>'
                      . '<p><strong>Email :</strong> ' . htmlspecialchars($d['email']) . '</p>'
                      . '<p><strong>Mot de passe temporaire :</strong> ' . htmlspecialchars($tempPassword) . '</p>'
                      . '<p>Vous devrez changer votre mot de passe lors de votre première connexion.</p>',
                    null, null, 'info'
                );
            }
        } catch (Exception $e) {
            error_log('Create user mail error: ' . $e->getMessage());
        }

        echo json_encode(['ok' => true, 'temp_password' => $tempPassword, 'email_sent' => $emailSent]);
        exit;
    }

    // GET : liste
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(
            $pdo->query('SELECT id,email,role,organisation,is_active,created_at FROM users')->fetchAll()
        );
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
    /* GET : tous rôles */
    if($_SERVER['REQUEST_METHOD']==='GET'){
        requireRole(['admin','user','viewer','saisie']);
        $rows = $pdo->query('SELECT * FROM registrations ORDER BY inscription_no DESC')->fetchAll();
        echo json_encode(decryptRows($rows)); exit;
    }

    /* POST : public OU user/admin */
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $d = json_decode(file_get_contents('php://input'), true);

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
            $no = (int)$pdo->lastInsertId();
        } else {
            // Fallback si la migration n'a pas encore été jouée
            $no = (int)($pdo->query('SELECT MAX(inscription_no) FROM registrations')->fetchColumn() ?: 0) + 1;
        }

        /* origine : orga de l'utilisateur connecté (si existe), sinon valeur front, sinon "en ligne"  */
        $myOrg = null;
        if (currentUserId()){
            $s=$pdo->prepare('SELECT organisation FROM users WHERE id=?');
            $s->execute([currentUserId()]);
            $myOrg=$s->fetchColumn() ?: null;
        }
        $origine = $myOrg ?: ($d['origine'] ?? 'en ligne');

        $st=$pdo->prepare('INSERT INTO registrations
          (inscription_no,nom,prenom,tel,email,naissance,sexe,tshirt_size,
           ville,entreprise,origine,paiement_mode,created_by)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([
          $no, encrypt($d['nom']), encrypt($d['prenom']), encrypt($d['tel']), encrypt($d['email']),
          encrypt($d['naissance'] ?: null),
          $d['sexe'] ?? 'H',
          $d['tshirt_size'] ?? '',
          encrypt($d['ville']), encrypt($d['entreprise']),
          $origine,
          $d['paiement_mode'],
          currentUserId()
        ]);
        $pdo->commit();

        // Envoyer mail de confirmation si email renseigné
        $inscEmail = trim($d['email'] ?? '');
        if ($inscEmail !== '') {
            try {
                require_once __DIR__ . '/googleMail.php';
                if (isGoogleConnectionValid()) {
                    sendMail(
                        $inscEmail,
                        'Inscription enregistrée - Forbach en Rose',
                        null, null,
                        $d['nom'] ?? '', $d['prenom'] ?? '',
                        'inscription'
                    );
                }
            } catch (\Throwable $e) {
                // Mail failure should not block inscription
            }
        }

        echo json_encode(['ok'=>true,'inscription_no'=>$no]); exit;
    }

    /* DELETE (admin) */
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        requireRole(['admin']);
        parse_str(file_get_contents('php://input'), $d);    // ← on lit ici, uniquement pour DELETE
        $pdo->prepare('DELETE FROM registrations WHERE id=?')->execute([$d['id']]);
        echo json_encode(['ok'=>true]); exit;
    }

    /* ---------- PUT (mise à jour) ---------- */
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        requireRole(['admin']);

        /* 1. Récupérer le corps de requête (JSON ou x-www-form-urlencoded) */
        $raw = file_get_contents('php://input');
        $ct  = $_SERVER['CONTENT_TYPE'] ?? '';

        if (stripos($ct, 'application/json') === 0) {
            $d = json_decode($raw, true) ?: [];
        } else {
            parse_str($raw, $d);                         // compatibilité ancienne version
        }

        /* 2. Vérifier l'id */
        $d['id'] = isset($d['id']) ? (int)$d['id'] : 0;
        if (!$d['id']) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'err' => 'id manquant']);
            exit;
        }

        /* 4. on garde seulement les champs autorisés ET réellement fournis */
        $allowed = ['nom','prenom','tel','email','naissance','sexe','tshirt_size',
                    'ville','entreprise','origine','paiement_mode'];
        $params  = array_intersect_key($d, array_flip($allowed));
        $params['id'] = $d['id'];          // on garde id séparément

        /* naissance vide -> NULL */
        if (isset($params['naissance']) && $params['naissance'] === '') {
            $params['naissance'] = null;
        }

        /* Chiffrer les champs sensibles avant mise à jour */
        encryptFields($params);

        /* SET : uniquement pour les clés présentes */
        $setParts = [];
        foreach ($params as $k => $v) {
            if ($k !== 'id') $setParts[] = "$k = :$k";
        }
        $set = implode(',', $setParts);

        $pdo->prepare("UPDATE registrations SET $set WHERE id = :id")->execute($params);
        echo json_encode(['ok'=>true]);
        exit;
    }
}

/* ───── IMPORT EXCEL (admin) ─────────────────── */
if ($route === 'import-excel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole(['admin']);

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
    ];
    if (!in_array($xlsExt, $allowedXlsExts, true) || !in_array($xlsMime, $allowedXlsMimes, true)) {
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
        $required = array_keys($mapFields);
        $missing = array_diff($required, array_keys($headerMap));

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

        // 6. Traitement des lignes
        $pdo->beginTransaction();
        $added = $skipped = 0;
        $duplicates = $errors = [];

        foreach ($sheet as $idx => $row) {
            if ($idx === 1) continue;

            $values = [];
            foreach ($mapFields as $excelLabel => $bddField) {
                $col = $headerMap[$excelLabel] ?? null;
                $value = $col ? trim($row[$col]) : null;

                if ($bddField === 'inscription_no') {
                    $value = (int)$value;
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

            $stmt->execute([
                $values['inscription_no'], encrypt($values['nom']), encrypt($values['prenom']),
                encrypt($values['tel']), encrypt($values['email']), encrypt($values['naissance']), $values['sexe'],
                '-', encrypt($values['ville']), encrypt($values['entreprise']), 'AssoConnect',
                'en ligne (CB)', $values['created_at'], currentUserId()
            ]);

            $existingTickets[] = $values['inscription_no'];
            $added++;
        }

        $pdo->commit();

        echo json_encode([
            'ok'            => true,
            'rows_added'    => $added,
            'rows_skipped'  => $skipped,
            'duplicates'    => $duplicates,
            'errors'        => $errors
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

/* ---------- Petites fonctions utilitaires ---------- */
function normaliseLabel(string $label): string {
    $label = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
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


/* ───── EXPORT EXCEL (admin) ─────────────────── */
if ($route === 'export-excel' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    requireRole(['admin','user']);

    require_once __DIR__.'/../vendor/autoload.php';
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    /* 1. Entêtes */
    $headers = ['No', 'Nom', 'Prénom', 'Tel', 'Email', 'Naissance',
                'Sexe', 'T-shirt', 'Ville', 'Entreprise', 'Origine',
                'Paiement', 'Créé le', 'Par'];
    $sheet->fromArray($headers, null, 'A1');

    /* 2. Données (déchiffrer les PII) */
    $rows = $pdo->query(
        'SELECT inscription_no, nom, prenom, tel, email, naissance,
                sexe, tshirt_size, ville, entreprise, origine,
                paiement_mode, created_at, created_by
         FROM registrations
         ORDER BY inscription_no'
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

/* ───── ARCHIVE CURRENT YEAR (admin) ─────────────────── */
if ($route === 'archive-current' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole(['admin']);

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
    requireRole(['admin', 'viewer']);

    $year = (int) ($_GET['year'] ?? date('Y'));
    // Sécurité : on construit le nom de table uniquement à partir de l'année (entier)
    // pour empêcher toute injection SQL via le paramètre table_name
    $tableArchive = "registrations_$year";

    try {
        // Vérifie si la table existe (requête préparée)
        $checkStmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $checkStmt->execute([$tableArchive]);
        if (!$checkStmt->rowCount()) {
            echo json_encode([]);
            exit;
        }

        $registrations = $pdo->query(
            "SELECT inscription_no,nom,prenom,tel,email,naissance,sexe,ville,tshirt_size
             FROM `$tableArchive`
             ORDER BY inscription_no DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(decryptRows($registrations));
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// Gestion des QR Codes
if ($route === 'qrcodes') {
    
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
        // Suppression d'un QR code (admin seulement)
        requireRole(['admin']);
        
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

        // Check token without redirecting — this is an AJAX endpoint, never redirect
        $tokenAvailable = (getAccessToken(false) !== false);

        if ($tokenAvailable) {
            $admins = $pdo->query("SELECT email FROM users WHERE role = 'admin' AND is_active = 1")
                          ->fetchAll(PDO::FETCH_COLUMN);

            $subject = 'Nouvelle demande de partenariat – Forbach en Rose';
            $body  = '<h2>Nouvelle demande de partenariat</h2>';
            $body .= '<p>Une entreprise souhaite devenir partenaire de Forbach en Rose.</p>';
            $body .= '<p><strong>Email :</strong> ' . htmlspecialchars($email) . '</p>';
            $body .= '<p><strong>Domaine :</strong> ' . htmlspecialchars($domain) . '</p>';
            $body .= '<p><strong>Date :</strong> ' . date('d/m/Y à H:i') . '</p>';
            $body .= '<hr><p style="color:#888;font-size:12px">Message automatique – Forbach en Rose</p>';

            foreach ($admins as $rawEmail) {
                $adminEmail = decrypt($rawEmail);
                if ($adminEmail && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                    sendMail($adminEmail, $subject, 'Nouvelle demande de partenariat', $body);
                }
            }
        } else {
            error_log('Partner request from ' . $email . ': Google token unavailable, admin notification skipped.');
        }
    } catch (\Throwable $e) {
        error_log('Partner request mail error: ' . $e->getMessage());
        // On ne bloque pas la réponse si le mail échoue
    }

    echo json_encode(['ok' => true, 'message' => 'Votre demande a bien été envoyée ! Nous vous recontacterons rapidement.']);
    exit;
}

http_response_code(404);
echo json_encode(['error'=>'route inconnue']);
