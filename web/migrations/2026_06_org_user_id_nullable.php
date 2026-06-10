<?php
/**
 * Migration: 2026_06_org_user_id_nullable.php
 *
 * The authoritative Dart schema (lib/db.dart) created organizations.user_id as
 * NOT NULL. That blocks the public "Apply for Discount" form on the landing page,
 * which is meant to accept applications from anonymous (not-logged-in) visitors —
 * they have no user_id. This rebuilds the table so user_id is nullable
 * (ON DELETE SET NULL, matching the original web migration's intent).
 *
 * It also adds the `password` column used by the organization login feature,
 * which the earlier 2026_06_organization_login.php migration failed to add: that
 * migration ran all its ALTERs in one transaction, and the first one
 * (ADD COLUMN user_id) aborted with "duplicate column" because Dart had already
 * created it — rolling back the password column too.
 *
 * Idempotent and data-preserving. Safe to re-run.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/database.php';

$db  = new Database();
$pdo = $db->getConnection();

// Inspect current schema
$cols       = [];
$userIdNotNull = false;
foreach ($pdo->query("PRAGMA table_info(organizations)") as $c) {
    $cols[] = $c['name'];
    if ($c['name'] === 'user_id' && (int)$c['notnull'] === 1) {
        $userIdNotNull = true;
    }
}

// 1) Add password column if it is missing (cheap, no rebuild needed)
if (!in_array('password', $cols, true)) {
    $pdo->exec("ALTER TABLE organizations ADD COLUMN password TEXT");
    echo "✓ Added organizations.password\n";
} else {
    echo "• organizations.password already present\n";
}

// 2) Rebuild only if user_id is still NOT NULL
if (!$userIdNotNull) {
    echo "✓ organizations.user_id is already nullable — nothing to rebuild\n";
    return;
}

try {
    $pdo->exec("PRAGMA foreign_keys = OFF");
    $pdo->beginTransaction();

    // New table: identical to the live schema but user_id is nullable
    // (ON DELETE SET NULL) and password is included.
    $pdo->exec("
        CREATE TABLE organizations_new (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id          INTEGER REFERENCES users(id) ON DELETE SET NULL,
            name             TEXT NOT NULL,
            type             TEXT NOT NULL DEFAULT 'company',
            contact_person   TEXT NOT NULL,
            contact_email    TEXT NOT NULL,
            phone            TEXT,
            staff_email_domain TEXT,
            discount_percent INTEGER NOT NULL DEFAULT 10,
            address          TEXT,
            notes            TEXT,
            status           TEXT NOT NULL DEFAULT 'pending',
            discount_code    TEXT,
            rejection_reason TEXT,
            password         TEXT,
            created_at       TEXT DEFAULT (datetime('now')),
            contact_name     TEXT,
            email_domain     TEXT,
            billing_email    TEXT
        )
    ");

    $pdo->exec("
        INSERT INTO organizations_new
            (id, user_id, name, type, contact_person, contact_email, phone,
             staff_email_domain, discount_percent, address, notes, status,
             discount_code, rejection_reason, created_at,
             contact_name, email_domain, billing_email)
        SELECT
             id, user_id, name, type, contact_person, contact_email, phone,
             staff_email_domain, discount_percent, address, notes, status,
             discount_code, rejection_reason, created_at,
             contact_name, email_domain, billing_email
        FROM organizations
    ");

    $pdo->exec("DROP TABLE organizations");
    $pdo->exec("ALTER TABLE organizations_new RENAME TO organizations");

    // Recreate index that lived on the old table
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_org_domain ON organizations(email_domain)");

    $pdo->commit();
    $pdo->exec("PRAGMA foreign_keys = ON");
    echo "✓ Rebuilt organizations with nullable user_id\n";
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec("PRAGMA foreign_keys = ON");
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
