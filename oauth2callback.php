<?php
require 'config/config.php';

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
    die("Clé OAuth manquante. (oauth2callback.php)");
}

// Fonction pour enregistrer des logs
function writeLog($message) {
    $logFile = __DIR__ . '/config/logs_google_mails.txt';
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Vérifier si on a reçu un code d'autorisation
if (!isset($_GET['code'])) {
    writeLog('Erreur OAuth : Aucun code d\'autorisation reçu');
    die('Erreur : aucun code OAuth reçu.');
}

try {
    // Configuration du client Google
    $client = new Google_Client();
    $client->setClientId($clientID);
    $client->setClientSecret($clientSecret);
    $client->setRedirectUri(oauth2_callback_url());
    $client->addScope(Google_Service_Gmail::GMAIL_SEND);
    $client->setAccessType('offline');
    $client->setPrompt('consent');

    // Échanger le code d'autorisation contre un jeton d'accès
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

    if (isset($token['error'])) {
        $errMsg = ($token['error_description'] ?? $token['error']) . ' | Details: ' . json_encode($token);
        writeLog('Erreur OAuth : ' . $errMsg);
        die('Erreur OAuth : ' . htmlspecialchars($errMsg));
    }

    // Sauvegarder le token dans token.json à la racine
    $tokenFile = __DIR__ . '/token.json';
    file_put_contents($tokenFile, json_encode($token));
    
    writeLog('✅ Token OAuth2 généré et sauvegardé avec succès dans : ' . $tokenFile);

    // Déterminer où rediriger l'utilisateur
    $redirectUrl = $_GET['redirect'] ?? 'inc/setting.php';
    
    // S'assurer que l'URL de redirection est relative et sécurisée
    if (strpos($redirectUrl, 'http') === 0 || strpos($redirectUrl, '//') !== false) {
        $redirectUrl = 'inc/setting.php'; // Sécurité : forcer une redirection locale
    }
    
    writeLog('Redirection vers : ' . $redirectUrl);
    
    // Rediriger avec un message de succès
    header("Location: " . $redirectUrl . "?auth=success");
    exit();

} catch (Exception $e) {
    writeLog('❌ Erreur lors de la génération du token : ' . $e->getMessage());
    
    // Rediriger vers la page de paramètres avec un message d'erreur
    $redirectUrl = $_GET['redirect'] ?? 'inc/setting.php';
    if (strpos($redirectUrl, 'http') === 0 || strpos($redirectUrl, '//') !== false) {
        $redirectUrl = 'inc/setting.php';
    }
    
    header("Location: " . $redirectUrl . "?auth=error&message=" . urlencode($e->getMessage()));
    exit();
}
?>