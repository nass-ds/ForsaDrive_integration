<?php
require_once '../server/session.php';

// Redirect if already logged in as org
if (isOrgLoggedIn()) {
    header('Location: org_dashboard.php');
    exit();
}

// Regenerate captcha on GET only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['org_login_cap_a'] = random_int(1, 9);
    $_SESSION['org_login_cap_b'] = random_int(1, 9);
}

$errors = [];
$email = '';

// ── POST: process login ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $captcha  = (int)($_POST['captcha'] ?? -1);

    if ($captcha !== (int)$_SESSION['org_login_cap_a'] + (int)$_SESSION['org_login_cap_b']) {
        $errors[] = 'Incorrect answer to the security question.';
    }

    // Refresh for next attempt
    $_SESSION['org_login_cap_a'] = random_int(1, 9);
    $_SESSION['org_login_cap_b'] = random_int(1, 9);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (empty($password)) {
        $errors[] = 'Password is required.';
    }

    if (empty($errors)) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("
                SELECT * FROM organizations
                WHERE contact_email = ? AND status IN ('active','approved')
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $org = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($org && $org['password'] && password_verify($password, $org['password'])) {
                loginOrg($org);
                header('Location: org_dashboard.php');
                exit();
            } else {
                $errors[] = 'Invalid email or password, or organization is not yet activated.';
            }
        } catch (Exception $e) {
            $errors[] = 'An error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organization Login — ForsaDrive</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../Css/index.css?v=<?= time() ?>">
    <style>
        .login-container {
            display: flex;
            min-height: 100vh;
            background: linear-gradient(135deg, #0a1628 0%, #1a2a4a 100%);
        }
        .login-card {
            margin: auto;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 420px;
            padding: 40px;
        }
        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }
        .login-header h1 {
            font-size: 28px;
            font-weight: 800;
            color: #0a1628;
            margin: 0 0 8px 0;
        }
        .login-header p {
            color: #666;
            font-size: 14px;
            margin: 0;
        }
        .login-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 16px;
            background: linear-gradient(135deg, #FFA500 0%, #FF8C00 100%);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: #fff;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }
        .form-group input {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
            box-sizing: border-box;
        }
        .form-group input:focus {
            outline: none;
            border-color: #FFA500;
            box-shadow: 0 0 0 3px rgba(255, 165, 0, 0.1);
        }
        .captcha-group {
            background: #f5f5f5;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .captcha-group p {
            font-size: 13px;
            color: #666;
            margin: 0 0 10px 0;
        }
        .captcha-question {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-bottom: 10px;
        }
        .captcha-question span {
            font-weight: 700;
            color: #0a1628;
        }
        .captcha-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
        }
        .errors {
            background: #fee;
            border: 1px solid #fcc;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 20px;
            color: #c33;
            font-size: 13px;
        }
        .errors ul {
            margin: 0;
            padding-left: 20px;
        }
        .errors li {
            margin: 4px 0;
        }
        .submit-btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #FFA500 0%, #FF8C00 100%);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(255, 165, 0, 0.3);
        }
        .submit-btn:active {
            transform: translateY(0);
        }
        .footer-links {
            text-align: center;
            margin-top: 20px;
            font-size: 13px;
        }
        .footer-links a {
            color: #FFA500;
            text-decoration: none;
            margin: 0 8px;
        }
        .footer-links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
<div class="login-container">
    <div class="login-card">
        <div class="login-header">
            <div class="login-icon">
                <i class="fas fa-building"></i>
            </div>
            <h1>Organization Login</h1>
            <p>Access your organization dashboard</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="errors">
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="email">Contact Email</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <div class="captcha-group">
                <p>Security Question:</p>
                <div class="captcha-question">
                    <span><?= (int)$_SESSION['org_login_cap_a'] ?> + <?= (int)$_SESSION['org_login_cap_b'] ?> = </span>
                    <input type="number" name="captcha" required style="width: 80px;">
                </div>
            </div>

            <button type="submit" class="submit-btn">
                <i class="fas fa-sign-in-alt"></i> Login to Dashboard
            </button>
        </form>

        <div class="footer-links">
            <a href="../index.php">← Back to Home</a>
            <span>•</span>
            <a href="../index.php#organizations">Apply for Discount</a>
        </div>
    </div>
</div>
</body>
</html>
