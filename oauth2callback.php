<?php
require 'config/config.php';
require_once __DIR__ . '/vendor/autoload.php'; // Adapter si nécessaire


$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/config/');
$dotenv->load();

$clientID = $_ENV['GOOGLE_CLIENT_ID'] ?? null;
$clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? null;

if (!$clientID || !$clientSecret) {
    die("Clé OAuth manquante. Veuillez vérifier le fichier .env.");
}

$client = new Google_Client();
$client->setClientId($clientID);
$client->setClientSecret($clientSecret);
$client->setRedirectUri('https://jr.zerobug-57.fr/FER/oauth2callback.php');
$client->addScope(Google_Service_Gmail::GMAIL_SEND);
$client->setAccessType('offline');

if (!isset($_GET['code'])) {
    die('Erreur : aucun code OAuth reçu.');
}

// Échanger le code d'autorisation contre un jeton d'accès
$token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

if (isset($token['error'])) {
    die('Erreur OAuth : ' . $token['error_description']);
}

// Sauvegarder le token dans token.json
file_put_contents('token.json', json_encode($token));

header("Location: index.php");
exit();