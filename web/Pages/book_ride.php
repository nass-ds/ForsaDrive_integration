<?php
require_once '../server/session.php';
require_once '../server/language.php';
require_once '../classes/rides.php';
require_once '../classes/bookings.php';
require_once '../classes/notifications.php';
require_once '../classes/profileaccess.php';

requireRegularUser();

$db   = getDB();
$ride = new Ride($db);
Booking::setPDO($db);
$notif = new Notifications($db);

$ud      = $_SESSION['user_data'];
$uid     = $ud['id'];
$isStudent = !empty($ud['is_student']);

$errors = [];
$success = '';
$selectedRide = null;
// Pre-booking, only a limited driver profile is exposed; once the booking is
// confirmed / paid, full ride-related details become visible. Backend gates
// both via ProfileAccessService.
$driverLimited = null;
$driverFull    = null;
$existingBooking = null;

// Load a specific ride for booking
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $rideId = (int)$_GET['id'];
    if ($ride->load($rideId)) {
        $bookedSoFar = $ride->getBookedSeats($rideId);
        $seatsLeft   = $ride->getAvailableSeats() - $bookedSoFar;
        $selectedRide = [
            'id'             => $ride->getId(),
            'from_location'  => $ride->getFromLocation(),
            'to_location'    => $ride->getToLocation(),
            'departure_time' => $ride->getDepartureTime(),
            'price'          => $ride->getPrice(),
            'available_seats'=> $ride->getAvailableSeats(),
            'seats_left'     => max(0, $seatsLeft),
            'driver_id'      => $ride->getDriverId(),
        ];
        if ($ride->getDriverId() === $uid) {
            $errors[] = "You cannot book your own ride.";
            $selectedRide = null;
        }

        if ($selectedRide) {
            $driverRow = $db->prepare("SELECT * FROM users WHERE id = ?");
            $driverRow->execute([$ride->getDriverId()]);
            $driver = $driverRow->fetch(PDO::FETCH_ASSOC);
            if ($driver) {
                // Identification spec: limited profile is always available.
                $driverLimited = ProfileAccessService::limitedProfile($db, $driver);
                // If the passenger already has a confirmed/active booking for this
                // ride, the full ride-related profile may also be shown.
                $viewer = $db->prepare("SELECT * FROM users WHERE id=?");
                $viewer->execute([$uid]);
                $viewerRow = $viewer->fetch(PDO::FETCH_ASSOC);
                if (ProfileAccessService::canViewFull($db, $viewerRow, $driver, (int)$selectedRide['id'])) {
                    $driverFull = ProfileAccessService::fullProfile($db, $driver, (int)$selectedRide['id']);
                }
            }
            $bk = $db->prepare("SELECT id, status FROM bookings WHERE ride_id=? AND passenger_id=? ORDER BY id DESC LIMIT 1");
            $bk->execute([$selectedRide['id'], $uid]);
            $existingBooking = $bk->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } else {
        $errors[] = "Ride not found.";
    }
}

// Handle booking submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ride_id'])) {
    $rideId = (int)$_POST['ride_id'];
    $seats  = max(1, (int)($_POST['seats'] ?? 1));

    if ($ride->load($rideId)) {
        if ($ride->getDriverId() === $uid) {
            $errors[] = "You cannot book your own ride.";
        } else {
            $bookedSoFar = $ride->getBookedSeats($rideId);
            $seatsLeft   = $ride->getAvailableSeats() - $bookedSoFar;
            $price       = $ride->getPrice();
            $finalPrice  = $isStudent ? $price * 0.5 : $price;
            $totalAmount = $finalPrice * $seats;

            if ($seats > $seatsLeft) {
                $errors[] = "Only $seatsLeft seat(s) available.";
            } elseif (($ud['balance'] ?? 0) < $totalAmount) {
                $errors[] = "Insufficient balance. You need " . number_format($totalAmount, 2) . " TND. <a href='payments.php'>Top up here</a>.";
            } else {
                $booking = new Booking();
                try {
                    $booking->setRideId($rideId)
                            ->setPassengerId($uid)
                            ->setSeats($seats)
                            ->setPaidAmount($totalAmount)
                            ->setStatus('pending');

                    if ($booking->save()) {
                        // Deduct balance
                        $db->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")->execute([$totalAmount, $uid]);
                        $_SESSION['user_data']['balance'] = ($ud['balance'] ?? 0) - $totalAmount;

                        // Notify driver
                        $driverId = $ride->getDriverId();
                        $notif->create($driverId, 'New Booking Request',
                            "{$ud['username']} wants {$seats} seat(s) on your ride from {$ride->getFromLocation()} to {$ride->getToLocation()}.",
                            'info', 'booking_requests.php');

                        $success = "Booking submitted! Waiting for driver confirmation.";
                        $selectedRide = null;
                    } else {
                        $errors[] = "Booking failed — please try again.";
                    }
                } catch (Exception $e) {
                    $errors[] = "Booking error: " . htmlspecialchars($e->getMessage());
                }
            }
        }
    } else {
        $errors[] = "Ride not found.";
    }
}

// Search / list available rides
$filters = [
    'from'  => trim($_GET['from']  ?? ''),
    'to'    => trim($_GET['to']    ?? ''),
    'date'  => trim($_GET['date']  ?? ''),
    'seats' => trim($_GET['seats'] ?? ''),
    'sort'  => trim($_GET['sort']  ?? ''),
];
$availableRides = $ride->getAvailableRides($filters);
$hasFilter = $filters['from'] || $filters['to'] || $filters['date'] || $filters['seats'];

$pageTitle = 'Book a Ride';
include '../include/header.php';
include '../include/sidebar.php';
?>

<div class="fd-main">
  <div class="page-header">
    <h1><i class="fas fa-search me-2 text-primary"></i>Book a Ride</h1>
  </div>

  <?php if ($success): ?>
  <div class="alert-fd-success"><i class="fas fa-check-circle me-2"></i><?= $success ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
  <div class="alert-fd-danger"><i class="fas fa-exclamation-circle me-2"></i><?= implode('<br>', $errors) ?></div>
  <?php endif; ?>

  <?php if ($selectedRide): ?>
  <!-- Booking form for selected ride -->
  <?php
    $dt = new DateTime($selectedRide['departure_time']);
    $finalPrice = $isStudent ? $selectedRide['price'] * 0.5 : $selectedRide['price'];
  ?>

  <!-- Driver profile — limited until booking is confirmed; full afterwards -->
  <?php if ($driverLimited): ?>
  <div class="fd-card mb-3" style="border-left:4px solid <?= $driverFull ? '#16a34a' : '#3730a3' ?>">
    <div class="d-flex align-items-start gap-3">
      <img src="<?= htmlspecialchars($driverLimited['picture'] ?: '../Src/default.jpg') ?>"
           onerror="this.src='../Src/default.jpg'" alt=""
           style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid #e5e7eb">
      <div class="flex-grow-1">
        <div class="d-flex align-items-center flex-wrap gap-2">
          <strong style="font-size:1.05rem">
            <?= htmlspecialchars($driverFull['full_name'] ?? $driverLimited['first_name']) ?>
          </strong>
          <span class="d-inline-flex align-items-center gap-1 px-2 py-1 rounded-pill"
                style="background:#eef2ff;color:#3730a3;font-size:.72rem;font-weight:600;border:1px solid #c7d2fe;font-family:'Segoe UI Mono','Consolas',monospace;">
            <i class="fas fa-id-badge"></i><?= htmlspecialchars($driverLimited['public_id']) ?>
          </span>
          <?php if (!empty($driverLimited['is_verified'])): ?>
            <span class="badge bg-success" title="Driver verified by ForsaDrive admin">
              <i class="fas fa-shield-alt me-1"></i>Verified
            </span>
          <?php endif; ?>
        </div>
        <div class="text-muted small mt-1">
          <i class="fas fa-star text-warning"></i> <?= number_format($driverLimited['score'], 1) ?>
          <span class="mx-1">·</span>
          <?= (int)$driverLimited['completed_rides'] ?> completed ride<?= $driverLimited['completed_rides']===1?'':'s' ?>
          <?php if (!empty($driverLimited['vehicle']['make']) || !empty($driverLimited['vehicle']['model'])): ?>
            <span class="mx-1">·</span>
            <i class="fas fa-car me-1"></i>
            <?= htmlspecialchars(trim(($driverLimited['vehicle']['make'] ?? '') . ' ' . ($driverLimited['vehicle']['model'] ?? ''))) ?>
            <?php if ($driverFull && !empty($driverFull['vehicle']['plate'])): ?>
              · <span class="badge" style="background:#fff7ed;color:#9a3412;border:1px solid #fed7aa">
                <i class="fas fa-id-card"></i> <?= htmlspecialchars($driverFull['vehicle']['plate']) ?>
              </span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php if ($driverFull): ?>
          <div class="small mt-2" style="color:#15803d">
            <i class="fas fa-unlock me-1"></i>
            Full ride profile is visible because your booking is confirmed.
            <?php if (!empty($driverFull['access_expires_at'])): ?>
              <span class="text-muted">(Access ends <?= htmlspecialchars($driverFull['access_expires_at']) ?> UTC)</span>
            <?php endif; ?>
          </div>
          <div class="small mt-1 text-muted">
            <i class="fas fa-phone me-1"></i><?= htmlspecialchars($driverFull['phone'] ?? '—') ?>
          </div>
        <?php else: ?>
          <div class="small text-muted mt-2">
            <i class="fas fa-lock me-1"></i>
            Limited profile — full driver details (full name, phone, plate) become available after booking confirmation.
          </div>
        <?php endif; ?>
        <div class="mt-2">
          <a class="btn btn-sm btn-outline-danger"
             href="report_user.php?public_id=<?= urlencode($driverLimited['public_id']) ?>&ride_id=<?= (int)$selectedRide['id'] ?>">
            <i class="fas fa-flag me-1"></i>Report this user
          </a>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="fd-card mb-3">
    <div class="card-title"><i class="fas fa-car text-primary"></i> Confirm Booking</div>
    <div class="ride-item mb-3">
      <div class="route">
        <i class="fas fa-map-marker-alt text-danger me-1"></i><?= htmlspecialchars($selectedRide['from_location']) ?>
        <i class="fas fa-arrow-right mx-2 text-muted small"></i>
        <i class="fas fa-map-marker text-success me-1"></i><?= htmlspecialchars($selectedRide['to_location']) ?>
      </div>
      <div class="meta mt-1"><i class="fas fa-clock me-1"></i><?= $dt->format('D, M j, Y \a\t g:i A') ?></div>
      <div class="meta"><i class="fas fa-users me-1"></i><?= $selectedRide['seats_left'] ?> seats available</div>
    </div>
    <form method="POST">
      <input type="hidden" name="ride_id" value="<?= $selectedRide['id'] ?>">
      <div class="row g-3">
        <div class="col-12 col-sm-4">
          <label class="fd-form-label">Seats</label>
          <input type="number" name="seats" class="form-control" min="1" max="<?= $selectedRide['seats_left'] ?>" value="1" id="seatsInput">
        </div>
        <div class="col-12 col-sm-4">
          <label class="fd-form-label">Price per seat</label>
          <div class="form-control-plaintext fw-bold text-primary" id="priceDisplay">
            <?= number_format($finalPrice, 2) ?> TND
            <?php if ($isStudent): ?><span class="text-muted text-decoration-line-through ms-2"><?= number_format($selectedRide['price'], 2) ?></span><?php endif; ?>
          </div>
        </div>
        <div class="col-12 col-sm-4">
          <label class="fd-form-label">Total</label>
          <div class="form-control-plaintext fw-bold fs-5 text-success" id="totalDisplay"><?= number_format($finalPrice, 2) ?> TND</div>
        </div>
      </div>
      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn-fd-primary btn">
          <i class="fas fa-check me-1"></i>Confirm Booking
        </button>
        <a href="book_ride.php" class="btn-fd-outline btn">Cancel</a>
      </div>
    </form>
  </div>
  <script>
  const perSeat = <?= $finalPrice ?>;
  document.getElementById('seatsInput').addEventListener('input', function() {
      document.getElementById('totalDisplay').textContent = (this.value * perSeat).toFixed(2) + ' TND';
  });
  </script>

  <?php else: ?>
  <!-- Search & list -->
  <div class="fd-card mb-3">
    <div class="card-title"><i class="fas fa-filter"></i> Search Filters</div>
    <form method="GET">
      <div class="row g-2">
        <div class="col-12 col-md-3">
          <input class="form-control" name="from" placeholder="From city..." value="<?= htmlspecialchars($filters['from']) ?>">
        </div>
        <div class="col-12 col-md-3">
          <input class="form-control" name="to" placeholder="To city..." value="<?= htmlspecialchars($filters['to']) ?>">
        </div>
        <div class="col-6 col-md-2">
          <input class="form-control" type="date" name="date" value="<?= htmlspecialchars($filters['date']) ?>">
        </div>
        <div class="col-6 col-md-1">
          <input class="form-control" type="number" name="seats" placeholder="Seats" min="1" value="<?= htmlspecialchars($filters['seats']) ?>">
        </div>
        <div class="col-6 col-md-2">
          <select class="form-select" name="sort">
            <option value="">Soonest first</option>
            <option value="price_asc"  <?= $filters['sort']==='price_asc'  ? 'selected':'' ?>>Price ↑</option>
            <option value="price_desc" <?= $filters['sort']==='price_desc' ? 'selected':'' ?>>Price ↓</option>
            <option value="seats"      <?= $filters['sort']==='seats'      ? 'selected':'' ?>>Most seats</option>
          </select>
        </div>
        <div class="col-6 col-md-1 d-flex gap-1">
          <button type="submit" class="btn-fd-primary btn flex-fill">Search</button>
          <?php if ($hasFilter): ?><a href="book_ride.php" class="btn-fd-outline btn"><i class="fas fa-times"></i></a><?php endif; ?>
        </div>
      </div>
    </form>
  </div>

  <div class="fd-card">
    <div class="card-title">
      <i class="fas fa-car text-primary"></i> Available Rides
      <?php if ($hasFilter): ?><span class="badge bg-primary ms-1"><?= count($availableRides) ?></span><?php endif; ?>
    </div>
    <?php if (empty($availableRides)): ?>
      <div class="empty-state">
        <i class="fas fa-car-side"></i>
        <p class="fw-semibold">No rides found</p>
        <p class="small"><?= $hasFilter ? 'Try broader search filters.' : 'No rides available right now.' ?></p>
      </div>
    <?php else: ?>
      <?php foreach ($availableRides as $r):
        $dt = new DateTime($r['departure_time']);
        $fp = $isStudent ? $r['price'] * 0.5 : $r['price'];
      ?>
      <div class="ride-item">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
          <div class="flex-grow-1">
            <div class="route">
              <i class="fas fa-map-marker-alt text-danger me-1"></i><?= htmlspecialchars($r['from_location']) ?>
              <i class="fas fa-arrow-right mx-2 text-muted small"></i>
              <i class="fas fa-map-marker text-success me-1"></i><?= htmlspecialchars($r['to_location']) ?>
            </div>
            <div class="meta mt-1">
              <i class="fas fa-clock me-1"></i><?= $dt->format('D, M j \a\t g:i A') ?>
              &nbsp;·&nbsp;<i class="fas fa-car me-1"></i><?= htmlspecialchars($r['vehicle_type'] ?? 'Car') ?>
            </div>
            <div class="meta">
              <?php
                // Limited preview only: first name + ★, never full name/email/phone here.
                $firstNameOnly = $r['driver_name'] ? preg_split('/\s+/', trim($r['driver_name']))[0] : 'Driver';
              ?>
              <i class="fas fa-user me-1"></i><?= htmlspecialchars($firstNameOnly) ?>
              <span class="stars ms-1 small"><?= str_repeat('★', round($r['driver_score'] ?? 5)) ?></span>
              &nbsp;·&nbsp;<i class="fas fa-users me-1"></i><?= $r['seats_left'] ?> seats
            </div>
            <div class="mt-2">
              <a href="book_ride.php?id=<?= $r['id'] ?>" class="btn-fd-primary btn btn-sm">
                <i class="fas fa-check me-1"></i>Book <?= number_format($fp, 2) ?> TND
                <?php if ($isStudent): ?><span class="ms-1 opacity-75 text-decoration-line-through"><?= number_format($r['price'], 2) ?></span><?php endif; ?>
              </a>
            </div>
          </div>
          <div class="text-end">
            <div class="price"><?= number_format($fp, 2) ?> TND</div>
            <?php if ($isStudent): ?><div class="small text-success">Student 50% off</div><?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
