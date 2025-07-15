<?php
require '../config/config.php';

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$id = intval($_POST['id'] ?? 0);
$type = $_POST['type'] ?? '';

if (!in_array($type, ['like', 'dislike']) || $id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Paramètres invalides']);
    exit;
}

$stmt = $pdo->prepare("UPDATE news SET `$type` = `$type` + 1 WHERE id = :id");
if (!$stmt->execute(['id' => $id])) {
    echo json_encode(['success' => false, 'error' => 'Erreur SQL']);
    exit;
}

$stmt = $pdo->prepare("SELECT `$type` FROM news WHERE id = :id");
$stmt->execute(['id' => $id]);
$count = $stmt->fetchColumn();

echo json_encode(['success' => true, 'count' => $count]);
