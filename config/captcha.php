<?php
/**
 * captcha.php — Helpers anti-bot partagés (formulaires publics)
 *
 * - getTurnstileConfig($pdo)   : lit la config Cloudflare Turnstile depuis BDD (cache statique).
 * - verifyTurnstileToken($t,$s,$ip) : POST vers Cloudflare siteverify, true/false.
 * - issuePublicCaptcha($pdo)   : renvoie le tableau {mode, sitekey | token+question} à exposer au client.
 * - verifyPublicCaptcha($data,$pdo) : valide le payload (turnstile_token OU captcha_token+captcha_answer).
 *
 * Les tokens "math" sont stockés dans $_SESSION['partner_captcha'] (nom historique conservé,
 * partagé entre tous les formulaires publics).
 */

function getTurnstileConfig(\PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $sitekey = null; $secret = null;
    try {
        $row = $pdo->query("SELECT turnstile_sitekey, turnstile_secret FROM setting WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $sitekey = trim((string)($row['turnstile_sitekey'] ?? ''));
            $secret  = trim((string)($row['turnstile_secret']  ?? ''));
        }
    } catch (\Throwable $e) { /* colonnes absentes → fallback maths */ }
    $cache = [
        'enabled' => $sitekey !== '' && $secret !== '',
        'sitekey' => $sitekey ?: '',
        'secret'  => $secret  ?: '',
    ];
    return $cache;
}

/**
 * Teste si un secret Turnstile est valide auprès de Cloudflare.
 * On envoie un response token bidon : Cloudflare renvoie alors un code d'erreur
 *   - "invalid-input-secret" si la clé secrète est mauvaise → SECRET INVALIDE
 *   - "missing-input-response" / "invalid-input-response" si la clé est OK mais le token bidon → SECRET VALIDE
 *   - succès si on a fourni le secret de test Cloudflare → SECRET VALIDE
 *
 * Retour : ['ok' => bool, 'reason' => string]
 *   reason ∈ {'valid','invalid_secret','unreachable','empty'}
 */
function testTurnstileSecret(string $secret): array {
    $secret = trim($secret);
    if ($secret === '') return ['ok' => false, 'reason' => 'empty'];

    $payload = http_build_query([
        'secret'   => $secret,
        'response' => 'test_dummy_token_for_secret_validation',
    ]);
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $payload,
            'timeout'       => 8,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
    if ($body === false) return ['ok' => false, 'reason' => 'unreachable'];
    $j = json_decode($body, true);
    if (!is_array($j)) return ['ok' => false, 'reason' => 'unreachable'];

    // Si succès direct : clé de test Cloudflare officielle → secret OK
    if (!empty($j['success'])) return ['ok' => true, 'reason' => 'valid'];

    $codes = $j['error-codes'] ?? [];
    if (in_array('invalid-input-secret', $codes, true)) {
        return ['ok' => false, 'reason' => 'invalid_secret'];
    }
    // Tout autre code d'erreur (missing/invalid response, timeout du token, etc.)
    // → secret accepté par Cloudflare, c'est juste notre token bidon qui est rejeté
    return ['ok' => true, 'reason' => 'valid'];
}

function verifyTurnstileToken(string $token, string $secret, string $remoteIp = ''): bool {
    if ($token === '' || $secret === '') return false;
    $payload = http_build_query([
        'secret'   => $secret,
        'response' => $token,
        'remoteip' => $remoteIp,
    ]);
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $payload,
            'timeout'       => 8,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
    if ($body === false) return false;
    $j = json_decode($body, true);
    return is_array($j) && !empty($j['success']);
}

/**
 * Génère un challenge math (utilisé en mode natif OU en fallback).
 */
function issueMathCaptcha(): array {
    $a = random_int(2, 9);
    $b = random_int(2, 9);
    $ops = ['+', '−'];
    $op = $ops[random_int(0, 1)];
    $answer = $op === '+' ? ($a + $b) : ($a - $b);
    $token = bin2hex(random_bytes(16));

    if (!isset($_SESSION['partner_captcha']) || !is_array($_SESSION['partner_captcha'])) {
        $_SESSION['partner_captcha'] = [];
    }
    $now = time();
    foreach ($_SESSION['partner_captcha'] as $k => $v) {
        if (!is_array($v) || ($v['exp'] ?? 0) < $now) unset($_SESSION['partner_captcha'][$k]);
    }
    if (count($_SESSION['partner_captcha']) > 20) {
        $_SESSION['partner_captcha'] = array_slice($_SESSION['partner_captcha'], -10, null, true);
    }
    $_SESSION['partner_captcha'][$token] = ['answer' => $answer, 'exp' => $now + 600];

    return [
        'ok'       => true,
        'mode'     => 'math',
        'token'    => $token,
        'question' => "Combien font $a $op $b ?",
    ];
}

/**
 * 🔒 [SEC-CAPTCHA] Rate-limit par IP des bascules « fallback maths ».
 * Empêche un bot de rétrograder Turnstile (fort) vers le captcha maths (faible) à
 * volonté en repartant de sessions neuves. Max 15 bascules / heure / IP. Les
 * utilisateurs légitimes (widget réellement cassé) n'atteignent jamais ce seuil.
 */
function _captchaFallbackAllowedByIp(): bool {
    $ip = function_exists('fer_client_ip') ? fer_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $key  = substr(hash('sha256', 'captchafb_' . $ip), 0, 32);
    $file = sys_get_temp_dir() . '/fer_' . $key . '.json';
    $now  = time();
    $times = [];
    if (@file_exists($file)) { $times = json_decode(@file_get_contents($file), true) ?: []; }
    $times = array_values(array_filter($times, fn($t) => $t > $now - 3600));
    if (count($times) >= 15) return false;
    $times[] = $now;
    @file_put_contents($file, json_encode($times));
    return true;
}

/**
 * Génère un challenge captcha à exposer au client.
 * Si $fallback=true → force le mode maths (utilisé quand Turnstile a échoué côté navigateur).
 * Retourne {ok:true, mode:'turnstile', sitekey:...}
 *   OU    {ok:true, mode:'math', token:..., question:...}
 */
function issuePublicCaptcha(\PDO $pdo, bool $fallback = false): array {
    $cfg = getTurnstileConfig($pdo);
    if ($cfg['enabled'] && !$fallback) {
        return ['ok' => true, 'mode' => 'turnstile', 'sitekey' => $cfg['sitekey']];
    }
    if ($fallback) {
        // Si Turnstile est configuré, la bascule vers le captcha maths est un
        // affaiblissement : on la limite par IP. Au-delà du quota, on renvoie le vrai
        // widget Turnstile plutôt que le mode maths.
        if ($cfg['enabled'] && !_captchaFallbackAllowedByIp()) {
            return ['ok' => true, 'mode' => 'turnstile', 'sitekey' => $cfg['sitekey']];
        }
        // Marque la session : ce navigateur a constaté que Turnstile est cassé,
        // on l'autorise donc à soumettre via le captcha maths.
        $_SESSION['partner_captcha_fallback_ok'] = time();
    }
    return issueMathCaptcha();
}

/**
 * Valide un payload captcha. Retourne true si OK, false sinon.
 * $data = array associatif issu de $_POST ou json_decode body.
 *
 * Si Turnstile est configuré :
 *   - On accepte d'abord un turnstile_token valide.
 *   - Sinon on accepte un captcha_token + captcha_answer SI ET SEULEMENT SI la session
 *     a le flag "fallback ok" (posé par issuePublicCaptcha(..., true) après un échec widget).
 *
 * Si Turnstile n'est pas configuré : seul le captcha maths est accepté.
 *
 * Les tokens math sont consommés (single-use) à l'usage.
 */
function verifyPublicCaptcha(array $data, \PDO $pdo): bool {
    $cfg = getTurnstileConfig($pdo);

    // Tentative Turnstile en premier si configuré
    if ($cfg['enabled']) {
        $tsToken = trim((string)($data['turnstile_token'] ?? ''));
        if ($tsToken !== '') {
            $ip = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '');
            if (verifyTurnstileToken($tsToken, $cfg['secret'], $ip)) return true;
        }
        // Pas de token Turnstile valide : on accepte le fallback maths uniquement si
        // le navigateur a déjà déclaré l'échec du widget (flag de session).
        $fallbackTs = $_SESSION['partner_captcha_fallback_ok'] ?? 0;
        $fallbackAllowed = $fallbackTs && $fallbackTs > time() - 1800; // 30 min
        if (!$fallbackAllowed) return false;
    }

    return verifyMathCaptcha($data);
}

/**
 * Valide UNIQUEMENT un captcha maths (token + réponse), indépendamment de Turnstile.
 * Usage : formulaires légers (newsletter) qui veulent un captcha simple et fiable
 * sans dépendre de la config Turnstile. Token à usage unique (consommé).
 */
function verifyMathCaptcha(array $data): bool {
    $capToken  = trim((string)($data['captcha_token']  ?? ''));
    $capAnswer = trim((string)($data['captcha_answer'] ?? ''));
    $store     = $_SESSION['partner_captcha'][$capToken] ?? null;
    $ok = $capToken !== '' && is_array($store) && ($store['exp'] ?? 0) >= time()
          && (string)$store['answer'] === $capAnswer;
    if ($capToken && isset($_SESSION['partner_captcha'][$capToken])) {
        unset($_SESSION['partner_captcha'][$capToken]);
    }
    return $ok;
}
