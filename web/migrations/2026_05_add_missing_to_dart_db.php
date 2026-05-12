<?php
/**
 * Migration: add tables and columns that the PHP admin/web pages need
 * but are absent from the Dart API's SQLite.
 *
 * Safe to re-run — every change is guarded by an existence check.
 *
 * CLI: php migrations/2026_05_add_missing_to_dart_db.php
 */

$target = realpath(__DIR__ . '/../../ForsaDrive_PFE/forsa_drive_api/database/DB.db');
if (!$target || !file_exists($target)) {
    fwrite(STDERR, "Dart DB not found. Aborting.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $target);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("PRAGMA foreign_keys = ON");
$pdo->exec("PRAGMA journal_mode = WAL");

$ok = []; $err = [];

function has_col(PDO $p, string $tbl, string $col): bool {
    foreach ($p->query("PRAGMA table_info($tbl)") as $r)
        if ($r['name'] === $col) return true;
    return false;
}
function has_table(PDO $p, string $tbl): bool {
    return (bool)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=" . $p->quote($tbl))->fetchColumn();
}
function add_col(PDO $p, string $tbl, string $col, string $ddl, array &$ok, array &$err): void {
    try {
        if (has_col($p, $tbl, $col)) { $ok[] = "$tbl.$col already present"; return; }
        $p->exec("ALTER TABLE $tbl ADD COLUMN $col $ddl");
        $ok[] = "Added $tbl.$col";
    } catch (PDOException $e) {
        $err[] = "$tbl.$col — " . $e->getMessage();
    }
}

// ── 1. student_domains table ─────────────────────────────────────────────────
if (!has_table($pdo, 'student_domains')) {
    try {
        $pdo->exec("
            CREATE TABLE student_domains (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                domain     TEXT NOT NULL UNIQUE,
                label      TEXT NOT NULL DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $ok[] = "Created student_domains";
    } catch (PDOException $e) { $err[] = "student_domains — " . $e->getMessage(); }
}

// Seed university domains (INSERT OR IGNORE = safe to re-run)
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
$seeded = 0;
foreach ($domains as [$dom, $lbl]) {
    try {
        $pdo->prepare("INSERT OR IGNORE INTO student_domains (domain, label) VALUES (?, ?)")->execute([$dom, $lbl]);
        if ($pdo->lastInsertId()) $seeded++;
    } catch (PDOException $e) {}
}
$ok[] = "student_domains seeded ($seeded new, " . (count($domains) - $seeded) . " already present)";

// ── 2. driver_profiles table ─────────────────────────────────────────────────
if (!has_table($pdo, 'driver_profiles')) {
    try {
        $pdo->exec("
            CREATE TABLE driver_profiles (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id        INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
                license_number TEXT,
                avg_rating     REAL DEFAULT 5.0,
                total_trips    INTEGER DEFAULT 0,
                total_earnings REAL DEFAULT 0,
                reliability    REAL DEFAULT 100.0,
                created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        // Backfill one row per existing driver
        $pdo->exec("INSERT OR IGNORE INTO driver_profiles (user_id) SELECT id FROM users WHERE is_driver=1");
        $ok[] = "Created driver_profiles (backfilled existing drivers)";
    } catch (PDOException $e) { $err[] = "driver_profiles — " . $e->getMessage(); }
} else {
    $ok[] = "driver_profiles already present";
}

// ── 3. vehicles — missing columns ────────────────────────────────────────────
add_col($pdo, 'vehicles', 'verified',     "INTEGER DEFAULT 0",  $ok, $err);
add_col($pdo, 'vehicles', 'verified_at',  "DATETIME",           $ok, $err);
add_col($pdo, 'vehicles', 'id_card_photo',"TEXT",               $ok, $err);
add_col($pdo, 'vehicles', 'photo',        "TEXT",               $ok, $err);
add_col($pdo, 'vehicles', 'is_accessible',"INTEGER DEFAULT 0",  $ok, $err);
add_col($pdo, 'vehicles', 'max_weight_kg',"REAL DEFAULT 0",     $ok, $err);
add_col($pdo, 'vehicles', 'notes',        "TEXT",               $ok, $err);

// ── 4. complaints — missing columns ──────────────────────────────────────────
add_col($pdo, 'complaints', 'admin_note', "TEXT",               $ok, $err);
add_col($pdo, 'complaints', 'evidence',   "TEXT",               $ok, $err);
add_col($pdo, 'complaints', 'updated_at', "DATETIME", $ok, $err);
try {
    $pdo->exec("UPDATE complaints SET updated_at = created_at WHERE updated_at IS NULL");
    $ok[] = "Backfilled complaints.updated_at from created_at";
} catch (PDOException $e) { $err[] = "complaints backfill — " . $e->getMessage(); }

// ── 5. organizations — PHP uses contact_name / email_domain aliases ───────────
// Dart stores them as contact_person / staff_email_domain; add alias columns and
// populate them so existing org rows are immediately visible in admin.php.
add_col($pdo, 'organizations', 'contact_name', "TEXT", $ok, $err);
add_col($pdo, 'organizations', 'email_domain',  "TEXT", $ok, $err);
add_col($pdo, 'organizations', 'billing_email', "TEXT", $ok, $err);

// Backfill alias columns from the Dart column names
try {
    $pdo->exec("UPDATE organizations SET contact_name = contact_person  WHERE contact_name IS NULL AND contact_person IS NOT NULL");
    $pdo->exec("UPDATE organizations SET email_domain  = staff_email_domain WHERE email_domain  IS NULL AND staff_email_domain IS NOT NULL");
    $ok[] = "Backfilled organizations.contact_name / email_domain from Dart columns";
} catch (PDOException $e) { $err[] = "organizations backfill — " . $e->getMessage(); }

// ── 6. student_verifications — missing updated_at ────────────────────────────
// SQLite ALTER TABLE does not accept non-constant defaults; add as NULL then backfill
add_col($pdo, 'student_verifications', 'updated_at', "DATETIME", $ok, $err);
try {
    $pdo->exec("UPDATE student_verifications SET updated_at = created_at WHERE updated_at IS NULL");
    $ok[] = "Backfilled student_verifications.updated_at from created_at";
} catch (PDOException $e) { $err[] = "student_verifications backfill — " . $e->getMessage(); }

// ── 7. helpdesk tables — missing columns ─────────────────────────────────────
add_col($pdo, 'helpdesk_messages',      'is_read',    "INTEGER DEFAULT 0", $ok, $err);
add_col($pdo, 'helpdesk_conversations', 'updated_at', "DATETIME",          $ok, $err);
try {
    $pdo->exec("UPDATE helpdesk_conversations SET updated_at = created_at WHERE updated_at IS NULL");
    $ok[] = "Backfilled helpdesk_conversations.updated_at from created_at";
} catch (PDOException $e) { $err[] = "helpdesk_conversations backfill — " . $e->getMessage(); }

// ── Output ───────────────────────────────────────────────────────────────────
echo "Migration 2026_05_add_missing_to_dart_db on:\n  $target\n";
echo str_repeat('-', 56) . "\n";
foreach ($ok  as $m) echo " [OK]  $m\n";
foreach ($err as $m) echo " [ERR] $m\n";
echo str_repeat('-', 56) . "\n";
echo count($ok) . " succeeded · " . count($err) . " failed\n";
exit(empty($err) ? 0 : 1);
