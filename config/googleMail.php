<?php
require_once __DIR__ . '/../vendor/autoload.php';   // charge l’autoloader Composer
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__); // si .env est à la racine de config
$dotenv->load();

$clientID = $_ENV['GOOGLE_CLIENT_ID'] ?? null;
$clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? null;

if (!$clientID || !$clientSecret) {
    die("Clé OAuth manquante. Veuillez vérifier le fichier .env.");
}

// Fonction pour enregistrer des logs dans un fichier texte
function writeLog($message) {
    $logFile = __DIR__ .'/logs_google_mails.txt'; // Nom du fichier de log
    $timestamp = date("Y-m-d H:i:s"); // Ajoute un timestamp au message de log
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND); // Écrit le message dans le fichier
}

// Fonction pour obtenir le jeton d'accès OAuth2
function getAccessToken() {
    global $clientID, $clientSecret;
    
    $token = __DIR__ .'/../token.json';
    $client = new Google_Client();
    $client->setClientId($clientID);
    $client->setClientSecret($clientSecret);
    $client->setRedirectUri('https://jr.zerobug-57.fr/FER/oauth2callback.php'); // URI de redirection configurée dans Google Cloud
    $client->addScope(Google_Service_Gmail::GMAIL_SEND);
    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $client->setIncludeGrantedScopes(true);
    $client->setAccessType('offline');

    if (file_exists($token)) {
        $accessToken = json_decode(file_get_contents($token), true);
        $client->setAccessToken($accessToken);

        if ($client->isAccessTokenExpired()) {
            writeLog('Le jeton d\'accès est expiré, tentative de rafraîchissement...');
            
            $refreshToken = $accessToken['refresh_token'] ?? null;
            if ($refreshToken) {
                $newAccessToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);

                if (isset($newAccessToken['error'])) {
                    writeLog('Erreur lors du rafraîchissement du token : ' . $newAccessToken['error_description']);
                    die('Erreur OAuth : ' . $newAccessToken['error_description']);
                }

                $newAccessToken['refresh_token'] = $refreshToken;
                file_put_contents($token, json_encode($newAccessToken));

                writeLog('Jeton d\'accès rafraîchi avec succès.');
                return $newAccessToken['access_token'];
            } else {
                writeLog('Aucun refresh token disponible. Veuillez ré-authentifier.');
                die('Aucun refresh token disponible. Veuillez ré-authentifier.');
            }
        }

        writeLog('Le jeton d\'accès est valide.');
        return $client->getAccessToken()['access_token'];
    } else {
        writeLog('Jeton d\'accès non trouvé. Veuillez autoriser l\'accès via OAuth2.');
        if (!file_exists($token)) {
            // Rediriger l'utilisateur vers l'authentification OAuth2
            $authUrl = $client->createAuthUrl();
            writeLog('Regénération du token ou Redirection vers l\'authentification Google...');
            header("Location: " . $authUrl);
            exit();
        }
    }
}

function sendMail($to, $subject, $htmlMessage) {
    $accessToken = getAccessToken();
    $client = new Google_Client();
    $client->setAccessToken($accessToken);
    $service = new Google_Service_Gmail($client);

    $from = 'reinert.joris@gmail.com';
    $encodedSubject = '=?UTF-8?B?' . base64_encode('JR | Maintenance Serveur') . '?=';

    $logoUrl = "https://jr.zerobug-57.fr/reset-password/images/jr-black.png";

    // Construction du mail HTML
    $body = <<<HTML
    <!DOCTYPE html>
    <html>
    <body style="font-family: Arial, sans-serif; color: #333;">
        <div style="max-width: 600px; margin: auto; padding: 20px; border-radius: 8px; background: #f9f9f9;">
            <div style="text-align: center; margin-bottom: 20px;">
                <img src="$logoUrl" alt="Logo JR" style="max-width: 90px;">
            </div>
            <h2 style="text-align: center;">Maintenance : $subject</h2>
            <p style="font-size: 16px;">Bonjour,</p>
            <p style="font-size: 16px;">
                Une opération de maintenance est en cours ou programmée.
            </p>
            $dateBlock
            <p style="font-size: 16px; background: #fff3cd; padding: 10px; border-left: 5px solid #ffc107;">
                <strong>Description :</strong><br>
                $htmlMessage
            </p>
            <p style="font-size: 16px;">
                Merci pour votre compréhension.
            </p>
        </div>
    </body>
    </html>
    HTML;

    $strRawMessage = "From: $from\r\n";
    $strRawMessage .= "To: $to\r\n";
    $strRawMessage .= "Subject: $encodedSubject\r\n";
    $strRawMessage .= "MIME-Version: 1.0\r\n";
    $strRawMessage .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $strRawMessage .= $body;

    $mime = rtrim(strtr(base64_encode($strRawMessage), '+/', '-_'), '=');
    $message = new Google_Service_Gmail_Message();
    $message->setRaw($mime);

    try {
        $service->users_messages->send('me', $message);
        writeLog("✅ Mail envoyé avec succès à : $to | Sujet : $subject");
        return true;
    } catch (Exception $e) {
        writeLog("❌ Erreur d'envoi de mail à $to : " . $e->getMessage());
        return false;
    }
}