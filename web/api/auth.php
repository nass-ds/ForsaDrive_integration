<?php
/**
 * /auth/* endpoints
 */

$action = $segments[1] ?? '';

switch ($method . ':' . $action) {

    // POST /auth/login
    case 'POST:login': {
        $b = require_fields(['email', 'password']);
        $pdo  = db();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([strtolower(trim($b['email']))]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($b['password'], $user['password'])) {
            json_error('Invalid email or password', 401);
        }
        Sanctions::applyExpiry($pdo, $user);
        if ($lockout = Sanctions::lockoutMessage($user)) {
            json_error($lockout, 403);
        }
        if (!empty($user['is_admin'])) {
            json_error('Admin accounts can only access the web panel. Please use the website to log in.', 403);
        }
        $token = generate_token((int)$user['id']);
        json_ok(['token' => $token, 'user' => user_payload($user)]);
    }

    // POST /auth/signup
    case 'POST:signup': {
        $b = require_fields(['username', 'email', 'password']);
        $email    = strtolower(trim($b['email']));
        $username = trim($b['username']);
        $password = $b['password'];

        if (strlen($password) < 6) json_error('Password must be at least 6 characters', 422);

        $pdo  = db();
        $exists = $pdo->prepare("SELECT 1 FROM users WHERE email = ?");
        $exists->execute([$email]);
        if ($exists->fetchColumn()) json_error('Email already registered', 409);

        $isStudent = is_student_email($email) ? 1 : 0;
        $isDriver  = empty($b['is_driver']) ? 0 : 1;
        $hash      = password_hash($password, PASSWORD_DEFAULT);

        $pdo->prepare("
            INSERT INTO users
                (username, email, password, is_driver, is_student, is_student_verified,
                 phone, gender, date_of_birth, governorate, Region)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $username, $email, $hash,
            $isDriver, $isStudent, $isStudent,
            $b['phone']         ?? null,
            $b['gender']        ?? null,
            $b['date_of_birth'] ?? null,
            $b['governorate']   ?? null,
            $b['region']        ?? 'TN',
        ]);

        $userId = (int)$pdo->lastInsertId();

        // If driver, create driver_profile row
        if ($isDriver) {
            $pdo->prepare("INSERT INTO driver_profiles (user_id) VALUES (?)")->execute([$userId]);
        }

        $token = generate_token($userId);
        $stmt  = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user  = $stmt->fetch(PDO::FETCH_ASSOC);
        json_ok(['token' => $token, 'user' => user_payload($user)], 201);
    }

    // POST /auth/logout
    case 'POST:logout': {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            db()->prepare("DELETE FROM api_tokens WHERE token = ?")->execute([$m[1]]);
        }
        json_ok(['message' => 'Logged out']);
    }

    // GET /auth/me
    case 'GET:me': {
        $user = auth_user();
        json_ok(['user' => user_payload($user)]);
    }

    default:
        json_error('Not found', 404);
}
