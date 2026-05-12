<?php
/**
 * Migration: bring the Dart-API's SQLite up to the column-set that the
 * PHP web/Pages/*.php code uses, so a single shared DB can serve both
 * backends without breaking either.
 *
 * Target file (the one the Dart API and the mobile app already use):
 *   ForsaDrive_PFE/forsa_drive_api/database/DB.db
 *
 * Safe to re-run — each ALTER is guarded by a column-existence check.
 *
 * CLI: php migrations/2026_05_unify_with_dart_db.php
 */

$target = realpath(__DIR__ . '/../../ForsaDrive_PFE/forsa_drive_api/database/DB.db');
if (!$target || !file_exists($target)) {
    fwrite(STDERR, "Dart DB not found at expected path. Aborting.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $target);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("PRAGMA foreign_keys = ON");
$pdo->exec("PRAGMA journal_mode = WAL");

function has_col(PDO $p, string $tbl, string $col): bool {
    foreach ($p->query("PRAGMA table_info($tbl)") as $r) {
        if ($r['name'] === $col) return true;
    }
    return false;
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

$ok = []; $err = [];

// ── users: columns the PHP pages read/write ─────────────────────────────────
add_col($pdo, 'users', 'is_student_verified', "INTEGER DEFAULT 0",        $ok, $err);
add_col($pdo, 'users', 'date_of_birth',       "DATE",                     $ok, $err);
add_col($pdo, 'users', 'address',             "TEXT",                     $ok, $err);
add_col($pdo, 'users', 'municipality',        "TEXT",                     $ok, $err);
add_col($pdo, 'users', 'student_email',       "TEXT",                     $ok, $err);
add_col($pdo, 'users', 'student_status',      "TEXT DEFAULT 'none'",      $ok, $err);
add_col($pdo, 'users', 'student_verified_at', "DATETIME",                 $ok, $err);
add_col($pdo, 'users', 'referral_code',       "TEXT",                     $ok, $err);
add_col($pdo, 'users', 'referred_by',         "INTEGER",                  $ok, $err);

// ── feed_posts: extra fields web/api/feed.php and Pages/feed.php expect ─────
add_col($pdo, 'feed_posts', 'boosted_at',     "DATETIME",                 $ok, $err);
add_col($pdo, 'feed_posts', 'likes_count',    "INTEGER DEFAULT 0",        $ok, $err);
add_col($pdo, 'feed_posts', 'comments_count', "INTEGER DEFAULT 0",        $ok, $err);

// ── Backfill counters so existing posts get accurate counts ─────────────────
try {
    $pdo->exec("
        UPDATE feed_posts SET likes_count = (
            SELECT COUNT(*) FROM feed_likes WHERE feed_likes.post_id = feed_posts.id
        )
    ");
    $pdo->exec("
        UPDATE feed_posts SET comments_count = (
            SELECT COUNT(*) FROM feed_comments WHERE feed_comments.post_id = feed_posts.id
        )
    ");
    $ok[] = 'Backfilled likes_count / comments_count';
} catch (PDOException $e) { $err[] = 'Backfill — ' . $e->getMessage(); }

// ── Output ──────────────────────────────────────────────────────────────────
echo "Migration 2026_05_unify_with_dart_db on:\n  $target\n";
echo str_repeat('-', 50) . "\n";
foreach ($ok  as $m) echo " [OK]  $m\n";
foreach ($err as $m) echo " [ERR] $m\n";
echo str_repeat('-', 50) . "\n";
echo count($ok) . " succeeded · " . count($err) . " failed\n";
exit(empty($err) ? 0 : 1);
