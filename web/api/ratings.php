<?php
/**
 * /ratings/ endpoints
 */

$user = auth_user();
$pdo  = db();

if ($method === 'GET') {
    $stmt = $pdo->prepare("
        SELECT r.*, u.username AS from_username, u.picture AS from_pic
        FROM ratings r
        JOIN users u ON u.id = r.from_user_id
        WHERE r.to_user_id=?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$user['id']]);
    json_ok(['ratings' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method === 'POST') {
    $b = require_fields(['ride_id', 'to_user_id', 'score']);
    $rideId    = (int)$b['ride_id'];
    $toUserId  = (int)$b['to_user_id'];
    $score     = (int)$b['score'];
    $comment   = $b['comment'] ?? null;

    if ($score < 1 || $score > 5) json_error('Score must be between 1 and 5', 422);

    try {
        $pdo->prepare("
            INSERT INTO ratings (ride_id, from_user_id, to_user_id, score, comment)
            VALUES (?,?,?,?,?)
        ")->execute([$rideId, $user['id'], $toUserId, $score, $comment]);

        // Update recipient's average score
        $avg = $pdo->prepare("SELECT AVG(score) FROM ratings WHERE to_user_id=?");
        $avg->execute([$toUserId]);
        $newScore = round((float)$avg->fetchColumn(), 1);
        $pdo->prepare("UPDATE users SET score=? WHERE id=?")->execute([$newScore, $toUserId]);
        // Update driver_profiles too if driver
        $pdo->prepare("UPDATE driver_profiles SET avg_rating=? WHERE user_id=?")->execute([$newScore, $toUserId]);

        json_ok(['message' => 'Rating submitted'], 201);
    } catch (PDOException $e) {
        json_error('You have already rated this user for this ride', 409);
    }
}

json_error('Method not allowed', 405);
