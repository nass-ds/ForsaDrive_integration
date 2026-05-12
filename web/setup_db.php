<?php
/**
 * ForsaDrive — Complete Database Reset & Rebuild
 * ⚠️  THIS DROPS ALL EXISTING DATA — run only to start fresh.
 * Web:  http://localhost/ForsaDrive/setup_db.php?confirm=yes
 * CLI:  php setup_db.php --confirm
 */
$cliConfirm = (PHP_SAPI === 'cli') && in_array('--confirm', $argv ?? [], true);
$webConfirm = isset($_GET['confirm']) && $_GET['confirm'] === 'yes';
if (!$cliConfirm && !$webConfirm) { ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>ForsaDrive — DB Reset</title>
<style>
    body { font-family: 'Segoe UI', sans-serif; background:#f0f4f8; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
    .card { background:#fff; padding:2.5rem; border-radius:14px; box-shadow:0 8px 32px rgba(0,0,0,.15); max-width:480px; width:90%; text-align:center; }
    h2 { color:#0a1628; margin-bottom:.5rem; }
    .warn { background:#fef3c7; border:1px solid #f59e0b; border-radius:8px; padding:1rem; margin:1.25rem 0; color:#92400e; font-size:.9rem; }
    .btn-danger { background:#ef4444; color:#fff; border:none; padding:.75rem 2rem; border-radius:8px; font-weight:700; font-size:1rem; cursor:pointer; text-decoration:none; display:inline-block; margin-top:.5rem; }
    .btn-safe { background:#e5e7eb; color:#374151; border:none; padding:.75rem 2rem; border-radius:8px; font-weight:700; font-size:1rem; cursor:pointer; text-decoration:none; display:inline-block; margin-top:.5rem; margin-right:.5rem; }
</style></head><body>
<div class="card">
    <div style="font-size:3rem;margin-bottom:1rem;">⚠️</div>
    <h2>Database Reset</h2>
    <p style="color:#6b7280;">This will <strong>permanently delete all data</strong> — users, rides, bookings, messages, and everything else.</p>
    <div class="warn">
        <strong>Only proceed on a fresh install.</strong><br>
        All existing accounts will be lost. A new admin account will be created.
    </div>
    <a href="Pages/login.php" class="btn-safe">Cancel — Go Back</a>
    <a href="?confirm=yes" class="btn-danger">Yes, Reset Everything</a>
</div>
</body></html>
<?php exit(); }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/database.php';

$db  = new Database();
$pdo = $db->getConnection();
$ok  = []; $err = [];

function run(PDO $p, string $sql, string $label, array &$ok, array &$err): void {
    try { $p->exec($sql); $ok[] = $label; }
    catch (PDOException $e) { $err[] = "$label — " . $e->getMessage(); }
}

// ── 1. Enable foreign keys + WAL ─────────────────────────────────────────────
$pdo->exec("PRAGMA foreign_keys = OFF");
$pdo->exec("PRAGMA journal_mode = WAL");

// ── 2. Drop everything ───────────────────────────────────────────────────────
$drops = [
    'feed_comments','feed_likes','feed_posts',
    'helpdesk_messages','helpdesk_conversations',
    'org_members','organizations',
    'student_verifications','driver_profiles',
    'complaints','messages','conversations',
    'notifications','ratings','payments',
    'bookings','rides','vehicles','users',
];
foreach ($drops as $t)
    run($pdo, "DROP TABLE IF EXISTS $t", "Drop $t", $ok, $err);

$pdo->exec("PRAGMA foreign_keys = ON");

// ── 3. Core tables ───────────────────────────────────────────────────────────

run($pdo, "
CREATE TABLE users (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    username             TEXT NOT NULL,
    email                TEXT NOT NULL UNIQUE,
    password             TEXT NOT NULL,
    Region               TEXT DEFAULT 'TN',
    is_driver            INTEGER DEFAULT 0,
    is_student           INTEGER DEFAULT 0,
    is_student_verified  INTEGER DEFAULT 0,
    is_admin             INTEGER DEFAULT 0,
    is_helpdesk_agent    INTEGER DEFAULT 0,
    balance              REAL DEFAULT 0,
    picture              TEXT DEFAULT '../Src/default.jpg',
    score                REAL DEFAULT 5.0,
    phone                TEXT,
    bio                  TEXT,
    date_of_birth        DATE,
    gender               TEXT,
    address              TEXT,
    governorate          TEXT,
    municipality         TEXT,
    student_email        TEXT,
    student_status       TEXT DEFAULT 'none'
                         CHECK (student_status IN ('none','pending','approved','rejected')),
    student_verified_at  DATETIME,
    referral_code        TEXT UNIQUE,
    referred_by          INTEGER REFERENCES users(id) ON DELETE SET NULL,
    suspended            INTEGER DEFAULT 0,
    ban_reason           TEXT,
    created_at           DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create users", $ok, $err);

run($pdo, "
CREATE TABLE vehicles (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type          TEXT NOT NULL DEFAULT 'car'
                  CHECK (type IN ('car','van','pickup','minibus','bike','motorcycle','other')),
    make          TEXT,
    model         TEXT,
    year          INTEGER,
    color         TEXT,
    plate_number  TEXT UNIQUE,
    seats         INTEGER NOT NULL DEFAULT 4,
    has_ac        INTEGER DEFAULT 0,
    has_wifi      INTEGER DEFAULT 0,
    is_accessible INTEGER DEFAULT 0,
    luggage       TEXT DEFAULT 'medium' CHECK (luggage IN ('none','small','medium','large','xl')),
    max_weight_kg REAL DEFAULT 0,
    photo         TEXT,
    notes         TEXT,
    is_verified   INTEGER DEFAULT 0,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create vehicles", $ok, $err);

run($pdo, "
CREATE TABLE rides (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    driver_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    vehicle_id      INTEGER REFERENCES vehicles(id) ON DELETE SET NULL,
    from_location   TEXT NOT NULL,
    to_location     TEXT NOT NULL,
    departure_time  DATETIME NOT NULL,
    price           REAL NOT NULL DEFAULT 0,
    available_seats INTEGER NOT NULL DEFAULT 1,
    status          TEXT DEFAULT 'open' CHECK (status IN ('open','full','cancelled','completed','pending')),
    notes           TEXT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create rides", $ok, $err);

run($pdo, "
CREATE TABLE bookings (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    ride_id      INTEGER NOT NULL REFERENCES rides(id) ON DELETE CASCADE,
    passenger_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    seats        INTEGER NOT NULL DEFAULT 1,
    paid_amount  REAL DEFAULT 0,
    status       TEXT DEFAULT 'pending' CHECK (status IN ('pending','confirmed','cancelled','completed')),
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create bookings", $ok, $err);

run($pdo, "
CREATE TABLE payments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    amount      REAL NOT NULL,
    type        TEXT DEFAULT 'deposit' CHECK (type IN ('deposit','charge','refund','earning')),
    description TEXT,
    ref_id      INTEGER,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create payments", $ok, $err);

run($pdo, "
CREATE TABLE ratings (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    ride_id      INTEGER REFERENCES rides(id) ON DELETE SET NULL,
    from_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    to_user_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    score        INTEGER NOT NULL CHECK (score BETWEEN 1 AND 5),
    comment      TEXT,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(ride_id, from_user_id, to_user_id)
)", "Create ratings", $ok, $err);

run($pdo, "
CREATE TABLE notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type       TEXT NOT NULL DEFAULT 'info',
    title      TEXT NOT NULL,
    body       TEXT,
    is_read    INTEGER NOT NULL DEFAULT 0,
    link       TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create notifications", $ok, $err);

run($pdo, "
CREATE TABLE conversations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_a     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    user_b     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    ride_id    INTEGER REFERENCES rides(id) ON DELETE SET NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_a, user_b, ride_id)
)", "Create conversations", $ok, $err);

run($pdo, "
CREATE TABLE messages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    sender_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body            TEXT NOT NULL,
    is_read         INTEGER NOT NULL DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create messages", $ok, $err);

run($pdo, "
CREATE TABLE complaints (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    from_user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    against_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    ride_id         INTEGER REFERENCES rides(id) ON DELETE SET NULL,
    type            TEXT NOT NULL DEFAULT 'general',
    description     TEXT NOT NULL,
    evidence        TEXT,
    status          TEXT NOT NULL DEFAULT 'open'
                    CHECK (status IN ('open','in_review','resolved','dismissed')),
    admin_note      TEXT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create complaints", $ok, $err);

run($pdo, "
CREATE TABLE driver_profiles (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id        INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    license_number TEXT,
    avg_rating     REAL DEFAULT 5.0,
    total_trips    INTEGER DEFAULT 0,
    total_earnings REAL DEFAULT 0,
    reliability    REAL DEFAULT 100.0,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create driver_profiles", $ok, $err);

run($pdo, "
CREATE TABLE student_verifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    document   TEXT,
    status     TEXT NOT NULL DEFAULT 'pending'
               CHECK (status IN ('pending','approved','rejected')),
    note       TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create student_verifications", $ok, $err);

run($pdo, "
CREATE TABLE organizations (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    name             TEXT NOT NULL,
    type             TEXT DEFAULT 'company'
                     CHECK (type IN ('company','university','school','ngo','government','other')),
    contact_name     TEXT NOT NULL,
    contact_email    TEXT NOT NULL UNIQUE,
    billing_email    TEXT,
    phone            TEXT,
    address          TEXT,
    email_domain     TEXT,
    discount_percent INTEGER NOT NULL DEFAULT 10
                     CHECK (discount_percent BETWEEN 1 AND 100),
    discount_code    TEXT UNIQUE,
    status           TEXT DEFAULT 'pending'
                     CHECK (status IN ('pending','active','suspended')),
    notes            TEXT,
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create organizations", $ok, $err);

run($pdo, "
CREATE TABLE org_members (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    org_id    INTEGER NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    user_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
    email     TEXT NOT NULL,
    status    TEXT DEFAULT 'active',
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(org_id, email)
)", "Create org_members", $ok, $err);

run($pdo, "
CREATE TABLE helpdesk_conversations (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    agent_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
    subject    TEXT,
    status     TEXT DEFAULT 'open'
               CHECK (status IN ('open','assigned','resolved','closed')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create helpdesk_conversations", $ok, $err);

run($pdo, "
CREATE TABLE helpdesk_messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    conv_id     INTEGER NOT NULL REFERENCES helpdesk_conversations(id) ON DELETE CASCADE,
    sender_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
    sender_type TEXT DEFAULT 'user' CHECK (sender_type IN ('user','agent','bot')),
    body        TEXT NOT NULL,
    is_read     INTEGER DEFAULT 0,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create helpdesk_messages", $ok, $err);

// ── 3b. Community feed (matches mobile FeedScreen) ───────────────────────────
run($pdo, "
CREATE TABLE feed_posts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type            TEXT NOT NULL DEFAULT 'driver_offer'
                    CHECK (type IN ('driver_offer','passenger_request','group_post')),
    content         TEXT NOT NULL,
    from_location   TEXT,
    to_location     TEXT,
    departure_date  DATE,
    seats           INTEGER DEFAULT 1,
    ride_id         INTEGER REFERENCES rides(id) ON DELETE SET NULL,
    is_boosted      INTEGER DEFAULT 0,
    boosted_at      DATETIME,
    likes_count     INTEGER DEFAULT 0,
    comments_count  INTEGER DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create feed_posts", $ok, $err);

run($pdo, "
CREATE TABLE feed_likes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id     INTEGER NOT NULL REFERENCES feed_posts(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(post_id, user_id)
)", "Create feed_likes", $ok, $err);

run($pdo, "
CREATE TABLE feed_comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id     INTEGER NOT NULL REFERENCES feed_posts(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body        TEXT NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create feed_comments", $ok, $err);

// ── 4. Indexes ───────────────────────────────────────────────────────────────
$indexes = [
    "CREATE INDEX idx_rides_driver    ON rides(driver_id, status)",
    "CREATE INDEX idx_rides_status    ON rides(status)",
    "CREATE INDEX idx_bookings_ride   ON bookings(ride_id, status)",
    "CREATE INDEX idx_bookings_pass   ON bookings(passenger_id)",
    "CREATE INDEX idx_notif_user      ON notifications(user_id, is_read)",
    "CREATE INDEX idx_msg_conv        ON messages(conversation_id, created_at)",
    "CREATE INDEX idx_conv_users      ON conversations(user_a, user_b)",
    "CREATE INDEX idx_hd_conv_user    ON helpdesk_conversations(user_id, status)",
    "CREATE INDEX idx_hd_msg_conv     ON helpdesk_messages(conv_id, created_at)",
    "CREATE INDEX idx_org_domain      ON organizations(email_domain)",
    "CREATE INDEX idx_vehicles_user   ON vehicles(user_id)",
    "CREATE INDEX idx_ratings_to      ON ratings(to_user_id)",
    "CREATE INDEX idx_feed_user       ON feed_posts(user_id, created_at)",
    "CREATE INDEX idx_feed_type       ON feed_posts(type, is_boosted DESC, created_at DESC)",
    "CREATE INDEX idx_feed_likes_post ON feed_likes(post_id)",
    "CREATE INDEX idx_feed_comments_post ON feed_comments(post_id, created_at)",
];
foreach ($indexes as $idx) run($pdo, $idx, "Index", $ok, $err);

// ── 5. API tokens table ──────────────────────────────────────────────────────
run($pdo, "
CREATE TABLE api_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token      TEXT NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create api_tokens", $ok, $err);

run($pdo, "CREATE INDEX idx_api_tokens_token   ON api_tokens(token)",   "Index api_tokens.token",   $ok, $err);
run($pdo, "CREATE INDEX idx_api_tokens_user_id ON api_tokens(user_id)", "Index api_tokens.user_id", $ok, $err);

// ── 6. Student domains table ─────────────────────────────────────────────────
run($pdo, "
CREATE TABLE student_domains (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    domain     TEXT NOT NULL UNIQUE,
    label      TEXT NOT NULL DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create student_domains", $ok, $err);

// Seed common Tunisian university domains
$domains = [
    ['esprit.tn',      'ESPRIT'],
    ['esprit.com',     'ESPRIT'],
    ['enit.utm.tn',    'ENIT'],
    ['fst.utm.tn',     'FST Tunis'],
    ['isg.rnu.tn',     'ISG Tunis'],
    ['isitc.ucar.tn',  'ISITC'],
    ['ucar.tn',        'Université de Carthage'],
    ['isim.rnu.tn',    'ISIM'],
    ['ihec.rnu.tn',    'IHEC Carthage'],
    ['insat.rnu.tn',   'INSAT'],
    ['fst.rnu.tn',     'FST'],
];
foreach ($domains as [$dom, $lbl]) {
    try {
        $pdo->prepare("INSERT OR IGNORE INTO student_domains (domain, label) VALUES (?, ?)")->execute([$dom, $lbl]);
    } catch (PDOException $e) {}
}
$ok[] = "Student domains seeded (" . count($domains) . " entries)";

// ── 7. Seed admin account ────────────────────────────────────────────────────
$adminPass = password_hash('Admin@1234', PASSWORD_DEFAULT);
try {
    $pdo->prepare("
        INSERT INTO users (username, email, password, is_admin, is_driver, Region)
        VALUES ('Admin', 'admin@forsadrive.tn', ?, 1, 0, 'TN')
    ")->execute([$adminPass]);
    $ok[] = "Admin account created — admin@forsadrive.tn / Admin@1234";
} catch (PDOException $e) {
    $err[] = "Admin seed — " . $e->getMessage();
}

// ── Output ───────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>ForsaDrive — DB Setup</title>
<style>
body { font-family:'Segoe UI',sans-serif; background:#f0f4f8; margin:0; padding:2rem; }
.card { max-width:720px; margin:0 auto; background:#fff; border-radius:14px; box-shadow:0 8px 32px rgba(0,0,0,.12); overflow:hidden; }
.card-header { background:linear-gradient(135deg,#0a1628,#1a3355); color:#fff; padding:1.5rem 2rem; }
.card-header h1 { margin:0; font-size:1.4rem; }
.card-header p { margin:.25rem 0 0; opacity:.7; font-size:.875rem; }
.card-body { padding:1.5rem 2rem; }
.ok-list, .err-list { list-style:none; padding:0; margin:0 0 1rem; }
.ok-list li { padding:.2rem 0; font-size:.85rem; color:#166534; }
.ok-list li::before { content:'✅ '; }
.err-list li { padding:.2rem 0; font-size:.85rem; color:#991b1b; }
.err-list li::before { content:'❌ '; }
.summary { padding:1rem 1.25rem; border-radius:8px; font-weight:600; margin-top:1rem; }
.summary.ok  { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
.summary.err { background:#fee2e2; color:#7f1d1d; border:1px solid #fca5a5; }
.cred-box { background:#fffbeb; border:1px solid #f59e0b; border-radius:8px; padding:1rem 1.25rem; margin:1rem 0; font-family:monospace; }
.btn-go { display:inline-block; background:#0a1628; color:#fff; padding:.65rem 1.5rem; border-radius:8px; text-decoration:none; font-weight:700; margin-top:.5rem; border:2px solid #f59e0b; }
</style>
</head>
<body>
<div class="card">
  <div class="card-header">
    <h1>🚗 ForsaDrive — Database Rebuilt</h1>
    <p><?= count($ok) ?> succeeded &nbsp;·&nbsp; <?= count($err) ?> failed</p>
  </div>
  <div class="card-body">

    <?php if (!empty($err)): ?>
    <ul class="err-list"><?php foreach ($err as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul>
    <?php endif; ?>

    <ul class="ok-list"><?php foreach ($ok as $m) echo "<li>".htmlspecialchars($m)."</li>"; ?></ul>

    <?php if (empty($err)): ?>
    <div class="summary ok">✅ All done! Database is clean and ready.</div>
    <div class="cred-box">
      <strong>🔑 Admin Login Credentials</strong><br>
      Email: &nbsp; <strong>admin@forsadrive.tn</strong><br>
      Password: <strong>Admin@1234</strong><br>
      <small style="color:#92400e;">Change this password after first login!</small>
    </div>
    <?php else: ?>
    <div class="summary err">⚠️ Some steps failed — check above. The DB may be in a partial state.</div>
    <?php endif; ?>

    <a href="Pages/login.php" class="btn-go">→ Go to Login</a>
  </div>
</div>
</body>
</html>
