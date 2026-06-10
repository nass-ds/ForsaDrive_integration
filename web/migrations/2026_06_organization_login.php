<?php
/**
 * Migration: 2026_06_organization_login.php
 * Adds user_id and password fields to organizations table to support organization login
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/database.php';

$db = new Database();
$pdo = $db->getConnection();

try {
    $pdo->beginTransaction();

    // Add user_id column (tracks who applied)
    $pdo->exec("ALTER TABLE organizations ADD COLUMN user_id INTEGER REFERENCES users(id) ON DELETE SET NULL");

    // Add password column (for org login)
    $pdo->exec("ALTER TABLE organizations ADD COLUMN password TEXT");

    // Add rejection_reason column (for rejected orgs)
    $pdo->exec("ALTER TABLE organizations ADD COLUMN rejection_reason TEXT");

    $pdo->commit();
    echo "✓ Migration completed successfully\n";
} catch (PDOException $e) {
    $pdo->rollBack();
    if (str_contains($e->getMessage(), 'duplicate column')) {
        echo "✓ Columns already exist (safe to retry)\n";
    } else {
        echo "✗ Migration failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
