<?php
/**
 * /complaints/ endpoints
 */

$user = auth_user();
$pdo  = db();

if ($method === 'GET') {
    $stmt = $pdo->prepare("
        SELECT c.*, u.username AS against_username
        FROM complaints c
        LEFT JOIN users u ON u.id = c.against_user_id
        WHERE c.from_user_id=?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$user['id']]);
    json_ok(['complaints' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method === 'POST') {
    $b = require_fields(['description']);
    $pdo->prepare("
        INSERT INTO complaints (from_user_id, against_user_id, ride_id, type, description)
        VALUES (?,?,?,?,?)
    ")->execute([
        $user['id'],
        isset($b['against_user_id']) ? (int)$b['against_user_id'] : null,
        isset($b['ride_id'])         ? (int)$b['ride_id']         : null,
        $b['type']        ?? 'general',
        $b['description'],
    ]);
    json_ok(['message' => 'Complaint submitted'], 201);
}

json_error('Method not allowed', 405);
