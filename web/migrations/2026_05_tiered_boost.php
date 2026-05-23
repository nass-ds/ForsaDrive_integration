<?php
/**
 * Migration: tiered ride/post boost (spec §3.2).
 *
 * Adds the columns the tiered-boost feature needs to feed_posts:
 *   - boost_tier        which tier was purchased ('12h'|'24h'|'48h'|'7d')
 *   - boost_expires_at  when the boost stops ranking the post (auto-expire)
 *
 * Existing flat-5-TND boosts keep is_boosted = 1 with NULL boost_expires_at,
 * which the feed treats as a permanent (legacy) boost — no data is lost.
 *
 * Safe to re-run — each ALTER is guarded by a column-existence check.
 *
 * CLI: php migrations/2026_05_tiered_boost.php
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

add_col($pdo, 'feed_posts', 'boost_tier',       "TEXT",     $ok, $err);
add_col($pdo, 'feed_posts', 'boost_expires_at', "DATETIME", $ok, $err);

echo "Migration 2026_05_tiered_boost on:\n  $target\n";
echo str_repeat('-', 50) . "\n";
foreach ($ok  as $m) echo " [OK]  $m\n";
foreach ($err as $m) echo " [ERR] $m\n";
echo str_repeat('-', 50) . "\n";
echo count($ok) . " succeeded · " . count($err) . " failed\n";
exit(empty($err) ? 0 : 1);
