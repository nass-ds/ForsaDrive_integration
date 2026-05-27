<?php
/**
 * Migration: give vehicles a persistent verification lifecycle so a rejected
 * "driver review" leaves the queue instead of looking identical to a brand-new,
 * never-reviewed submission.
 *
 * A vehicle submission already bundles the driver's documents (carte grise,
 * permis de conduire, assurance, …), so this single status is the combined
 * "Driver review" decision surfaced in the admin Verifications queue.
 *
 *   verification_status : 'pending' | 'approved' | 'rejected'  (default pending)
 *   rejection_note      : reviewer's reason when rejected
 *
 * Backfill: any already-verified vehicle (verified=1) becomes 'approved'.
 *
 * Target file (shared by the Dart API, the mobile app and the PHP web UI):
 *   ForsaDrive_PFE/forsa_drive_api/database/DB.db
 * Falls back to the legacy web/Database/DB.db if the shared DB is absent.
 *
 * Safe to re-run — each ALTER is guarded by a column-existence check.
 *
 * CLI: php migrations/2026_05_vehicle_verification_status.php
 */

$shared = __DIR__ . '/../../ForsaDrive_PFE/forsa_drive_api/database/DB.db';
$legacy = __DIR__ . '/../Database/DB.db';
$target = file_exists($shared) ? $shared : $legacy;
$target = realpath($target) ?: $target;

if (!file_exists($target)) {
    fwrite(STDERR, "DB not found (looked for shared then legacy). Aborting.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $target);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

add_col($pdo, 'vehicles', 'verification_status', "TEXT DEFAULT 'pending'", $ok, $err);
add_col($pdo, 'vehicles', 'rejection_note',      "TEXT",                   $ok, $err);

// Backfill: existing verified vehicles are already-decided → approved.
try {
    $n = $pdo->exec("
        UPDATE vehicles
           SET verification_status = 'approved'
         WHERE verified = 1
           AND (verification_status IS NULL OR verification_status = 'pending')
    ");
    $ok[] = "Backfilled $n already-verified vehicle(s) → approved";
} catch (PDOException $e) { $err[] = 'Backfill — ' . $e->getMessage(); }

echo "Migration 2026_05_vehicle_verification_status on:\n  $target\n";
echo str_repeat('-', 50) . "\n";
foreach ($ok  as $m) echo " [OK]  $m\n";
foreach ($err as $m) echo " [ERR] $m\n";
echo str_repeat('-', 50) . "\n";
echo count($ok) . " succeeded · " . count($err) . " failed\n";
exit(empty($err) ? 0 : 1);
