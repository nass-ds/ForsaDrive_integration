<?php
require_once '../server/session.php';
require_once '../classes/password_reset.php';

// Already signed in? Nothing to recover.
if (isLoggedIn()) { header('Location: home.php'); exit(); }

$errors    = [];
$submitted = false;
$demoLink  = null;          // shown only in demo mode (no SMTP configured)
$sentEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        try {
            $pr     = new PasswordReset(getDB());
            $result = $pr->createToken($email);
            $submitted = true;
            $sentEmail = $email;
            // Demo delivery: surface the link on screen (this build has no SMTP).
            if ($result) {
                $demoLink = PasswordReset::buildLink($result['token']);
            }
        } catch (Throwable $e) {
            error_log("Forgot-password error: " . $e->getMessage());
            $errors[] = 'A server error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — ForsaDrive</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../Css/app.css">
    <style>
        body { min-height:100vh; background:linear-gradient(135deg,#0a2540 0%,#0d3b6e 55%,#1a5276 100%);
               display:flex; align-items:center; justify-content:center; padding:1.5rem; font-family:'Segoe UI',sans-serif; }
        .login-card { width:100%; max-width:420px; background:#fff; border-radius:20px;
                      box-shadow:0 24px 64px rgba(0,0,0,.40); overflow:hidden; }
        .login-header { background:linear-gradient(135deg,#0a2540,#0d6efd); padding:2rem 2rem 1.5rem; text-align:center; color:#fff; }
        .login-header .logo-icon { width:64px; height:64px; background:rgba(255,255,255,.15); border-radius:16px;
                      display:flex; align-items:center; justify-content:center; font-size:1.8rem; margin:0 auto 1rem; }
        .login-header .brand-name { font-size:2rem; font-weight:800; letter-spacing:-.5px; line-height:1; }
        .login-header .brand-name span { color:#f59e0b; }
        .login-header .tagline { margin-top:.4rem; opacity:.75; font-size:.875rem; }
        .login-body { padding:2rem; }
        .input-group-text { background:#f8fafc; border-color:#e5e7eb; color:#9ca3af; }
        .form-control { border-color:#e5e7eb; padding:.6rem .9rem; font-size:.9rem; }
        .form-control:focus { border-color:#0d6efd; box-shadow:0 0 0 3px rgba(13,110,253,.12); }
        .btn-login { width:100%; background:linear-gradient(135deg,#0d6efd,#0a2540); color:#fff; border:none;
                     border-radius:10px; padding:.75rem; font-size:1rem; font-weight:700; }
        .btn-login:hover { opacity:.92; }
        .auth-links { text-align:center; font-size:.875rem; margin-top:1.25rem; }
        .auth-links a { color:#0d6efd; text-decoration:none; font-weight:600; }
        .demo-box { background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:.85rem; font-size:.8rem; color:#92400e; }
        .demo-box code { word-break:break-all; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-header">
        <div class="logo-icon"><i class="fa fa-key"></i></div>
        <div class="brand-name">Forsa<span>Drive</span></div>
        <p class="tagline">Reset your password</p>
    </div>
    <div class="login-body">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger py-2 mb-3" style="font-size:.875rem; border-radius:10px;">
            <i class="fa fa-circle-xmark me-1"></i><?= htmlspecialchars($errors[0]) ?>
        </div>
        <?php endif; ?>

        <?php if ($submitted): ?>
            <div class="alert alert-success py-2 mb-3" style="font-size:.875rem; border-radius:10px;">
                <i class="fa fa-circle-check me-1"></i>
                If an account exists for <strong><?= htmlspecialchars($sentEmail) ?></strong>,
                a password-reset link has been generated. The link expires in <?= PasswordReset::TOKEN_TTL_MIN ?> minutes.
            </div>

            <?php if ($demoLink): ?>
            <div class="demo-box mb-3">
                <strong><i class="fa fa-flask me-1"></i>Demo mode</strong> — no email service is configured on this
                build, so the reset link is shown here instead of being emailed:
                <div class="mt-2"><a href="<?= htmlspecialchars($demoLink) ?>"><code><?= htmlspecialchars($demoLink) ?></code></a></div>
            </div>
            <?php endif; ?>

            <div class="auth-links">
                <a href="login.php"><i class="fa fa-arrow-left me-1"></i>Back to sign in</a>
            </div>
        <?php else: ?>
            <p class="text-muted small mb-3">
                Enter the email address linked to your account and we'll generate a link to reset your password.
            </p>
            <form method="POST" novalidate>
                <div class="mb-3">
                    <label class="form-label fd-form-label">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa fa-envelope"></i></span>
                        <input type="email" name="email" class="form-control" placeholder="you@example.com"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autocomplete="email" autofocus>
                    </div>
                </div>
                <button type="submit" class="btn-login"><i class="fa fa-paper-plane me-2"></i>Send reset link</button>
            </form>
            <div class="auth-links">
                Remembered it? <a href="login.php">Back to sign in</a>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
