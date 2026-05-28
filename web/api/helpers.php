<?php
/**
 * API helpers — shared across all endpoint files.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/database.php';
require_once __DIR__ . '/../classes/sanctions.php';

// ── Database ──────────────────────────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $db  = new Database();
        $pdo = $db->getConnection();
        $pdo->exec("PRAGMA foreign_keys = ON");
        $pdo->exec("PRAGMA journal_mode = WAL");
    }
    return $pdo;
}

// ── JSON response helpers ─────────────────────────────────────────────────────
function json_ok(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function json_error(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['message' => $message]);
    exit;
}

// ── Request body ─────────────────────────────────────────────────────────────
function body(): array {
    static $parsed = null;
    if ($parsed === null) {
        $raw    = file_get_contents('php://input');
        $parsed = json_decode($raw, true) ?? [];
    }
    return $parsed;
}

function require_fields(array $fields): array {
    $b = body();
    foreach ($fields as $f) {
        if (!isset($b[$f]) || $b[$f] === '') {
            json_error("Missing required field: $f", 422);
        }
    }
    return $b;
}

// ── Auth middleware ───────────────────────────────────────────────────────────
function auth_user(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        json_error('Unauthorized', 401);
    }
    $token = $m[1];
    $pdo   = db();
    $stmt  = $pdo->prepare(
        "SELECT u.* FROM api_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token = ? AND t.expires_at > datetime('now')"
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        json_error('Unauthorized', 401);
    }
    Sanctions::applyExpiry($pdo, $user);
    if ($lockout = Sanctions::lockoutMessage($user)) {
        json_error($lockout, 403);
    }
    return $user;
}

function require_admin(): array {
    $u = auth_user();
    if (empty($u['is_admin'])) json_error('Forbidden', 403);
    return $u;
}

function require_driver(): array {
    $u = auth_user();
    if (empty($u['is_driver'])) json_error('This action requires a driver account', 403);
    return $u;
}

// ── Token generator ───────────────────────────────────────────────────────────
function generate_token(int $userId): string {
    $token = bin2hex(random_bytes(32));
    $pdo   = db();
    $pdo->prepare(
        "INSERT INTO api_tokens (user_id, token, expires_at)
         VALUES (?, ?, datetime('now', '+30 days'))"
    )->execute([$userId, $token]);
    return $token;
}

// ── User array for API responses ──────────────────────────────────────────────
function user_payload(array $u): array {
    return [
        'id'               => (int)$u['id'],
        'public_id'        => $u['public_id'] ?? null,
        'username'         => $u['username'],
        'email'            => $u['email'],
        'phone'            => $u['phone'] ?? null,
        'gender'           => $u['gender'] ?? null,
        'date_of_birth'    => $u['date_of_birth'] ?? null,
        'governorate'      => $u['governorate'] ?? null,
        'region'           => $u['Region'] ?? $u['region'] ?? 'TN',
        'bio'              => $u['bio'] ?? null,
        'is_driver'        => (bool)$u['is_driver'],
        'is_student'       => (bool)$u['is_student'],
        'is_admin'         => (bool)$u['is_admin'],
        'is_helpdesk_agent'=> (bool)$u['is_helpdesk_agent'],
        'balance'          => (float)$u['balance'],
        'score'            => (float)$u['score'],
        'picture'          => $u['picture'] ?? null,
        'suspended'        => (bool)$u['suspended'],
    ];
}

// ── Student domain check ──────────────────────────────────────────────────────
function is_student_email(string $email): bool {
    $parts = explode('@', strtolower($email));
    if (count($parts) !== 2) return false;
    $domain = $parts[1];
    $stmt   = db()->prepare("SELECT 1 FROM student_domains WHERE domain = ?");
    $stmt->execute([$domain]);
    return (bool)$stmt->fetchColumn();
}
