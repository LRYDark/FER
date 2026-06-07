<?php
require_once 'config.php'; // Inclure le fichier fusionné (même dossier)

// Force global scope — nécessaire quand googleMail.php est chargé depuis une fonction
global $data, $clientID, $clientSecret, $googleMailReady;

$stmt = $pdo->prepare(
    'SELECT *
       FROM setting
      WHERE id = :id
      LIMIT 1');
$stmt->execute(['id' => 1]);

$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$clientID = decrypt($data['client_id'] ?? null);
$clientSecret = decrypt($data['client_secret'] ?? null);

$googleMailReady = ($clientID && $clientSecret);

// Fonction pour enregistrer des logs dans un fichier texte
function writeLog($message) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
    $logFile = $logDir . '/logs_google_mails.log';
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

function writeSmtpLog($message) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
    $logFile = $logDir . '/logs_smtp_mails.log';
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Fonction pour vérifier si la connexion Google est OK
function isGoogleConnectionValid() {
    global $clientID, $clientSecret, $googleMailReady;
    if (!$googleMailReady) return false;

    // 🔒 [FIX-06] token.json déplacé dans config/ — hors webroot direct (CWE-538)
    $tokenFile = __DIR__ . '/token.json';

    if (!file_exists($tokenFile)) {
        writeLog('Fichier token.json non trouvé.');
        return false;
    }

    try {
        $client = new Google_Client();
        $client->setClientId($clientID);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri(oauth2_callback_url());
        $client->addScope(Google_Service_Gmail::GMAIL_SEND);
        $client->setAccessType('offline');

        $accessToken = json_decode(file_get_contents($tokenFile), true);
        $client->setAccessToken($accessToken);

        // Vérifier si le token est valide (pas expiré ou rafraîchissable)
        if ($client->isAccessTokenExpired()) {
            $refreshToken = $accessToken['refresh_token'] ?? null;
            if ($refreshToken) {
                $newAccessToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
                if (isset($newAccessToken['error'])) {
                    writeLog('Token expiré et non rafraîchissable : ' . $newAccessToken['error_description']);
                    return false;
                }
                // Sauvegarder le nouveau token
                $newAccessToken['refresh_token'] = $refreshToken;
                file_put_contents($tokenFile, json_encode($newAccessToken));
                writeLog('Token rafraîchi automatiquement.');
                return true;
            } else {
                writeLog('Token expiré et aucun refresh token disponible.');
                return false;
            }
        }

        writeLog('Connexion Google valide.');
        return true;
    } catch (Exception $e) {
        writeLog('Erreur lors de la vérification de la connexion Google : ' . $e->getMessage());
        return false;
    }
}

// Fonction pour générer l'URL d'autorisation Google
function getGoogleAuthUrl($redirectAfterAuth = 'setting.php') {
    global $clientID, $clientSecret, $googleMailReady;
    if (!$googleMailReady) return null;

    // Générer un état CSRF pour le callback OAuth (RFC 6749 §10.12)
    if (session_status() === PHP_SESSION_NONE) session_start();
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_redirect'] = 'inc/' . $redirectAfterAuth;

    $client = new Google_Client();
    $client->setClientId($clientID);
    $client->setClientSecret($clientSecret);
    $client->setRedirectUri(oauth2_callback_url());
    $client->addScope(Google_Service_Gmail::GMAIL_SEND);
    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $client->setIncludeGrantedScopes(true);
    $client->setState($state);

    return $client->createAuthUrl();
}

// Fonction pour obtenir le jeton d'accès OAuth2
function getAccessToken(bool $autoRedirect = true) {
    global $clientID, $clientSecret, $googleMailReady;
    if (!$googleMailReady) {
        writeLog('❌ getAccessToken : googleMailReady=false (client_id ou client_secret manquant)');
        return false;
    }
    
    $tokenFile = __DIR__ . '/token.json';
    $client = new Google_Client();
    $client->setClientId($clientID);
    $client->setClientSecret($clientSecret);
    $client->setRedirectUri(oauth2_callback_url());
    $client->addScope(Google_Service_Gmail::GMAIL_SEND);
    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $client->setIncludeGrantedScopes(true);

    if (file_exists($tokenFile)) {
        $accessToken = json_decode(file_get_contents($tokenFile), true);
        $client->setAccessToken($accessToken);

        if ($client->isAccessTokenExpired()) {
            writeLog('Le jeton d\'accès est expiré, tentative de rafraîchissement...');
            
            $refreshToken = $accessToken['refresh_token'] ?? null;
            if ($refreshToken) {
                $newAccessToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);

                if (isset($newAccessToken['error'])) {
                    writeLog('Erreur lors du rafraîchissement du token : ' . $newAccessToken['error_description']);
                    if ($autoRedirect) {
                        $authUrl = $client->createAuthUrl();
                        writeLog('Redirection vers l\'authentification Google...');
                        header("Location: " . $authUrl);
                        exit();
                    }
                    return false;
                }

                $newAccessToken['refresh_token'] = $refreshToken;
                file_put_contents($tokenFile, json_encode($newAccessToken));

                writeLog('Jeton d\'accès rafraîchi avec succès.');
                return $newAccessToken['access_token'];
            } else {
                writeLog('Aucun refresh token disponible. Veuillez ré-authentifier.');
                if ($autoRedirect) {
                    $authUrl = $client->createAuthUrl();
                    writeLog('Redirection vers l\'authentification Google...');
                    header("Location: " . $authUrl);
                    exit();
                }
                return false;
            }
        }

        writeLog('Le jeton d\'accès est valide.');
        return $client->getAccessToken()['access_token'];
    } else {
        writeLog('Jeton d\'accès non trouvé. Veuillez autoriser l\'accès via OAuth2.');
        if ($autoRedirect) {
            $authUrl = $client->createAuthUrl();
            writeLog('Redirection vers l\'authentification Google...');
            header("Location: " . $authUrl);
            exit();
        }
        return false;
    }
}

/**
 * Génère un QR Code en data URI (base64 PNG) pour un inscription_no donné.
 * Utilisé uniquement pour la prévisualisation HTML dans le navigateur (admin).
 * Pour l'envoi par mail, voir generateQrCodePngBytes() qui renvoie les octets bruts
 * destinés à être embarqués en pièce jointe inline (CID).
 * Retourne '' si la lib n'est pas disponible.
 */
function generateQrCodeDataUri(string|int $inscriptionNo): string
{
    try {
        $qrCode = new \Endroid\QrCode\QrCode(
            data: (string) $inscriptionNo,
            size: 200,
            margin: 8
        );
        $writer = new \Endroid\QrCode\Writer\PngWriter();
        $result = $writer->write($qrCode);
        return $result->getDataUri();
    } catch (\Throwable $e) {
        writeLog("⚠️ Erreur génération QR Code : " . $e->getMessage());
        return '';
    }
}

/**
 * Génère un QR Code en PNG (octets bruts) pour un inscription_no donné.
 * Renvoie null si la lib n'est pas disponible ou en cas d'erreur.
 * Ces octets sont destinés à être embarqués comme image inline via CID
 * (Gmail/Outlook bloquent les data: URI dans les <img>).
 */
function generateQrCodePngBytes(string|int $inscriptionNo): ?string
{
    try {
        $qrCode = new \Endroid\QrCode\QrCode(
            data: (string) $inscriptionNo,
            size: 200,
            margin: 8
        );
        $writer = new \Endroid\QrCode\Writer\PngWriter();
        $result = $writer->write($qrCode);
        return $result->getString();
    } catch (\Throwable $e) {
        writeLog("⚠️ Erreur génération QR Code PNG : " . $e->getMessage());
        return null;
    }
}

/**
 * Détermine si le QR Code doit être inclus dans le mail pour cet inscrit.
 * Se base sur le mode configuré et le rang de l'inscrit par date d'inscription.
 */
function shouldIncludeQrCode(string|int $inscriptionNo): bool
{
    global $data, $pdo;

    $mode = $data['qrcode_mail_mode'] ?? 'none';

    if ($mode === 'none') return false;
    if ($mode === 'all') return true;

    // mode 'first_x' : vérifier le rang chronologique de cet inscrit
    // parmi les inscrits AYANT PAYÉ (montant_du > 0). Les non-payés (gratuit /
    // enfant -12 ans) sont écartés du décompte et ne reçoivent pas de QR Code.
    $limit = (int) ($data['qrcode_mail_limit'] ?? 0);
    if ($limit <= 0) return false;

    try {
        // Récupérer la date d'inscription et le montant dû de cet inscrit
        $stmtSelf = $pdo->prepare('SELECT created_at, montant_du FROM registrations WHERE inscription_no = :no LIMIT 1');
        $stmtSelf->execute(['no' => $inscriptionNo]);
        $self = $stmtSelf->fetch(PDO::FETCH_ASSOC);

        if (!$self || empty($self['created_at'])) return false;

        // Inscrit non-payé → jamais éligible en mode first_x
        if ((float) ($self['montant_du'] ?? 0) <= 0) return false;

        // Compter combien d'inscrits PAYANTS ont été créés AVANT ou en même temps
        $stmtRank = $pdo->prepare(
            'SELECT COUNT(*) FROM registrations
             WHERE montant_du > 0
               AND (created_at < :created_at
                    OR (created_at = :created_at2 AND inscription_no <= :no))'
        );
        $stmtRank->execute([
            'created_at'  => $self['created_at'],
            'created_at2' => $self['created_at'],
            'no'          => $inscriptionNo,
        ]);
        $rank = (int) $stmtRank->fetchColumn();

        return $rank <= $limit;
    } catch (\Throwable $e) {
        writeLog("⚠️ Erreur vérification QR Code limit : " . $e->getMessage());
        return false;
    }
}

function render(string $path, array $vars = []): string
{
    extract($vars, EXTR_SKIP);  // 1) crée $logoUrl, $subject, etc.
    ob_start();                 // 2) démarre le tampon
    include $path;              // 3) exécute le template
    return ob_get_clean();      // 4) récupère le rendu
}

/** @var string|null $lastMailError Dernière erreur détaillée de sendMail() */
$lastMailError = null;

/**
 * Envoie un mail via SMTP (PHPMailer).
 */
function sendMailSmtp($to, string $subject, $mailTitle = null, $description = null, $lastname = null, $firstname = null, string $type = 'info', string|int|null $inscriptionNo = null, ?string $mailSubtype = null, array $attachments = []) {
    global $data, $lastMailError, $pdo;
    $lastMailError = null;

    $smtpHost  = $data['smtp_host'] ?? '';
    $smtpPort  = (int)($data['smtp_port'] ?? 465);
    $smtpUser  = $data['smtp_user'] ?? '';
    $smtpPass  = !empty($data['smtp_pass']) ? decrypt($data['smtp_pass']) : '';
    $smtpEnc   = $data['smtp_encryption'] ?? 'ssl';
    $fromEmail = $data['smtp_from_email'] ?? $smtpUser;
    $fromName  = $data['smtp_from_name'] ?? 'Forbach en Rose';

    if (!$smtpHost || !$smtpUser || !$smtpPass) {
        $lastMailError = "Configuration SMTP incomplète.";
        writeSmtpLog("❌ " . $lastMailError);
        return false;
    }

    writeSmtpLog("Envoi SMTP vers : " . (is_array($to) ? implode(', ', $to) : $to) . " | Serveur : $smtpHost:$smtpPort ($smtpEnc)");

    /* ---------- Corps (même template que Gmail) ---------- */
    $built  = buildMailBody($to, $subject, $mailTitle, $description, $lastname, $firstname, $type, $inscriptionNo, $mailSubtype);
    $body   = $built['body'];
    $qrPng  = $built['qrPng'];
    if (empty($body)) {
        $lastMailError = "Le template mail est vide ou introuvable (mail_template.php).";
        writeSmtpLog("❌ " . $lastMailError);
        return false;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->Port       = $smtpPort;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 10;

        if ($smtpEnc === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($smtpEnc === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom($fromEmail, $fromName);

        if (is_array($to)) {
            foreach ($to as $addr) $mail->addBCC($addr);
            $mail->addAddress($fromEmail); // To: self for BCC-only
        } else {
            $mail->addAddress($to);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        // QR Code en pièce jointe inline référencée par cid:qrcode_inline
        if ($qrPng !== null) {
            $mail->addStringEmbeddedImage($qrPng, 'qrcode_inline', 'qrcode.png', 'base64', 'image/png', 'inline');
        }

        // Pièces jointes (ex. formulaire de contact)
        foreach ($attachments as $att) {
            if (!empty($att['content']) && !empty($att['name'])) {
                $mail->addStringAttachment($att['content'], $att['name'], 'base64', $att['mime'] ?? 'application/octet-stream');
            }
        }

        $mail->send();
        writeSmtpLog("✅ Mail SMTP envoyé à : " . (is_array($to) ? implode(', ', $to) : $to));
        return true;
    } catch (\Throwable $e) {
        $lastMailError = "Erreur SMTP : " . $e->getMessage();
        writeSmtpLog("❌ " . $lastMailError);
        return false;
    }
}

/**
 * Construit le corps HTML du mail (partagé entre Gmail et SMTP).
 * Renvoie un tableau ['body' => string, 'qrPng' => ?string] où qrPng contient
 * les octets PNG du QR Code à embarquer en pièce jointe inline (CID), ou null.
 */
function buildMailBody($to, string $subject, $mailTitle, $description, $lastname, $firstname, string $type, string|int|null $inscriptionNo, ?string $mailSubtype): array {
    global $data;

    $formattedDate = '';
    if ($type === 'inscription' && !empty($data['date_course'])) {
        try {
            $dateCourse = new DateTime($data['date_course']);
            $formatter = new IntlDateFormatter(
                'fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE,
                'Europe/Paris', IntlDateFormatter::GREGORIAN, 'd MMMM yyyy'
            );
            $formattedDate = $formatter->format($dateCourse);
        } catch (\Throwable $e) {
            writeLog("⚠️ Erreur formatage date : " . $e->getMessage());
        }
    }

    // Le QR Code est embarqué en pièce jointe inline référencée via cid:
    // (les data: URI sont bloquées par Gmail/Outlook et apparaissent comme image cassée).
    $qrPngBytes = null;
    $qrcodeSrc = '';
    if ($type === 'inscription' && $inscriptionNo !== null && shouldIncludeQrCode($inscriptionNo)) {
        $qrPngBytes = generateQrCodePngBytes($inscriptionNo);
        if ($qrPngBytes !== null) {
            $qrcodeSrc = 'cid:qrcode_inline';
        }
    }

    $mtcJson = $data['mail_template_config'] ?? null;
    $mtc = $mtcJson ? json_decode($mtcJson, true) : [];

    $body = render('mail_template.php', [
        'type'        => $type,
        'mailTitle'   => $mailTitle,
        'description' => $description,
        'firstname'   => $firstname,
        'lastname'    => $lastname,
        'date'        => $formattedDate,
        'instagram'   => $data['link_instagram'] ?? '',
        'facebook'    => $data['link_facebook'] ?? '',
        'cancer'      => $data['link_cancer'] ?? '',
        'mail_email'  => $data['mail_email'] ?? '',
        'mail_phone'  => $data['mail_phone'] ?? '',
        'qrcode'      => $qrcodeSrc,
        'inscription_no' => $inscriptionNo,
        'mtc'         => $mtc,
        'mail_subtype' => $mailSubtype ?? ($type === 'inscription' ? 'inscription' : 'info'),
    ]);

    return ['body' => $body, 'qrPng' => $qrPngBytes];
}

/**
 * Envoie un mail via le fournisseur actif (Google ou SMTP).
 */
function sendMail($to, string  $subject, $mailTitle = null, $description = null, $lastname = null, $firstname = null, string  $type = 'info', string|int|null $inscriptionNo = null, ?string $mailSubtype = null, array $attachments = []) {
    global $data, $lastMailError;
    $lastMailError = null;

    // Route vers SMTP si c'est le fournisseur actif
    $provider = $data['mail_provider'] ?? 'google';
    if ($provider === 'smtp') {
        return sendMailSmtp($to, $subject, $mailTitle, $description, $lastname, $firstname, $type, $inscriptionNo, $mailSubtype, $attachments);
    }

    /* ---------- Auth Gmail ---------- */
    $accessToken = getAccessToken(false);
    if (!$accessToken) {
        $lastMailError = "Impossible d'obtenir un token d'accès valide. Vérifiez la connexion Google.";
        writeLog("❌ " . $lastMailError);
        return false;
    }

    $client = new Google_Client();
    $client->setAccessToken($accessToken);
    $service = new Google_Service_Gmail($client);

    /* ---------- Destinataires ---------- */
    if (is_array($to)) {
        $bccHeader = implode(', ', $to);
        $toHeader  = '';
    } else {
        $toHeader  = $to;
        $bccHeader = '';
    }

    /* ---------- Sujet ---------- */
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    /* ---------- Corps (template partagé) ---------- */
    $built = buildMailBody($to, $subject, $mailTitle, $description, $lastname, $firstname, $type, $inscriptionNo, $mailSubtype);
    $body  = $built['body'];
    $qrPng = $built['qrPng'];

    if (empty($body)) {
        $lastMailError = "Le template mail est vide ou introuvable (mail_template.php).";
        writeLog("❌ " . $lastMailError);
        return false;
    }

    /* ---------- Construction du message ---------- */
    // L'adresse From est remplie automatiquement par Gmail API
    $from = $_SESSION['email'] ?? $data['mail_email'] ?? '';

    if ($toHeader === '') $toHeader = $from ?: 'me';
    if ($from) {
        $raw = "From: $from\r\n";
    } else {
        $raw = '';
    }
    $raw .= "To: $toHeader\r\n";
    if ($bccHeader) $raw .= "Bcc: $bccHeader\r\n";
    $raw .= "Subject: $encodedSubject\r\n";
    $raw .= "MIME-Version: 1.0\r\n";

    // Partie "contenu" : HTML seul, ou multipart/related (HTML + QR inline).
    $relBoundary = 'fer_rel_' . bin2hex(random_bytes(10));
    $buildContentPart = function () use ($qrPng, $body, $relBoundary) {
        if ($qrPng !== null) {
            // multipart/related : HTML + image inline référencée par cid:qrcode_inline
            // (Gmail/Outlook bloquent les data: URI dans les <img>, d'où l'embed CID).
            $p  = "Content-Type: multipart/related; boundary=\"$relBoundary\"; type=\"text/html\"\r\n\r\n";
            $p .= "--$relBoundary\r\n";
            $p .= "Content-Type: text/html; charset=UTF-8\r\n";
            $p .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $p .= chunk_split(base64_encode($body), 76, "\r\n") . "\r\n";
            $p .= "--$relBoundary\r\n";
            $p .= "Content-Type: image/png; name=\"qrcode.png\"\r\n";
            $p .= "Content-Transfer-Encoding: base64\r\n";
            $p .= "Content-ID: <qrcode_inline>\r\n";
            $p .= "Content-Disposition: inline; filename=\"qrcode.png\"\r\n\r\n";
            $p .= chunk_split(base64_encode($qrPng), 76, "\r\n") . "\r\n";
            $p .= "--$relBoundary--\r\n";
            return $p;
        }
        return "Content-Type: text/html; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($body), 76, "\r\n") . "\r\n";
    };

    if (!empty($attachments)) {
        // multipart/mixed : partie contenu (HTML [+ QR]) + 1..N pièces jointes.
        $mixBoundary = 'fer_mix_' . bin2hex(random_bytes(10));
        $raw .= "Content-Type: multipart/mixed; boundary=\"$mixBoundary\"\r\n\r\n";
        $raw .= "This is a multi-part message in MIME format.\r\n\r\n";
        $raw .= "--$mixBoundary\r\n";
        $raw .= $buildContentPart();
        foreach ($attachments as $att) {
            if (empty($att['content']) || empty($att['name'])) continue;
            $attName = str_replace(['"', "\r", "\n"], '', $att['name']);
            $attMime = preg_match('#^[a-z0-9.+/-]+$#i', $att['mime'] ?? '') ? $att['mime'] : 'application/octet-stream';
            $raw .= "--$mixBoundary\r\n";
            $raw .= "Content-Type: $attMime; name=\"$attName\"\r\n";
            $raw .= "Content-Transfer-Encoding: base64\r\n";
            $raw .= "Content-Disposition: attachment; filename=\"$attName\"\r\n\r\n";
            $raw .= chunk_split(base64_encode($att['content']), 76, "\r\n") . "\r\n";
        }
        $raw .= "--$mixBoundary--\r\n";
    } elseif ($qrPng !== null) {
        // HTML + QR inline (sans pièce jointe). buildContentPart() commence par
        // l'en-tête Content-Type, qui doit suivre immédiatement MIME-Version.
        $raw .= $buildContentPart();
    } else {
        $raw .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $raw .= $body;
    }

    $mime = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    $msg  = new Google_Service_Gmail_Message();
    $msg->setRaw($mime);

    try {
        $service->users_messages->send('me', $msg);
        writeLog("✅ Mail envoyé à : " . (is_array($to) ? implode(', ', $to) : $toHeader));
        return true;
    } catch (\Throwable $e) {
        $lastMailError = "Erreur d'envoi Gmail : " . $e->getMessage();
        writeLog("❌ " . $lastMailError);
        return false;
    }
}

// Fonction pour supprimer le token (déconnexion)
function revokeGoogleConnection() {
    $tokenFile = __DIR__ . '/token.json';
    
    if (file_exists($tokenFile)) {
        unlink($tokenFile);
        writeLog('Token supprimé - Déconnexion Google effectuée.');
        return true;
    }
    
    return false;
}
?>