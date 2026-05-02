<?php
/**
 * /payments/ endpoints
 */

$user = auth_user();
$pdo  = db();

if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE user_id=? ORDER BY created_at DESC");
    $stmt->execute([$user['id']]);
    $uStmt = $pdo->prepare("SELECT balance FROM users WHERE id=?");
    $uStmt->execute([$user['id']]);
    $balance = (float)$uStmt->fetchColumn();
    json_ok(['payments' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'balance' => $balance]);
}

if ($method === 'POST') {
    $b = body();
    if (($b['action'] ?? '') === 'deposit') {
        $amount = (float)($b['amount'] ?? 0);
        if ($amount <= 0) json_error('Invalid amount', 422);
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$amount, $user['id']]);
        $pdo->prepare("INSERT INTO payments (user_id, amount, type, description) VALUES (?,?,'deposit','Manual deposit')")
            ->execute([$user['id'], $amount]);
        $uStmt = $pdo->prepare("SELECT balance FROM users WHERE id=?");
        $uStmt->execute([$user['id']]);
        json_ok(['new_balance' => (float)$uStmt->fetchColumn()]);
    }
    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
