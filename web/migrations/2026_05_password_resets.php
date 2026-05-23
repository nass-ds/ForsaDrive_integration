<?php
/**
 * Migration: forgot-password flow (spec §2.2).
 *
 * Adds the password_resets table backing the reset-by-token flow. Tokens are
 * stored hashed (sha256 of the raw token that travels in the link), single-use
 * (used_at), and short-lived (expires_at). No SMTP is required — consistent with
 * the rest of the project, the link is surfaced on-screen in demo mode and the
 * row is ready to be emailed instead once an SMTP service is wired in.
 *
 * Safe to re-run — CREATE TABLE IF NOT EXISTS.
 *
 * CLI: php migrations/2026_05_password_resets.php
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
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at    DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $ok[] = "password_resets table ready";
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id)");
    $ok[] = "password_resets index ready";
} catch (PDOException $e) {
    $err[] = "password_resets — " . $e->getMessage();
}

echo "Migration 2026_05_password_resets on:\n  $target\n";
echo str_repeat('-', 50) . "\n";
foreach ($ok  as $m) echo " [OK]  $m\n";
foreach ($err as $m) echo " [ERR] $m\n";
echo str_repeat('-', 50) . "\n";
echo count($ok) . " succeeded · " . count($err) . " failed\n";
exit(empty($err) ? 0 : 1);
