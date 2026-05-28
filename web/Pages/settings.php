<?php
require_once '../server/session.php';
require_once '../server/language.php';
require_once '../classes/users.php';

requireRegularUser();

$db  = getDB();
$uid = $_SESSION['user_id'];
$regions = require_once '../data/tunisia_regions.php';

$currentUser = new User($db);
$currentUser->load($uid);

// Load full user row for extra fields
$fullRow = $db->prepare("SELECT * FROM users WHERE id=?");
$fullRow->execute([$uid]);
$u = $fullRow->fetch(PDO::FETCH_ASSOC);

// Load vehicles
$vStmt = $db->prepare("SELECT * FROM vehicles WHERE user_id=? ORDER BY id DESC");
$vStmt->execute([$uid]);
$vehicles = $vStmt->fetchAll(PDO::FETCH_ASSOC);

$errors  = [];
$success = '';
$tab     = $_POST['active_tab'] ?? $_GET['tab'] ?? 'profile';

// ─────────────────────────────────────────────────────────────────
// POST handlers
// ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Profile (name, phone, bio, DOB, gender) ────────────────────
    if (isset($_POST['update_profile'])) {
        $tab    = 'profile';
        $name   = trim($_POST['name']   ?? '');
        $phone  = trim($_POST['phone']  ?? '');
        $bio    = trim($_POST['bio']    ?? '');
        $dob    = trim($_POST['dob']    ?? '');
        $gender = trim($_POST['gender'] ?? '');

        if (!$name) { $errors[] = 'Full name is required.'; }
        if ($dob) {
            $age = (int)(new DateTime())->diff(new DateTime($dob))->y;
            if ($age < 18) $errors[] = 'You must be at least 18 years old.';
        }
        if (empty($errors)) {
            $db->prepare("UPDATE users SET username=?, phone=?, bio=?, date_of_birth=?, gender=? WHERE id=?")
               ->execute([$name, $phone, $bio, $dob ?: null, $gender ?: null, $uid]);
            $currentUser->load($uid);
            refreshUserInSession($currentUser);
            $success = 'Profile updated!';
        }

    // ── Location ───────────────────────────────────────────────────
    } elseif (isset($_POST['update_location'])) {
        $tab   = 'location';
        $gov   = trim($_POST['governorate']  ?? '');
        $muni  = trim($_POST['municipality'] ?? '');
        $addr  = trim($_POST['address']      ?? '');
        if (!$gov) { $errors[] = 'Please select a governorate.'; }
        if (empty($errors)) {
            $db->prepare("UPDATE users SET governorate=?, municipality=?, address=?, Region='TN' WHERE id=?")
               ->execute([$gov, $muni, $addr, $uid]);
            $success = 'Location updated!';
        }

    // ── Profile picture ────────────────────────────────────────────
    } elseif (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $tab     = 'profile';
        $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
        $mime    = mime_content_type($_FILES['profile_picture']['tmp_name']);
        if (!isset($allowed[$mime]))             $errors[] = 'Invalid file type (JPEG/PNG/WebP/GIF).';
        elseif ($_FILES['profile_picture']['size'] > 3*1024*1024) $errors[] = 'Max file size is 3 MB.';
        if (empty($errors)) {
            $fn   = 'profile_' . $uid . '.' . $allowed[$mime];
            $dest = realpath(__DIR__ . '/../Src') . '/' . $fn;
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $dest)) {
                $rel = '../Src/' . $fn;
                $db->prepare("UPDATE users SET picture=? WHERE id=?")->execute([$rel, $uid]);
                $_SESSION['user_data']['profile_picture'] = $rel;
                $currentUser->load($uid);
                refreshUserInSession($currentUser);
                $success = 'Profile picture updated!';
            } else { $errors[] = 'Upload failed. Check /Src/ permissions.'; }
        }

    // ── Vehicle update ─────────────────────────────────────────────
    } elseif (isset($_POST['update_vehicle'])) {
        $tab   = 'vehicle';
        $vid   = (int)($_POST['vehicle_id'] ?? 0);
        // Must own the vehicle
        $chk = $db->prepare("SELECT id FROM vehicles WHERE id=? AND user_id=?");
        $chk->execute([$vid, $uid]);
        if (!$chk->fetch()) {
            $errors[] = 'Vehicle not found.';
        } else {
            $db->prepare("UPDATE vehicles SET type=?,make=?,model=?,year=?,color=?,plate_number=?,seats=?,has_ac=?,luggage=?,max_weight_kg=? WHERE id=?")
               ->execute([
                   $_POST['veh_type'], $_POST['veh_make'], $_POST['veh_model'],
                   (int)$_POST['veh_year'], $_POST['veh_color'], $_POST['veh_plate'],
                   (int)$_POST['veh_seats'], isset($_POST['veh_ac'])?1:0,
                   $_POST['veh_luggage'], $_POST['veh_weight']?:(null),
                   $vid
               ]);
            // Handle vehicle photo update
            if (isset($_FILES['veh_photo']) && $_FILES['veh_photo']['error'] === UPLOAD_ERR_OK) {
                $am = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                $m  = mime_content_type($_FILES['veh_photo']['tmp_name']);
                if (isset($am[$m])) {
                    $dest = realpath(__DIR__.'/../Src')."/vehicle_{$uid}_".time().".{$am[$m]}";
                    if (move_uploaded_file($_FILES['veh_photo']['tmp_name'], $dest)) {
                        $db->prepare("UPDATE vehicles SET photo=? WHERE id=?")->execute(['../Src/'.basename($dest), $vid]);
                    }
                }
            }
            // Handle carte grise update
            if (isset($_FILES['id_card_photo']) && $_FILES['id_card_photo']['error'] === UPLOAD_ERR_OK) {
                $am = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                $m  = mime_content_type($_FILES['id_card_photo']['tmp_name']);
                if (isset($am[$m])) {
                    $dest = realpath(__DIR__.'/../Src')."/idcard_{$uid}_".time().".{$am[$m]}";
                    if (move_uploaded_file($_FILES['id_card_photo']['tmp_name'], $dest)) {
                        $db->prepare("UPDATE vehicles SET id_card_photo=?, verified=0 WHERE id=?")
                           ->execute(['../Src/'.basename($dest), $vid]);
                    }
                }
            }
            $success = 'Vehicle updated!';
        }

    // ── Add vehicle ────────────────────────────────────────────────
    } elseif (isset($_POST['add_vehicle'])) {
        $tab = 'vehicle';
        $plate = trim($_POST['veh_plate'] ?? '');
        if (!$plate) { $errors[] = 'Plate number is required.'; }
        else {
            $vehPhotoPath = null;
            if (isset($_FILES['veh_photo_new']) && $_FILES['veh_photo_new']['error'] === UPLOAD_ERR_OK) {
                $am = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                $m  = mime_content_type($_FILES['veh_photo_new']['tmp_name']);
                if (isset($am[$m])) {
                    $dest = realpath(__DIR__.'/../Src')."/vehicle_{$uid}_".time().".{$am[$m]}";
                    if (move_uploaded_file($_FILES['veh_photo_new']['tmp_name'], $dest))
                        $vehPhotoPath = '../Src/'.basename($dest);
                }
            }
            $idCardPath = null;
            if (isset($_FILES['id_card_new']) && $_FILES['id_card_new']['error'] === UPLOAD_ERR_OK) {
                $am = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                $m  = mime_content_type($_FILES['id_card_new']['tmp_name']);
                if (isset($am[$m])) {
                    $dest = realpath(__DIR__.'/../Src')."/idcard_{$uid}_".time().".{$am[$m]}";
                    if (move_uploaded_file($_FILES['id_card_new']['tmp_name'], $dest))
                        $idCardPath = '../Src/'.basename($dest);
                }
            }
            $db->prepare("INSERT INTO vehicles (user_id,type,make,model,year,color,plate_number,seats,has_ac,luggage,max_weight_kg,photo,id_card_photo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                   $uid, $_POST['veh_type_new'], $_POST['veh_make_new'], $_POST['veh_model_new'],
                   (int)$_POST['veh_year_new'], $_POST['veh_color_new'], $plate,
                   (int)$_POST['veh_seats_new'], isset($_POST['veh_ac_new'])?1:0,
                   $_POST['veh_luggage_new']??'none', $_POST['veh_weight_new']?:null,
                   $vehPhotoPath, $idCardPath
               ]);
            // Enable driver flag if not already
            $db->prepare("UPDATE users SET is_driver=1 WHERE id=?")->execute([$uid]);
            $db->prepare("INSERT OR IGNORE INTO driver_profiles (user_id) VALUES (?)")->execute([$uid]);
            $_SESSION['user_data']['is_driver'] = 1;
            $success = 'Vehicle added! You are now a driver.';
        }

    // ── Student verification request ────────────────────────────────
    } elseif (isset($_POST['request_student'])) {
        $tab          = 'student';
        $studentEmail = strtolower(trim($_POST['student_email'] ?? ''));
        // Accept .edu, .tn university-style emails
        $validDomains = false;
        $eduPatterns  = ['/\.edu\.tn$/', '/^[\w.]+@u-\w+\.tn$/', '/\.ens\w*\.tn$/', '/\.utm\.tn$/', '/\.insat\.tn$/', '/\.fst\w*\.tn$/', '/\.isim\w*\.tn$/', '/\.isg\.rnu\.tn$/', '/\.enis\.tn$/', '/\.rnu\.tn$/'];
        foreach ($eduPatterns as $pat) {
            if (preg_match($pat, $studentEmail)) { $validDomains = true; break; }
        }
        if (!filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid institutional email address.';
        } elseif (!$validDomains) {
            $errors[] = 'Your email must be from a Tunisian educational institution (e.g. @enis.tn, @rnu.tn, @u-tunis.tn). Contact support if yours is not recognised.';
        } elseif ($u['student_status'] === 'approved') {
            $errors[] = 'Your student status is already approved.';
        } else {
            $db->prepare("UPDATE users SET student_email=?, student_status='pending', is_student=0 WHERE id=?")
               ->execute([$studentEmail, $uid]);
            $success = 'Student verification request submitted! An admin will review it within 24–48 hours.';
        }

    // ── Language preference ─────────────────────────────────────────
    } elseif (isset($_POST['update_language'])) {
        $tab  = 'language';
        $lang = $_POST['lang_pref'] ?? 'en';
        if (!isset($GLOBALS['languages'][$lang])) $lang = 'en';
        $db->prepare("UPDATE users SET lang_pref=? WHERE id=?")->execute([$lang, $uid]);
        $_SESSION['lang'] = $lang;
        $success = 'Language preference saved!';
        header('Location: settings.php?tab=language&ok=1');
        exit();

    // ── Password change ─────────────────────────────────────────────
    } elseif (isset($_POST['change_password'])) {
        $tab  = 'security';
        $cur  = $_POST['current_password'] ?? '';
        $new  = $_POST['new_password'] ?? '';
        $conf = $_POST['confirm_password'] ?? '';
        if (!$cur || !$new || !$conf)              $errors[] = 'All fields required.';
        elseif (!$currentUser->verifyPassword($cur)) $errors[] = 'Current password incorrect.';
        elseif ($new !== $conf)                    $errors[] = 'New passwords do not match.';
        elseif (strlen($new) < 8)                  $errors[] = 'Password must be 8+ characters.';
        elseif (!preg_match('/[A-Z]/',$new)||!preg_match('/[a-z]/',$new)||!preg_match('/[0-9]/',$new))
            $errors[] = 'Password needs uppercase, lowercase, and a number.';
        if (empty($errors)) {
            if ($currentUser->changePassword($cur, $new)) {
                refreshUserInSession($currentUser); $success = 'Password changed!';
            } else { $errors[] = 'Failed to change password.'; }
        }

    // ── Delete account ─────────────────────────────────────────────
    } elseif (isset($_POST['delete_account'])) {
        $tab = 'danger';
        if (!empty($_POST['confirm_delete'])) {
            if ($currentUser->deleteAccount()) { logoutUser(); header('Location: ../index.php?deleted=1'); exit(); }
            else { $errors[] = 'Account deletion failed.'; }
        } else { $errors[] = 'Please check the confirmation box.'; }
    }

    // Reload data after POST
    $fullRow->execute([$uid]);
    $u = $fullRow->fetch(PDO::FETCH_ASSOC);
    $vStmt->execute([$uid]);
    $vehicles = $vStmt->fetchAll(PDO::FETCH_ASSOC);
}

if (isset($_GET['ok'])) $success = 'Language preference saved!';

$pic        = $u['picture'] ?? '../Src/default.jpg';
$regionsJson = json_encode($regions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
$pageTitle  = 'Settings';
include '../include/header.php';
include '../include/sidebar.php';
?>
<style>
.settings-tab-nav .nav-link { border-radius:8px; padding:.5rem .85rem; color:var(--fd-dark); font-size:.875rem; }
.settings-tab-nav .nav-link.active { background:var(--fd-primary); color:#fff; }
.settings-tab-nav .nav-link i { width:18px; }
.photo-drop { border:2px dashed #d1d5db; border-radius:12px; padding:1.5rem; text-align:center; cursor:pointer; transition:border-color .2s; position:relative; }
.photo-drop:hover { border-color:var(--fd-primary); }
.photo-drop input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; }
.vehicle-card { border:1px solid #e5e7eb; border-radius:12px; padding:1rem; background:#fafafa; transition:box-shadow .2s; }
.vehicle-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
.badge-verified   { background:#dcfce7; color:#166534; font-size:.7rem; padding:.2rem .55rem; border-radius:99px; }
.badge-unverified { background:#fef9c3; color:#854d0e; font-size:.7rem; padding:.2rem .55rem; border-radius:99px; }
.badge-pending    { background:#e0f2fe; color:#0369a1; font-size:.7rem; padding:.2rem .55rem; border-radius:99px; }
</style>

<div class="fd-main">
  <div class="page-header">
    <h1><i class="fas fa-cog me-2 text-primary"></i>Account Settings</h1>
  </div>

  <?php if ($success): ?>
    <div class="alert-fd-success mb-3"><i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert-fd-danger mb-3"><i class="fas fa-exclamation-circle me-2"></i><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Left: tab nav -->
    <div class="col-12 col-md-3">
      <div class="fd-card p-2">
        <ul class="nav flex-column settings-tab-nav gap-1" id="settingsTabs">
          <?php
          $tabs = [
            'profile'  => ['fa-user',           'Profile'],
            'location' => ['fa-map-marker-alt',  'Location'],
            'vehicle'  => ['fa-car',             'My Vehicles'],
            'student'  => ['fa-graduation-cap',  'Student Status'],
            'language' => ['fa-globe',           'Language'],
            'security' => ['fa-lock',            'Security'],
            'danger'   => ['fa-exclamation-triangle', 'Danger Zone'],
          ];
          foreach ($tabs as $tid => [$icon, $label]):
            $active = ($tab === $tid) ? 'active' : '';
          ?>
          <li class="nav-item">
            <a class="nav-link <?= $active ?>" href="#" data-tab="<?= $tid ?>">
              <i class="fas <?= $icon ?> me-2"></i><?= $label ?>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <!-- Right: tab content -->
    <div class="col-12 col-md-9">

      <!-- ── PROFILE TAB ─────────────────────────────────────────── -->
      <div class="tab-pane <?= $tab==='profile'?'':'d-none' ?>" id="pane-profile">
        <div class="row g-4">
          <!-- Avatar -->
          <div class="col-12 col-sm-4">
            <div class="fd-card text-center">
              <img src="<?= htmlspecialchars($pic) ?>" alt="Profile" id="avatarPreview"
                   onerror="this.src='../Src/default.jpg'"
                   style="width:100px;height:100px;border-radius:50%;object-fit:cover;border:3px solid var(--fd-primary);margin-bottom:1rem">
              <form method="POST" enctype="multipart/form-data" id="picForm">
                <input type="hidden" name="active_tab" value="profile">
                <div class="photo-drop" onclick="document.getElementById('avatarInput').click()" style="cursor:pointer">
                  <input type="file" name="profile_picture" id="avatarInput" accept="image/*" style="display:none">
                  <div>
                    <i class="fas fa-camera text-muted"></i><br>
                    <small class="text-muted">Click to change photo</small>
                  </div>
                </div>
                <div id="faceStatus" class="mt-2 small" style="min-height:1.4rem"></div>
                <button type="submit" id="picSaveBtn" class="btn-fd-primary btn btn-sm mt-2 w-100" style="display:none">
                  <i class="fas fa-save me-1"></i>Save Photo
                </button>
              </form>
              <div class="mt-3 fw-semibold"><?= htmlspecialchars($u['username']) ?></div>
              <div class="text-muted small"><?= htmlspecialchars($u['email']) ?></div>
              <?php if (!empty($u['public_id'])): ?>
              <div class="mt-2 d-inline-flex align-items-center gap-1 px-2 py-1 rounded-pill"
                   style="background:#eef2ff;color:#3730a3;font-size:.75rem;font-weight:600;border:1px solid #c7d2fe;"
                   title="Your public ForsaDrive ID — safe to share">
                <i class="fas fa-id-badge"></i>
                <span id="publicIdValue"><?= htmlspecialchars($u['public_id']) ?></span>
                <button type="button" class="btn btn-link p-0 ms-1" style="line-height:1;color:#3730a3;"
                        onclick="navigator.clipboard.writeText(document.getElementById('publicIdValue').textContent.trim()); this.querySelector('i').className='fas fa-check'; setTimeout(()=>this.querySelector('i').className='far fa-copy',1500)"
                        title="Copy ID">
                  <i class="far fa-copy" style="font-size:.7rem"></i>
                </button>
              </div>
              <div class="text-muted" style="font-size:.7rem">Share this ID to report or get support — your email & phone stay private.</div>
              <?php endif; ?>
              <?php if (!empty($u['is_driver'])): ?>
              <span class="badge bg-primary mt-1">Driver</span>
              <?php endif; ?>
              <?php if (!empty($u['is_student']) && $u['student_status']==='approved'): ?>
              <span class="badge bg-success mt-1">Student ✓</span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Profile form -->
          <div class="col-12 col-sm-8">
            <div class="fd-card">
              <form method="POST">
                <input type="hidden" name="active_tab" value="profile">
                <div class="mb-3">
                  <label class="fd-form-label">Full Name *</label>
                  <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($u['username']) ?>" required>
                </div>
                <div class="row g-3 mb-3">
                  <div class="col-sm-6">
                    <label class="fd-form-label">Date of Birth</label>
                    <input type="date" name="dob" class="form-control" value="<?= htmlspecialchars($u['date_of_birth']??'') ?>"
                           max="<?= date('Y-m-d', strtotime('-18 years')) ?>">
                  </div>
                  <div class="col-sm-6">
                    <label class="fd-form-label">Gender</label>
                    <select name="gender" class="form-select">
                      <option value="">Prefer not to say</option>
                      <option value="male"   <?= ($u['gender']??'')==='male'   ?'selected':'' ?>>Male</option>
                      <option value="female" <?= ($u['gender']??'')==='female' ?'selected':'' ?>>Female</option>
                    </select>
                  </div>
                </div>
                <div class="mb-3">
                  <label class="fd-form-label">Phone Number</label>
                  <div class="input-group">
                    <span class="input-group-text">+216</span>
                    <input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($u['phone']??'') ?>" placeholder="XX XXX XXX">
                  </div>
                </div>
                <div class="mb-3">
                  <label class="fd-form-label">Bio</label>
                  <textarea name="bio" class="form-control" rows="3" placeholder="Tell others about yourself..."><?= htmlspecialchars($u['bio']??'') ?></textarea>
                </div>
                <button type="submit" name="update_profile" class="btn-fd-primary btn">
                  <i class="fas fa-save me-1"></i>Save Profile
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- ── LOCATION TAB ─────────────────────────────────────────── -->
      <div class="tab-pane <?= $tab==='location'?'':'d-none' ?>" id="pane-location">
        <div class="fd-card">
          <div class="card-title"><i class="fas fa-map-marker-alt text-primary"></i> Your Location in Tunisia</div>
          <form method="POST">
            <input type="hidden" name="active_tab" value="location">
            <div class="mb-3">
              <label class="fd-form-label">Governorate *</label>
              <select name="governorate" id="govSelect" class="form-select" required>
                <option value="">— Select Governorate —</option>
                <?php foreach (array_keys($regions) as $gov): ?>
                <option value="<?= htmlspecialchars($gov) ?>" <?= ($u['governorate']??'')===$gov?'selected':'' ?>>
                  <?= htmlspecialchars($gov) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="fd-form-label">Municipality / Delegation</label>
              <select name="municipality" id="muniSelect" class="form-select">
                <option value="">— Select Municipality —</option>
                <?php
                $curGov = $u['governorate'] ?? '';
                if ($curGov && isset($regions[$curGov])) {
                    foreach ($regions[$curGov] as $m) {
                        $sel = ($u['municipality']??'') === $m ? 'selected' : '';
                        echo '<option value="'.htmlspecialchars($m).'" '.$sel.'>'.htmlspecialchars($m).'</option>';
                    }
                }
                ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="fd-form-label">Street Address</label>
              <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($u['address']??'') ?>" placeholder="Street, building...">
            </div>
            <button type="submit" name="update_location" class="btn-fd-primary btn">
              <i class="fas fa-save me-1"></i>Save Location
            </button>
          </form>
        </div>
      </div>

      <!-- ── VEHICLE TAB ─────────────────────────────────────────── -->
      <div class="tab-pane <?= $tab==='vehicle'?'':'d-none' ?>" id="pane-vehicle">

        <!-- Car & Carte Grise Detection Info Banner -->
        <div class="alert-fd-info mb-3">
          <i class="fas fa-shield-alt me-2"></i>
          <strong>Vehicle Verification:</strong> After uploading your vehicle photo and registration card (carte grise), an admin will manually verify them within 48 hours. Verified vehicles display a green badge.
        </div>

        <!-- Existing vehicles -->
        <?php foreach ($vehicles as $v): ?>
        <div class="fd-card mb-3 vehicle-card">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
              <span class="fw-bold"><?= htmlspecialchars($v['make']??'') ?> <?= htmlspecialchars($v['model']??'') ?></span>
              <span class="text-muted small ms-2">(<?= htmlspecialchars($v['year']??'') ?>)</span>
              <?php if (!empty($v['verified'])): ?>
              <span class="badge-verified ms-2"><i class="fas fa-check-circle me-1"></i>Verified</span>
              <?php else: ?>
              <span class="badge-unverified ms-2"><i class="fas fa-clock me-1"></i>Pending Verification</span>
              <?php endif; ?>
            </div>
            <div class="text-muted small">
              <i class="fas fa-hashtag"></i> <?= htmlspecialchars($v['plate_number']??'N/A') ?>
            </div>
          </div>

          <!-- Vehicle photos -->
          <div class="row g-3 mb-3">
            <div class="col-6">
              <div class="text-muted small mb-1"><i class="fas fa-car me-1"></i>Vehicle Photo</div>
              <?php if (!empty($v['photo'])): ?>
              <img src="<?= htmlspecialchars($v['photo']) ?>" alt="Vehicle" style="width:100%;max-height:120px;object-fit:cover;border-radius:8px;">
              <?php else: ?>
              <div class="d-flex align-items-center justify-content-center bg-light rounded" style="height:80px;color:#9ca3af"><i class="fas fa-car fa-2x"></i></div>
              <?php endif; ?>
            </div>
            <div class="col-6">
              <div class="text-muted small mb-1"><i class="fas fa-id-card me-1 text-warning"></i>Carte Grise</div>
              <?php if (!empty($v['id_card_photo'])): ?>
              <img src="<?= htmlspecialchars($v['id_card_photo']) ?>" alt="Carte Grise" style="width:100%;max-height:120px;object-fit:cover;border-radius:8px;border:2px solid #f59e0b;">
              <?php else: ?>
              <div class="d-flex align-items-center justify-content-center bg-warning bg-opacity-10 rounded border border-warning" style="height:80px;color:#f59e0b"><i class="fas fa-id-card fa-2x"></i><div class="ms-2 small">Not uploaded</div></div>
              <?php endif; ?>
            </div>
          </div>

          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="active_tab" value="vehicle">
            <input type="hidden" name="vehicle_id"  value="<?= $v['id'] ?>">
            <div class="row g-2 mb-2">
              <div class="col-sm-4">
                <label class="fd-form-label">Type</label>
                <select name="veh_type" class="form-select form-select-sm">
                  <?php foreach (['car'=>'Car','van'=>'Van','pickup'=>'Pickup','minibus'=>'Minibus','bike'=>'Bike'] as $vv=>$ll): ?>
                  <option value="<?= $vv ?>" <?= ($v['type']??'')===$vv?'selected':'' ?>><?= $ll ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-sm-4">
                <label class="fd-form-label">Make</label>
                <input type="text" name="veh_make" class="form-control form-control-sm" value="<?= htmlspecialchars($v['make']??'') ?>">
              </div>
              <div class="col-sm-4">
                <label class="fd-form-label">Model</label>
                <input type="text" name="veh_model" class="form-control form-control-sm" value="<?= htmlspecialchars($v['model']??'') ?>">
              </div>
              <div class="col-sm-3">
                <label class="fd-form-label">Year</label>
                <input type="number" name="veh_year" class="form-control form-control-sm" min="2000" max="<?= date('Y') ?>" value="<?= htmlspecialchars($v['year']??'') ?>">
              </div>
              <div class="col-sm-3">
                <label class="fd-form-label">Color</label>
                <input type="text" name="veh_color" class="form-control form-control-sm" value="<?= htmlspecialchars($v['color']??'') ?>">
              </div>
              <div class="col-sm-3">
                <label class="fd-form-label">Plate (Matricule)</label>
                <input type="text" name="veh_plate" class="form-control form-control-sm" value="<?= htmlspecialchars($v['plate_number']??'') ?>">
              </div>
              <div class="col-sm-3">
                <label class="fd-form-label">Seats</label>
                <input type="number" name="veh_seats" class="form-control form-control-sm" min="1" max="15" value="<?= (int)($v['seats']??4) ?>">
              </div>
              <div class="col-sm-4">
                <label class="fd-form-label">Luggage</label>
                <select name="veh_luggage" class="form-select form-select-sm">
                  <?php foreach (['none'=>'None','small'=>'Small','medium'=>'Medium','large'=>'Large'] as $lv=>$ll): ?>
                  <option value="<?= $lv ?>" <?= ($v['luggage']??'none')===$lv?'selected':'' ?>><?= $ll ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-sm-4">
                <label class="fd-form-label">Max Weight (kg)</label>
                <input type="number" name="veh_weight" class="form-control form-control-sm" value="<?= htmlspecialchars($v['max_weight_kg']??'') ?>" placeholder="Optional">
              </div>
              <div class="col-sm-4 d-flex align-items-end">
                <div class="form-check mb-2">
                  <input class="form-check-input" type="checkbox" name="veh_ac" id="ac_<?= $v['id'] ?>" <?= !empty($v['has_ac'])?'checked':'' ?>>
                  <label class="form-check-label small" for="ac_<?= $v['id'] ?>"><i class="fas fa-wind me-1 text-primary"></i>A/C</label>
                </div>
              </div>
              <div class="col-sm-6">
                <label class="fd-form-label"><i class="fas fa-camera me-1"></i>Update Vehicle Photo</label>
                <input type="file" name="veh_photo" class="form-control form-control-sm" accept="image/*">
              </div>
              <div class="col-sm-6">
                <label class="fd-form-label"><i class="fas fa-id-card me-1 text-warning"></i>Update Carte Grise</label>
                <input type="file" name="id_card_photo" class="form-control form-control-sm" accept="image/*">
                <div class="form-text small text-warning">Re-uploading will reset verification status.</div>
              </div>
            </div>
            <button type="submit" name="update_vehicle" class="btn-fd-primary btn btn-sm">
              <i class="fas fa-save me-1"></i>Save Changes
            </button>
          </form>
        </div>
        <?php endforeach; ?>

        <!-- Add new vehicle -->
        <div class="fd-card" id="addVehicleCard">
          <div class="card-title d-flex justify-content-between align-items-center">
            <span><i class="fas fa-plus-circle text-success"></i> Add New Vehicle</span>
            <button class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('addVehicleForm').classList.toggle('d-none')">
              <i class="fas fa-chevron-down"></i>
            </button>
          </div>
          <form method="POST" enctype="multipart/form-data" id="addVehicleForm" class="d-none">
            <input type="hidden" name="active_tab" value="vehicle">
            <div class="row g-2 mb-2">
              <div class="col-sm-4"><label class="fd-form-label">Type *</label>
                <select name="veh_type_new" class="form-select form-select-sm">
                  <?php foreach (['car'=>'Car','van'=>'Van','pickup'=>'Pickup','minibus'=>'Minibus','bike'=>'Bike'] as $vv=>$ll): ?>
                  <option value="<?= $vv ?>"><?= $ll ?></option><?php endforeach; ?>
                </select></div>
              <div class="col-sm-4"><label class="fd-form-label">Make *</label>
                <input type="text" name="veh_make_new" class="form-control form-control-sm" placeholder="e.g. Toyota" required></div>
              <div class="col-sm-4"><label class="fd-form-label">Model *</label>
                <input type="text" name="veh_model_new" class="form-control form-control-sm" placeholder="e.g. Corolla" required></div>
              <div class="col-sm-3"><label class="fd-form-label">Year *</label>
                <input type="number" name="veh_year_new" class="form-control form-control-sm" min="2000" max="<?= date('Y') ?>" required></div>
              <div class="col-sm-3"><label class="fd-form-label">Color *</label>
                <input type="text" name="veh_color_new" class="form-control form-control-sm" required></div>
              <div class="col-sm-3"><label class="fd-form-label">Plate (Matricule) *</label>
                <input type="text" name="veh_plate" class="form-control form-control-sm" placeholder="123 TUN 4567" required></div>
              <div class="col-sm-3"><label class="fd-form-label">Seats *</label>
                <input type="number" name="veh_seats_new" class="form-control form-control-sm" min="1" max="15" value="4" required></div>
              <div class="col-sm-4"><label class="fd-form-label">Luggage</label>
                <select name="veh_luggage_new" class="form-select form-select-sm">
                  <option value="none">None</option><option value="small">Small</option>
                  <option value="medium">Medium</option><option value="large">Large</option>
                </select></div>
              <div class="col-sm-4"><label class="fd-form-label">Max Weight (kg)</label>
                <input type="number" name="veh_weight_new" class="form-control form-control-sm" placeholder="Optional"></div>
              <div class="col-sm-4 d-flex align-items-end">
                <div class="form-check mb-2">
                  <input class="form-check-input" type="checkbox" name="veh_ac_new" id="acNew">
                  <label class="form-check-label small" for="acNew"><i class="fas fa-wind me-1 text-primary"></i>A/C</label>
                </div>
              </div>
              <div class="col-sm-6"><label class="fd-form-label"><i class="fas fa-camera me-1"></i>Vehicle Photo</label>
                <input type="file" name="veh_photo_new" class="form-control form-control-sm" accept="image/*"></div>
              <div class="col-sm-6"><label class="fd-form-label"><i class="fas fa-id-card me-1 text-warning"></i>Carte Grise (required)</label>
                <input type="file" name="id_card_new" class="form-control form-control-sm" accept="image/*" required></div>
            </div>
            <button type="submit" name="add_vehicle" class="btn-fd-success btn btn-sm">
              <i class="fas fa-plus me-1"></i>Add Vehicle
            </button>
          </form>
        </div>
      </div>

      <!-- ── STUDENT TAB ─────────────────────────────────────────── -->
      <div class="tab-pane <?= $tab==='student'?'':'d-none' ?>" id="pane-student">
        <div class="fd-card">
          <div class="card-title"><i class="fas fa-graduation-cap text-primary"></i> Student Discount Verification</div>

          <?php
          $ss = $u['student_status'] ?? 'none';
          if ($ss === 'approved'): ?>
          <div class="alert-fd-success">
            <i class="fas fa-check-circle me-2"></i><strong>Verified Student!</strong>
            You receive a <strong>50% discount</strong> on all rides. Verified on <?= htmlspecialchars($u['student_verified_at']??'') ?>.
          </div>
          <?php elseif ($ss === 'pending'): ?>
          <div class="alert-fd-info">
            <i class="fas fa-clock me-2"></i><strong>Verification Pending.</strong>
            Your request is under review (submitted email: <code><?= htmlspecialchars($u['student_email']??'') ?></code>).
            An admin will process it within 24–48 hours.
          </div>
          <?php elseif ($ss === 'rejected'): ?>
          <div class="alert-fd-danger mb-3">
            <i class="fas fa-times-circle me-2"></i><strong>Verification Rejected.</strong>
            The submitted email was not recognised as a valid Tunisian institutional address. You may resubmit.
          </div>
          <?php endif; ?>

          <?php if ($ss !== 'approved'): ?>
          <p class="text-muted small mb-3">
            To receive the <strong>50% student discount</strong>, you must verify your student status using your institutional email address from a recognised Tunisian university or school.
            Accepted domains include: <code>@enis.tn</code>, <code>@rnu.tn</code>, <code>@u-tunis.tn</code>, <code>@insat.tn</code>, <code>@utm.tn</code>, <code>@fst*.tn</code>, and other <code>.edu.tn</code> addresses.
          </p>
          <form method="POST">
            <input type="hidden" name="active_tab" value="student">
            <div class="mb-3">
              <label class="fd-form-label">Institutional / University Email *</label>
              <input type="email" name="student_email" class="form-control"
                     value="<?= htmlspecialchars($u['student_email']??'') ?>"
                     placeholder="yourname@enis.tn or yourname@u-sfax.tn" required>
              <div class="form-text">Must be from your school's official email domain.</div>
            </div>
            <button type="submit" name="request_student" class="btn-fd-primary btn">
              <i class="fas fa-paper-plane me-1"></i>
              <?= ($ss === 'pending') ? 'Resubmit Request' : 'Submit Verification Request' ?>
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <!-- ── LANGUAGE TAB ─────────────────────────────────────────── -->
      <div class="tab-pane <?= $tab==='language'?'':'d-none' ?>" id="pane-language">
        <div class="fd-card">
          <div class="card-title"><i class="fas fa-globe text-primary"></i> Language & Display</div>
          <p class="text-muted small mb-4">Choose your preferred language. The interface will automatically switch when you log in.</p>
          <form method="POST">
            <input type="hidden" name="active_tab" value="language">
            <div class="row g-3 mb-4">
              <?php foreach ($GLOBALS['languages'] as $code => $info): ?>
              <?php $isActive = ($u['lang_pref']??'en') === $code; ?>
              <div class="col-12 col-sm-4">
                <label class="d-block" style="cursor:pointer">
                  <input type="radio" name="lang_pref" value="<?= $code ?>" class="d-none" <?= $isActive?'checked':'' ?>>
                  <div class="fd-card text-center py-3 <?= $isActive?'border-primary':'' ?>"
                       style="<?= $isActive?'border:2px solid var(--fd-primary);background:#f0f7ff':'' ?>"
                       onclick="this.closest('label').querySelector('input').checked=true; document.querySelectorAll('.lang-card').forEach(c=>c.style.background=''); this.style.background='#f0f7ff'">
                    <div style="font-size:2rem"><?= $info['flag'] ?></div>
                    <div class="fw-semibold mt-1"><?= $info['name'] ?></div>
                    <?php if ($isActive): ?><div class="small text-primary">Current</div><?php endif; ?>
                  </div>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
            <button type="submit" name="update_language" class="btn-fd-primary btn">
              <i class="fas fa-save me-1"></i>Save Language
            </button>
          </form>

          <!-- Google Translate quick switcher -->
          <hr class="my-4">
          <div class="card-title"><i class="fas fa-language text-primary"></i> Instant Page Translation</div>
          <p class="text-muted small mb-3">Use Google Translate to instantly translate the entire page without changing your account preference:</p>
          <div class="d-flex gap-2 flex-wrap">
            <button onclick="triggerGoogleTranslate('en')" class="btn btn-outline-secondary btn-sm">🇬🇧 English</button>
            <button onclick="triggerGoogleTranslate('fr')" class="btn btn-outline-secondary btn-sm">🇫🇷 Français</button>
            <button onclick="triggerGoogleTranslate('ar')" class="btn btn-outline-secondary btn-sm">🇹🇳 العربية</button>
          </div>
        </div>
      </div>

      <!-- ── SECURITY TAB ─────────────────────────────────────────── -->
      <div class="tab-pane <?= $tab==='security'?'':'d-none' ?>" id="pane-security">
        <div class="fd-card">
          <div class="card-title"><i class="fas fa-key text-primary"></i> Change Password</div>
          <form method="POST" style="max-width:460px">
            <input type="hidden" name="active_tab" value="security">
            <div class="mb-3">
              <label class="fd-form-label">Current Password</label>
              <input type="password" name="current_password" class="form-control" required>
            </div>
            <div class="mb-3">
              <label class="fd-form-label">New Password</label>
              <input type="password" name="new_password" id="newPass" class="form-control" required>
            </div>
            <div class="mb-3">
              <label class="fd-form-label">Confirm New Password</label>
              <input type="password" name="confirm_password" class="form-control" required>
            </div>
            <div class="alert-fd-info small mb-3">
              <i class="fas fa-info-circle me-1"></i>8+ characters, uppercase, lowercase, and a number.
            </div>
            <button type="submit" name="change_password" class="btn-fd-primary btn">
              <i class="fas fa-key me-1"></i>Change Password
            </button>
          </form>
        </div>
      </div>

      <!-- ── DANGER TAB ─────────────────────────────────────────── -->
      <div class="tab-pane <?= $tab==='danger'?'':'d-none' ?>" id="pane-danger">
        <div class="fd-card" style="border-left:3px solid var(--fd-danger)">
          <div class="card-title text-danger"><i class="fas fa-exclamation-triangle"></i> Delete Account</div>
          <p class="text-muted small">Permanently deletes your account, all rides, bookings, and data. <strong>This cannot be undone.</strong></p>
          <form method="POST" onsubmit="return confirm('Are you absolutely sure? This cannot be undone.')">
            <input type="hidden" name="active_tab" value="danger">
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" name="confirm_delete" id="confirmDel" required>
              <label class="form-check-label small" for="confirmDel">I understand my account will be permanently deleted.</label>
            </div>
            <button type="submit" name="delete_account" class="btn-fd-danger btn">
              <i class="fas fa-trash-alt me-1"></i>Delete My Account
            </button>
          </form>
        </div>
      </div>

    </div><!-- col-md-9 -->
  </div><!-- row -->
</div><!-- fd-main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const REGIONS = <?= $regionsJson ?>;

// Tab switching (client-side, no reload)
document.querySelectorAll('#settingsTabs .nav-link').forEach(link => {
    link.addEventListener('click', e => {
        e.preventDefault();
        document.querySelectorAll('#settingsTabs .nav-link').forEach(l => l.classList.remove('active'));
        link.classList.add('active');
        const id = link.dataset.tab;
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.add('d-none'));
        document.getElementById('pane-' + id).classList.remove('d-none');
    });
});

// Governorate → Municipality cascade
document.getElementById('govSelect').addEventListener('change', function() {
    const muni = document.getElementById('muniSelect');
    muni.innerHTML = '<option value="">— Select Municipality —</option>';
    (REGIONS[this.value] || []).forEach(m => {
        const o = document.createElement('option'); o.value = m; o.textContent = m; muni.appendChild(o);
    });
});

// Preview image before upload
function previewImg(input, previewId) {
    if (input.files && input.files[0]) {
        const r = new FileReader();
        r.onload = e => document.getElementById(previewId).src = e.target.result;
        r.readAsDataURL(input.files[0]);
    }
}

// Quick translate button: redirect with ?lang= so server sets the cookie properly
function triggerGoogleTranslate(lang) {
    window.location.href = 'settings.php?tab=language&lang=' + lang;
}
</script>

<!-- face-api.js for profile picture face detection -->
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script>
(function(){
    const WEIGHTS_URL = 'https://raw.githubusercontent.com/justadudewhohacks/face-api.js/master/weights/';
    let faceApiReady  = false;

    async function loadFaceApi() {
        if (faceApiReady) return;
        try {
            await faceapi.nets.tinyFaceDetector.loadFromUri(WEIGHTS_URL);
            faceApiReady = true;
        } catch(e) {
            console.warn('face-api.js load failed:', e);
        }
    }

    const avatarInput  = document.getElementById('avatarInput');
    const avatarPreview = document.getElementById('avatarPreview');
    const faceStatus   = document.getElementById('faceStatus');
    const picSaveBtn   = document.getElementById('picSaveBtn');

    if (!avatarInput) return;

    avatarInput.addEventListener('change', async function() {
        if (!this.files || !this.files[0]) return;
        const file = this.files[0];

        // Preview immediately
        const reader = new FileReader();
        reader.onload = async (e) => {
            avatarPreview.src = e.target.result;
            picSaveBtn.style.display = 'block';
            faceStatus.innerHTML = '<span class="text-secondary"><i class="fas fa-spinner fa-spin me-1"></i>Checking photo…</span>';

            await loadFaceApi();

            if (!faceApiReady) {
                faceStatus.innerHTML = '<span class="text-muted"><i class="fas fa-info-circle me-1"></i>Face check unavailable — you can still save.</span>';
                return;
            }

            try {
                const img = new Image();
                img.src = e.target.result;
                await new Promise(r => { img.onload = r; });

                const detections = await faceapi.detectAllFaces(img, new faceapi.TinyFaceDetectorOptions());

                if (detections.length === 1) {
                    faceStatus.innerHTML = '<span class="text-success"><i class="fas fa-circle-check me-1"></i>Face detected — looks great!</span>';
                } else if (detections.length === 0) {
                    faceStatus.innerHTML = '<span class="text-warning"><i class="fas fa-triangle-exclamation me-1"></i>No face detected — please use a clear solo photo of yourself.</span>';
                } else {
                    faceStatus.innerHTML = '<span class="text-warning"><i class="fas fa-triangle-exclamation me-1"></i>Multiple faces — please use a solo photo.</span>';
                }
            } catch(err) {
                faceStatus.innerHTML = '<span class="text-muted"><i class="fas fa-info-circle me-1"></i>Could not analyse photo.</span>';
            }
        };
        reader.readAsDataURL(file);
    });
})();
</script>
</body>
</html>
