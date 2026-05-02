<?php
/**
 * /admin/* endpoints — requires is_admin flag
 */

$action = $segments[1] ?? '';

// ── Student domains (GET, POST, DELETE) ───────────────────────────────────────
if ($action === 'student-domains') {
    $admin = require_admin();
    $pdo   = db();
    $domainId = isset($segments[2]) && is_numeric($segments[2]) ? (int)$segments[2] : null;

    if ($method === 'GET') {
        $stmt = $pdo->prepare("SELECT * FROM student_domains ORDER BY label");
        $stmt->execute();
        json_ok(['domains' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method === 'POST') {
        $b = require_fields(['domain']);
        $domain = strtolower(trim($b['domain']));
        $label  = trim($b['label'] ?? $domain);
        try {
            $pdo->prepare("INSERT INTO student_domains (domain, label) VALUES (?,?)")->execute([$domain, $label]);
            json_ok(['message' => "Domain '$domain' added"], 201);
        } catch (PDOException $e) {
            json_error('Domain already exists', 409);
        }
    }

    if ($method === 'DELETE' && $domainId !== null) {
        $pdo->prepare("DELETE FROM student_domains WHERE id=?")->execute([$domainId]);
        json_ok(['message' => 'Domain removed']);
    }

    json_error('Method not allowed', 405);
}

// ── General admin data ────────────────────────────────────────────────────────
if ($action === '' || $method === 'GET') {
    $admin = require_admin();
    $pdo   = db();
    $tab   = $_GET['tab'] ?? 'users';

    $data = [];
    if ($tab === 'users' || $tab === 'all') {
        $stmt = $pdo->prepare("SELECT id, username, email, is_driver, is_student, is_admin, is_helpdesk_agent, suspended, balance, score, created_at FROM users ORDER BY created_at DESC");
        $stmt->execute();
        $data['users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($tab === 'rides' || $tab === 'all') {
        $stmt = $pdo->prepare("
            SELECT r.*, u.username AS driver_name
            FROM rides r JOIN users u ON u.id=r.driver_id
            ORDER BY r.created_at DESC LIMIT 200
        ");
        $stmt->execute();
        $data['rides'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($tab === 'complaints' || $tab === 'all') {
        $stmt = $pdo->prepare("
            SELECT c.*, f.username AS from_username, a.username AS against_username
            FROM complaints c
            JOIN users f ON f.id=c.from_user_id
            LEFT JOIN users a ON a.id=c.against_user_id
            ORDER BY c.created_at DESC
        ");
        $stmt->execute();
        $data['complaints'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($tab === 'student_domains') {
        $stmt = $pdo->prepare("SELECT * FROM student_domains ORDER BY label");
        $stmt->execute();
        $data['student_domains'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    json_ok($data);
}

// ── Admin POST actions ────────────────────────────────────────────────────────
if ($method === 'POST') {
    $admin = require_admin();
    $b     = body();
    $pdo   = db();
    $act   = $b['action'] ?? '';

    if ($act === 'suspend_user') {
        $pdo->prepare("UPDATE users SET suspended=1, ban_reason=? WHERE id=?")->execute([$b['reason'] ?? null, (int)$b['user_id']]);
        json_ok(['message' => 'User suspended']);
    }
    if ($act === 'unsuspend_user') {
        $pdo->prepare("UPDATE users SET suspended=0, ban_reason=NULL WHERE id=?")->execute([(int)$b['user_id']]);
        json_ok(['message' => 'User unsuspended']);
    }
    if ($act === 'resolve_complaint') {
        $pdo->prepare("UPDATE complaints SET status='resolved', admin_note=? WHERE id=?")->execute([$b['note'] ?? null, (int)$b['complaint_id']]);
        json_ok(['message' => 'Complaint resolved']);
    }
    json_error('Unknown action', 400);
}

json_error('Not found', 404);
