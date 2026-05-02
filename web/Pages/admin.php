<?php
require_once '../server/session.php';
require_once '../server/language.php';
require_once '../classes/complaints.php';
require_once '../classes/notifications.php';

if (!isLoggedIn() || empty($_SESSION['user_data']['is_admin'])) {
    header('Location: interface.php'); exit();
}

$db    = getDB();
$uid   = $_SESSION['user_id'];
$notif = new Notifications($db);
$comp  = new Complaints($db);

$msg = ''; $msgType = '';
$tab = $_GET['tab'] ?? 'overview';

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action']    ?? '';
    $targetId = (int)($_POST['target_id'] ?? 0);

    switch ($action) {
        case 'ban_user':
            $reason = trim($_POST['ban_reason'] ?? 'Policy violation');
            $db->prepare("UPDATE users SET suspended=1, ban_reason=? WHERE id=? AND is_admin=0")->execute([$reason, $targetId]);
            $notif->create($targetId, 'Account Suspended', "Your account has been suspended. Reason: $reason", 'danger');
            $msg = 'User suspended.'; $msgType = 'success'; $tab = 'users'; break;

        case 'unban_user':
            $db->prepare("UPDATE users SET suspended=0, ban_reason=NULL WHERE id=?")->execute([$targetId]);
            $notif->create($targetId, 'Account Reinstated', 'Your account has been reinstated. Welcome back!', 'success');
            $msg = 'User reinstated.'; $msgType = 'success'; $tab = 'users'; break;

        case 'make_driver':
            $db->prepare("UPDATE users SET is_driver=1 WHERE id=?")->execute([$targetId]);
            $db->prepare("INSERT OR IGNORE INTO driver_profiles (user_id) VALUES (?)")->execute([$targetId]);
            $msg = 'User promoted to driver.'; $msgType = 'success'; $tab = 'users'; break;

        case 'make_agent':
            $db->prepare("UPDATE users SET is_helpdesk_agent=1 WHERE id=?")->execute([$targetId]);
            $notif->create($targetId, 'HelpDesk Agent Role', 'You have been assigned as a HelpDesk support agent.', 'success');
            $msg = 'User set as HelpDesk agent.'; $msgType = 'success'; $tab = 'users'; break;

        case 'remove_agent':
            $db->prepare("UPDATE users SET is_helpdesk_agent=0 WHERE id=?")->execute([$targetId]);
            $msg = 'Agent role removed.'; $msgType = 'success'; $tab = 'users'; break;

        case 'approve_verification':
            $db->prepare("UPDATE student_verifications SET status='approved',updated_at=datetime('now') WHERE id=?")->execute([$targetId]);
            $sv = $db->prepare("SELECT user_id FROM student_verifications WHERE id=?"); $sv->execute([$targetId]);
            if ($userId = $sv->fetchColumn()) {
                $db->prepare("UPDATE users SET is_student_verified=1, is_student=1 WHERE id=?")->execute([$userId]);
                $notif->create($userId, 'Student Verified ✅', 'Your student status is verified! You now receive 50% discounts.', 'success');
            }
            $msg = 'Verification approved.'; $msgType = 'success'; $tab = 'verifications'; break;

        case 'reject_verification':
            $note = trim($_POST['admin_note'] ?? '');
            $db->prepare("UPDATE student_verifications SET status='rejected',note=?,updated_at=datetime('now') WHERE id=?")->execute([$note, $targetId]);
            $sv = $db->prepare("SELECT user_id FROM student_verifications WHERE id=?"); $sv->execute([$targetId]);
            if ($userId = $sv->fetchColumn()) {
                $notif->create($userId, 'Verification Rejected', "Your student verification was rejected. $note", 'danger');
            }
            $msg = 'Verification rejected.'; $msgType = 'danger'; $tab = 'verifications'; break;

        case 'resolve_complaint':
            $comp->updateStatus($targetId, 'resolved', trim($_POST['admin_note'] ?? ''));
            $msg = 'Complaint resolved.'; $msgType = 'success'; $tab = 'complaints'; break;

        case 'dismiss_complaint':
            $comp->updateStatus($targetId, 'dismissed', trim($_POST['admin_note'] ?? ''));
            $msg = 'Complaint dismissed.'; $msgType = 'success'; $tab = 'complaints'; break;

        case 'approve_org':
            $code = strtoupper(substr(md5(uniqid()), 0, 8));
            $db->prepare("UPDATE organizations SET status='active', discount_code=? WHERE id=?")->execute([$code, $targetId]);
            $msg = "Organization approved. Discount code: <strong>$code</strong>"; $msgType = 'success'; $tab = 'organizations'; break;

        case 'reject_org':
            $db->prepare("UPDATE organizations SET status='suspended' WHERE id=?")->execute([$targetId]);
            $msg = 'Organization rejected.'; $msgType = 'danger'; $tab = 'organizations'; break;

        case 'assign_helpdesk':
            $convId = (int)($_POST['conv_id'] ?? 0);
            $db->prepare("UPDATE helpdesk_conversations SET agent_id=?, status='assigned', updated_at=datetime('now') WHERE id=?")->execute([$targetId, $convId]);
            $notif->create($targetId, 'HelpDesk Ticket Assigned', 'A support ticket has been assigned to you.', 'info', 'helpdesk.php');
            $msg = 'Ticket assigned.'; $msgType = 'success'; $tab = 'helpdesk'; break;

        // Student verification (new flow: users.student_status)
        case 'add_student_domain':
            $domain = strtolower(trim($_POST['domain'] ?? ''));
            $label  = trim($_POST['label'] ?? $domain);
            if ($domain) {
                try {
                    $db->prepare("INSERT INTO student_domains (domain, label) VALUES (?,?)")->execute([$domain, $label]);
                    $msg = "Domain '$domain' added."; $msgType = 'success';
                } catch (PDOException $e) {
                    $msg = "Domain already exists."; $msgType = 'danger';
                }
            }
            $tab = 'student_domains'; break;

        case 'remove_student_domain':
            $db->prepare("DELETE FROM student_domains WHERE id=?")->execute([(int)($_POST['target_id'] ?? 0)]);
            // Auto-update all users whose email now matches no domain
            $db->exec("UPDATE users SET is_student=0, is_student_verified=0 WHERE is_student=1 AND NOT EXISTS (
                SELECT 1 FROM student_domains WHERE LOWER(SUBSTR(users.email, INSTR(users.email,'@')+1)) = student_domains.domain
            )");
            $msg = 'Domain removed.'; $msgType = 'success'; $tab = 'student_domains'; break;

        case 'approve_student':
            $db->prepare("UPDATE users SET student_status='approved', is_student=1, student_verified_at=datetime('now') WHERE id=?")->execute([$targetId]);
            $notif->create($targetId, 'Student Discount Activated ✅', 'Your student verification was approved. You now receive a 50% discount on all rides!', 'success');
            $msg = 'Student approved.'; $msgType = 'success'; $tab = 'student_queue'; break;

        case 'reject_student':
            $note = trim($_POST['admin_note'] ?? 'Invalid institutional email.');
            $db->prepare("UPDATE users SET student_status='rejected', is_student=0 WHERE id=?")->execute([$targetId]);
            $notif->create($targetId, 'Student Verification Rejected', "Your student verification was rejected. Reason: $note. You may resubmit with a valid .edu.tn or .rnu.tn email.", 'danger');
            $msg = 'Student rejected.'; $msgType = 'danger'; $tab = 'student_queue'; break;

        // Vehicle verification
        case 'approve_vehicle':
            $db->prepare("UPDATE vehicles SET verified=1, verified_at=datetime('now') WHERE id=?")->execute([$targetId]);
            $vRow = $db->prepare("SELECT user_id FROM vehicles WHERE id=?"); $vRow->execute([$targetId]);
            if ($vOwner = $vRow->fetchColumn()) {
                $notif->create($vOwner, 'Vehicle Verified ✅', 'Your vehicle has been verified. You can now offer rides!', 'success');
            }
            $msg = 'Vehicle verified.'; $msgType = 'success'; $tab = 'vehicle_queue'; break;

        case 'reject_vehicle':
            $note = trim($_POST['admin_note'] ?? 'Documents unclear.');
            $db->prepare("UPDATE vehicles SET verified=0 WHERE id=?")->execute([$targetId]);
            $vRow = $db->prepare("SELECT user_id FROM vehicles WHERE id=?"); $vRow->execute([$targetId]);
            if ($vOwner = $vRow->fetchColumn()) {
                $notif->create($vOwner, 'Vehicle Verification Failed', "Your vehicle could not be verified. Reason: $note. Please upload a clearer photo of your carte grise.", 'danger');
            }
            $msg = 'Vehicle rejected.'; $msgType = 'danger'; $tab = 'vehicle_queue'; break;
    }

    header("Location: admin.php?tab=$tab&msg=" . urlencode($msg) . "&mt=$msgType"); exit();
}

if (isset($_GET['msg'])) { $msg = $_GET['msg']; $msgType = $_GET['mt'] ?? 'info'; }

// ── Data ──────────────────────────────────────────────────────────────────────
$stats = [
    'users'           => $db->query("SELECT COUNT(*) FROM users WHERE is_admin=0")->fetchColumn(),
    'drivers'         => $db->query("SELECT COUNT(*) FROM users WHERE is_driver=1 AND is_admin=0")->fetchColumn(),
    'rides'           => $db->query("SELECT COUNT(*) FROM rides")->fetchColumn(),
    'bookings'        => $db->query("SELECT COUNT(*) FROM bookings")->fetchColumn(),
    'complaints'      => $db->query("SELECT COUNT(*) FROM complaints WHERE status='open'")->fetchColumn(),
    'pending_ver'     => $db->query("SELECT COUNT(*) FROM student_verifications WHERE status='pending'")->fetchColumn(),
    'pending_student' => $db->query("SELECT COUNT(*) FROM student_verifications WHERE status='pending'")->fetchColumn(),
    'student_domains' => $db->query("SELECT COUNT(*) FROM student_domains")->fetchColumn(),
    'pending_vehicle' => $db->query("SELECT COUNT(*) FROM vehicles WHERE verified=0 AND id_card_photo IS NOT NULL AND id_card_photo != ''")->fetchColumn(),
    'orgs'            => $db->query("SELECT COUNT(*) FROM organizations WHERE status='pending'")->fetchColumn(),
    'revenue'         => $db->query("SELECT COALESCE(SUM(paid_amount),0) FROM bookings WHERE status='completed'")->fetchColumn(),
];

$vtypes         = $db->query("SELECT type, COUNT(*) cnt FROM vehicles GROUP BY type ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
$rstats         = $db->query("SELECT status, COUNT(*) cnt FROM rides GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
$users          = $db->query("SELECT u.*, dp.avg_rating, dp.total_trips FROM users u LEFT JOIN driver_profiles dp ON dp.user_id=u.id WHERE u.is_admin=0 ORDER BY u.created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$verifications  = $db->query("SELECT sv.*, u.username, u.email, u.governorate FROM student_verifications sv JOIN users u ON u.id=sv.user_id WHERE sv.status='pending' ORDER BY sv.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
$pendingStudents = $db->query("SELECT id, username, email, governorate, created_at FROM users WHERE is_student=0 AND is_student_verified=0 AND email LIKE '%@%' ORDER BY created_at ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$studentDomains  = $db->query("SELECT * FROM student_domains ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);
$pendingVehicles = $db->query("SELECT v.*, u.username AS owner_name, u.email AS owner_email FROM vehicles v JOIN users u ON u.id=v.user_id WHERE v.verified=0 AND v.id_card_photo IS NOT NULL AND v.id_card_photo != '' ORDER BY v.created_at ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$openComplaints = $comp->getAllComplaints('open');
$orgs           = $db->query("SELECT * FROM organizations ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$hdConvs        = $db->query("SELECT hc.*, u.username AS user_name, a.username AS agent_name, (SELECT COUNT(*) FROM helpdesk_messages hm WHERE hm.conv_id=hc.id AND hm.is_read=0 AND hm.sender_type='user') AS unread FROM helpdesk_conversations hc JOIN users u ON u.id=hc.user_id LEFT JOIN users a ON a.id=hc.agent_id ORDER BY hc.updated_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$agents         = $db->query("SELECT id, username FROM users WHERE is_helpdesk_agent=1 ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$recentActivity = $db->query("SELECT bk.*, r.from_location, r.to_location, p.username AS passenger, dr.username AS driver FROM bookings bk JOIN rides r ON r.id=bk.ride_id JOIN users p ON p.id=bk.passenger_id JOIN users dr ON dr.id=r.driver_id ORDER BY bk.created_at DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Admin Console';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ForsaDrive — Admin Console</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="../Css/app.css" rel="stylesheet">
<style>
/* ── Admin Layout ── */
body { background:#f0f4f8; margin:0; }
.admin-layout { display:flex; min-height:100vh; }

/* Sidebar */
.admin-nav {
    width:240px; background:#0a1628; flex-shrink:0; display:flex;
    flex-direction:column; padding:0; position:fixed; top:0; left:0;
    height:100vh; overflow-y:auto; z-index:1050;
    transition:transform .25s ease;
}
.admin-nav .brand {
    padding:1.1rem 1.25rem .85rem;
    border-bottom:1px solid rgba(255,255,255,.08);
    background: linear-gradient(135deg,#0a1628,#1a2f50);
}
.admin-nav .brand h5 { color:#fff; font-weight:800; margin:0; font-size:1.1rem; letter-spacing:.03em; }
.admin-nav .brand h5 span { color:#f59e0b; }
.admin-nav .brand .badge-admin {
    display:inline-block; background:#ef4444; color:#fff;
    font-size:.55rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase;
    padding:.15rem .5rem; border-radius:20px; margin-top:.3rem;
}
.admin-nav .nav-section {
    padding:.6rem 1.25rem .15rem;
    font-size:.6rem; text-transform:uppercase; letter-spacing:.12em;
    color:rgba(255,255,255,.3); margin-top:.25rem;
}
.admin-nav a {
    display:flex; align-items:center; gap:.65rem;
    color:rgba(255,255,255,.65); padding:.65rem 1.25rem;
    font-size:.82rem; text-decoration:none;
    border-left:3px solid transparent; transition:all .15s;
}
.admin-nav a:hover { background:rgba(255,255,255,.07); color:#fff; }
.admin-nav a.active { background:rgba(245,158,11,.12); color:#f59e0b; border-left-color:#f59e0b; }
.admin-nav a i { width:17px; text-align:center; flex-shrink:0; }
.admin-nav .nav-badge {
    background:#ef4444; color:#fff; border-radius:20px;
    padding:.1rem .45rem; font-size:.6rem; font-weight:700; margin-left:auto;
}
.admin-nav .nav-footer { margin-top:auto; border-top:1px solid rgba(255,255,255,.08); }

/* Mobile topbar */
.admin-topbar {
    display:none; position:fixed; top:0; left:0; right:0; height:56px;
    background:#0a1628; z-index:1049; align-items:center;
    padding:0 1rem; gap:.75rem;
    border-bottom:2px solid #ef4444;
}
.admin-topbar .brand-m { color:#fff; font-weight:800; font-size:1rem; flex:1; }
.admin-topbar .brand-m span { color:#f59e0b; }
.admin-topbar .badge-admin-m {
    background:#ef4444; color:#fff; font-size:.6rem; font-weight:700;
    text-transform:uppercase; padding:.15rem .5rem; border-radius:20px; letter-spacing:.08em;
}

/* Overlay */
.admin-overlay {
    display:none; position:fixed; inset:0; background:rgba(0,0,0,.5);
    z-index:1048;
}
.admin-overlay.show { display:block; }

/* Content area */
.admin-content {
    flex:1; padding:1.75rem; overflow-x:hidden; min-width:0;
    margin-left:240px;
    /* Red accent stripe at top */
    border-top:3px solid #ef4444;
}

/* Admin identity banner */
.admin-identity-bar {
    background:linear-gradient(90deg,#0a1628,#1e3a5f);
    color:#fff; padding:.5rem 1rem;
    display:flex; align-items:center; gap:.75rem;
    border-radius:8px; margin-bottom:1.5rem;
    border-left:4px solid #ef4444;
}
.admin-identity-bar .badge-role {
    background:#ef4444; color:#fff; font-size:.65rem; font-weight:700;
    text-transform:uppercase; padding:.2rem .55rem; border-radius:4px; letter-spacing:.08em;
    flex-shrink:0;
}
.admin-identity-bar .info { font-size:.82rem; color:rgba(255,255,255,.75); }
.admin-identity-bar .info strong { color:#f59e0b; }

@media(max-width:767px){
    .admin-topbar { display:flex; }
    .admin-nav { transform:translateX(-100%); top:56px; height:calc(100vh - 56px); }
    .admin-nav.open { transform:translateX(0); }
    .admin-content { margin-left:0; padding:.85rem; padding-top:calc(56px + .85rem); border-top:none; }
}
</style>
</head>
<body>
<div class="admin-layout">

<!-- Mobile topbar -->
<div class="admin-topbar" id="adminTopbar">
  <button onclick="toggleAdminNav()" style="background:none;border:none;color:rgba(255,255,255,.8);padding:0;font-size:1.1rem;cursor:pointer;">
    <i class="fas fa-bars"></i>
  </button>
  <div class="brand-m">Forsa<span>Drive</span></div>
  <span class="badge-admin-m">Admin</span>
</div>
<div class="admin-overlay" id="adminOverlay" onclick="toggleAdminNav()"></div>

<!-- ── Navigation ────────────────────────────────────────────────────────── -->
<aside class="admin-nav" id="adminNav">
  <div class="brand">
    <h5>Forsa<span>Drive</span></h5>
    <div class="badge-admin"><i class="fas fa-shield-alt me-1"></i>Admin Console</div>
  </div>
  <div class="nav-section">Dashboard</div>
  <a href="?tab=overview"      class="<?= $tab==='overview'?'active':'' ?>"><i class="fas fa-tachometer-alt"></i> Overview</a>
  <a href="?tab=activity"      class="<?= $tab==='activity'?'active':'' ?>"><i class="fas fa-stream"></i> Activity</a>
  <div class="nav-section">People</div>
  <a href="?tab=users"         class="<?= $tab==='users'?'active':'' ?>"><i class="fas fa-users"></i> Users
    <span class="nav-badge"><?= $stats['users'] ?></span></a>
  <a href="?tab=verifications" class="<?= $tab==='verifications'?'active':'' ?>"><i class="fas fa-id-card"></i> Verifications
    <?php if ($stats['pending_ver']): ?><span class="nav-badge"><?= $stats['pending_ver'] ?></span><?php endif; ?></a>
  <a href="?tab=student_queue" class="<?= $tab==='student_queue'?'active':'' ?>"><i class="fas fa-user-graduate"></i> Students
    <?php if ($stats['pending_student']): ?><span class="nav-badge"><?= $stats['pending_student'] ?></span><?php endif; ?></a>
  <a href="?tab=student_domains" class="<?= $tab==='student_domains'?'active':'' ?>"><i class="fas fa-at"></i> Student Domains
    <span class="nav-badge"><?= $stats['student_domains'] ?></span></a>
  <a href="?tab=vehicle_queue" class="<?= $tab==='vehicle_queue'?'active':'' ?>"><i class="fas fa-car"></i> Vehicles
    <?php if ($stats['pending_vehicle']): ?><span class="nav-badge"><?= $stats['pending_vehicle'] ?></span><?php endif; ?></a>
  <div class="nav-section">Operations</div>
  <a href="?tab=complaints"    class="<?= $tab==='complaints'?'active':'' ?>"><i class="fas fa-flag"></i> Complaints
    <?php if ($stats['complaints']): ?><span class="nav-badge"><?= $stats['complaints'] ?></span><?php endif; ?></a>
  <a href="?tab=organizations" class="<?= $tab==='organizations'?'active':'' ?>"><i class="fas fa-building"></i> Organizations
    <?php if ($stats['orgs']): ?><span class="nav-badge"><?= $stats['orgs'] ?></span><?php endif; ?></a>
  <a href="?tab=helpdesk"      class="<?= $tab==='helpdesk'?'active':'' ?>"><i class="fas fa-headset"></i> HelpDesk</a>
  <div class="nav-footer">
    <a href="interface.php"><i class="fas fa-home"></i> Main Site</a>
    <a href="../index.php" style="color:rgba(239,68,68,.7)"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </div>
</aside>

<!-- ── Main ──────────────────────────────────────────────────────────────── -->
<main class="admin-content">
  <!-- Admin identity bar -->
  <div class="admin-identity-bar">
    <span class="badge-role"><i class="fas fa-shield-alt me-1"></i>Admin</span>
    <div class="info">Logged in as <strong><?= htmlspecialchars($_SESSION['user_data']['username']) ?></strong> &nbsp;·&nbsp; Full administrative access</div>
    <a href="../index.php" class="ms-auto text-white-50 small text-decoration-none"><i class="fas fa-external-link-alt me-1"></i>Public site</a>
  </div>
  <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <h1 class="fw-bold mb-0" style="color:#0a2540;font-size:1.35rem">
      <?= match($tab) {
        'users'         => '<i class="fas fa-users me-2 text-primary"></i>User Management',
        'verifications' => '<i class="fas fa-id-card me-2 text-primary"></i>Student Verifications',
        'complaints'    => '<i class="fas fa-flag me-2 text-primary"></i>Complaints',
        'organizations' => '<i class="fas fa-building me-2 text-primary"></i>Organizations',
        'helpdesk'      => '<i class="fas fa-headset me-2 text-primary"></i>HelpDesk Console',
        'activity'      => '<i class="fas fa-stream me-2 text-primary"></i>Activity Feed',
        'student_queue'    => '<i class="fas fa-user-graduate me-2 text-warning"></i>Student Verification Queue',
        'student_domains'  => '<i class="fas fa-at me-2 text-success"></i>Student Email Domains',
        'vehicle_queue' => '<i class="fas fa-car me-2 text-primary"></i>Vehicle Verification Queue',
        default         => '<i class="fas fa-tachometer-alt me-2 text-primary"></i>Overview',
      } ?>
    </h1>
    <span class="text-muted small d-none d-sm-inline"><i class="fas fa-clock me-1"></i><?= date('D, d M Y') ?></span>
  </div>

  <?php if ($msg): ?>
  <div class="alert-fd-<?= $msgType === 'success' ? 'success' : 'danger' ?> mb-3">
    <i class="fas fa-<?= $msgType==='success'?'check':'exclamation' ?>-circle me-2"></i><?= $msg ?>
  </div>
  <?php endif; ?>

  <?php /* ═══ OVERVIEW ════════════════════════════════════════════════════ */ if ($tab === 'overview'): ?>
  <div class="row g-3 mb-4">
    <?php foreach ([
      ['blue',  'users',            $stats['users'],                         'Total Users'],
      ['green', 'car',              $stats['rides'],                         'Rides Posted'],
      ['amber', 'ticket-alt',       $stats['bookings'],                      'All Bookings'],
      ['green', 'money-bill-wave',  number_format($stats['revenue'],2).' TND','Revenue'],
      ['blue',  'user-tie',         $stats['drivers'],                       'Drivers'],
      ['red',   'flag',             $stats['complaints'],                    'Open Complaints'],
      ['amber', 'user-graduate',    $stats['pending_student'],               'Pending Students'],
      ['red',   'car-side',         $stats['pending_vehicle'],               'Pending Vehicles'],
      ['purple','building',         count($orgs),                            'Organizations'],
    ] as [$c,$i,$v,$l]): ?>
    <div class="col-6 col-sm-4 col-lg-3">
      <div class="stat-card">
        <div class="icon <?= $c ?>"><i class="fas fa-<?= $i ?>"></i></div>
        <div><div class="val"><?= $v ?></div><div class="lbl"><?= $l ?></div></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3">
    <div class="col-12 col-md-6">
      <div class="fd-card">
        <div class="card-title"><i class="fas fa-car text-primary"></i> Fleet by Vehicle Type</div>
        <?php if (empty($vtypes)): ?>
          <p class="text-muted small">No vehicles yet</p>
        <?php else:
          $total_v = array_sum(array_column($vtypes,'cnt'));
          foreach ($vtypes as $vt):
            $pct = round($vt['cnt']/$total_v*100); ?>
          <div class="d-flex align-items-center gap-2 mb-2">
            <div style="width:90px;font-size:.82rem;color:#374151"><?= htmlspecialchars(ucfirst($vt['type'])) ?></div>
            <div class="flex-grow-1 rounded bg-light" style="height:8px">
              <div class="rounded bg-primary" style="width:<?= $pct ?>%;height:8px"></div>
            </div>
            <span class="text-muted small" style="width:24px"><?= $vt['cnt'] ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <div class="col-12 col-md-6">
      <div class="fd-card">
        <div class="card-title"><i class="fas fa-chart-pie text-primary"></i> Ride Status</div>
        <?php foreach ($rstats as $rs): ?>
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="status-badge <?= $rs['status'] ?>"><?= ucfirst($rs['status']) ?></span>
          <strong><?= $rs['cnt'] ?></strong>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php /* ═══ ACTIVITY ════════════════════════════════════════════════════ */ elseif ($tab === 'activity'): ?>
  <div class="fd-card">
    <div class="card-title"><i class="fas fa-stream text-primary"></i> Recent Bookings</div>
    <div class="table-responsive">
      <table class="table table-hover align-middle small mb-0">
        <thead class="table-light">
          <tr><th>#</th><th>Passenger</th><th>Driver</th><th>Route</th><th>Amount</th><th>Status</th><th>Date</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recentActivity as $b): ?>
          <tr>
            <td class="text-muted">#<?= $b['id'] ?></td>
            <td><?= htmlspecialchars($b['passenger']) ?></td>
            <td><?= htmlspecialchars($b['driver']) ?></td>
            <td><?= htmlspecialchars($b['from_location']) ?> → <?= htmlspecialchars($b['to_location']) ?></td>
            <td class="fw-bold"><?= number_format($b['paid_amount'],2) ?> TND</td>
            <td><span class="status-badge <?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span></td>
            <td class="text-muted"><?= date('d M, H:i', strtotime($b['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php /* ═══ USERS ═══════════════════════════════════════════════════════ */ elseif ($tab === 'users'): ?>
  <div class="fd-card">
    <div class="mb-3">
      <input class="form-control" id="userSearch" placeholder="Search name or email…" style="max-width:320px">
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle small" id="usersTable">
        <thead class="table-light">
          <tr><th>User</th><th>Email</th><th>Roles</th><th>Trips</th><th>Rating</th><th>Joined</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr class="<?= $u['suspended'] ? 'table-danger' : '' ?>">
            <td>
              <div class="d-flex align-items-center gap-2">
                <img src="<?= htmlspecialchars($u['picture'] ?? '../Src/default.jpg') ?>"
                     style="width:32px;height:32px;border-radius:50%;object-fit:cover" alt="">
                <div>
                  <div class="fw-semibold"><?= htmlspecialchars($u['username']) ?></div>
                  <div class="text-muted" style="font-size:.7rem"><?= htmlspecialchars($u['governorate'] ?? '') ?></div>
                </div>
              </div>
            </td>
            <td class="text-muted small"><?= htmlspecialchars($u['email']) ?></td>
            <td>
              <?php if ($u['is_driver']): ?><span class="badge bg-primary me-1">Driver</span><?php endif; ?>
              <?php if ($u['is_student']): ?><span class="badge bg-info text-dark me-1">Student</span><?php endif; ?>
              <?php if ($u['is_helpdesk_agent']): ?><span class="badge bg-success me-1">Agent</span><?php endif; ?>
              <?php if ($u['suspended']): ?><span class="badge bg-danger">Banned</span><?php endif; ?>
            </td>
            <td><?= $u['total_trips'] ?? 0 ?></td>
            <td><span class="stars">★</span> <?= number_format($u['avg_rating'] ?? ($u['score'] ?? 5), 1) ?></td>
            <td class="text-muted small"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
            <td class="text-end">
              <div class="d-flex justify-content-end gap-1 flex-wrap">
                <?php if (!$u['suspended']): ?>
                <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#banModal<?= $u['id'] ?>">
                  <i class="fas fa-ban"></i>
                </button>
                <?php else: ?>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="unban_user">
                  <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                  <button class="btn btn-success btn-sm"><i class="fas fa-check"></i></button>
                </form>
                <?php endif; ?>

                <?php if (!$u['is_driver']): ?>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="make_driver">
                  <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                  <button class="btn btn-outline-primary btn-sm" title="Make Driver"><i class="fas fa-car"></i></button>
                </form>
                <?php endif; ?>

                <?php if (!$u['is_helpdesk_agent']): ?>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="make_agent">
                  <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                  <button class="btn btn-outline-success btn-sm" title="Make HelpDesk Agent"><i class="fas fa-headset"></i></button>
                </form>
                <?php else: ?>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="remove_agent">
                  <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                  <button class="btn btn-outline-secondary btn-sm" title="Remove Agent Role"><i class="fas fa-headset"></i></button>
                </form>
                <?php endif; ?>
              </div>

              <!-- Ban modal -->
              <div class="modal fade" id="banModal<?= $u['id'] ?>" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                  <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                      <h5 class="modal-title">Suspend <?= htmlspecialchars($u['username']) ?></h5>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                      <div class="modal-body">
                        <input type="hidden" name="action" value="ban_user">
                        <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                        <label class="fd-form-label">Reason for suspension *</label>
                        <textarea name="ban_reason" class="form-control" rows="3" required placeholder="Explain the reason…"></textarea>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Confirm Suspension</button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php /* ═══ VERIFICATIONS ═══════════════════════════════════════════════ */ elseif ($tab === 'verifications'): ?>
  <?php if (empty($verifications)): ?>
    <div class="empty-state"><i class="fas fa-id-card"></i><p>No pending verifications</p></div>
  <?php else: ?>
    <?php foreach ($verifications as $v): ?>
    <div class="fd-card mb-3">
      <div class="d-flex justify-content-between flex-wrap gap-3">
        <div>
          <div class="fw-bold fs-6"><?= htmlspecialchars($v['username']) ?></div>
          <div class="text-muted small"><?= htmlspecialchars($v['email']) ?></div>
          <?php if ($v['governorate']): ?><div class="text-muted small"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($v['governorate']) ?></div><?php endif; ?>
          <div class="text-muted small mt-1"><i class="fas fa-clock me-1"></i>Submitted <?= date('d M Y', strtotime($v['created_at'])) ?></div>
        </div>
        <div class="d-flex gap-2 align-items-start flex-wrap">
          <form method="POST">
            <input type="hidden" name="action" value="approve_verification">
            <input type="hidden" name="target_id" value="<?= $v['id'] ?>">
            <button class="btn-fd-success btn btn-sm"><i class="fas fa-check me-1"></i>Approve</button>
          </form>
          <form method="POST" class="d-flex gap-1 align-items-start">
            <input type="hidden" name="action" value="reject_verification">
            <input type="hidden" name="target_id" value="<?= $v['id'] ?>">
            <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="Reason…" style="width:160px">
            <button class="btn-fd-danger btn btn-sm"><i class="fas fa-times me-1"></i>Reject</button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php /* ═══ STUDENT QUEUE ═════════════════════════════════════════════ */ elseif ($tab === 'student_queue'): ?>
  <div class="fd-card mb-3 p-3" style="background:#fffbeb;border-left:4px solid #f59e0b">
    <div class="fw-semibold mb-1"><i class="fas fa-info-circle me-2 text-warning"></i>Student Verification Queue</div>
    <div class="small text-muted">These users submitted an institutional email for the 50% student discount. Verify that the email domain is a real Tunisian university (e.g. <code>.edu.tn</code>, <code>.rnu.tn</code>, <code>.utm.tn</code>, etc.).</div>
  </div>
  <?php if (empty($pendingStudents)): ?>
    <div class="empty-state"><i class="fas fa-user-graduate"></i><p>No pending student verifications</p></div>
  <?php else: ?>
    <?php foreach ($pendingStudents as $s): ?>
    <div class="fd-card mb-3">
      <div class="d-flex justify-content-between flex-wrap gap-3">
        <div>
          <div class="fw-bold"><?= htmlspecialchars($s['username']) ?></div>
          <div class="text-muted small"><?= htmlspecialchars($s['email']) ?></div>
          <?php if ($s['governorate']): ?>
          <div class="text-muted small"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($s['governorate']) ?></div>
          <?php endif; ?>
          <div class="mt-2">
            <span class="badge bg-primary" style="font-size:.8rem"><i class="fas fa-graduation-cap me-1"></i>Submitted email:</span>
            <code class="ms-2"><?= htmlspecialchars($s['student_email'] ?? '—') ?></code>
          </div>
        </div>
        <div class="d-flex gap-2 align-items-start flex-wrap">
          <form method="POST">
            <input type="hidden" name="action" value="approve_student">
            <input type="hidden" name="target_id" value="<?= $s['id'] ?>">
            <button class="btn-fd-success btn btn-sm"><i class="fas fa-check me-1"></i>Approve</button>
          </form>
          <form method="POST" class="d-flex gap-1 align-items-start">
            <input type="hidden" name="action" value="reject_student">
            <input type="hidden" name="target_id" value="<?= $s['id'] ?>">
            <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="Reason…" style="width:180px">
            <button class="btn-fd-danger btn btn-sm"><i class="fas fa-times me-1"></i>Reject</button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php /* ═══ STUDENT DOMAINS ════════════════════════════════════════════ */ elseif ($tab === 'student_domains'): ?>
  <div class="fd-card mb-3 p-3" style="background:#f0fdf4;border-left:4px solid #22c55e">
    <div class="fw-semibold mb-1"><i class="fas fa-info-circle me-2 text-success"></i>Student Email Domains</div>
    <div class="small text-muted">Users who register with an email from these domains are automatically granted the 50% student discount. Adding or removing a domain here takes effect immediately for new sign-ups. Removing a domain also revokes student status for existing users whose email matches only that domain.</div>
  </div>

  <!-- Add domain form -->
  <div class="fd-card mb-4 p-4">
    <h6 class="fw-bold mb-3"><i class="fas fa-plus-circle me-2 text-success"></i>Add New Domain</h6>
    <form method="POST" class="row g-2 align-items-end">
      <input type="hidden" name="action" value="add_student_domain">
      <div class="col-sm-5">
        <label class="form-label small fw-semibold">Email Domain <span class="text-danger">*</span></label>
        <input type="text" name="domain" class="form-control form-control-sm" placeholder="esprit.tn" required>
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold">Institution Label</label>
        <input type="text" name="label" class="form-control form-control-sm" placeholder="ESPRIT">
      </div>
      <div class="col-sm-3">
        <button type="submit" class="btn btn-success btn-sm w-100"><i class="fas fa-plus me-1"></i>Add Domain</button>
      </div>
    </form>
  </div>

  <!-- Domain list -->
  <?php if (empty($studentDomains)): ?>
    <div class="empty-state"><i class="fas fa-at"></i><p>No student domains configured</p></div>
  <?php else: ?>
  <div class="fd-card p-0 overflow-hidden">
    <table class="table table-hover mb-0">
      <thead style="background:#f8fafc">
        <tr>
          <th class="ps-4">Domain</th>
          <th>Institution</th>
          <th>Added</th>
          <th class="text-end pe-4">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($studentDomains as $sd): ?>
        <tr>
          <td class="ps-4"><code>@<?= htmlspecialchars($sd['domain']) ?></code></td>
          <td><?= htmlspecialchars($sd['label']) ?></td>
          <td class="text-muted small"><?= date('d M Y', strtotime($sd['created_at'])) ?></td>
          <td class="text-end pe-4">
            <form method="POST" onsubmit="return confirm('Remove this domain? Users with this domain will lose student status.')">
              <input type="hidden" name="action" value="remove_student_domain">
              <input type="hidden" name="target_id" value="<?= $sd['id'] ?>">
              <button class="btn btn-outline-danger btn-sm"><i class="fas fa-trash me-1"></i>Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php /* ═══ VEHICLE QUEUE ══════════════════════════════════════════════ */ elseif ($tab === 'vehicle_queue'): ?>
  <div class="fd-card mb-3 p-3" style="background:#eff6ff;border-left:4px solid #3b82f6">
    <div class="fw-semibold mb-1"><i class="fas fa-info-circle me-2 text-primary"></i>Vehicle Verification Queue</div>
    <div class="small text-muted">These vehicles have a carte grise photo awaiting admin review. Check that the plate number matches the photo and the document is valid.</div>
  </div>
  <?php if (empty($pendingVehicles)): ?>
    <div class="empty-state"><i class="fas fa-car"></i><p>No vehicles awaiting verification</p></div>
  <?php else: ?>
    <?php foreach ($pendingVehicles as $v): ?>
    <div class="fd-card mb-3">
      <div class="d-flex justify-content-between flex-wrap gap-3">
        <div class="flex-grow-1">
          <div class="fw-bold"><?= htmlspecialchars($v['owner_name']) ?>
            <span class="text-muted fw-normal small ms-2"><?= htmlspecialchars($v['owner_email']) ?></span>
          </div>
          <div class="mt-2 d-flex flex-wrap gap-2 align-items-center">
            <span class="badge bg-secondary"><?= htmlspecialchars(ucfirst($v['type'] ?? 'car')) ?></span>
            <?php if ($v['make'] ?? ''): ?><span class="text-muted small"><?= htmlspecialchars($v['make']) ?> <?= htmlspecialchars($v['model'] ?? '') ?> <?= htmlspecialchars($v['year'] ?? '') ?></span><?php endif; ?>
            <?php if ($v['plate_number'] ?? ''): ?>
            <span class="badge bg-dark"><i class="fas fa-car-side me-1"></i><?= htmlspecialchars($v['plate_number']) ?></span>
            <?php endif; ?>
            <span class="badge bg-info text-dark"><?= $v['seats'] ?> seats</span>
          </div>
          <?php if ($v['id_card_photo'] ?? ''): ?>
          <div class="mt-3">
            <div class="fw-semibold small mb-1"><i class="fas fa-file-image me-1 text-primary"></i>Carte Grise Photo:</div>
            <a href="../<?= htmlspecialchars($v['id_card_photo']) ?>" target="_blank">
              <img src="../<?= htmlspecialchars($v['id_card_photo']) ?>"
                   style="max-height:160px;max-width:340px;border-radius:8px;border:2px solid #e2e8f0;object-fit:contain"
                   onerror="this.outerHTML='<span class=\'text-danger small\'>Photo not found</span>'"
                   alt="Carte Grise">
            </a>
          </div>
          <?php endif; ?>
          <?php if ($v['photo'] ?? ''): ?>
          <div class="mt-2">
            <div class="fw-semibold small mb-1"><i class="fas fa-image me-1 text-success"></i>Vehicle Photo:</div>
            <a href="../<?= htmlspecialchars($v['photo']) ?>" target="_blank">
              <img src="../<?= htmlspecialchars($v['photo']) ?>"
                   style="max-height:120px;max-width:240px;border-radius:8px;border:2px solid #e2e8f0;object-fit:cover"
                   onerror="this.style.display='none'"
                   alt="Vehicle">
            </a>
          </div>
          <?php endif; ?>
        </div>
        <div class="d-flex flex-column gap-2 align-items-end">
          <form method="POST">
            <input type="hidden" name="action" value="approve_vehicle">
            <input type="hidden" name="target_id" value="<?= $v['id'] ?>">
            <button class="btn-fd-success btn btn-sm"><i class="fas fa-check me-1"></i>Verify Vehicle</button>
          </form>
          <form method="POST" class="d-flex gap-1 align-items-start">
            <input type="hidden" name="action" value="reject_vehicle">
            <input type="hidden" name="target_id" value="<?= $v['id'] ?>">
            <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="Reason…" style="width:180px">
            <button class="btn-fd-danger btn btn-sm"><i class="fas fa-times me-1"></i>Reject</button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php /* ═══ COMPLAINTS ══════════════════════════════════════════════════ */ elseif ($tab === 'complaints'): ?>
  <?php if (empty($openComplaints)): ?>
    <div class="empty-state"><i class="fas fa-flag"></i><p>No open complaints</p></div>
  <?php else: ?>
    <?php foreach ($openComplaints as $c): ?>
    <div class="fd-card mb-3">
      <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
        <div>
          <span class="status-badge <?= $c['status'] ?>"><?= ucfirst(str_replace('_',' ',$c['status'])) ?></span>
          <span class="badge bg-secondary ms-1"><?= htmlspecialchars(ucfirst($c['type'])) ?></span>
        </div>
        <span class="text-muted small"><?= date('d M Y', strtotime($c['created_at'])) ?></span>
      </div>
      <div class="small mb-1">
        <strong>From:</strong> <?= htmlspecialchars($c['from_name']) ?>
        <?php if ($c['against_name']): ?>&nbsp;→ <strong>Against:</strong> <?= htmlspecialchars($c['against_name']) ?><?php endif; ?>
      </div>
      <?php if ($c['from_location']): ?>
      <div class="small text-muted mb-1"><i class="fas fa-route me-1"></i><?= htmlspecialchars($c['from_location']) ?> → <?= htmlspecialchars($c['to_location']) ?></div>
      <?php endif; ?>
      <p class="small mb-3 text-dark"><?= htmlspecialchars($c['description']) ?></p>
      <form method="POST" class="d-flex gap-2 flex-wrap align-items-start">
        <input type="hidden" name="target_id" value="<?= $c['id'] ?>">
        <textarea name="admin_note" class="form-control form-control-sm" rows="1" placeholder="Admin note (optional)" style="max-width:280px"></textarea>
        <button name="action" value="resolve_complaint" class="btn-fd-success btn btn-sm"><i class="fas fa-check me-1"></i>Resolve</button>
        <button name="action" value="dismiss_complaint" class="btn btn-outline-secondary btn-sm">Dismiss</button>
      </form>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php /* ═══ ORGANIZATIONS ════════════════════════════════════════════════ */ elseif ($tab === 'organizations'): ?>
  <?php if (empty($orgs)): ?>
    <div class="empty-state"><i class="fas fa-building"></i><p>No organizations yet</p>
      <p class="small text-muted">Organizations register on the homepage.</p></div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle small">
        <thead class="table-light">
          <tr><th>Organization</th><th>Contact</th><th>Domain</th><th>Discount</th><th>Status</th><th>Code</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($orgs as $o): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= htmlspecialchars($o['name']) ?></div>
              <div class="text-muted" style="font-size:.7rem"><?= htmlspecialchars(ucfirst($o['type'])) ?></div>
            </td>
            <td><?= htmlspecialchars($o['contact_name']) ?><br><span class="text-muted"><?= htmlspecialchars($o['contact_email']) ?></span></td>
            <td class="text-muted">@<?= htmlspecialchars($o['email_domain'] ?? '—') ?></td>
            <td class="fw-bold text-success"><?= $o['discount_percent'] ?>%</td>
            <td><span class="status-badge <?= match($o['status']){ 'active'=>'confirmed','suspended'=>'cancelled',default=>'pending' } ?>"><?= ucfirst($o['status']) ?></span></td>
            <td><code style="font-size:.78rem"><?= htmlspecialchars($o['discount_code'] ?? '—') ?></code></td>
            <td>
              <?php if ($o['status'] === 'pending'): ?>
              <div class="d-flex gap-1">
                <form method="POST">
                  <input type="hidden" name="action" value="approve_org">
                  <input type="hidden" name="target_id" value="<?= $o['id'] ?>">
                  <button class="btn-fd-success btn btn-sm">Approve</button>
                </form>
                <form method="POST">
                  <input type="hidden" name="action" value="reject_org">
                  <input type="hidden" name="target_id" value="<?= $o['id'] ?>">
                  <button class="btn-fd-danger btn btn-sm">Reject</button>
                </form>
              </div>
              <?php else: ?>
              <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php /* ═══ HELPDESK ════════════════════════════════════════════════════ */ elseif ($tab === 'helpdesk'): ?>
  <?php if (empty($hdConvs)): ?>
    <div class="empty-state"><i class="fas fa-headset"></i><p>No support tickets yet</p></div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle small">
        <thead class="table-light">
          <tr><th>#</th><th>User</th><th>Subject</th><th>Assigned Agent</th><th>Status</th><th>Unread</th><th>Reassign</th></tr>
        </thead>
        <tbody>
          <?php foreach ($hdConvs as $hc): ?>
          <tr>
            <td class="text-muted">#<?= $hc['id'] ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($hc['user_name']) ?></td>
            <td><?= htmlspecialchars($hc['subject'] ?? 'Support request') ?></td>
            <td><?= htmlspecialchars($hc['agent_name'] ?? '—') ?></td>
            <td><span class="status-badge <?= $hc['status'] === 'open' ? 'pending' : 'confirmed' ?>"><?= ucfirst($hc['status']) ?></span></td>
            <td><?= $hc['unread'] > 0 ? "<span class='badge bg-danger'>{$hc['unread']}</span>" : '—' ?></td>
            <td>
              <?php if (!empty($agents)): ?>
              <form method="POST" class="d-flex gap-1 align-items-center">
                <input type="hidden" name="action" value="assign_helpdesk">
                <input type="hidden" name="conv_id" value="<?= $hc['id'] ?>">
                <select name="target_id" class="form-select form-select-sm" style="min-width:130px">
                  <?php foreach ($agents as $ag): ?>
                  <option value="<?= $ag['id'] ?>" <?= $hc['agent_id']==$ag['id']?'selected':'' ?>>
                    <?= htmlspecialchars($ag['username']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-primary btn-sm">Go</button>
              </form>
              <?php else: ?>
              <a href="?tab=users" class="text-muted small">Assign agents first</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <?php endif; ?>

</main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const s = document.getElementById('userSearch');
if (s) s.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#usersTable tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

function toggleAdminNav() {
    const nav = document.getElementById('adminNav');
    const overlay = document.getElementById('adminOverlay');
    nav.classList.toggle('open');
    overlay.classList.toggle('show');
}
</script>
</body>
</html>
