<?php
/**
 * /rides/* endpoints
 */

$action   = $segments[1] ?? '';
$rideId   = is_numeric($action) ? (int)$action : null;
$subAction = $segments[2] ?? '';

// GET /rides — list available rides
if ($method === 'GET' && $action === '') {
    $user    = auth_user();
    $pdo     = db();
    $where   = ["r.status = 'open'", "(r.available_seats - COALESCE(b.booked_seats,0)) > 0"];
    $params  = [];

    if (!empty($_GET['from'])) {
        $where[]  = "LOWER(r.from_location) LIKE ?";
        $params[] = '%' . strtolower($_GET['from']) . '%';
    }
    if (!empty($_GET['to'])) {
        $where[]  = "LOWER(r.to_location) LIKE ?";
        $params[] = '%' . strtolower($_GET['to']) . '%';
    }
    if (!empty($_GET['date'])) {
        $where[]  = "DATE(r.departure_time) = ?";
        $params[] = $_GET['date'];
    }
    if (!empty($_GET['seats'])) {
        $where[]  = "(r.available_seats - COALESCE(b.booked_seats,0)) >= ?";
        $params[] = (int)$_GET['seats'];
    }

    $whereStr = 'WHERE ' . implode(' AND ', $where);
    $orderBy  = match($_GET['sort'] ?? '') {
        'price_asc'  => 'r.price ASC',
        'price_desc' => 'r.price DESC',
        'seats'      => 'seats_left DESC',
        default      => 'r.departure_time ASC',
    };

    $stmt = $pdo->prepare("
        SELECT r.*,
               (r.available_seats - COALESCE(b.booked_seats, 0)) AS seats_left,
               u.username  AS driver_name,
               u.score     AS driver_score,
               u.picture   AS driver_pic,
               v.type      AS vehicle_type,
               v.make      AS vehicle_make,
               v.model     AS vehicle_model,
               v.color     AS vehicle_color,
               v.has_ac    AS vehicle_has_ac
        FROM rides r
        LEFT JOIN (
            SELECT ride_id, SUM(seats) AS booked_seats
            FROM bookings WHERE status IN ('confirmed','completed')
            GROUP BY ride_id
        ) b ON b.ride_id = r.id
        JOIN users u ON u.id = r.driver_id
        LEFT JOIN vehicles v ON v.id = r.vehicle_id
        $whereStr
        ORDER BY $orderBy
    ");
    $stmt->execute($params);
    json_ok(['rides' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// POST /rides — create ride (driver only)
if ($method === 'POST' && $action === '') {
    $user = require_driver();
    $b    = require_fields(['from_location', 'to_location', 'departure_time', 'price', 'available_seats']);
    $pdo  = db();

    // Pick default vehicle for this driver
    $vStmt = $pdo->prepare("SELECT id FROM vehicles WHERE user_id = ? LIMIT 1");
    $vStmt->execute([$user['id']]);
    $vehicleId = $vStmt->fetchColumn() ?: null;

    $pdo->prepare("
        INSERT INTO rides (driver_id, vehicle_id, from_location, to_location, departure_time, price, available_seats, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $user['id'], $vehicleId,
        $b['from_location'], $b['to_location'],
        $b['departure_time'], (float)$b['price'],
        (int)$b['available_seats'],
        $b['notes'] ?? null,
    ]);

    $rideId2 = (int)$pdo->lastInsertId();
    $stmt    = $pdo->prepare("SELECT * FROM rides WHERE id = ?");
    $stmt->execute([$rideId2]);
    json_ok(['ride' => $stmt->fetch(PDO::FETCH_ASSOC)], 201);
}

// GET /rides/my — rides for the authenticated user
if ($method === 'GET' && $action === 'my') {
    $user = auth_user();
    $pdo  = db();

    if ($user['is_driver']) {
        $stmt = $pdo->prepare("
            SELECT r.*,
                   (r.available_seats - COALESCE(b.cnt,0)) AS seats_left,
                   COALESCE(b.cnt, 0) AS booked_count
            FROM rides r
            LEFT JOIN (SELECT ride_id, SUM(seats) cnt FROM bookings WHERE status IN ('confirmed','completed') GROUP BY ride_id) b
              ON b.ride_id = r.id
            WHERE r.driver_id = ?
            ORDER BY r.departure_time DESC
        ");
        $stmt->execute([$user['id']]);
        json_ok(['rides' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'role' => 'driver']);
    } else {
        $stmt = $pdo->prepare("
            SELECT r.*, bk.id AS booking_id, bk.status AS booking_status,
                   bk.seats AS booked_seats, bk.paid_amount,
                   u.username AS driver_name, u.score AS driver_score, u.picture AS driver_pic
            FROM rides r
            JOIN bookings bk ON bk.ride_id = r.id
            JOIN users u ON u.id = r.driver_id
            WHERE bk.passenger_id = ?
            ORDER BY r.departure_time DESC
        ");
        $stmt->execute([$user['id']]);
        json_ok(['rides' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'role' => 'passenger']);
    }
}

// GET /rides/{id} — ride detail
if ($method === 'GET' && $rideId !== null && $subAction === '') {
    $user = auth_user();
    $pdo  = db();
    $stmt = $pdo->prepare("
        SELECT r.*,
               u.username AS driver_name, u.score AS driver_score, u.picture AS driver_pic,
               u.phone AS driver_phone,
               v.type AS vehicle_type, v.make, v.model, v.color, v.plate_number,
               v.has_ac, v.seats AS vehicle_seats
        FROM rides r
        JOIN users u ON u.id = r.driver_id
        LEFT JOIN vehicles v ON v.id = r.vehicle_id
        WHERE r.id = ?
    ");
    $stmt->execute([$rideId]);
    $ride = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ride) json_error('Ride not found', 404);

    // Booked passengers
    $pStmt = $pdo->prepare("
        SELECT bk.id, bk.seats, bk.status, bk.paid_amount,
               p.id AS passenger_id, p.username AS passenger_name, p.score AS passenger_score, p.picture AS passenger_pic
        FROM bookings bk
        JOIN users p ON p.id = bk.passenger_id
        WHERE bk.ride_id = ? AND bk.status IN ('confirmed','pending')
    ");
    $pStmt->execute([$rideId]);
    $ride['passengers'] = $pStmt->fetchAll(PDO::FETCH_ASSOC);

    json_ok(['ride' => $ride]);
}

// POST /rides/{id}/depart
if ($method === 'POST' && $rideId !== null && $subAction === 'depart') {
    $user = require_driver();
    $pdo  = db();
    $stmt = $pdo->prepare("UPDATE rides SET status='full' WHERE id = ? AND driver_id = ? AND status = 'open'");
    $stmt->execute([$rideId, $user['id']]);
    if ($stmt->rowCount() === 0) json_error('Cannot update this ride', 400);

    // Notify confirmed passengers
    $pStmt = $pdo->prepare("
        SELECT bk.passenger_id FROM bookings bk WHERE bk.ride_id = ? AND bk.status = 'confirmed'
    ");
    $pStmt->execute([$rideId]);
    $ins = $pdo->prepare("INSERT INTO notifications (user_id, type, title, body) VALUES (?, 'ride', ?, ?)");
    foreach ($pStmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $ins->execute([$pid, 'Ride departed', "Your driver has departed. Have a safe journey!"]);
    }
    json_ok(['message' => 'Ride marked as departed']);
}

// POST /rides/{id}/arrive
if ($method === 'POST' && $rideId !== null && $subAction === 'arrive') {
    $user = require_driver();
    $pdo  = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE rides SET status='completed' WHERE id = ? AND driver_id = ?");
    $stmt->execute([$rideId, $user['id']]);

    // Complete all confirmed bookings and credit driver
    $bkStmt = $pdo->prepare("
        SELECT * FROM bookings WHERE ride_id = ? AND status = 'confirmed'
    ");
    $bkStmt->execute([$rideId]);
    $bookings = $bkStmt->fetchAll(PDO::FETCH_ASSOC);

    $total = 0;
    foreach ($bookings as $bk) {
        $pdo->prepare("UPDATE bookings SET status='completed' WHERE id=?")->execute([$bk['id']]);
        $total += (float)$bk['paid_amount'];
        $pdo->prepare("INSERT INTO notifications (user_id, type, title, body) VALUES (?, 'ride', ?, ?)")
            ->execute([$bk['passenger_id'], 'Ride completed', 'Your ride has been completed. Please rate your driver!']);
    }

    // Credit driver earnings
    if ($total > 0) {
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$total, $user['id']]);
        $pdo->prepare("INSERT INTO payments (user_id, amount, type, description, ref_id) VALUES (?, ?, 'earning', 'Ride earnings', ?)")
            ->execute([$user['id'], $total, $rideId]);
    }
    $pdo->commit();
    json_ok(['message' => 'Ride completed', 'earnings' => $total]);
}

// GET /rides/{id}/receipt
if ($method === 'GET' && $rideId !== null && $subAction === 'receipt') {
    $user = auth_user();
    $pdo  = db();
    $stmt = $pdo->prepare("
        SELECT r.*, u.username AS driver_name, v.type AS vehicle_type, v.make, v.model
        FROM rides r
        JOIN users u ON u.id = r.driver_id
        LEFT JOIN vehicles v ON v.id = r.vehicle_id
        WHERE r.id = ?
    ");
    $stmt->execute([$rideId]);
    $ride = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ride) json_error('Ride not found', 404);

    $bkStmt = $pdo->prepare("SELECT * FROM bookings WHERE ride_id = ? AND passenger_id = ? ORDER BY created_at DESC LIMIT 1");
    $bkStmt->execute([$rideId, $user['id']]);
    $booking = $bkStmt->fetch(PDO::FETCH_ASSOC);

    json_ok(['ride' => $ride, 'booking' => $booking]);
}

// POST /rides/{id}/cancel OR DELETE /rides/{id} — cancel ride
if (($method === 'POST' && $rideId !== null && $subAction === 'cancel') ||
    ($method === 'DELETE' && $rideId !== null && $subAction === '')) {
    $user = require_driver();
    $pdo  = db();
    $stmt = $pdo->prepare("UPDATE rides SET status='cancelled' WHERE id = ? AND driver_id = ? AND status = 'open'");
    $stmt->execute([$rideId, $user['id']]);
    if ($stmt->rowCount() === 0) json_error('Cannot cancel this ride', 400);

    // Refund confirmed passengers
    $bkStmt = $pdo->prepare("SELECT * FROM bookings WHERE ride_id = ? AND status IN ('pending','confirmed')");
    $bkStmt->execute([$rideId]);
    $refundStmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
    $notifStmt  = $pdo->prepare("INSERT INTO notifications (user_id, type, title, body) VALUES (?, 'ride', ?, ?)");
    foreach ($bkStmt->fetchAll(PDO::FETCH_ASSOC) as $bk) {
        $pdo->prepare("UPDATE bookings SET status='cancelled' WHERE id=?")->execute([$bk['id']]);
        if ((float)$bk['paid_amount'] > 0) {
            $refundStmt->execute([$bk['paid_amount'], $bk['passenger_id']]);
        }
        $notifStmt->execute([$bk['passenger_id'], 'Ride cancelled', 'The driver has cancelled the ride. Your balance has been refunded.']);
    }
    json_ok(['message' => 'Ride cancelled']);
}

json_error('Not found', 404);
