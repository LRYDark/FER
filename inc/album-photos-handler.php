<?php
/**
 * AJAX handler for local album photo operations (upload / delete / list)
 * Returns JSON responses.
 */
require '../config/config.php';
require_once __DIR__ . '/../config/csrf.php';
requireRole(['admin']);

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$albumId = (int)($_POST['album_id'] ?? $_GET['album_id'] ?? 0);

if ($albumId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'album_id manquant']);
    exit;
}

// Fetch album and verify it's a local album
$stmt = $pdo->prepare("SELECT * FROM photo_albums WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$albumId]);
$album = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$album) {
    http_response_code(404);
    echo json_encode(['error' => 'Album introuvable']);
    exit;
}

if (($album['album_type'] ?? 'link') !== 'local') {
    http_response_code(400);
    echo json_encode(['error' => 'Cet album n\'est pas un album local']);
    exit;
}

$folderName = $album['album_link'];
$folderPath = realpath(__DIR__ . '/../files/_albums') . DIRECTORY_SEPARATOR . $folderName;

// Ensure folder exists
if (!is_dir($folderPath)) {
    mkdir($folderPath, 0755, true);
}

// ─── LIST photos ───
if ($action === 'list') {
    $photos = scanAlbumPhotos($folderPath, $folderName);
    echo json_encode(['photos' => $photos]);
    exit;
}

// CSRF check for POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF invalide']);
    exit;
}

// ─── UPLOAD photos ───
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $uploaded = [];
    $errors = [];

    $files = $_FILES['photos'] ?? null;
    if (!$files || empty($files['name'][0])) {
        http_response_code(400);
        echo json_encode(['error' => 'Aucun fichier']);
        exit;
    }

    $fileCount = count($files['name']);
    for ($i = 0; $i < $fileCount; $i++) {
        $name = $files['name'][$i];
        $tmp = $files['tmp_name'][$i];
        $error = $files['error'][$i];

        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = "$name: erreur d'upload (code $error)";
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = mime_content_type($tmp);

        if (!in_array($ext, $allowedExts) || !in_array($mime, $allowedMimes)) {
            $errors[] = "$name: format non autorise ($ext / $mime)";
            continue;
        }

        $safeName = uniqid('photo_', true) . '.' . $ext;
        $dest = $folderPath . DIRECTORY_SEPARATOR . $safeName;

        if (move_uploaded_file($tmp, $dest)) {
            $uploaded[] = [
                'filename' => $safeName,
                'url' => '../files/_albums/' . $folderName . '/' . $safeName,
                'original' => $name
            ];
        } else {
            $errors[] = "$name: echec du deplacement";
        }
    }

    // Auto-set thumbnail from first photo if album has no image yet
    if (!empty($uploaded) && empty($album['album_img'])) {
        $firstPhoto = $uploaded[0]['filename'];
        $thumbName = uniqid('album_', true) . '.' . pathinfo($firstPhoto, PATHINFO_EXTENSION);
        $src = $folderPath . DIRECTORY_SEPARATOR . $firstPhoto;
        $thumbDest = realpath(__DIR__ . '/../files/_albums') . DIRECTORY_SEPARATOR . $thumbName;
        if (copy($src, $thumbDest)) {
            $stmtThumb = $pdo->prepare("UPDATE photo_albums SET album_img = ? WHERE id = ?");
            $stmtThumb->execute([$thumbName, $albumId]);
        }
    }

    echo json_encode([
        'uploaded' => $uploaded,
        'errors' => $errors,
        'total' => $fileCount,
        'success_count' => count($uploaded),
        'error_count' => count($errors)
    ]);
    exit;
}

// ─── DELETE ALL photos ───
if ($action === 'delete_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $deleted = 0;
    if (is_dir($folderPath)) {
        foreach (scandir($folderPath) as $f) {
            if ($f === '.' || $f === '..') continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts)) continue;
            if (unlink($folderPath . DIRECTORY_SEPARATOR . $f)) $deleted++;
        }
    }
    echo json_encode(['ok' => true, 'deleted' => $deleted]);
    exit;
}

// ─── DELETE a photo ───
if ($action === 'delete_photo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $filename = basename($_POST['filename'] ?? '');
    if ($filename === '') {
        http_response_code(400);
        echo json_encode(['error' => 'filename manquant']);
        exit;
    }

    $filePath = $folderPath . DIRECTORY_SEPARATOR . $filename;
    if (is_file($filePath)) {
        unlink($filePath);
        echo json_encode(['ok' => true, 'deleted' => $filename]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Fichier introuvable']);
    }
    exit;
}

// ─── SET thumbnail ───
if ($action === 'set_thumbnail' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $filename = basename($_POST['filename'] ?? '');
    if ($filename === '') {
        http_response_code(400);
        echo json_encode(['error' => 'filename manquant']);
        exit;
    }

    $src = $folderPath . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($src)) {
        http_response_code(404);
        echo json_encode(['error' => 'Fichier introuvable']);
        exit;
    }

    // Copy as thumbnail
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $thumbName = uniqid('album_', true) . '.' . $ext;
    $thumbDest = realpath(__DIR__ . '/../files/_albums') . DIRECTORY_SEPARATOR . $thumbName;

    // Remove old thumbnail
    if (!empty($album['album_img'])) {
        $oldThumb = realpath(__DIR__ . '/../files/_albums') . DIRECTORY_SEPARATOR . $album['album_img'];
        if (is_file($oldThumb)) {
            unlink($oldThumb);
        }
    }

    if (copy($src, $thumbDest)) {
        $stmt = $pdo->prepare("UPDATE photo_albums SET album_img = ? WHERE id = ?");
        $stmt->execute([$thumbName, $albumId]);
        echo json_encode(['ok' => true, 'thumbnail' => $thumbName]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Echec de la copie']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Action inconnue']);

// ─── Helper ───
function scanAlbumPhotos(string $folderPath, string $folderName): array
{
    $photos = [];
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!is_dir($folderPath)) return [];

    $files = scandir($folderPath);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) continue;
        $fullPath = $folderPath . DIRECTORY_SEPARATOR . $f;
        $photos[] = [
            'filename' => $f,
            'url' => '../files/_albums/' . $folderName . '/' . $f,
            'size' => filesize($fullPath),
            'modified' => filemtime($fullPath)
        ];
    }

    // Sort by modification time desc
    usort($photos, function ($a, $b) {
        return $b['modified'] - $a['modified'];
    });

    return $photos;
}
