<?php
/**
 * Upload handler for mail template header image.
 * Stores in files/_imagemail/ and returns JSON with the path.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/csrf.php';

header('Content-Type: application/json');
requirePage('mail-settings');
if (!canDoAction('mail.write')) {
    http_response_code(403);
    echo json_encode(['error' => 'Action non autorisée (lecture seule).']);
    exit;
}

if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF invalide']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Aucun fichier envoyé']);
    exit;
}

$file = $_FILES['file'];

// 🔒 [SEC-SVG] Pas de SVG : un SVG est un document XML pouvant embarquer du
// <script>. Servi statiquement depuis files/_imagemail/ (hors CSP PHP), il
// s'exécuterait dans l'origine du site (XSS stocké). Formats raster uniquement.
$allowedTypes = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];

$maxSize = 5 * 1024 * 1024; // 5 MB

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);

if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'Fichier trop volumineux (max 5 Mo)']);
    exit;
}

if (!isset($allowedTypes[$ext]) || $allowedTypes[$ext] !== $mimeType) {
    http_response_code(400);
    echo json_encode(['error' => 'Type non autorisé (images uniquement)']);
    exit;
}

$uploadDir = __DIR__ . '/../files/_imagemail/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$safeName = 'header_' . uniqid() . '.' . $ext;

if (!move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) {
    http_response_code(500);
    echo json_encode(['error' => 'Échec de l\'upload']);
    exit;
}

echo json_encode([
    'ok'       => true,
    'path'     => '../files/_imagemail/' . $safeName,
    'filename' => $safeName,
]);
