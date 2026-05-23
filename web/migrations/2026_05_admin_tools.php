<?php
/**
 * Migration: admin tools (spec §2.4) — audit log, broadcast announcements,
 * and promo codes.
 *
 *   audit_logs          append-only trail of admin actions (who/what/when).
 *   announcements        broadcasts an admin sent; fanned out to users as
 *                        notifications at send time.
 *   promo_codes          wallet-credit codes (amount in TND) with optional
 *                        max-uses and expiry, plus an active toggle.
 *   promo_redemptions    one row per (code, user) — enforces once-per-user.
 *
 * Safe to re-run — every statement is CREATE TABLE/INDEX IF NOT EXISTS.
 *
 * CLI: php migrations/2026_05_admin_tools.php
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

$ok = []; $err = [];
function step(PDO $p, string $sql, string $label, array &$ok, array &$err): void {
    try { $p->exec($sql); $ok[] = $label; }
    catch (PDOException $e) { $err[] = "$label — " . $e->getMessage(); }
}

step($pdo, "
    CREATE TABLE IF NOT EXISTS audit_logs (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id    INTEGER,
        action      TEXT NOT NULL,
        target_type TEXT,
        target_id   INTEGER,
        summary     TEXT,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )", "audit_logs table", $ok, $err);
step($pdo, "CREATE INDEX IF NOT EXISTS idx_audit_logs_created ON audit_logs(created_at DESC)", "audit_logs index", $ok, $err);

step($pdo, "
    CREATE TABLE IF NOT EXISTS announcements (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id   INTEGER,
        title      TEXT NOT NULL,
        body       TEXT NOT NULL,
        audience   TEXT NOT NULL DEFAULT 'all',
        sent_count INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )", "announcements table", $ok, $err);

step($pdo, "
    CREATE TABLE IF NOT EXISTS promo_codes (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        code       TEXT NOT NULL UNIQUE,
        amount     REAL NOT NULL,
        max_uses   INTEGER DEFAULT 0,
        used_count INTEGER DEFAULT 0,
        expires_at DATETIME,
        is_active  INTEGER DEFAULT 1,
        created_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )", "promo_codes table", $ok, $err);

step($pdo, "
    CREATE TABLE IF NOT EXISTS promo_redemptions (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        promo_id   INTEGER NOT NULL,
        user_id    INTEGER NOT NULL,
        amount     REAL NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(promo_id, user_id)
    )", "promo_redemptions table", $ok, $err);

echo "Migration 2026_05_admin_tools on:\n  $target\n";
echo str_repeat('-', 50) . "\n";
foreach ($ok  as $m) echo " [OK]  $m\n";
foreach ($err as $m) echo " [ERR] $m\n";
echo str_repeat('-', 50) . "\n";
echo count($ok) . " succeeded · " . count($err) . " failed\n";
exit(empty($err) ? 0 : 1);
