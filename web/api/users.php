<?php
/**
 * /users/* endpoints
 * Also handles /vehicles/, /profile/, /student/ for Flutter compatibility
 */

$group2   = $segments[0] ?? '';   // 'users' | 'vehicles' | 'profile' | 'student'
$action   = $segments[1] ?? '';
$entityId = is_numeric($action) ? (int)$action : null;

// ── /vehicles/ ────────────────────────────────────────────────────────────────
if ($group2 === 'vehicles') {
    $user = auth_user();
    $pdo  = db();

    if ($method === 'GET') {
        $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$user['id']]);
        json_ok(['vehicles' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method === 'POST') {
        $b = body();
        if (($b['action'] ?? '') === 'delete') {
            $pdo->prepare("DELETE FROM vehicles WHERE id=? AND user_id=?")->execute([(int)$b['id'], $user['id']]);
            json_ok(['message' => 'Vehicle deleted']);
        }
        // Add vehicle
        $pdo->prepare("
            INSERT INTO vehicles (user_id, type, make, model, year, color, plate_number, seats, has_ac, luggage)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $user['id'],
            $b['type']         ?? 'car',
            $b['make']         ?? null,
            $b['model']        ?? null,
            $b['year']         ?? null,
            $b['color']        ?? null,
            $b['plate_number'] ?? null,
            (int)($b['seats']  ?? 4),
            empty($b['has_ac']) ? 0 : 1,
            $b['luggage']      ?? 'medium',
        ]);
        $vid  = $pdo->lastInsertId();
        $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id=?");
        $stmt->execute([$vid]);
        json_ok(['vehicle' => $stmt->fetch(PDO::FETCH_ASSOC)], 201);
    }
    json_error('Method not allowed', 405);
}

// ── /profile/ ────────────────────────────────────────────────────────────────
if ($group2 === 'profile') {
    $user = auth_user();
    $pdo  = db();
    $b    = body();
    $act  = $b['action'] ?? '';

    if ($method === 'GET' || ($method === 'POST' && $act === 'get')) {
        json_ok(['user' => user_payload($user)]);
    }

    if ($method === 'POST' && $act === 'update') {
        $fields = [];
        $vals   = [];
        $allowed = ['username', 'phone', 'bio', 'gender', 'date_of_birth', 'governorate', 'address'];
        foreach ($allowed as $f) {
            if (isset($b[$f])) { $fields[] = "$f = ?"; $vals[] = $b[$f]; }
        }
        if (isset($b['region'])) { $fields[] = "Region = ?"; $vals[] = $b['region']; }
        if (empty($fields)) json_ok(['message' => 'Nothing to update']);
        $vals[] = $user['id'];
        $pdo->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id=?")->execute($vals);
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
        $stmt->execute([$user['id']]);
        json_ok(['user' => user_payload($stmt->fetch(PDO::FETCH_ASSOC))]);
    }

    if ($method === 'POST' && $act === 'change_password') {
        if (empty($b['current_password']) || empty($b['new_password'])) json_error('Missing fields', 422);
        if (!password_verify($b['current_password'], $user['password'])) json_error('Current password is incorrect', 401);
        $hash = password_hash($b['new_password'], PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $user['id']]);
        json_ok(['message' => 'Password updated']);
    }

    if ($method === 'POST' && $act === 'become_driver') {
        if (!empty($user['is_driver'])) json_error('Already a driver', 409);
        $pdo->prepare("UPDATE users SET is_driver=1 WHERE id=?")->execute([$user['id']]);
        $pdo->prepare("INSERT OR IGNORE INTO driver_profiles (user_id) VALUES (?)")->execute([$user['id']]);
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
        $stmt->execute([$user['id']]);
        json_ok(['user' => user_payload($stmt->fetch(PDO::FETCH_ASSOC))]);
    }

    json_error('Unknown action', 400);
}

// ── /student/* ────────────────────────────────────────────────────────────────
if ($group2 === 'student') {
    $subRoute = $action;

    // GET /student/domains — list of accepted student email domains
    if ($method === 'GET' && $subRoute === 'domains') {
        auth_user();
        $stmt = db()->prepare("SELECT domain, label FROM student_domains ORDER BY label");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_ok(['domains' => array_column($rows, 'domain'), 'details' => $rows]);
    }

    // POST /student/send-otp — kept for compatibility; student status is set via email domain at signup
    if ($method === 'POST' && $subRoute === 'send-otp') {
        $user = auth_user();
        if (!empty($user['is_student'])) {
            json_ok(['message' => 'Your student status is already verified via your email domain.', 'already_verified' => true]);
        }
        $b     = body();
        $email = strtolower(trim($b['email'] ?? $user['email']));
        if (!is_student_email($email)) {
            json_error('This email domain is not recognized as a student institution.', 400);
        }
        // Auto-verify since domain is trusted
        $pdo = db();
        $pdo->prepare("UPDATE users SET is_student=1, is_student_verified=1 WHERE id=?")->execute([$user['id']]);
        json_ok(['message' => 'Student status verified via email domain.', 'verified' => true]);
    }

    // POST /student/verify-otp — stub (domain-based verification needs no OTP)
    if ($method === 'POST' && $subRoute === 'verify-otp') {
        $user = auth_user();
        json_ok(['message' => 'Verification is automatic for recognized student email domains.', 'verified' => (bool)$user['is_student']]);
    }

    json_error('Not found', 404);
}

// ── /users/me ─────────────────────────────────────────────────────────────────
if ($group2 === 'users') {
    $user = auth_user();
    if ($method === 'GET' && $action === 'me') {
        json_ok(['user' => user_payload($user)]);
    }
}

json_error('Not found', 404);
