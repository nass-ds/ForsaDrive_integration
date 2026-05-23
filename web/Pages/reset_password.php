<?php
require_once '../server/session.php';
require_once '../classes/password_reset.php';

if (isLoggedIn()) { header('Location: home.php'); exit(); }

$pr      = new PasswordReset(getDB());
$token   = trim($_POST['token'] ?? $_GET['token'] ?? '');
$errors  = [];
$done    = false;

// Validate the token up front so an expired/used link shows a clean message
// instead of a password form that can never succeed.
$valid   = $pr->validate($token);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    $password = $_POST['password']         ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    [$ok, $message] = $pr->consume($token, $password, $confirm);
    if ($ok) {
        $done = true;
    } else {
        $errors[] = $message;
        // Re-check: consume() may have rejected on policy while the token is still valid.
        $valid = $pr->validate($token);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — ForsaDrive</title>
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
        .req-hint { font-size:.78rem; color:#6b7280; margin-top:.4rem; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-header">
        <div class="logo-icon"><i class="fa fa-lock"></i></div>
        <div class="brand-name">Forsa<span>Drive</span></div>
        <p class="tagline">Choose a new password</p>
    </div>
    <div class="login-body">

        <?php if ($done): ?>
            <div class="alert alert-success py-2 mb-3" style="font-size:.875rem; border-radius:10px;">
                <i class="fa fa-circle-check me-1"></i>
                Your password has been reset successfully. You can now sign in with your new password.
            </div>
            <a href="login.php" class="btn-login d-block text-center text-decoration-none">
                <i class="fa fa-right-to-bracket me-2"></i>Go to sign in
            </a>

        <?php elseif (!$valid): ?>
            <div class="alert alert-danger py-2 mb-3" style="font-size:.875rem; border-radius:10px;">
                <i class="fa fa-triangle-exclamation me-1"></i>
                This reset link is invalid or has expired. Reset links are valid for
                <?= PasswordReset::TOKEN_TTL_MIN ?> minutes and can be used only once.
            </div>
            <div class="auth-links">
                <a href="forgot_password.php"><i class="fa fa-rotate-right me-1"></i>Request a new link</a>
            </div>

        <?php else: ?>
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger py-2 mb-3" style="font-size:.875rem; border-radius:10px;">
                <i class="fa fa-circle-xmark me-1"></i><?= htmlspecialchars($errors[0]) ?>
            </div>
            <?php endif; ?>

            <p class="text-muted small mb-3">
                Resetting the password for <strong><?= htmlspecialchars($valid['email']) ?></strong>.
            </p>
            <form method="POST" novalidate>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                <div class="mb-3">
                    <label class="form-label fd-form-label">New Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa fa-lock"></i></span>
                        <input type="password" name="password" id="pw" class="form-control"
                               placeholder="New password" required autocomplete="new-password" autofocus>
                        <button type="button" class="btn btn-outline-secondary" id="togglePw" title="Toggle visibility">
                            <i class="fa fa-eye" id="togglePwIcon"></i>
                        </button>
                    </div>
                    <div class="req-hint">At least 8 characters, with one uppercase letter and one number.</div>
                </div>

                <div class="mb-4">
                    <label class="form-label fd-form-label">Confirm New Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa fa-lock"></i></span>
                        <input type="password" name="confirm_password" class="form-control"
                               placeholder="Repeat new password" required autocomplete="new-password">
                    </div>
                </div>

                <button type="submit" class="btn-login"><i class="fa fa-check me-2"></i>Reset password</button>
            </form>
            <div class="auth-links">
                <a href="login.php"><i class="fa fa-arrow-left me-1"></i>Back to sign in</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const t = document.getElementById('togglePw');
    if (t) t.addEventListener('click', () => {
        const f = document.getElementById('pw');
        const i = document.getElementById('togglePwIcon');
        const show = f.type === 'password';
        f.type = show ? 'text' : 'password';
        i.classList.toggle('fa-eye', !show);
        i.classList.toggle('fa-eye-slash', show);
    });
</script>
</body>
</html>
