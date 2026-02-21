<?php
require_once 'config.php'; // Inclure le fichier fusionné (même dossier)

$stmt = $pdo->prepare(
    'SELECT *
       FROM setting
      WHERE id = :id
      LIMIT 1');
$stmt->execute(['id' => 1]);

$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$clientID = decrypt($data['client_id'] ?? null);
$clientSecret = decrypt($data['client_secret'] ?? null);

if (!$clientID || !$clientSecret) {
    die("Clé OAuth manquante. (googleMail.php)");
}

// Fonction pour enregistrer des logs dans un fichier texte
function writeLog($message) {
    $logFile = __DIR__ .'/logs_google_mails.txt'; // Nom du fichier de log
    $timestamp = date("Y-m-d H:i:s"); // Ajoute un timestamp au message de log
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND); // Écrit le message dans le fichier
}

// Fonction pour vérifier si la connexion Google est OK
function isGoogleConnectionValid() {
    global $clientID, $clientSecret;
    
    $tokenFile = __DIR__ .'/../token.json';
    
    if (!file_exists($tokenFile)) {
        writeLog('Fichier token.json non trouvé.');
        return false;
    }

    try {
        $client = new Google_Client();
        $client->setClientId($clientID);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri('https://jr.zerobug-57.fr/FER/oauth2callback.php');
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
    global $clientID, $clientSecret;
    
    $client = new Google_Client();
    $client->setClientId($clientID);
    $client->setClientSecret($clientSecret);
    $client->setRedirectUri('https://jr.zerobug-57.fr/FER/oauth2callback.php');
    $client->addScope(Google_Service_Gmail::GMAIL_SEND);
    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $client->setIncludeGrantedScopes(true);

    return $client->createAuthUrl();
}

// Fonction pour obtenir le jeton d'accès OAuth2
function getAccessToken(bool $autoRedirect = true) {
    global $clientID, $clientSecret;
    
    $tokenFile = __DIR__ .'/../token.json';
    $client = new Google_Client();
    $client->setClientId($clientID);
    $client->setClientSecret($clientSecret);
    $client->setRedirectUri('https://jr.zerobug-57.fr/FER/oauth2callback.php');
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

function sendMail($to, string  $subject, $mailTitle = null, $description = null, $lastname = null, $firstname = null, string  $type = 'info') {
    global $data;
    /* ---------- Auth Gmail ---------- */
    $accessToken = getAccessToken();
    if (!$accessToken) {
        writeLog("❌ Impossible d'obtenir un token d'accès valide.");
        return false;
    }

    $client = new Google_Client();
    $client->setAccessToken($accessToken);
    $service = new Google_Service_Gmail($client);

    /* ---------- Destinataires ---------- */
    // $to  peut être tableau ou chaîne déjà formatée
    if (is_array($to)) {
        // Tableau → on place tout en Bcc:
        $bccHeader = implode(', ', $to);           // mail1, mail2, ...
        $toHeader  = 'undisclosed-recipients:;';   // champ To “public” vide
    } else {
        // Chaîne (déjà "Nom <mail>, Nom2 <mail2>")
        $toHeader  = $to;
        $bccHeader = '';                           // pas de Bcc
    }

    /* ---------- Sujet ---------- */
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    /* ---------- Corps ---------- */
    switch ($type) {
        case 'info':
            $body = render('mail_info.php', [
                'mailTitle'   => $mailTitle,
                'description' => $description,
                'instagram'   => $data['link_instagram'] ?? '',
                'facebook'    => $data['link_facebook'] ?? '',
            ]);
            break;

        case 'inscription':
            $formattedDate = '';
            if (!empty($data['date_course'])) {
                $dateCourse = new DateTime($data['date_course']);
                $formatter = new IntlDateFormatter(
                    'fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE,
                    'Europe/Paris', IntlDateFormatter::GREGORIAN, 'd MMMM yyyy'
                );
                $formattedDate = $formatter->format($dateCourse);
            }
            $body = render('mail_inscription.php', [
                'firstname'   => $firstname,
                'lastname'    => $lastname,
                'date'        => $formattedDate,
                'instagram'   => $data['link_instagram'] ?? '',
                'facebook'    => $data['link_facebook'] ?? '',
            ]);
            break;

        default:
            throw new InvalidArgumentException('Type de mail inconnu : ' . $type);
    }

    /* ---------- Construction du message ---------- */
    $from = 'reinert.joris@gmail.com';

    $raw  = "From: $from\r\n";
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
    } catch (Exception $e) {
        writeLog("❌ Erreur d'envoi : " . $e->getMessage());
        return false;
    }
}

// Fonction pour supprimer le token (déconnexion)
function revokeGoogleConnection() {
    $tokenFile = __DIR__ .'/../token.json';
    
    if (file_exists($tokenFile)) {
        unlink($tokenFile);
        writeLog('Token supprimé - Déconnexion Google effectuée.');
        return true;
    }
    
    return false;
}
?>