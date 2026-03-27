<?php
require 'config/config.php';

// ── Vérifier que l'utilisateur est un admin authentifié ──────────────────────
requireRole(['admin']);

// ── Vérifier le paramètre state (protection CSRF OAuth — RFC 6749 §10.12) ───
if (!isset($_GET['state']) || !isset($_SESSION['oauth_state'])
    || !hash_equals($_SESSION['oauth_state'], $_GET['state'])) {
    error_log('OAuth2callback : état CSRF invalide ou absent.');
    unset($_SESSION['oauth_state']);
    header('Location: inc/setting.php?auth=error&message=' . urlencode('État OAuth invalide. Veuillez relancer l\'autorisation.'));
    exit;
}
unset($_SESSION['oauth_state']);

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
    $logFile = __DIR__ . '/config/logs/logs_google_mails.txt';
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

    // 🔒 [FIX-06] token.json stocké dans config/ (protégé par .htaccess) (CWE-538)
    $tokenFile = __DIR__ . '/config/token.json';
    // ⚠️ [IMPACT FONCTIONNEL] Mettre à jour googleMail.php : __DIR__.'/../token.json' → __DIR__.'/token.json'
    file_put_contents($tokenFile, json_encode($token));
    
    writeLog('✅ Token OAuth2 généré et sauvegardé avec succès dans : ' . $tokenFile);

    // Déterminer où rediriger l'utilisateur
    $redirectUrl = $_GET['redirect'] ?? 'inc/setting.php';

    // S'assurer que l'URL de redirection est relative et sécurisée
    if (!preg_match('/^[a-zA-Z0-9\/_\-\.]+(\?[a-zA-Z0-9=&_\-]*)?$/', $redirectUrl)) {
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
    if (!preg_match('/^[a-zA-Z0-9\/_\-\.]+(\?[a-zA-Z0-9=&_\-]*)?$/', $redirectUrl)) {
        $redirectUrl = 'inc/setting.php';
    }
    
    header("Location: " . $redirectUrl . "?auth=error&message=" . urlencode($e->getMessage()));
    exit();
}
?>