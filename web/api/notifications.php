<?php
/**
 * /notifications/ endpoints
 */

$user = auth_user();
$pdo  = db();

if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 100");
    $stmt->execute([$user['id']]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $unread = count(array_filter($notifications, fn($n) => !$n['is_read']));
    json_ok(['notifications' => $notifications, 'unread_count' => $unread]);
}

if ($method === 'POST') {
    $b = body();
    if (($b['action'] ?? '') === 'read_all') {
        $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$user['id']]);
        json_ok(['message' => 'All notifications marked as read']);
    }
    if (($b['action'] ?? '') === 'read' && isset($b['id'])) {
        $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([(int)$b['id'], $user['id']]);
        json_ok(['message' => 'Notification marked as read']);
    }
    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
