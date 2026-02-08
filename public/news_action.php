<?php
require '../config/config.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ─── Routage par action ───
switch ($action) {

// ════════════════════════════════════════════════════
//  COMMENTAIRES — Endpoints publics
// ════════════════════════════════════════════════════

case 'get_comments':
    $newsId = intval($_GET['news_id'] ?? 0);
    if ($newsId <= 0) { echo json_encode(['success' => false]); exit; }

    $stmt = $pdo->prepare('SELECT id, parent_id, author_name, content, likes, created_at FROM news_comments WHERE news_id = :nid ORDER BY created_at ASC');
    $stmt->execute(['nid' => $newsId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Grouper parent / replies
    $parents = [];
    $replies = [];
    foreach ($rows as $r) {
        $r['likes'] = (int)$r['likes'];
        if ($r['parent_id'] === null) {
            $r['replies'] = [];
            $parents[$r['id']] = $r;
        } else {
            $replies[] = $r;
        }
    }
    foreach ($replies as $r) {
        $pid = (int)$r['parent_id'];
        if (isset($parents[$pid])) {
            $parents[$pid]['replies'][] = $r;
        }
    }

    // Trier parents par date DESC (plus recent en premier)
    $result = array_values($parents);
    usort($result, function($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
    });

    echo json_encode(['success' => true, 'comments' => $result, 'total' => count($rows)]);
    exit;

case 'add_comment':
    $newsId = intval($_POST['news_id'] ?? 0);
    $authorName = trim(strip_tags($_POST['author_name'] ?? ''));
    $content = trim(strip_tags($_POST['content'] ?? ''));
    $parentId = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Validation
    if ($newsId <= 0 || $authorName === '' || $content === '') {
        echo json_encode(['success' => false, 'error' => 'Veuillez remplir tous les champs obligatoires.']);
        exit;
    }
    if (mb_strlen($authorName) > 100) $authorName = mb_substr($authorName, 0, 100);
    if (mb_strlen($content) > 2000) $content = mb_substr($content, 0, 2000);

    // Check IP ban
    $stmtBan = $pdo->prepare('SELECT id FROM news_banned_ips WHERE ip_address = :ip LIMIT 1');
    $stmtBan->execute(['ip' => $ip]);
    if ($stmtBan->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Vous ne pouvez pas commenter.']);
        exit;
    }

    // Rate limit : 30s
    $stmtRate = $pdo->prepare('SELECT id FROM news_comments WHERE news_id = :nid AND ip_address = :ip AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND) LIMIT 1');
    $stmtRate->execute(['nid' => $newsId, 'ip' => $ip]);
    if ($stmtRate->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Veuillez patienter avant de poster un nouveau commentaire.']);
        exit;
    }

    // Flatten nested replies (max 1 level)
    if ($parentId !== null) {
        $stmtP = $pdo->prepare('SELECT id, parent_id FROM news_comments WHERE id = :id LIMIT 1');
        $stmtP->execute(['id' => $parentId]);
        $parentRow = $stmtP->fetch(PDO::FETCH_ASSOC);
        if (!$parentRow) {
            $parentId = null;
        } elseif ($parentRow['parent_id'] !== null) {
            $parentId = (int)$parentRow['parent_id'];
        }
    }

    $stmtIns = $pdo->prepare('INSERT INTO news_comments (news_id, parent_id, author_name, content, ip_address) VALUES (:nid, :pid, :author, :content, :ip)');
    $stmtIns->execute([
        'nid' => $newsId,
        'pid' => $parentId,
        'author' => $authorName,
        'content' => $content,
        'ip' => $ip
    ]);

    echo json_encode(['success' => true]);
    exit;

case 'like_comment':
    $commentId = intval($_POST['comment_id'] ?? 0);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if ($commentId <= 0) { echo json_encode(['success' => false]); exit; }

    // Check duplicate
    $stmtChk = $pdo->prepare('SELECT id FROM news_comments_likes WHERE comment_id = :cid AND ip_address = :ip LIMIT 1');
    $stmtChk->execute(['cid' => $commentId, 'ip' => $ip]);
    if ($stmtChk->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Deja like']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO news_comments_likes (comment_id, ip_address) VALUES (:cid, :ip)')->execute(['cid' => $commentId, 'ip' => $ip]);
        $pdo->prepare('UPDATE news_comments SET likes = likes + 1 WHERE id = :id')->execute(['id' => $commentId]);
        $stmtL = $pdo->prepare('SELECT likes FROM news_comments WHERE id = :id');
        $stmtL->execute(['id' => $commentId]);
        $likes = (int)$stmtL->fetchColumn();
        $pdo->commit();
        echo json_encode(['success' => true, 'likes' => $likes]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false]);
    }
    exit;

// ════════════════════════════════════════════════════
//  COMMENTAIRES — Endpoints admin
// ════════════════════════════════════════════════════

case 'get_admin_comments':
    if (!isset($_SESSION['uid']) || ($_SESSION['role'] ?? '') !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Non autorise']);
        exit;
    }
    $newsId = intval($_GET['news_id'] ?? 0);
    if ($newsId <= 0) { echo json_encode(['success' => false]); exit; }

    $stmt = $pdo->prepare('
        SELECT c.*, (b.id IS NOT NULL) as is_banned, b.reason as ban_reason
        FROM news_comments c
        LEFT JOIN news_banned_ips b ON c.ip_address = b.ip_address
        WHERE c.news_id = :nid
        ORDER BY c.created_at DESC
    ');
    $stmt->execute(['nid' => $newsId]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($comments as &$c) {
        $c['is_banned'] = (bool)$c['is_banned'];
        $c['likes'] = (int)$c['likes'];
    }
    echo json_encode(['success' => true, 'comments' => $comments]);
    exit;

case 'delete_comment':
    if (!isset($_SESSION['uid']) || ($_SESSION['role'] ?? '') !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Non autorise']);
        exit;
    }
    $commentId = intval($_POST['comment_id'] ?? 0);
    if ($commentId <= 0) { echo json_encode(['success' => false]); exit; }
    $pdo->prepare('DELETE FROM news_comments WHERE id = :id')->execute(['id' => $commentId]);
    echo json_encode(['success' => true]);
    exit;

case 'ban_ip':
    if (!isset($_SESSION['uid']) || ($_SESSION['role'] ?? '') !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Non autorise']);
        exit;
    }
    $ipAddr = trim($_POST['ip_address'] ?? '');
    $reason = trim(strip_tags($_POST['reason'] ?? ''));
    $bannedBy = $_SESSION['username'] ?? 'admin';
    if ($ipAddr === '') { echo json_encode(['success' => false]); exit; }

    $stmt = $pdo->prepare('INSERT IGNORE INTO news_banned_ips (ip_address, reason, banned_by) VALUES (:ip, :reason, :by)');
    $stmt->execute(['ip' => $ipAddr, 'reason' => $reason, 'by' => $bannedBy]);
    echo json_encode(['success' => true]);
    exit;

case 'unban_ip':
    if (!isset($_SESSION['uid']) || ($_SESSION['role'] ?? '') !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Non autorise']);
        exit;
    }
    $ipAddr = trim($_POST['ip_address'] ?? '');
    if ($ipAddr === '') { echo json_encode(['success' => false]); exit; }
    $pdo->prepare('DELETE FROM news_banned_ips WHERE ip_address = :ip')->execute(['ip' => $ipAddr]);
    echo json_encode(['success' => true]);
    exit;

// ════════════════════════════════════════════════════
//  VOTE ARTICLE — Code existant (pas d'action specifique)
// ════════════════════════════════════════════════════

default:
    $id = intval($_POST['id'] ?? 0);
    $type = $_POST['type'] ?? '';
    $remove = $_POST['remove'] ?? null;

    if (!in_array($type, ['like', 'dislike']) || $id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Parametres invalides']);
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
    exit;
}
