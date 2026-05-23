<?php
class Database {
    private PDO $connection;

    public function __construct(string $dbPath = null) {
        if ($dbPath === null) {
            // Single source of truth shared with the Dart API and the mobile
            // app (ForsaDrive_PFE/forsa_drive_api). When this file exists, use
            // it; otherwise fall back to the legacy web-only DB.
            $shared = __DIR__ . '/../../ForsaDrive_PFE/forsa_drive_api/database/DB.db';
            $legacy = __DIR__ . '/../Database/DB.db';
            $dbPath = file_exists($shared) ? $shared : $legacy;
        }

        $dbPath = realpath($dbPath) ?: $dbPath;

        try {
            $this->connection = new PDO("sqlite:" . $dbPath);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // WAL lets PHP and the Dart server share the file concurrently
            $this->connection->exec("PRAGMA journal_mode = WAL");
            $this->connection->exec("PRAGMA foreign_keys = ON");

            // Auto-migrate: web pages expect columns/tables that the Dart API
            // schema doesn't ship with. Idempotent — every ALTER is in try/catch.
            $this->autoMigrate();
        } catch (PDOException $e) {
            die("Erreur de connexion à la base de données : " . $e->getMessage());
        }
    }

    public function getConnection(): PDO {
        return $this->connection;
    }

    /**
     * Add user/profile columns and helper tables the web side relies on.
     * Each ALTER is guarded — re-running is a no-op.
     */
    private function autoMigrate(): void {
        // ── users columns the web expects but Dart API schema doesn't ship ──
        $userCols = [
            'is_student_verified'  => "INTEGER DEFAULT 0",
            'student_email'        => "TEXT",
            'student_status'       => "TEXT DEFAULT 'none'",
            'student_verified_at'  => "DATETIME",
            'referral_code'        => "TEXT",
            'referred_by'          => "INTEGER",
            'address'              => "TEXT",
            'municipality'         => "TEXT",
            'date_of_birth'        => "DATE",
            // Progressive sanctions (§2.4): when a temporary suspension lifts,
            // and a running warning tally to drive escalation.
            'suspended_until'      => "DATETIME",
            'warnings_count'       => "INTEGER DEFAULT 0",
        ];
        foreach ($userCols as $col => $ddl) {
            try {
                $this->connection->exec("ALTER TABLE users ADD COLUMN $col $ddl");
            } catch (PDOException $e) { /* already exists */ }
        }

        // ── vehicles columns the web admin/verification flows need ────────────
        $vehicleCols = [
            'verified'      => "INTEGER DEFAULT 0",
            'verified_at'   => "DATETIME",
            'id_card_photo' => "TEXT",
            'is_accessible' => "INTEGER DEFAULT 0",
            'max_weight_kg' => "REAL DEFAULT 0",
            'photo'         => "TEXT",
            'notes'         => "TEXT",
        ];
        foreach ($vehicleCols as $col => $ddl) {
            try {
                $this->connection->exec("ALTER TABLE vehicles ADD COLUMN $col $ddl");
            } catch (PDOException $e) { /* already exists */ }
        }

        // ── payments.ref_id — the Dart API creates payments without it, but
        // the PHP booking/ride/boost flows insert it. Add so those don't fail. ─
        try {
            $this->connection->exec("ALTER TABLE payments ADD COLUMN ref_id INTEGER");
        } catch (PDOException $e) { /* already exists */ }

        // ── feed_posts columns the tiered-boost feature needs (§3.2) ─────────
        // The Dart API's feed_posts ships only is_boosted; the web/mobile boost
        // flow adds a purchased tier + auto-expiry on top.
        $feedCols = [
            'boosted_at'       => "DATETIME",
            'boost_tier'       => "TEXT",
            'boost_expires_at' => "DATETIME",
            'likes_count'      => "INTEGER DEFAULT 0",
            'comments_count'   => "INTEGER DEFAULT 0",
        ];
        foreach ($feedCols as $col => $ddl) {
            try {
                $this->connection->exec("ALTER TABLE feed_posts ADD COLUMN $col $ddl");
            } catch (PDOException $e) { /* already exists */ }
        }

        // ── student_domains lookup table ──────────────────────────────────────
        try {
            $this->connection->exec("
                CREATE TABLE IF NOT EXISTS student_domains (
                    id         INTEGER PRIMARY KEY AUTOINCREMENT,
                    domain     TEXT NOT NULL UNIQUE,
                    label      TEXT NOT NULL DEFAULT '',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } catch (PDOException $e) {}

        // ── driver_profiles table (web admin / driver dashboard rely on it) ──
        try {
            $this->connection->exec("
                CREATE TABLE IF NOT EXISTS driver_profiles (
                    id             INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id        INTEGER NOT NULL UNIQUE,
                    license_number TEXT,
                    avg_rating     REAL DEFAULT 5.0,
                    total_trips    INTEGER DEFAULT 0,
                    total_earnings REAL DEFAULT 0,
                    reliability    REAL DEFAULT 100.0,
                    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            // Backfill: create a profile row for every existing driver who doesn't have one
            $this->connection->exec("
                INSERT OR IGNORE INTO driver_profiles (user_id)
                SELECT id FROM users WHERE is_driver = 1
            ");
        } catch (PDOException $e) {}

        // ── org_members table (web admin uses this name; Dart uses organization_members) ──
        try {
            $this->connection->exec("
                CREATE TABLE IF NOT EXISTS org_members (
                    id        INTEGER PRIMARY KEY AUTOINCREMENT,
                    org_id    INTEGER NOT NULL,
                    user_id   INTEGER,
                    email     TEXT NOT NULL,
                    status    TEXT DEFAULT 'active',
                    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(org_id, email)
                )
            ");
        } catch (PDOException $e) {}

        // ── organizations aliased columns the web side expects ───────────────
        $orgCols = [
            'contact_name'  => "TEXT",
            'email_domain'  => "TEXT",
            'billing_email' => "TEXT",
        ];
        foreach ($orgCols as $col => $ddl) {
            try {
                $this->connection->exec("ALTER TABLE organizations ADD COLUMN $col $ddl");
            } catch (PDOException $e) { /* already exists */ }
        }
        // Backfill aliases from Dart column names where possible
        try { $this->connection->exec("UPDATE organizations SET contact_name = contact_person  WHERE contact_name IS NULL AND contact_person IS NOT NULL"); } catch (PDOException $e) {}
        try { $this->connection->exec("UPDATE organizations SET email_domain = staff_email_domain WHERE email_domain IS NULL AND staff_email_domain IS NOT NULL"); } catch (PDOException $e) {}

        // ── complaints columns admin pages use ────────────────────────────────
        $complaintCols = [
            'admin_note' => "TEXT",
            'updated_at' => "DATETIME",
        ];
        foreach ($complaintCols as $col => $ddl) {
            try {
                $this->connection->exec("ALTER TABLE complaints ADD COLUMN $col $ddl");
            } catch (PDOException $e) { /* already exists */ }
        }

        // ── helpdesk_conversations columns used by the agent console ─────────
        $helpdeskCols = [
            'priority'         => "TEXT DEFAULT 'normal'",
            'agent_initiated'  => "INTEGER DEFAULT 0",
            'updated_at'       => "DATETIME",
        ];
        foreach ($helpdeskCols as $col => $ddl) {
            try {
                $this->connection->exec("ALTER TABLE helpdesk_conversations ADD COLUMN $col $ddl");
            } catch (PDOException $e) { /* already exists */ }
        }

        // ── helpdesk_messages.is_read for unread counts ──────────────────────
        try {
            $this->connection->exec("ALTER TABLE helpdesk_messages ADD COLUMN is_read INTEGER DEFAULT 0");
        } catch (PDOException $e) {}

        // ── user_sanctions audit trail (progressive sanctions §2.4) ──────────
        try {
            $this->connection->exec("
                CREATE TABLE IF NOT EXISTS user_sanctions (
                    id         INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id    INTEGER NOT NULL,
                    admin_id   INTEGER,
                    type       TEXT NOT NULL,   -- 'warning' | 'suspension' | 'ban' | 'lift'
                    reason     TEXT,
                    days       INTEGER,
                    expires_at DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_user_sanctions_user ON user_sanctions(user_id, created_at DESC)");
        } catch (PDOException $e) {}

        // ── password_resets — forgot-password flow (§2.2) ────────────────────
        try {
            $this->connection->exec("
                CREATE TABLE IF NOT EXISTS password_resets (
                    id         INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id    INTEGER NOT NULL,
                    token_hash TEXT NOT NULL UNIQUE,
                    expires_at DATETIME NOT NULL,
                    used_at    DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id)");
        } catch (PDOException $e) {}

        // ── Admin tools (§2.4): audit log, announcements, promo codes ────────
        try {
            $this->connection->exec("
                CREATE TABLE IF NOT EXISTS audit_logs (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    admin_id    INTEGER,
                    action      TEXT NOT NULL,
                    target_type TEXT,
                    target_id   INTEGER,
                    summary     TEXT,
                    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_audit_logs_created ON audit_logs(created_at DESC)");
            $this->connection->exec("
                CREATE TABLE IF NOT EXISTS announcements (
                    id         INTEGER PRIMARY KEY AUTOINCREMENT,
                    admin_id   INTEGER,
                    title      TEXT NOT NULL,
                    body       TEXT NOT NULL,
                    audience   TEXT NOT NULL DEFAULT 'all',
                    sent_count INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $this->connection->exec("
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
                )
            ");
            $this->connection->exec("
                CREATE TABLE IF NOT EXISTS promo_redemptions (
                    id         INTEGER PRIMARY KEY AUTOINCREMENT,
                    promo_id   INTEGER NOT NULL,
                    user_id    INTEGER NOT NULL,
                    amount     REAL NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(promo_id, user_id)
                )
            ");
        } catch (PDOException $e) {}
    }
}
