<?php
/**
 * /bookings/* endpoints
 */

$action    = $segments[1] ?? '';
$bookingId = is_numeric($action) ? (int)$action : null;
$subAction = $segments[2] ?? '';

// POST /bookings — create booking
if ($method === 'POST' && $action === '') {
    $user = auth_user();
    $b    = require_fields(['ride_id', 'seats']);
    $pdo  = db();

    $rideId = (int)$b['ride_id'];
    $seats  = (int)$b['seats'];

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        SELECT r.*,
               (r.available_seats - COALESCE(bk.cnt, 0)) AS seats_left
        FROM rides r
        LEFT JOIN (SELECT ride_id, SUM(seats) cnt FROM bookings WHERE status IN ('confirmed','pending') GROUP BY ride_id) bk
          ON bk.ride_id = r.id
        WHERE r.id = ? AND r.status = 'open'
    ");
    $stmt->execute([$rideId]);
    $ride = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ride) { $pdo->rollBack(); json_error('Ride not found or unavailable', 404); }
    if ($ride['driver_id'] == $user['id']) { $pdo->rollBack(); json_error('Cannot book your own ride', 400); }
    if ((int)$ride['seats_left'] < $seats) { $pdo->rollBack(); json_error('Not enough seats available', 400); }

    // Check for existing active booking
    $dup = $pdo->prepare("SELECT 1 FROM bookings WHERE ride_id=? AND passenger_id=? AND status IN ('pending','confirmed')");
    $dup->execute([$rideId, $user['id']]);
    if ($dup->fetchColumn()) { $pdo->rollBack(); json_error('You already have a booking on this ride', 409); }

    $price  = (float)$ride['price'] * $seats;
    $isStudent = !empty($user['is_student']);
    if ($isStudent) $price *= 0.5; // 50% student discount

    $uBalance = (float)$user['balance'];
    if ($uBalance < $price) { $pdo->rollBack(); json_error('Insufficient balance', 402); }

    $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")->execute([$price, $user['id']]);
    $pdo->prepare("INSERT INTO bookings (ride_id, passenger_id, seats, paid_amount, status) VALUES (?, ?, ?, ?, 'pending')")
        ->execute([$rideId, $user['id'], $seats, $price]);
    $bookingId2 = (int)$pdo->lastInsertId();

    // Payment record
    $pdo->prepare("INSERT INTO payments (user_id, amount, type, description, ref_id) VALUES (?, ?, 'charge', 'Ride booking', ?)")
        ->execute([$user['id'], $price, $rideId]);

    // Notify driver
    $pdo->prepare("INSERT INTO notifications (user_id, type, title, body) VALUES (?, 'booking', 'New booking request', ?)")
        ->execute([$ride['driver_id'], "A passenger wants to book {$seats} seat(s) on your ride."]);

    $pdo->commit();

    $bkStmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
    $bkStmt->execute([$bookingId2]);
    json_ok(['booking' => $bkStmt->fetch(PDO::FETCH_ASSOC)], 201);
}

// GET /bookings/requests — driver's pending booking requests
if ($method === 'GET' && $action === 'requests') {
    $user = require_driver();
    $pdo  = db();
    $stmt = $pdo->prepare("
        SELECT bk.*, r.from_location, r.to_location, r.departure_time, r.price,
               p.id AS passenger_id, p.username AS passenger_name, p.score AS passenger_score,
               p.picture AS passenger_pic, p.is_student
        FROM bookings bk
        JOIN rides r ON r.id = bk.ride_id
        JOIN users p ON p.id = bk.passenger_id
        WHERE r.driver_id = ? AND bk.status = 'pending'
        ORDER BY bk.created_at ASC
    ");
    $stmt->execute([$user['id']]);
    json_ok(['requests' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// POST /bookings/requests/{id} — accept or reject
if ($method === 'POST' && $action === 'requests' && is_numeric($subAction)) {
    $user    = require_driver();
    $b       = require_fields(['action']);
    $bkId    = (int)$subAction;
    $pdo     = db();
    $approve = $b['action'] === 'accept';

    $stmt = $pdo->prepare("
        SELECT bk.*, r.driver_id, r.from_location, r.to_location, r.available_seats,
               (r.available_seats - COALESCE(c.cnt,0)) AS seats_left
        FROM bookings bk
        JOIN rides r ON r.id = bk.ride_id
        LEFT JOIN (SELECT ride_id, SUM(seats) cnt FROM bookings WHERE status='confirmed' GROUP BY ride_id) c ON c.ride_id=r.id
        WHERE bk.id = ? AND r.driver_id = ? AND bk.status = 'pending'
    ");
    $stmt->execute([$bkId, $user['id']]);
    $bk = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bk) json_error('Booking request not found', 404);

    if ($approve) {
        if ((int)$bk['seats_left'] < (int)$bk['seats']) json_error('Not enough seats', 400);
        $pdo->prepare("UPDATE bookings SET status='confirmed' WHERE id=?")->execute([$bkId]);
        $pdo->prepare("INSERT INTO notifications (user_id, type, title, body) VALUES (?, 'booking', ?, ?)")
            ->execute([$bk['passenger_id'], 'Booking confirmed!', "Your booking from {$bk['from_location']} to {$bk['to_location']} has been confirmed."]);
    } else {
        $pdo->prepare("UPDATE bookings SET status='cancelled' WHERE id=?")->execute([$bkId]);
        // Refund passenger
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$bk['paid_amount'], $bk['passenger_id']]);
        $pdo->prepare("INSERT INTO notifications (user_id, type, title, body) VALUES (?, 'booking', ?, ?)")
            ->execute([$bk['passenger_id'], 'Booking rejected', "Your booking was not accepted. Your balance has been refunded."]);
    }
    json_ok(['message' => $approve ? 'Booking confirmed' : 'Booking rejected']);
}

// POST /bookings/group — group booking
if ($method === 'POST' && $action === 'group') {
    $user = auth_user();
    $b    = require_fields(['ride_id', 'seats']);
    $pdo  = db();
    $rideId = (int)$b['ride_id'];
    $seats  = (int)$b['seats'];
    $notes  = $b['notes'] ?? null;

    $pdo->beginTransaction();
    $rStmt = $pdo->prepare("
        SELECT r.*, (r.available_seats - COALESCE(bk.cnt,0)) AS seats_left
        FROM rides r
        LEFT JOIN (SELECT ride_id, SUM(seats) cnt FROM bookings WHERE status IN ('confirmed','pending') GROUP BY ride_id) bk ON bk.ride_id=r.id
        WHERE r.id = ? AND r.status='open'
    ");
    $rStmt->execute([$rideId]);
    $ride = $rStmt->fetch(PDO::FETCH_ASSOC);
    if (!$ride) { $pdo->rollBack(); json_error('Ride not found', 404); }
    if ((int)$ride['seats_left'] < $seats) { $pdo->rollBack(); json_error('Not enough seats', 400); }

    $price = (float)$ride['price'] * $seats;
    if (!empty($user['is_student'])) $price *= 0.5;
    if ((float)$user['balance'] < $price) { $pdo->rollBack(); json_error('Insufficient balance', 402); }

    $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id=?")->execute([$price, $user['id']]);
    $pdo->prepare("INSERT INTO bookings (ride_id, passenger_id, seats, paid_amount, status) VALUES (?,?,?,?,'pending')")
        ->execute([$rideId, $user['id'], $seats, $price]);
    $bkId2 = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO payments (user_id, amount, type, description, ref_id) VALUES (?,?,'charge','Group booking',?)")
        ->execute([$user['id'], $price, $rideId]);
    $pdo->prepare("INSERT INTO notifications (user_id, type, title, body) VALUES (?,'booking','New group booking',?)")
        ->execute([$ride['driver_id'], "A group of {$seats} wants to book your ride."]);
    $pdo->commit();

    json_ok(['booking_id' => $bkId2, 'total' => $price], 201);
}

// POST /bookings/{id}/cash-collected
if ($method === 'POST' && $bookingId !== null && $subAction === 'cash-collected') {
    $user = require_driver();
    $pdo  = db();
    $stmt = $pdo->prepare("
        SELECT bk.* FROM bookings bk JOIN rides r ON r.id=bk.ride_id
        WHERE bk.id=? AND r.driver_id=? AND bk.status='completed'
    ");
    $stmt->execute([$bookingId, $user['id']]);
    $bk = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bk) json_error('Booking not found', 404);
    $pdo->prepare("INSERT INTO payments (user_id, amount, type, description, ref_id) VALUES (?,?,'earning','Cash collected',?)")
        ->execute([$user['id'], $bk['paid_amount'], $bookingId]);
    json_ok(['message' => 'Cash collected recorded']);
}

// DELETE /bookings/{id} — passenger cancels
if ($method === 'DELETE' && $bookingId !== null && $subAction === '') {
    $user = auth_user();
    $pdo  = db();
    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id=? AND passenger_id=? AND status IN ('pending','confirmed')");
    $stmt->execute([$bookingId, $user['id']]);
    $bk = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bk) json_error('Booking not found', 404);
    $pdo->prepare("UPDATE bookings SET status='cancelled' WHERE id=?")->execute([$bookingId]);
    if ((float)$bk['paid_amount'] > 0) {
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$bk['paid_amount'], $user['id']]);
    }
    json_ok(['message' => 'Booking cancelled']);
}

json_error('Not found', 404);
