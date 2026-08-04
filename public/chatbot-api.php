<?php
/**
 * API du chatbot public — Forbach en Rose
 *
 * Endpoint AJAX (POST uniquement, JSON en sortie) pour le widget de chat.
 * Actions :
 *   - ask          : analyse un message et renvoie la réponse du bot
 *   - check_email  : vérifie une adresse e-mail (inscription / t-shirt)
 *   - contact_send : envoi du formulaire de contact (reprend la logique de
 *                    l'ancienne page contact.php : captcha + CSRF + rate-limit
 *                    + pièces jointes + sendMail)
 *
 * Sécurité : CSRF obligatoire, rate-limit par IP et par action, captcha
 * Cloudflare Turnstile (ou fallback maths) pour l'envoi de message, aucune
 * donnée personnelle divulguée par la vérification e-mail (compteurs anonymes).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * OÙ IL SE SITUE PARMI LES POINTS D'ENTRÉE JSON — c'est le TYPE DE CLIENT qui
 * les sépare, pas « interne » contre « externe » :
 *
 *   api/v1 · api/mobile/     → un LOGICIEL ou une APPLICATION, avec un jeton,
 *                               depuis n'importe où. Contrat public, documenté.
 *   admin-api.php             → le JAVASCRIPT DES PAGES DU SITE, dans le
 *   public/chatbot-api.php      navigateur d'un visiteur. Aucun jeton d'API,
 *   (ce fichier)                aucune documentation publique, aucune promesse
 *                               de stabilité : ça change avec les écrans.
 *
 * ⚠️ CE FICHIER EST ENTIÈREMENT ANONYME. Il n'exige aucune session — c'est le
 * widget de discussion du site public. Tout ce qu'on y ajoute est donc exposé à
 * Internet : ne jamais y renvoyer de donnée personnelle sans une preuve
 * d'identité explicite, comme le fait déjà `check_email` avec ses compteurs.
 *
 * La carte de Réglages → API le liste avec les trois autres.
 */
require '../src/core/config.php';
require_once '../src/security/csrf.php';
require_once '../src/security/captcha.php';
require_once '../src/content/chatbot-engine.php';

header('Content-Type: application/json; charset=utf-8');

function chatbot_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Rate-limit fichier (même mécanisme que contact.php / accueil.php). */
function chatbot_rate_limit(string $bucket, int $max, int $windowSec): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $file = sys_get_temp_dir() . '/fer_' . md5($bucket . '_' . $ip) . '.json';
    $times = @file_exists($file) ? (json_decode(@file_get_contents($file), true) ?: []) : [];
    $now = time();
    $times = array_values(array_filter($times, fn($t) => $t > $now - $windowSec));
    if (count($times) >= $max) return false;
    $times[] = $now;
    @file_put_contents($file, json_encode($times));
    return true;
}

// ── Garde-fous globaux ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chatbot_json(['ok' => false, 'message' => 'Méthode non autorisée.'], 405);
}
if (!csrf_verify()) {
    chatbot_json(['ok' => false, 'message' => 'Session expirée — rechargez la page.'], 403);
}

// Réglages (maintenance + activation + données de réponse)
try {
    $set = $pdo->query('SELECT * FROM setting WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    chatbot_json(['ok' => false, 'message' => 'Service indisponible.'], 503);
}
$data = $set; // googleMail.php (inclus pour contact_send) lit $data['client_id'/...]
$isAdminLogged = isset($_SESSION['uid']) && in_array(currentRole(), ['admin', 'user'], true);
if (!empty($set['maintenance_mode']) && !$isAdminLogged) {
    chatbot_json(['ok' => false, 'message' => 'Site en maintenance — revenez un peu plus tard !'], 503);
}
if (isset($set['chatbot_enabled']) && (int)$set['chatbot_enabled'] === 0) {
    chatbot_json(['ok' => false, 'message' => 'L\'assistant est momentanément désactivé.'], 403);
}

$action = $_POST['action'] ?? '';

/* ══════════════ 1) Question libre ══════════════ */
if ($action === 'ask') {
    if (!chatbot_rate_limit('chatbot_ask', 30, 60)) {
        chatbot_json(['ok' => false, 'message' => 'Doucement ! 😅 Patientez quelques secondes avant de renvoyer un message.']);
    }
    // Le widget encode le message en base64 (contournement WAF, comme le
    // formulaire de contact) ; decodeHtmlField retombe sur le brut sinon.
    $message = trim((string)decodeHtmlField((string)($_POST['message'] ?? '')));
    if ($message === '' || mb_strlen($message) > 500) {
        chatbot_json(['ok' => false, 'message' => 'Message vide ou trop long (500 caractères max).']);
    }

    $norm = chatbot_normalize($message);

    // Si le message EST une adresse e-mail seule, on aiguille selon le contexte fourni par le widget
    $emailCtx = $_POST['email_context'] ?? '';
    if (filter_var($norm, FILTER_VALIDATE_EMAIL) && in_array($emailCtx, ['registration', 'tshirt', 'qrcode'], true)) {
        $_POST['email'] = $norm;
        $_POST['context'] = $emailCtx;
        $action = 'check_email'; // tombe dans le bloc suivant
    } else {
        [$intent] = chatbot_match_intent($norm);

        // Intentions intégrées d'abord ; sinon la FAQ gérée depuis l'admin
        if ($intent === 'fallback') {
            $faq = chatbot_faq_match($pdo, $norm);
            if ($faq !== null) {
                chatbot_json(['ok' => true, 'reply' => chatbot_faq_reply($faq)]);
            }
            // Vraiment incompris → journalisé (anonyme) pour enrichir le bot depuis l'admin
            try {
                $pdo->prepare('INSERT INTO chatbot_unmatched (question) VALUES (?)')
                    ->execute([mb_substr($message, 0, 500)]);
            } catch (\Throwable $e) { /* table absente avant migration : non bloquant */ }
        }
        chatbot_json(['ok' => true, 'reply' => chatbot_answer($intent, $set)]);
    }
}

/* ══════════════ 2) Vérification e-mail (inscription / t-shirt) ══════════════ */
if ($action === 'check_email') {
    // Anti-énumération : limite stricte
    if (!chatbot_rate_limit('chatbot_email', 8, 60)) {
        chatbot_json(['ok' => true, 'reply' => [
            'text' => 'Trop de vérifications d\'affilée — patientez une petite minute avant de réessayer. ⏳',
            'quick' => [], 'action' => null,
        ]]);
    }
    $email = trim((string)($_POST['email'] ?? ''));
    $context = in_array($_POST['context'] ?? '', ['tshirt', 'qrcode'], true) ? $_POST['context'] : 'registration';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        chatbot_json(['ok' => true, 'reply' => [
            'text' => 'Hmm, cette adresse e-mail ne semble pas valide. 🤔 Pouvez-vous la vérifier ?',
            'quick' => [], 'action' => 'ask_email_' . $context,
        ]]);
    }

    /* ── Renvoi du mail de confirmation / QR code ─────────────────────────
       Sécurité : le QR n'est jamais affiché dans le chat — il repart par
       mail à l'adresse de l'inscrit uniquement (un tiers qui connaît un
       e-mail ne peut donc rien récupérer). Limite stricte anti-abus. */
    if ($context === 'qrcode') {
        if (!chatbot_rate_limit('chatbot_qr_resend', 3, 3600)) {
            chatbot_json(['ok' => true, 'reply' => [
                'text' => 'Le renvoi de confirmation est limité à quelques essais par heure. ⏳<br>Si le mail n\'arrive toujours pas, laissez-nous un message !',
                'quick' => ['✉️ Nous écrire'], 'action' => null,
            ]]);
        }
        try {
            $matches = chatbot_qr_lookup($pdo, $email);
        } catch (\Throwable $e) {
            chatbot_json(['ok' => true, 'reply' => [
                'text' => 'Petit souci technique pendant la vérification — réessayez dans un instant.',
                'quick' => [], 'action' => null,
            ]]);
        }
        if (!$matches) {
            chatbot_json(['ok' => true, 'reply' => [
                'text' => 'Je ne trouve pas d\'inscription avec cette adresse. 😔<br>'
                    . 'Vérifiez l\'orthographe de l\'e-mail, ou <a href="register">inscrivez-vous en 5 minutes</a> !<br>'
                    . 'Si vous pensez qu\'il s\'agit d\'une erreur, laissez-nous un message.',
                'quick' => ['🔁 Réessayer avec un autre e-mail', '✉️ Nous écrire'], 'action' => null,
            ]]);
        }

        // Récapitulatif : numéro d'inscrit + t-shirt (selon la config quota)
        $lines = [];
        foreach ($matches as $m) {
            $lines[] = '• Inscription <strong>n° ' . htmlspecialchars($m['no']) . '</strong> — t-shirt : ' . ($m['eligible'] ? 'oui 🎽' : 'non');
        }

        // Renvoi du mail de confirmation (QR inclus automatiquement selon la
        // config, via shouldIncludeQrCode) — max 3 mails pour un même e-mail.
        $sent = 0;
        try {
            require_once __DIR__ . '/../src/mail/googleMail.php';
            if (isMailConfigured()) {
                foreach (array_slice($matches, 0, 3) as $m) {
                    $ok = sendMail(
                        $email,
                        'Votre confirmation d\'inscription - Forbach en Rose',
                        null, null,
                        $m['nom'], $m['prenom'],
                        'inscription', $m['no']
                    );
                    if ($ok) $sent++;
                }
            }
        } catch (\Throwable $e) {
            error_log('[CHATBOT][QR-RESEND] ' . $e->getMessage());
        }

        $text = 'Bonne nouvelle, je vous ai retrouvé ! ✅<br>' . implode('<br>', $lines) . '<br><br>';
        if ($sent > 0) {
            $text .= '📩 <strong>' . ($sent === 1 ? 'Le mail de confirmation vient d\'être renvoyé' : $sent . ' mails de confirmation viennent d\'être renvoyés')
                . '</strong> à votre adresse' . ($matches[0]['eligible'] || $sent > 1 ? ' (avec le QR code si votre inscription y donne droit)' : '') . '.<br>'
                . '<small>Pensez à vérifier vos indésirables — le mail peut mettre quelques minutes à arriver.</small>';
        } else {
            $text .= 'En revanche, le renvoi automatique du mail n\'a pas fonctionné. 😔 Laissez-nous un message et on vous le renvoie à la main !';
        }
        chatbot_json(['ok' => true, 'reply' => [
            'text' => $text,
            'quick' => $sent > 0 ? ['📍 Lieu & horaires'] : ['✉️ Nous écrire'],
            'action' => null,
        ]]);
    }

    try {
        $lookup = chatbot_email_lookup($pdo, $email);
    } catch (\Throwable $e) {
        chatbot_json(['ok' => true, 'reply' => [
            'text' => 'Petit souci technique pendant la vérification — réessayez dans un instant.',
            'quick' => [], 'action' => null,
        ]]);
    }
    chatbot_json(['ok' => true, 'reply' => chatbot_email_answer($lookup, $context, $set)]);
}

/* ══════════════ 3) Envoi du formulaire de contact ══════════════ */
if ($action === 'contact_send') {
    // Même clé de rate-limit que l'ancienne page contact : 3 envois/heure/IP
    $contactIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rlFile = sys_get_temp_dir() . '/fer_' . md5('contact_' . $contactIp) . '.json';
    $rlTimes = @file_exists($rlFile) ? (json_decode(@file_get_contents($rlFile), true) ?: []) : [];
    $now = time();
    $rlTimes = array_values(array_filter($rlTimes, fn($t) => $t > $now - 3600));
    if (count($rlTimes) >= 3) {
        chatbot_json(['ok' => false, 'message' => 'Trop de messages envoyés. Réessayez dans une heure.']);
    }
    if (!verifyPublicCaptcha($_POST, $pdo)) {
        chatbot_json(['ok' => false, 'message' => 'Vérification anti-robot échouée. Recommencez.']);
    }
    $rlTimes[] = $now;
    @file_put_contents($rlFile, json_encode($rlTimes));

    $nom = trim((string)($_POST['nom'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $sujet = trim((string)($_POST['sujet'] ?? ''));
    // Message encodé base64 côté widget (contournement WAF, comme l'ancien contact.php)
    $message = trim((string)decodeHtmlField($_POST['message'] ?? ''));

    if ($nom === '' || $email === '' || $sujet === '' || $message === '') {
        chatbot_json(['ok' => false, 'message' => 'Veuillez remplir tous les champs.']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        chatbot_json(['ok' => false, 'message' => 'Adresse email invalide.']);
    }

    require_once '../src/mail/googleMail.php';

    // Config mail selon le fournisseur actif
    $provider = $set['mail_provider'] ?? 'google';
    if ($provider === 'smtp') {
        $mailConfigured = !empty($set['smtp_host']) && !empty($set['smtp_user']) && !empty($set['smtp_pass']);
    } else {
        $mailConfigured = !empty($clientID) && !empty($clientSecret) && file_exists(__DIR__ . '/../config/token.json');
    }

    // Destinataires : notify_recipients si activé, sinon fallback mail_email/smtp_from_email
    $recipients = isNotifyEnabled($pdo, 'contact') ? getNotifyRecipients($pdo) : [];
    if (empty($recipients)) {
        $fallback = $set['mail_email'] ?? $set['smtp_from_email'] ?? '';
        if ($fallback) $recipients = [$fallback];
    }

    // ── Pièces jointes (facultatives) : max 3, 5 Mo/fichier, types whitelistés ──
    $attachments = [];
    $attachError = '';
    if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'] ?? null)) {
        $allowed = [
            'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif',
            'webp'=>'image/webp','pdf'=>'application/pdf',
            'doc'=>'application/msword',
            'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt'=>'text/plain',
        ];
        $maxPerFile = 5 * 1024 * 1024;
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $names = $_FILES['attachments']['name'];
        $kept = 0;
        for ($i = 0; $i < count($names); $i++) {
            $err = $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if ($err !== UPLOAD_ERR_OK) { $attachError = "Erreur lors de l'envoi d'une pièce jointe."; break; }
            if (++$kept > 3) { $attachError = "3 pièces jointes maximum."; break; }
            $tmp  = $_FILES['attachments']['tmp_name'][$i];
            $size = (int)($_FILES['attachments']['size'][$i] ?? 0);
            if ($size <= 0 || $size > $maxPerFile) { $attachError = "Chaque pièce jointe doit faire moins de 5 Mo."; break; }
            $ext  = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
            $mime = is_uploaded_file($tmp) ? ($finfo->file($tmp) ?: '') : '';
            if (!isset($allowed[$ext]) || $allowed[$ext] !== $mime) {
                $attachError = "Type de fichier non autorisé (images, PDF, Word ou texte uniquement)."; break;
            }
            $content = file_get_contents($tmp);
            if ($content === false) { $attachError = "Lecture impossible d'une pièce jointe."; break; }
            $safeName = preg_replace('/[^\w.\- ]+/u', '_', $names[$i]);
            if ($safeName === '' || $safeName === null) $safeName = 'fichier.' . $ext;
            $attachments[] = ['content' => $content, 'name' => $safeName, 'mime' => $mime];
        }
    }

    if (!$mailConfigured || empty($recipients)) {
        chatbot_json(['ok' => false, 'message' => 'Une erreur est survenue, veuillez réessayer plus tard.']);
    }
    if ($attachError !== '') {
        chatbot_json(['ok' => false, 'message' => $attachError]);
    }

    $body = "Nouveau message depuis le formulaire de contact :<br><br>";
    $body .= "<strong>Nom :</strong> " . htmlspecialchars($nom) . "<br>";
    $body .= "<strong>Email :</strong> " . htmlspecialchars($email) . "<br>";
    $body .= "<strong>Sujet :</strong> " . htmlspecialchars($sujet) . "<br><br>";
    $body .= "<strong>Message :</strong><br>" . nl2br(htmlspecialchars($message));
    if (!empty($attachments)) {
        $body .= "<br><br><strong>Pièce(s) jointe(s) :</strong><br>"
               . implode("<br>", array_map(fn($a) => '• ' . htmlspecialchars($a['name']), $attachments));
    }

    $contactEmail = count($recipients) === 1 ? $recipients[0] : $recipients;
    $sent = sendMail(
        $contactEmail,
        "Contact - " . $sujet,
        "Nouveau message de contact",
        $body,
        null, null, 'info', null, null,
        $attachments
    );

    if ($sent) {
        chatbot_json(['ok' => true, 'message' => 'Votre message a bien été envoyé ! Nous vous répondrons dans les meilleurs délais. 💗']);
    }
    chatbot_json(['ok' => false, 'message' => 'Une erreur est survenue, veuillez réessayer plus tard.']);
}

chatbot_json(['ok' => false, 'message' => 'Action inconnue.'], 400);
