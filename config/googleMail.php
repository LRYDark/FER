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
    $logFile = $logDir . '/logs_google_mails.txt';
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

function render(string $path, array $vars = []): string
{
    extract($vars, EXTR_SKIP);  // 1) crée $logoUrl, $subject, etc.
    ob_start();                 // 2) démarre le tampon
    include $path;              // 3) exécute le template
    return ob_get_clean();      // 4) récupère le rendu
}

/** @var string|null $lastMailError Dernière erreur détaillée de sendMail() */
$lastMailError = null;

function sendMail($to, string  $subject, $mailTitle = null, $description = null, $lastname = null, $firstname = null, string  $type = 'info') {
    global $data, $lastMailError;
    $lastMailError = null;

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

    /* ---------- Corps (template unique) ---------- */
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

    $body = render('mail_template.php', [
        'type'        => $type,
        'mailTitle'   => $mailTitle,
        'description' => $description,
        'firstname'   => $firstname,
        'lastname'    => $lastname,
        'date'        => $formattedDate,
        'instagram'   => $data['link_instagram'] ?? '',
        'facebook'    => $data['link_facebook'] ?? '',
        'mail_email'  => $data['mail_email'] ?? '',
        'mail_phone'  => $data['mail_phone'] ?? '',
    ]);

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
    $raw .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $raw .= $body;

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