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

function sendMail($to, $subject, $htmlMessage, $dateBlock = '') {
    $accessToken = getAccessToken();
    if (!$accessToken) {
        writeLog("❌ Impossible d'obtenir un token d'accès valide pour l'envoi de mail.");
        return false;
    }

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