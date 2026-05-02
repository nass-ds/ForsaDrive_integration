<?php
require_once '../server/session.php';
$regions = require_once '../data/tunisia_regions.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: interface.php');
    exit();
}

// Generate CAPTCHA only on first GET load (not on POST — validation reads the stored values first)
if (!isset($_SESSION['captcha_a'])) {
    $_SESSION['captcha_a'] = random_int(1, 9);
    $_SESSION['captcha_b'] = random_int(1, 9);
}

$errors   = [];
$oldInput = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── collect & sanitise ────────────────────────────────────────────
    $username     = trim($_POST['username']     ?? '');
    $email        = trim($_POST['email']        ?? '');
    $dob          = trim($_POST['dob']          ?? '');
    $gender       = trim($_POST['gender']       ?? '');
    $phone        = trim($_POST['phone']        ?? '');
    $password     = $_POST['password']          ?? '';
    $confirm_pass = $_POST['confirm_password']  ?? '';
    $captcha      = (int)($_POST['captcha']     ?? -1);
    $governorate  = trim($_POST['governorate']  ?? '');
    $municipality = trim($_POST['municipality'] ?? '');
    $address      = trim($_POST['address']      ?? '');
    $role         = trim($_POST['role']         ?? '');
    $is_student   = isset($_POST['is_student']) ? 1 : 0;

    // Driver fields
    $veh_type     = trim($_POST['veh_type']     ?? '');
    $veh_make     = trim($_POST['veh_make']     ?? '');
    $veh_model    = trim($_POST['veh_model']    ?? '');
    $veh_year     = (int)($_POST['veh_year']    ?? 0);
    $veh_color    = trim($_POST['veh_color']    ?? '');
    $veh_plate    = trim($_POST['veh_plate']    ?? '');
    $veh_seats    = (int)($_POST['veh_seats']   ?? 0);
    $veh_ac       = isset($_POST['veh_ac']) ? 1 : 0;
    $veh_luggage  = trim($_POST['veh_luggage']  ?? 'none');
    $veh_weight   = (float)($_POST['veh_weight'] ?? 0);

    $oldInput = compact(
        'username','email','dob','gender','phone',
        'governorate','municipality','address','role','is_student',
        'veh_type','veh_make','veh_model','veh_year','veh_color',
        'veh_plate','veh_seats','veh_luggage','veh_weight'
    );

    // ── CAPTCHA ───────────────────────────────────────────────────────
    if ($captcha !== ($_SESSION['captcha_a'] + $_SESSION['captcha_b'])) {
        $errors[] = 'Incorrect answer to the math question.';
    }
    // Regenerate CAPTCHA after attempt
    $_SESSION['captcha_a'] = random_int(1, 9);
    $_SESSION['captcha_b'] = random_int(1, 9);

    // ── Personal Info ─────────────────────────────────────────────────
    if ($username === '')  $errors[] = 'Full name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if ($phone === '')     $errors[] = 'Phone number is required.';

    // Age check (18+)
    if ($dob === '') {
        $errors[] = 'Date of birth is required.';
    } else {
        $dobDate  = new DateTime($dob);
        $today    = new DateTime();
        $age      = (int)$today->diff($dobDate)->y;
        if ($age < 18) $errors[] = 'You must be at least 18 years old to register.';
    }

    // Password
    if (strlen($password) < 8)                                     $errors[] = 'Password must be at least 8 characters.';
    elseif (!preg_match('/[A-Z]/', $password))                     $errors[] = 'Password must contain at least one uppercase letter.';
    elseif (!preg_match('/[0-9]/', $password))                     $errors[] = 'Password must contain at least one number.';
    if ($password !== $confirm_pass)                               $errors[] = 'Passwords do not match.';

    // Location
    if ($governorate === '') $errors[] = 'Please select a governorate.';

    // Role
    if (!in_array($role, ['passenger', 'driver'])) $errors[] = 'Please select a role.';

    // Driver vehicle validation
    if ($role === 'driver') {
        if ($veh_type  === '') $errors[] = 'Vehicle type is required.';
        if ($veh_make  === '') $errors[] = 'Vehicle make/brand is required.';
        if ($veh_model === '') $errors[] = 'Vehicle model is required.';
        if ($veh_year < 2000 || $veh_year > (int)date('Y')) $errors[] = 'Enter a valid vehicle year (2000–' . date('Y') . ').';
        if ($veh_color === '') $errors[] = 'Vehicle color is required.';
        if ($veh_plate === '') $errors[] = 'Plate number (matricule) is required.';
        if ($veh_seats < 1 || $veh_seats > 15) $errors[] = 'Number of seats must be between 1 and 15.';
    }

    if (empty($errors)) {
        $pdo = getDB();

        // Check duplicates
        $dup = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1");
        $dup->execute([$email, $username]);
        if ($dup->fetch()) {
            $errors[] = 'Email or username is already taken.';
        } else {
            try {
                $pdo->beginTransaction();

                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $is_driver = ($role === 'driver') ? 1 : 0;

                $ins = $pdo->prepare("
                    INSERT INTO users
                        (username, email, password, date_of_birth, gender, phone,
                         governorate, municipality, address, is_driver, is_student, Region)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,'TN')
                ");
                $ins->execute([
                    $username, $email, $hashed, $dob, $gender, $phone,
                    $governorate, $municipality, $address, $is_driver, $is_student,
                ]);
                $userId = (int)$pdo->lastInsertId();

                // Profile photo
                $picturePath = null;
                if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                    $mime    = mime_content_type($_FILES['profile_photo']['tmp_name']);
                    if (isset($allowed[$mime])) {
                        $ext  = $allowed[$mime];
                        $dest = realpath(__DIR__ . '/../Src') . "/profile_{$userId}.{$ext}";
                        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dest)) {
                            $picturePath = "../Src/profile_{$userId}.{$ext}";
                            $pdo->prepare("UPDATE users SET picture = ? WHERE id = ?")->execute([$picturePath, $userId]);
                        }
                    }
                }

                // Driver extras
                if ($is_driver) {
                    // Vehicle photo
                    $vehPhotoPath = null;
                    if (isset($_FILES['vehicle_photo']) && $_FILES['vehicle_photo']['error'] === UPLOAD_ERR_OK) {
                        $allowedMime = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                        $mime = mime_content_type($_FILES['vehicle_photo']['tmp_name']);
                        if (isset($allowedMime[$mime])) {
                            $ext  = $allowedMime[$mime];
                            $dest = realpath(__DIR__ . '/../Src') . "/vehicle_{$userId}_" . time() . ".{$ext}";
                            if (move_uploaded_file($_FILES['vehicle_photo']['tmp_name'], $dest)) {
                                $vehPhotoPath = "../Src/vehicle_{$userId}_" . basename($dest, ".$ext") . ".$ext";
                                // simpler relative path
                                $vehPhotoPath = "../Src/" . basename($dest);
                            }
                        }
                    }

                    // Vehicle ID card photo (carte grise)
                    $idCardPath = null;
                    if (isset($_FILES['id_card_photo']) && $_FILES['id_card_photo']['error'] === UPLOAD_ERR_OK) {
                        $allowedMime = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                        $mime = mime_content_type($_FILES['id_card_photo']['tmp_name']);
                        if (isset($allowedMime[$mime])) {
                            $ext  = $allowedMime[$mime];
                            $dest = realpath(__DIR__ . '/../Src') . "/idcard_{$userId}_" . time() . ".{$ext}";
                            if (move_uploaded_file($_FILES['id_card_photo']['tmp_name'], $dest)) {
                                $idCardPath = "../Src/" . basename($dest);
                            }
                        }
                    }

                    $pdo->prepare("
                        INSERT INTO vehicles
                            (user_id, type, make, model, year, color, plate_number, seats, has_ac, luggage, max_weight_kg, photo, id_card_photo)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ")->execute([
                        $userId, $veh_type, $veh_make, $veh_model, $veh_year,
                        $veh_color, $veh_plate, $veh_seats, $veh_ac, $veh_luggage,
                        $veh_weight ?: null, $vehPhotoPath, $idCardPath,
                    ]);

                    $pdo->prepare("INSERT INTO driver_profiles (user_id) VALUES (?)")->execute([$userId]);
                }

                $pdo->commit();
                header('Location: login.php?registered=1');
                exit();

            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log("Signup error: " . $e->getMessage());
                $errors[] = 'Registration failed. Please try again.';
            }
        }
    }
}

$captchaA = $_SESSION['captcha_a'];
$captchaB = $_SESSION['captcha_b'];
$regionsJson = json_encode($regions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
$currentYear = (int)date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up — ForsaDrive</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../Css/app.css">
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #0a2540 0%, #0d3b6e 50%, #1a5276 100%);
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 2rem 1rem 3rem;
            font-family: 'Segoe UI', sans-serif;
        }
        .signup-card {
            width: 100%;
            max-width: 620px;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 20px 60px rgba(0,0,0,.35);
            overflow: hidden;
        }
        .signup-header {
            background: linear-gradient(135deg, #0a2540, #0d6efd);
            padding: 2rem 2rem 1.5rem;
            text-align: center;
            color: #fff;
        }
        .signup-header .brand-name { font-size: 1.8rem; font-weight: 800; letter-spacing: -.5px; }
        .signup-header .brand-name span { color: #f59e0b; }
        .signup-header p { opacity: .8; margin: 0; font-size: .9rem; }

        /* Progress bar */
        .step-progress {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 2rem 0;
            gap: 0;
        }
        .step-node {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
        }
        .step-circle {
            width: 36px; height: 36px;
            border-radius: 50%;
            border: 2px solid #d1d5db;
            background: #fff;
            color: #9ca3af;
            font-weight: 700;
            font-size: .85rem;
            display: flex; align-items: center; justify-content: center;
            transition: all .3s;
        }
        .step-circle.active  { border-color: #0d6efd; color: #0d6efd; background: #eff6ff; }
        .step-circle.done    { border-color: #22c55e; background: #22c55e; color: #fff; }
        .step-label {
            font-size: .7rem;
            color: #9ca3af;
            margin-top: .3rem;
            white-space: nowrap;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .step-label.active { color: #0d6efd; }
        .step-label.done   { color: #22c55e; }
        .step-line {
            flex: 1;
            height: 2px;
            background: #e5e7eb;
            margin: 0 .5rem;
            margin-bottom: 1.4rem;
            transition: background .3s;
        }
        .step-line.done { background: #22c55e; }

        .signup-body { padding: 1.5rem 2rem 2rem; }

        /* Step panes */
        .step-pane { display: none; }
        .step-pane.active { display: block; }

        /* Role cards */
        .role-card {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.25rem;
            cursor: pointer;
            transition: all .2s;
            text-align: center;
            background: #fff;
        }
        .role-card:hover { border-color: #0d6efd; background: #eff6ff; }
        .role-card.selected { border-color: #0d6efd; background: #eff6ff; box-shadow: 0 0 0 3px rgba(13,110,253,.15); }
        .role-card i { font-size: 2.2rem; color: #0d6efd; margin-bottom: .5rem; }
        .role-card h6 { font-weight: 700; color: #0a2540; margin: 0 0 .25rem; }
        .role-card p  { font-size: .8rem; color: #6b7280; margin: 0; }

        /* Password strength */
        .strength-bar {
            height: 4px;
            border-radius: 2px;
            background: #e5e7eb;
            margin-top: .4rem;
            overflow: hidden;
        }
        .strength-fill {
            height: 100%;
            width: 0%;
            border-radius: 2px;
            transition: width .3s, background .3s;
        }
        .strength-text { font-size: .75rem; color: #6b7280; margin-top: .25rem; }

        /* Photo preview */
        .photo-drop {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: border-color .2s;
            position: relative;
        }
        .photo-drop:hover { border-color: #0d6efd; }
        .photo-drop input[type=file] {
            position: absolute; inset: 0;
            opacity: 0; cursor: pointer; width: 100%; height: 100%;
        }
        #photo-preview {
            display: none;
            width: 100px; height: 100px;
            border-radius: 50%;
            object-fit: cover;
            margin: .75rem auto 0;
            border: 3px solid #0d6efd;
        }
        .face-status { font-size: .82rem; margin-top: .5rem; min-height: 1.4em; }

        /* Nav buttons */
        .step-nav { display: flex; gap: .75rem; justify-content: flex-end; margin-top: 1.5rem; }
        .btn-back { background: #f3f4f6; color: #374151; border: none; border-radius: 8px; padding: .6rem 1.4rem; font-weight: 600; }
        .btn-back:hover { background: #e5e7eb; }
        .btn-next { background: #0d6efd; color: #fff; border: none; border-radius: 8px; padding: .6rem 1.4rem; font-weight: 600; }
        .btn-next:hover { background: #0b5ed7; }
        .btn-submit { background: linear-gradient(135deg, #0d6efd, #0a2540); color: #fff; border: none; border-radius: 8px; padding: .65rem 1.6rem; font-weight: 700; font-size: 1rem; }
        .btn-submit:hover { opacity: .9; color: #fff; }

        .form-control:focus, .form-select:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 3px rgba(13,110,253,.12);
        }
        .section-title {
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #6b7280;
            border-bottom: 1px solid #f3f4f6;
            padding-bottom: .4rem;
            margin-bottom: 1rem;
        }
        .login-link {
            text-align: center;
            font-size: .875rem;
            color: #6b7280;
            margin-top: 1.25rem;
        }
        .login-link a { color: #0d6efd; font-weight: 600; text-decoration: none; }
    </style>
</head>
<body>

<div class="signup-card">
    <!-- Header -->
    <div class="signup-header">
        <div class="brand-name">Forsa<span>Drive</span></div>
        <p>Create your account to get started</p>
    </div>

    <!-- Server errors -->
    <?php if (!empty($errors)): ?>
    <div class="mx-3 mt-3 alert alert-danger alert-dismissible fade show py-2" role="alert">
        <strong><i class="fa fa-circle-exclamation me-1"></i>Please fix the following:</strong>
        <ul class="mb-0 mt-1 ps-3" style="font-size:.875rem;">
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Step progress -->
    <div class="step-progress" id="stepProgress">
        <div class="step-node">
            <div class="step-circle active" id="circle-1">1</div>
            <div class="step-label active" id="label-1">Personal</div>
        </div>
        <div class="step-line" id="line-1"></div>
        <div class="step-node">
            <div class="step-circle" id="circle-2">2</div>
            <div class="step-label" id="label-2">Location</div>
        </div>
        <div class="step-line" id="line-2"></div>
        <div class="step-node">
            <div class="step-circle" id="circle-3">3</div>
            <div class="step-label" id="label-3">Account</div>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" id="signupForm" novalidate>
    <div class="signup-body">

        <!-- ══════════════ STEP 1 — Personal Info ══════════════ -->
        <div class="step-pane active" id="step-1">
            <div class="section-title"><i class="fa fa-user me-1"></i>Personal Information</div>

            <div class="mb-3">
                <label class="form-label fd-form-label">Full Name <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-user"></i></span>
                    <input type="text" name="username" class="form-control" placeholder="Your full name"
                           value="<?= htmlspecialchars($oldInput['username'] ?? '') ?>" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fd-form-label">Email Address <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-envelope"></i></span>
                    <input type="email" name="email" class="form-control" placeholder="you@example.com"
                           value="<?= htmlspecialchars($oldInput['email'] ?? '') ?>" required>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <label class="form-label fd-form-label">Date of Birth <span class="text-danger">*</span></label>
                    <input type="date" name="dob" class="form-control"
                           max="<?= date('Y-m-d', strtotime('-18 years')) ?>"
                           value="<?= htmlspecialchars($oldInput['dob'] ?? '') ?>" required>
                </div>
                <div class="col-sm-6">
                    <label class="form-label fd-form-label">Gender</label>
                    <select name="gender" class="form-select">
                        <option value="">Prefer not to say</option>
                        <option value="male"   <?= (($oldInput['gender']??'') === 'male')   ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= (($oldInput['gender']??'') === 'female') ? 'selected' : '' ?>>Female</option>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fd-form-label">Phone Number <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-phone"></i></span>
                    <input type="tel" name="phone" class="form-control" placeholder="+216 XX XXX XXX"
                           value="<?= htmlspecialchars($oldInput['phone'] ?? '') ?>" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fd-form-label">Password <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-lock"></i></span>
                    <input type="password" name="password" id="passwordInput" class="form-control" placeholder="Create a password" required>
                    <button type="button" class="btn btn-outline-secondary" id="togglePass">
                        <i class="fa fa-eye" id="togglePassIcon"></i>
                    </button>
                </div>
                <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                <div class="strength-text" id="strengthText">Enter a password</div>
            </div>

            <div class="mb-3">
                <label class="form-label fd-form-label">Confirm Password <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-lock"></i></span>
                    <input type="password" name="confirm_password" id="confirmPassInput" class="form-control" placeholder="Repeat your password" required>
                </div>
                <div class="form-text" id="matchText"></div>
            </div>

            <!-- CAPTCHA -->
            <div class="mb-3 p-3 rounded" style="background:#f8fafc; border:1px solid #e5e7eb;">
                <label class="form-label fd-form-label">
                    <i class="fa fa-robot me-1"></i>Security Check
                </label>
                <p class="mb-2" style="font-size:.9rem;">
                    What is <strong><?= $captchaA ?></strong> + <strong><?= $captchaB ?></strong>?
                </p>
                <input type="number" name="captcha" class="form-control" style="max-width:120px;"
                       placeholder="Answer" min="0" max="18" required>
            </div>

            <div class="step-nav">
                <button type="button" class="btn-next" id="next1">
                    Next: Location <i class="fa fa-arrow-right ms-1"></i>
                </button>
            </div>
        </div>

        <!-- ══════════════ STEP 2 — Location ══════════════ -->
        <div class="step-pane" id="step-2">
            <div class="section-title"><i class="fa fa-map-marker-alt me-1"></i>Your Location</div>

            <div class="mb-3">
                <label class="form-label fd-form-label">Governorate <span class="text-danger">*</span></label>
                <select name="governorate" id="governorate" class="form-select" required>
                    <option value="">— Select Governorate —</option>
                    <?php foreach (array_keys($regions) as $gov): ?>
                        <option value="<?= htmlspecialchars($gov) ?>"
                            <?= (($oldInput['governorate'] ?? '') === $gov) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($gov) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label fd-form-label">Municipality</label>
                <select name="municipality" id="municipality" class="form-select">
                    <option value="">— Select Municipality —</option>
                    <?php
                    if (!empty($oldInput['governorate']) && isset($regions[$oldInput['governorate']])) {
                        foreach ($regions[$oldInput['governorate']] as $muni) {
                            $sel = ($oldInput['municipality'] === $muni) ? 'selected' : '';
                            echo '<option value="' . htmlspecialchars($muni) . '" ' . $sel . '>' . htmlspecialchars($muni) . '</option>';
                        }
                    }
                    ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label fd-form-label">Street Address</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-home"></i></span>
                    <input type="text" name="address" class="form-control" placeholder="Street, building, apartment..."
                           value="<?= htmlspecialchars($oldInput['address'] ?? '') ?>">
                </div>
            </div>

            <div class="step-nav">
                <button type="button" class="btn-back" id="back2"><i class="fa fa-arrow-left me-1"></i>Back</button>
                <button type="button" class="btn-next" id="next2">
                    Next: Account Setup <i class="fa fa-arrow-right ms-1"></i>
                </button>
            </div>
        </div>

        <!-- ══════════════ STEP 3 — Account Setup ══════════════ -->
        <div class="step-pane" id="step-3">
            <div class="section-title"><i class="fa fa-shield-halved me-1"></i>Account Setup</div>

            <!-- Role selection -->
            <label class="form-label fd-form-label mb-2">I am registering as... <span class="text-danger">*</span></label>
            <input type="hidden" name="role" id="roleInput" value="<?= htmlspecialchars($oldInput['role'] ?? '') ?>">
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <div class="role-card <?= (($oldInput['role']??'') === 'passenger') ? 'selected' : '' ?>"
                         onclick="selectRole('passenger')">
                        <i class="fa fa-person"></i>
                        <h6>Passenger</h6>
                        <p>Book rides &amp; travel</p>
                    </div>
                </div>
                <div class="col-6">
                    <div class="role-card <?= (($oldInput['role']??'') === 'driver') ? 'selected' : '' ?>"
                         onclick="selectRole('driver')">
                        <i class="fa fa-car"></i>
                        <h6>Driver</h6>
                        <p>Offer rides &amp; earn</p>
                    </div>
                </div>
            </div>

            <!-- Driver vehicle fields (shown when driver selected) -->
            <div id="vehicleFields" style="display:<?= (($oldInput['role']??'') === 'driver') ? 'block' : 'none' ?>;">
                <div class="section-title mt-3"><i class="fa fa-car me-1"></i>Vehicle Information</div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label fd-form-label">Vehicle Type <span class="text-danger">*</span></label>
                        <select name="veh_type" class="form-select">
                            <option value="">Select type</option>
                            <?php foreach (['car'=>'Car','van'=>'Van','pickup'=>'Pickup','minibus'=>'Minibus','bike'=>'Bike','motorcycle'=>'Motorcycle'] as $val=>$lbl): ?>
                                <option value="<?= $val ?>" <?= (($oldInput['veh_type']??'') === $val) ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fd-form-label">Make / Brand <span class="text-danger">*</span></label>
                        <input type="text" name="veh_make" class="form-control" placeholder="e.g. Toyota, Kia"
                               value="<?= htmlspecialchars($oldInput['veh_make'] ?? '') ?>">
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label fd-form-label">Model <span class="text-danger">*</span></label>
                        <input type="text" name="veh_model" class="form-control" placeholder="e.g. Corolla, Picanto"
                               value="<?= htmlspecialchars($oldInput['veh_model'] ?? '') ?>">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fd-form-label">Year <span class="text-danger">*</span></label>
                        <input type="number" name="veh_year" class="form-control"
                               min="2000" max="<?= $currentYear ?>" placeholder="e.g. 2019"
                               value="<?= htmlspecialchars((string)($oldInput['veh_year'] ?? '')) ?>">
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label fd-form-label">Color <span class="text-danger">*</span></label>
                        <input type="text" name="veh_color" class="form-control" placeholder="e.g. White, Black"
                               value="<?= htmlspecialchars($oldInput['veh_color'] ?? '') ?>">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fd-form-label">Plate Number (Matricule) <span class="text-danger">*</span></label>
                        <input type="text" name="veh_plate" class="form-control" placeholder="123 TUN 4567"
                               value="<?= htmlspecialchars($oldInput['veh_plate'] ?? '') ?>">
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-4">
                        <label class="form-label fd-form-label">Seats <span class="text-danger">*</span></label>
                        <input type="number" name="veh_seats" class="form-control" min="1" max="15" placeholder="4"
                               value="<?= htmlspecialchars((string)($oldInput['veh_seats'] ?? '')) ?>">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label fd-form-label">Luggage</label>
                        <select name="veh_luggage" class="form-select">
                            <?php foreach (['none'=>'None','small'=>'Small','medium'=>'Medium','large'=>'Large','xl'=>'XL'] as $v=>$l): ?>
                                <option value="<?= $v ?>" <?= (($oldInput['veh_luggage']??'none') === $v) ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label fd-form-label">Max Weight (kg)</label>
                        <input type="number" name="veh_weight" class="form-control" min="0" placeholder="Optional"
                               value="<?= htmlspecialchars((string)($oldInput['veh_weight'] ?? '')) ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="veh_ac" id="vehAc"
                               <?= !empty($oldInput['veh_ac']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="vehAc">
                            <i class="fa fa-wind me-1 text-primary"></i> Vehicle has Air Conditioning
                        </label>
                    </div>
                </div>

                <!-- Vehicle photo -->
                <div class="mb-3">
                    <label class="form-label fd-form-label"><i class="fa fa-camera me-1"></i>Vehicle Photo <span class="text-muted fw-normal">(optional)</span></label>
                    <div class="photo-drop" id="vehPhotoDrop" style="padding:1rem;">
                        <input type="file" name="vehicle_photo" id="vehPhotoInput" accept="image/*">
                        <div id="vehPhotoPrompt">
                            <i class="fa fa-car fa-2x text-secondary mb-2"></i>
                            <p class="mb-0 text-muted" style="font-size:.85rem;">Click or drag to upload a photo of your vehicle</p>
                            <p class="text-muted" style="font-size:.75rem;">JPG, PNG, WebP — max 5 MB</p>
                        </div>
                        <img id="veh-photo-preview" alt="Vehicle Preview"
                             style="display:none;max-width:100%;max-height:180px;border-radius:8px;margin:.75rem auto 0;border:2px solid #0d6efd;object-fit:cover;">
                    </div>
                </div>

                <!-- Vehicle ID / Grey Card photo -->
                <div class="mb-3">
                    <label class="form-label fd-form-label">
                        <i class="fa fa-id-card me-1 text-warning"></i>
                        Vehicle Registration Card (Carte Grise) <span class="text-danger">*</span>
                    </label>
                    <p class="text-muted small mb-2">Take a clear photo of your vehicle's registration document (carte grise / carte d'immatriculation). This is required to verify your vehicle.</p>
                    <div class="photo-drop" id="idCardDrop" style="padding:1rem; border-color:#f59e0b;">
                        <input type="file" name="id_card_photo" id="idCardInput" accept="image/*">
                        <div id="idCardPrompt">
                            <i class="fa fa-id-card fa-2x text-warning mb-2"></i>
                            <p class="mb-0 text-muted" style="font-size:.85rem;">Upload a photo of the registration card</p>
                            <p class="text-muted" style="font-size:.75rem;">JPG, PNG, WebP — max 5 MB</p>
                        </div>
                        <img id="id-card-preview" alt="ID Card Preview"
                             style="display:none;max-width:100%;max-height:180px;border-radius:8px;margin:.75rem auto 0;border:2px solid #f59e0b;object-fit:cover;">
                    </div>
                </div>
            </div>

            <!-- Student checkbox -->
            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_student" id="isStudent"
                           <?= !empty($oldInput['is_student']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="isStudent">
                        <i class="fa fa-graduation-cap me-1 text-primary"></i> I am a student (eligible for student discounts)
                    </label>
                </div>
            </div>

            <!-- Profile photo -->
            <div class="section-title"><i class="fa fa-camera me-1"></i>Profile Photo <span class="text-muted fw-normal">(optional)</span></div>
            <div class="photo-drop" id="photoDrop">
                <input type="file" name="profile_photo" id="photoInput" accept="image/*">
                <div id="photoPrompt">
                    <i class="fa fa-cloud-arrow-up fa-2x text-secondary mb-2"></i>
                    <p class="mb-0 text-muted" style="font-size:.875rem;">Click or drag to upload a photo</p>
                    <p class="text-muted" style="font-size:.75rem;">JPG, PNG, WebP — max 5 MB</p>
                </div>
                <img id="photo-preview" alt="Preview">
            </div>
            <div class="face-status" id="faceStatus"></div>

            <div class="step-nav">
                <button type="button" class="btn-back" id="back3"><i class="fa fa-arrow-left me-1"></i>Back</button>
                <button type="submit" class="btn-submit">
                    <i class="fa fa-user-plus me-1"></i> Create Account
                </button>
            </div>
        </div>

    </div><!-- .signup-body -->
    </form>

    <div class="login-link pb-3">
        Already have an account? <a href="login.php">Sign in</a>
    </div>
</div>

<!-- Regions data for JS -->
<script>
const REGIONS = <?= $regionsJson ?>;
</script>

<!-- face-api.js -->
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Step management ───────────────────────────────────────────────────────────
let currentStep = 1;
const totalSteps = 3;

function showStep(n) {
    document.querySelectorAll('.step-pane').forEach(p => p.classList.remove('active'));
    document.getElementById('step-' + n).classList.add('active');

    for (let i = 1; i <= totalSteps; i++) {
        const circle = document.getElementById('circle-' + i);
        const label  = document.getElementById('label-' + i);
        circle.classList.remove('active', 'done');
        label.classList.remove('active', 'done');
        if (i < n) {
            circle.classList.add('done');
            circle.innerHTML = '<i class="fa fa-check" style="font-size:.65rem"></i>';
            label.classList.add('done');
        } else if (i === n) {
            circle.classList.add('active');
            circle.textContent = i;
            label.classList.add('active');
        } else {
            circle.textContent = i;
        }
    }
    for (let i = 1; i < totalSteps; i++) {
        const line = document.getElementById('line-' + i);
        line.classList.toggle('done', i < n);
    }
    currentStep = n;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Step 1 validation ─────────────────────────────────────────────────────────
function validateStep1() {
    const name  = document.querySelector('[name=username]').value.trim();
    const email = document.querySelector('[name=email]').value.trim();
    const dob   = document.querySelector('[name=dob]').value;
    const phone = document.querySelector('[name=phone]').value.trim();
    const pass  = document.getElementById('passwordInput').value;
    const conf  = document.getElementById('confirmPassInput').value;
    const cap   = document.querySelector('[name=captcha]').value.trim();
    let errs = [];

    if (!name)  errs.push('Full name is required.');
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errs.push('Valid email is required.');
    if (!phone) errs.push('Phone number is required.');
    if (!dob)   errs.push('Date of birth is required.');
    else {
        const age = (Date.now() - new Date(dob)) / (365.25 * 24 * 3600 * 1000);
        if (age < 18) errs.push('You must be 18 or older.');
    }
    if (pass.length < 8) errs.push('Password must be at least 8 characters.');
    if (pass !== conf)   errs.push('Passwords do not match.');
    if (!cap)            errs.push('Please answer the security question.');

    return errs;
}

// ── Step 2 validation ─────────────────────────────────────────────────────────
function validateStep2() {
    const gov = document.getElementById('governorate').value;
    if (!gov) return ['Please select a governorate.'];
    return [];
}

// ── Navigate ──────────────────────────────────────────────────────────────────
document.getElementById('next1').addEventListener('click', () => {
    const errs = validateStep1();
    if (errs.length) { showInlineAlert(errs); return; }
    showStep(2);
});
document.getElementById('next2').addEventListener('click', () => {
    const errs = validateStep2();
    if (errs.length) { showInlineAlert(errs); return; }
    showStep(3);
});
document.getElementById('back2').addEventListener('click', () => showStep(1));
document.getElementById('back3').addEventListener('click', () => showStep(2));

function showInlineAlert(msgs) {
    let el = document.getElementById('jsAlert');
    if (!el) {
        el = document.createElement('div');
        el.id = 'jsAlert';
        el.className = 'alert alert-danger alert-dismissible fade show py-2 mx-0 mt-2';
        el.style.fontSize = '.875rem';
        document.querySelector('.step-pane.active').prepend(el);
    }
    el.innerHTML = '<strong><i class="fa fa-circle-exclamation me-1"></i>Please fix:</strong><ul class="mb-0 mt-1 ps-3">'
        + msgs.map(m => `<li>${m}</li>`).join('')
        + '</ul><button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>';
}

// If server returned errors, jump to step 1 to show them
<?php if (!empty($errors)): ?>
showStep(1);
<?php endif; ?>

// ── Governorate → Municipality ────────────────────────────────────────────────
document.getElementById('governorate').addEventListener('change', function() {
    const muni = document.getElementById('municipality');
    muni.innerHTML = '<option value="">— Select Municipality —</option>';
    const list = REGIONS[this.value] || [];
    list.forEach(m => {
        const opt = document.createElement('option');
        opt.value = m; opt.textContent = m;
        muni.appendChild(opt);
    });
});

// ── Role cards ────────────────────────────────────────────────────────────────
function selectRole(role) {
    document.getElementById('roleInput').value = role;
    document.querySelectorAll('.role-card').forEach(c => c.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
    document.getElementById('vehicleFields').style.display = (role === 'driver') ? 'block' : 'none';
}

// ── Password strength ─────────────────────────────────────────────────────────
document.getElementById('passwordInput').addEventListener('input', function() {
    const v = this.value;
    let score = 0;
    if (v.length >= 8) score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    const fill = document.getElementById('strengthFill');
    const text = document.getElementById('strengthText');
    const map = [
        [0,   '#e5e7eb', 'Too short'],
        [25,  '#ef4444', 'Weak'],
        [50,  '#f59e0b', 'Fair'],
        [75,  '#3b82f6', 'Good'],
        [100, '#22c55e', 'Strong'],
    ];
    const [pct, color, label] = map[score] || map[0];
    fill.style.width   = pct + '%';
    fill.style.background = color;
    text.textContent   = label;
    text.style.color   = color;
});

// ── Password confirm match ────────────────────────────────────────────────────
document.getElementById('confirmPassInput').addEventListener('input', function() {
    const match = this.value === document.getElementById('passwordInput').value;
    const el = document.getElementById('matchText');
    el.textContent = this.value ? (match ? '✓ Passwords match' : '✗ Passwords do not match') : '';
    el.style.color = match ? '#22c55e' : '#ef4444';
});

// ── Toggle password visibility ────────────────────────────────────────────────
document.getElementById('togglePass').addEventListener('click', function() {
    const inp  = document.getElementById('passwordInput');
    const icon = document.getElementById('togglePassIcon');
    const isText = inp.type === 'text';
    inp.type  = isText ? 'password' : 'text';
    icon.className = isText ? 'fa fa-eye' : 'fa fa-eye-slash';
});

// ── Profile photo + face detection ───────────────────────────────────────────
const WEIGHTS_URL = 'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/';
let faceApiReady  = false;

async function loadFaceApi() {
    if (faceApiReady) return;
    try {
        await faceapi.nets.tinyFaceDetector.loadFromUri(WEIGHTS_URL);
        faceApiReady = true;
    } catch (e) {
        console.warn('face-api.js could not load:', e);
    }
}

document.getElementById('photoInput').addEventListener('change', async function() {
    if (!this.files || !this.files[0]) return;
    const file    = this.files[0];
    const preview = document.getElementById('photo-preview');
    const status  = document.getElementById('faceStatus');
    const prompt  = document.getElementById('photoPrompt');

    const reader = new FileReader();
    reader.onload = async (e) => {
        preview.src   = e.target.result;
        preview.style.display = 'block';
        prompt.style.display  = 'none';

        status.innerHTML = '<span class="text-secondary"><i class="fa fa-spinner fa-spin me-1"></i>Checking photo…</span>';

        await loadFaceApi();

        if (!faceApiReady) {
            status.innerHTML = '<span class="text-muted"><i class="fa fa-info-circle me-1"></i>Face check unavailable (offline).</span>';
            return;
        }

        try {
            const img = document.createElement('img');
            img.src   = e.target.result;
            await new Promise(r => { img.onload = r; });

            const detections = await faceapi.detectAllFaces(img, new faceapi.TinyFaceDetectorOptions());

            if (detections.length === 1) {
                status.innerHTML = '<span class="text-success"><i class="fa fa-circle-check me-1"></i>Face detected — looks good!</span>';
            } else if (detections.length === 0) {
                status.innerHTML = '<span class="text-warning"><i class="fa fa-triangle-exclamation me-1"></i>No face detected — please use a clear photo of yourself.</span>';
            } else {
                status.innerHTML = '<span class="text-warning"><i class="fa fa-triangle-exclamation me-1"></i>Multiple faces detected — please use a solo photo.</span>';
            }
        } catch (err) {
            status.innerHTML = '<span class="text-muted"><i class="fa fa-info-circle me-1"></i>Could not analyse photo.</span>';
        }
    };
    reader.readAsDataURL(file);
});

// ── Vehicle photo preview ─────────────────────────────────────────────────────
const vehPhotoInput = document.getElementById('vehPhotoInput');
if (vehPhotoInput) {
    vehPhotoInput.addEventListener('change', function() {
        if (!this.files || !this.files[0]) return;
        const preview = document.getElementById('veh-photo-preview');
        const prompt  = document.getElementById('vehPhotoPrompt');
        const reader  = new FileReader();
        reader.onload = e => {
            preview.src = e.target.result;
            preview.style.display = 'block';
            if (prompt) prompt.style.display = 'none';
        };
        reader.readAsDataURL(this.files[0]);
    });
}

// ── ID card photo preview ─────────────────────────────────────────────────────
const idCardInput = document.getElementById('idCardInput');
if (idCardInput) {
    idCardInput.addEventListener('change', function() {
        if (!this.files || !this.files[0]) return;
        const preview = document.getElementById('id-card-preview');
        const prompt  = document.getElementById('idCardPrompt');
        const reader  = new FileReader();
        reader.onload = e => {
            preview.src = e.target.result;
            preview.style.display = 'block';
            if (prompt) prompt.style.display = 'none';
        };
        reader.readAsDataURL(this.files[0]);
    });
}
</script>
</body>
</html>
