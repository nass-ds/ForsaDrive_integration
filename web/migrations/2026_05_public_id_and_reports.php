<?php
/**
 * Migration — public ForsaDrive IDs + report-by-public-id (§ public identification spec).
 *
 * Adds:
 *   - users.public_id              (unique, immutable; format FD-{P|D|A|H}-{seq})
 *   - users.completed_at columns are NOT touched here (separate concern)
 *   - rides.completed_at           (records when a ride moved to status='completed';
 *                                   feeds the 48-hour full-profile window)
 *   - reports table                (report-by-public-id workflow)
 *
 * Idempotent: every ALTER/CREATE is guarded; safe to re-run.
 * Backfills public_id for every existing user (passenger=FD-P-, driver=FD-D-,
 * admin=FD-A-, helpdesk=FD-H-). Ranges start at 10001/20001/30001/40001 per spec.
 *
 * Run from the web root:  php migrations/2026_05_public_id_and_reports.php
 * (Auto-runs on every Database connection too — see classes/database.php autoMigrate.)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/database.php';
require_once __DIR__ . '/../classes/publicid.php';

$pdo = (new Database())->getConnection();

echo "ForsaDrive public_id + reports migration\n";
echo str_repeat('-', 50) . "\n";

// 1) users.public_id column
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN public_id TEXT");
    echo "[+] Added users.public_id\n";
} catch (PDOException $e) {
    echo "[=] users.public_id already exists\n";
}

try {
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_public_id ON users(public_id)");
    echo "[+] Index idx_users_public_id ready\n";
} catch (PDOException $e) {
    echo "[!] Could not create unique index — duplicate public_ids? " . $e->getMessage() . "\n";
}

// 2) rides.completed_at — feeds the 48-hour window for full-profile access.
try {
    $pdo->exec("ALTER TABLE rides ADD COLUMN completed_at DATETIME");
    echo "[+] Added rides.completed_at\n";
} catch (PDOException $e) {
    echo "[=] rides.completed_at already exists\n";
}
// Backfill: rides marked completed before this migration use their departure_time
// as a best-effort timestamp, so the 48-hour rule still works on legacy data.
try {
    $pdo->exec("UPDATE rides SET completed_at = COALESCE(completed_at, departure_time)
                WHERE status = 'completed' AND completed_at IS NULL");
    echo "[~] Backfilled completed_at for legacy completed rides\n";
} catch (PDOException $e) {}

// 3) reports table
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reports (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            reporter_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            reported_user_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
            reported_public_id TEXT NOT NULL,
            ride_id            INTEGER REFERENCES rides(id) ON DELETE SET NULL,
            category           TEXT NOT NULL,
            description        TEXT NOT NULL,
            status             TEXT NOT NULL DEFAULT 'pending',
            admin_note         TEXT,
            handled_by         INTEGER REFERENCES users(id) ON DELETE SET NULL,
            handled_at         DATETIME,
            created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "[+] reports table ready\n";
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_reports_reporter ON reports(reporter_id, created_at DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_reports_reported ON reports(reported_user_id, created_at DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_reports_public_id ON reports(reported_public_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_reports_status ON reports(status, created_at DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_reports_ride ON reports(ride_id)");
    echo "[+] reports indexes ready\n";
} catch (PDOException $e) {
    echo "[!] reports table error: " . $e->getMessage() . "\n";
}

// 4) Backfill public_id for any existing user that doesn't have one.
$backfilled = PublicIdService::backfillAll($pdo);
echo "[~] Backfilled public_id for {$backfilled} existing users\n";

echo str_repeat('-', 50) . "\n";
echo "Done.\n";
