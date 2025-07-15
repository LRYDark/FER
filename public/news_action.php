<?php
require '../config/config.php';
header('Content-Type: application/json');

$id = intval($_POST['id'] ?? 0);
$type = $_POST['type'] ?? '';
$remove = $_POST['remove'] ?? null;

if (!in_array($type, ['like', 'dislike']) || $id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Paramètres invalides']);
    exit;
}

$pdo->beginTransaction();

try {
    if (in_array($remove, ['like', 'dislike'])) {
        $stmt = $pdo->prepare("UPDATE news SET `$remove` = `$remove` - 1 WHERE id = :id AND `$remove` > 0");
        $stmt->execute(['id' => $id]);
    }

    $stmt = $pdo->prepare("UPDATE news SET `$type` = `$type` + 1 WHERE id = :id");
    $stmt->execute(['id' => $id]);

    $stmt = $pdo->prepare("SELECT `like`, `dislike` FROM news WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();
    echo json_encode(['success' => true, 'count' => $counts]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => 'Erreur SQL']);
}
