<?php
/**
 * TinyMCE file upload handler
 * Receives images or PDF from TinyMCE editor and saves them to files/_tiny/
 * Returns JSON with the file location for TinyMCE to insert.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/csrf.php';

header('Content-Type: application/json');

// Auth check
requireRole(['admin', 'user']);

// CSRF check
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];

// Types autorisés : images + PDF
$allowedTypes = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
];

$maxSize = 10 * 1024 * 1024; // 10 MB

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);

if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'Fichier trop volumineux (max 10 Mo)']);
    exit;
}

if (!isset($allowedTypes[$ext]) || $allowedTypes[$ext] !== $mimeType) {
    http_response_code(400);
    echo json_encode(['error' => 'Type de fichier non autorisé (images et PDF uniquement)']);
    exit;
}

$uploadDir = __DIR__ . '/../files/_tiny/';
$safeName  = uniqid('tiny_', true) . '.' . $ext;

if (!move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) {
    http_response_code(500);
    echo json_encode(['error' => 'Upload failed']);
    exit;
}

echo json_encode([
    'location' => '../files/_tiny/' . $safeName,
    'title'    => pathinfo($file['name'], PATHINFO_FILENAME),
]);
