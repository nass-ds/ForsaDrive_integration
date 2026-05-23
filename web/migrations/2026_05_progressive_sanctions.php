<?php
/**
 * Migration: progressive admin sanctions (spec §2.4).
 *
 * Replaces the flat suspend/unsuspend with an escalating ladder:
 *   Warning  →  Suspension (7–30 days)  →  Ban (permanent)
 *
 * users gets:
 *   - suspended_until  DATETIME   when a temporary suspension auto-lifts.
 *                                  NULL while suspended=1 means a permanent ban;
 *                                  a value in the past is treated as expired and
 *                                  cleared on the next login (expire-on-read,
 *                                  same idea as the tiered-boost expiry §3.2).
 *   - warnings_count   INTEGER    running tally used to suggest the next step.
 *
 * New table user_sanctions is the per-user audit trail every level writes to,
 * so the admin can see the history and escalate appropriately.
 *
 * Safe to re-run — each ALTER is guarded by a column-existence check and the
 * table uses CREATE TABLE IF NOT EXISTS.
 *
 * CLI: php migrations/2026_05_progressive_sanctions.php
 */

$target = realpath(__DIR__ . '/../../ForsaDrive_PFE/forsa_drive_api/database/DB.db');
if (!$target || !file_exists($target)) {
    fwrite(STDERR, "Shared DB not found at expected path. Aborting.\n");
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

add_col($pdo, 'users', 'suspended_until', "DATETIME",          $ok, $err);
add_col($pdo, 'users', 'warnings_count',  "INTEGER DEFAULT 0", $ok, $err);

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_sanctions (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            admin_id   INTEGER,
            type       TEXT NOT NULL,   -- 'warning' | 'suspension' | 'ban' | 'lift'
            reason     TEXT,
            days       INTEGER,         -- suspension length in days; NULL otherwise
            expires_at DATETIME,        -- suspension end; NULL for warning/ban/lift
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $ok[] = "user_sanctions table ready";
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_sanctions_user ON user_sanctions(user_id, created_at DESC)");
    $ok[] = "user_sanctions index ready";
} catch (PDOException $e) {
    $err[] = "user_sanctions — " . $e->getMessage();
}

echo "Migration 2026_05_progressive_sanctions on:\n  $target\n";
echo str_repeat('-', 50) . "\n";
foreach ($ok  as $m) echo " [OK]  $m\n";
foreach ($err as $m) echo " [ERR] $m\n";
echo str_repeat('-', 50) . "\n";
echo count($ok) . " succeeded · " . count($err) . " failed\n";
exit(empty($err) ? 0 : 1);
