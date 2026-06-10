<?php
/**
 * Migration: 2026_06_bookings_discount_code.php
 *
 * Records which organizational discount code (if any) was used on a booking, so
 * the organization dashboard can report real usage. Previously the promo code
 * only reduced the price at booking time and was then discarded — there was no
 * column linking a booking to an org, which made org_dashboard.php's usage query
 * (it referenced a non-existent rides.discount_code) impossible.
 *
 * Idempotent and safe to re-run.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/database.php';

$db  = new Database();
$pdo = $db->getConnection();

$cols = [];
foreach ($pdo->query("PRAGMA table_info(bookings)") as $c) {
    $cols[] = $c['name'];
}

if (!in_array('discount_code', $cols, true)) {
    $pdo->exec("ALTER TABLE bookings ADD COLUMN discount_code TEXT");
    echo "✓ Added bookings.discount_code\n";
} else {
    echo "• bookings.discount_code already present\n";
}
