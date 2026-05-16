<?php
/**
 * ForsaDrive — demo data seeder.
 *
 * Wipes test data (users whose email ends in @demo.tn) and re-creates a
 * deterministic, demo-ready dataset:
 *   - one driver with a vehicle and a published ride
 *   - one regular passenger with a funded wallet
 *   - one verified student passenger with a funded wallet
 *   - one extra published ride on a different corridor
 *
 * Run BEFORE the defense to guarantee a clean demo state:
 *   php seed_demo.php
 *
 * Safe to run repeatedly — it never touches non-demo users.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/database.php';

$pdo = (new Database())->getConnection();
$pdo->exec("PRAGMA foreign_keys = ON");

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────
function find_user(PDO $pdo, string $email): ?array {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}

function upsert_user(PDO $pdo, array $cols): int {
    $existing = find_user($pdo, $cols['email']);
    if ($existing) {
        $set    = [];
        $params = [];
        foreach ($cols as $k => $v) {
            if ($k === 'email') continue;
            $set[]    = "$k = ?";
            $params[] = $v;
        }
        $params[] = $cols['email'];
        $pdo->prepare("UPDATE users SET " . implode(', ', $set) . " WHERE email = ?")
            ->execute($params);
        return (int)$existing['id'];
    }
    $keys  = array_keys($cols);
    $vals  = array_values($cols);
    $ph    = implode(',', array_fill(0, count($vals), '?'));
    $pdo->prepare("INSERT INTO users (" . implode(',', $keys) . ") VALUES ($ph)")
        ->execute($vals);
    return (int)$pdo->lastInsertId();
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Wipe previous demo data (only @demo.tn users + their cascading rows)
// ─────────────────────────────────────────────────────────────────────────────
$pdo->beginTransaction();
$ids = $pdo->query("SELECT id FROM users WHERE email LIKE '%@demo.tn'")
           ->fetchAll(PDO::FETCH_COLUMN);
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    // Foreign keys ON DELETE CASCADE handle rides, bookings, vehicles,
    // driver_profiles, payments, etc., but api_tokens has no cascade.
    $pdo->prepare("DELETE FROM api_tokens WHERE user_id IN ($in)")->execute($ids);
    $pdo->prepare("DELETE FROM users WHERE id IN ($in)")->execute($ids);
}
$pdo->commit();

// ─────────────────────────────────────────────────────────────────────────────
// 2. Seed deterministic demo accounts
// ─────────────────────────────────────────────────────────────────────────────
$pwd = password_hash('demo123', PASSWORD_BCRYPT);

$driverId = upsert_user($pdo, [
    'username'            => 'Karim Driver',
    'email'               => 'driver@demo.tn',
    'password'            => $pwd,
    'is_driver'           => 1,
    'is_student'          => 0,
    'is_student_verified' => 0,
    'balance'             => 0,
    'score'               => 4.8,
    'phone'               => '+216 22 111 111',
    'governorate'         => 'Tunis',
]);

$passengerId = upsert_user($pdo, [
    'username'            => 'Sami Passenger',
    'email'               => 'passenger@demo.tn',
    'password'            => $pwd,
    'is_driver'           => 0,
    'is_student'          => 0,
    'is_student_verified' => 0,
    'balance'             => 100.00,
    'score'               => 5.0,
    'phone'               => '+216 22 222 222',
    'governorate'         => 'Sfax',
]);

$studentId = upsert_user($pdo, [
    'username'            => 'Mariem Student',
    'email'               => 'student@demo.tn',
    'password'            => $pwd,
    'is_driver'           => 0,
    'is_student'          => 1,
    'is_student_verified' => 1,
    'student_status'      => 'approved',
    'student_email'       => 'mariem.student@isi.utm.tn',
    'balance'             => 100.00,
    'score'               => 5.0,
    'phone'               => '+216 22 333 333',
    'governorate'         => 'Sousse',
]);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Driver profile + vehicle
// ─────────────────────────────────────────────────────────────────────────────
$pdo->prepare("INSERT OR REPLACE INTO driver_profiles
    (user_id, license_number, avg_rating, total_trips, total_earnings, reliability)
    VALUES (?, ?, ?, ?, ?, ?)")
    ->execute([$driverId, 'TN-DEMO-001', 4.8, 12, 360.00, 92.5]);

$pdo->prepare("DELETE FROM vehicles WHERE user_id = ?")->execute([$driverId]);
$pdo->prepare("INSERT INTO vehicles
    (user_id, type, make, model, year, color, plate_number, seats, has_ac, has_wifi)
    VALUES (?, 'car', 'Volkswagen', 'Golf 7', 2020, 'White', '123-TUN-456', 4, 1, 1)")
    ->execute([$driverId]);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Two published rides (always tomorrow + day after, so they're always "open")
// ─────────────────────────────────────────────────────────────────────────────
$pdo->prepare("DELETE FROM rides WHERE driver_id = ?")->execute([$driverId]);

$tomorrow      = (new DateTime('tomorrow 08:30'))->format('Y-m-d H:i:s');
$dayAfter      = (new DateTime('+2 days 14:00'))->format('Y-m-d H:i:s');

$pdo->prepare("INSERT INTO rides
    (driver_id, from_location, to_location, departure_time, price,
     available_seats, status, notes)
    VALUES (?, 'Tunis', 'Sfax', ?, 30.00, 4, 'open',
            'Demo ride — direct, AC + WiFi, smoking forbidden.')")
    ->execute([$driverId, $tomorrow]);

$pdo->prepare("INSERT INTO rides
    (driver_id, from_location, to_location, departure_time, price,
     available_seats, status, notes)
    VALUES (?, 'Sousse', 'Tunis', ?, 20.00, 3, 'open',
            'Demo ride — return trip, quiet ride preferred.')")
    ->execute([$driverId, $dayAfter]);

// ─────────────────────────────────────────────────────────────────────────────
// 5. Print credentials
// ─────────────────────────────────────────────────────────────────────────────
echo str_repeat('━', 68) . "\n";
echo "  ForsaDrive — demo state ready\n";
echo str_repeat('━', 68) . "\n";
echo "\n  Accounts (password for all three: demo123)\n";
printf("    %-22s  %s\n", "Driver",            "driver@demo.tn");
printf("    %-22s  %s\n", "Passenger",         "passenger@demo.tn");
printf("    %-22s  %s\n", "Student passenger", "student@demo.tn");
echo "\n  Pre-published rides (driver: Karim)\n";
echo "    Tunis  → Sfax    tomorrow 08:30  •  30 TND  •  4 seats\n";
echo "    Sousse → Tunis   in 2 days 14:00 •  20 TND  •  3 seats\n";
echo "\n  Wallet balances\n";
echo "    Sami:    100 TND   |   Mariem (verified student): 100 TND\n";
echo "\n  Demo flow suggestion\n";
echo "    1. Log in as Sami → search Tunis Sfax → book → show 50% prepayment\n";
echo "    2. Log in as Mariem → search same → notice the auto -50% discount\n";
echo "    3. Log in as Karim → see the booking request → accept\n";
echo str_repeat('━', 68) . "\n";
