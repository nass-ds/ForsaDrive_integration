<?php
require_once '../server/session.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: home.php');
    exit();
}

// Generate CAPTCHA on first GET load only — validation reads stored values before regenerating
if (!isset($_SESSION['captcha_a'])) {
    $_SESSION['captcha_a'] = random_int(1, 9);
    $_SESSION['captcha_b'] = random_int(1, 9);
}

$errors     = [];
$flashType  = '';   // 'success' | 'error'
$flashMsg   = '';

// ── POST: process login ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lowercase the email — the Dart API and the PHP /auth/signup both store
    // emails lowercased, so the WHERE clause has to match that.
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password']      ?? '';
    $captcha  = (int)($_POST['captcha'] ?? -1);

    // CAPTCHA first
    if ($captcha !== ($_SESSION['captcha_a'] + $_SESSION['captcha_b'])) {
        $errors[] = 'Incorrect answer to the security question.';
    }

    // Regenerate after attempt
    $_SESSION['captcha_a'] = random_int(1, 9);
    $_SESSION['captcha_b'] = random_int(1, 9);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (empty($password)) {
        $errors[] = 'Password is required.';
    }

    if (empty($errors)) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {

                // Suspended check
                if (!empty($user['suspended'])) {
                    $reason = htmlspecialchars($user['ban_reason'] ?? 'No reason provided.');
                    $errors[] = "Your account has been suspended. Reason: {$reason}";
                } else {
                    // Populate session manually (full dataset as specified)
                    $_SESSION['user_id']   = $user['id'];
                    $_SESSION['user_data'] = [
                        'id'                => $user['id'],
                        'username'          => $user['username'],
                        'email'             => $user['email'],
                        'is_driver'         => (bool)$user['is_driver'],
                        'is_student'        => (bool)$user['is_student'],
                        'is_admin'          => (bool)($user['is_admin'] ?? false),
                        'is_helpdesk_agent' => (bool)($user['is_helpdesk_agent'] ?? false),
                        'balance'           => (float)($user['balance'] ?? 0),
                        'score'             => (float)($user['score'] ?? 5.0),
                        'region'            => $user['Region'] ?? $user['region'] ?? 'TN',
                        'profile_picture'   => $user['picture'] ?? '../Src/default.jpg',
                        'governorate'       => $user['governorate'] ?? '',
                    ];

                    // Admin → admin panel, else → home (post-login landing)
                    if (!empty($user['is_admin'])) {
                        header('Location: admin.php');
                    } else {
                        header('Location: home.php');
                    }
                    exit();
                }

            } else {
                $errors[] = 'Invalid email or password. Please try again.';
            }

        } catch (Throwable $e) {
            error_log("Login error: " . $e->getMessage());
            $errors[] = 'A server error occurred. Please try again.';
        }
    }
}

$captchaA   = $_SESSION['captcha_a'];
$captchaB   = $_SESSION['captcha_b'];
$registered = isset($_GET['registered']);
$oldEmail   = htmlspecialchars($_POST['email'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — ForsaDrive</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../Css/app.css">
    <style>
        /* ── Full-page background ── */
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #0a2540 0%, #0d3b6e 55%, #1a5276 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            font-family: 'Segoe UI', sans-serif;
        }

        /* ── Login card ── */
        .login-card {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 24px 64px rgba(0, 0, 0, .40);
            overflow: hidden;
        }

        /* ── Card header / brand ── */
        .login-header {
            background: linear-gradient(135deg, #0a2540, #0d6efd);
            padding: 2.25rem 2rem 1.75rem;
            text-align: center;
            color: #fff;
        }
        .login-header .logo-icon {
            width: 64px; height: 64px;
            background: rgba(255,255,255,.15);
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.8rem;
            margin: 0 auto 1rem;
            backdrop-filter: blur(4px);
        }
        .login-header .brand-name {
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: -.5px;
            line-height: 1;
        }
        .login-header .brand-name span { color: #f59e0b; }
        .login-header .tagline {
            margin-top: .4rem;
            opacity: .75;
            font-size: .875rem;
        }

        /* ── Card body ── */
        .login-body {
            padding: 2rem;
        }

        /* ── Input groups ── */
        .input-group-text {
            background: #f8fafc;
            border-color: #e5e7eb;
            color: #9ca3af;
        }
        .form-control {
            border-color: #e5e7eb;
            padding: .6rem .9rem;
            font-size: .9rem;
        }
        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 3px rgba(13,110,253,.12);
        }

        /* ── Submit button ── */
        .btn-login {
            width: 100%;
            background: linear-gradient(135deg, #0d6efd, #0a2540);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: .75rem;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: .02em;
            transition: opacity .2s, transform .15s;
        }
        .btn-login:hover  { opacity: .9; transform: translateY(-1px); color: #fff; }
        .btn-login:active { transform: translateY(0); }

        /* ── Links section ── */
        .auth-links {
            text-align: center;
            font-size: .875rem;
            color: #6b7280;
            margin-top: 1.25rem;
        }
        .auth-links a {
            color: #0d6efd;
            font-weight: 600;
            text-decoration: none;
        }
        .auth-links a:hover { text-decoration: underline; }

        /* ── Divider ── */
        .or-divider {
            display: flex;
            align-items: center;
            gap: .75rem;
            color: #9ca3af;
            font-size: .8rem;
            margin: 1rem 0;
        }
        .or-divider::before,
        .or-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e5e7eb;
        }

        /* ── CAPTCHA box ── */
        .captcha-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: .85rem 1rem;
        }
        .captcha-question {
            font-size: .9rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: .5rem;
        }

        /* ── Forgot password ── */
        .forgot-link {
            font-size: .8rem;
            color: #9ca3af;
            text-decoration: none;
            float: right;
            margin-top: -.1rem;
        }
        .forgot-link:hover { color: #0d6efd; text-decoration: underline; }

        /* ── Success flash ── */
        .flash-success {
            background: linear-gradient(135deg, #dcfce7, #bbf7d0);
            border: 1px solid #86efac;
            border-radius: 10px;
            padding: .85rem 1rem;
            display: flex;
            align-items: flex-start;
            gap: .6rem;
            font-size: .875rem;
            color: #166534;
            margin-bottom: 1.25rem;
        }
        .flash-success i { font-size: 1.1rem; flex-shrink: 0; margin-top: .05rem; }
    </style>
</head>
<body>

<div class="login-card">

    <!-- Brand header -->
    <div class="login-header">
        <div class="logo-icon">
            <i class="fa fa-car-side"></i>
        </div>
        <div class="brand-name">Forsa<span>Drive</span></div>
        <p class="tagline">Your ride, your way</p>
    </div>

    <!-- Card body -->
    <div class="login-body">

        <!-- Registration success flash -->
        <?php if ($registered): ?>
        <div class="flash-success">
            <i class="fa fa-circle-check"></i>
            <div>
                <strong>Account created!</strong><br>
                Welcome to ForsaDrive. Please sign in to continue.
            </div>
        </div>
        <?php endif; ?>

        <!-- Error alerts -->
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger py-2 mb-3" style="font-size:.875rem; border-radius:10px;">
            <?php if (count($errors) === 1): ?>
                <i class="fa fa-circle-xmark me-1"></i><?= htmlspecialchars($errors[0]) ?>
            <?php else: ?>
                <strong><i class="fa fa-circle-exclamation me-1"></i>Please fix the following:</strong>
                <ul class="mb-0 mt-1 ps-3">
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="loginForm" novalidate>

            <!-- Email -->
            <div class="mb-3">
                <label class="form-label fd-form-label">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-envelope"></i></span>
                    <input type="email" name="email" class="form-control"
                           placeholder="you@example.com"
                           value="<?= $oldEmail ?>" required autocomplete="email">
                </div>
            </div>

            <!-- Password -->
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label fd-form-label mb-0">Password</label>
                    <a href="#" class="forgot-link">Forgot password?</a>
                </div>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-lock"></i></span>
                    <input type="password" name="password" id="passwordField" class="form-control"
                           placeholder="Your password" required autocomplete="current-password">
                    <button type="button" class="btn btn-outline-secondary" id="togglePass"
                            title="Toggle visibility">
                        <i class="fa fa-eye" id="togglePassIcon"></i>
                    </button>
                </div>
            </div>

            <!-- CAPTCHA -->
            <div class="mb-4 captcha-box">
                <div class="captcha-question">
                    <i class="fa fa-robot me-1 text-primary"></i>Security check:
                    What is <strong><?= $captchaA ?></strong> + <strong><?= $captchaB ?></strong>?
                </div>
                <input type="number" name="captcha" class="form-control"
                       style="max-width:130px;" placeholder="Your answer"
                       min="0" max="18" required>
            </div>

            <!-- Submit -->
            <button type="submit" class="btn-login">
                <i class="fa fa-right-to-bracket me-2"></i>Sign In
            </button>
        </form>

        <div class="or-divider">or</div>

        <!-- Auth links -->
        <div class="auth-links">
            Don't have an account? <a href="signup.php">Create one for free</a>
        </div>
        <div class="auth-links mt-2">
            <a href="../index.php" style="color:#9ca3af; font-weight:400;">
                <i class="fa fa-arrow-left me-1"></i>Back to home
            </a>
        </div>

    </div><!-- .login-body -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Toggle password visibility
document.getElementById('togglePass').addEventListener('click', function () {
    const field = document.getElementById('passwordField');
    const icon  = document.getElementById('togglePassIcon');
    const show  = field.type === 'password';
    field.type  = show ? 'text' : 'password';
    icon.className = show ? 'fa fa-eye-slash' : 'fa fa-eye';
});

// Basic client-side validation before submit
document.getElementById('loginForm').addEventListener('submit', function (e) {
    const email = this.querySelector('[name=email]').value.trim();
    const pass  = this.querySelector('[name=password]').value;
    const cap   = this.querySelector('[name=captcha]').value.trim();

    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        e.preventDefault();
        alert('Please enter a valid email address.');
        return;
    }
    if (!pass) {
        e.preventDefault();
        alert('Password is required.');
        return;
    }
    if (!cap) {
        e.preventDefault();
        alert('Please answer the security question.');
    }
});
</script>
</body>
</html>
