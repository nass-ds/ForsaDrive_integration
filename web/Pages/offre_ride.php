<?php
require_once '../server/session.php';
require_once '../server/language.php';
require_once '../classes/rides.php';
require_once '../classes/users.php';

requireRegularUser();

$db          = getDB();
$currentUser = getCurrentUser();

if (!$currentUser || !$currentUser->isDriver()) {
    header('Location: interface.php');
    exit();
}

$ride    = new Ride($db);
$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $from       = trim($_POST['departure_point'] ?? '');
    $to         = trim($_POST['destination'] ?? '');
    $date       = trim($_POST['ride_date'] ?? '');
    $time       = trim($_POST['departure_time'] ?? '');
    $seats      = (int)($_POST['available_seats'] ?? 0);
    $price      = (float)($_POST['price_per_seat'] ?? 0);

    if (!$from)           $errors[] = 'Departure point is required.';
    if (!$to)             $errors[] = 'Destination is required.';
    if (!$date)           $errors[] = 'Date is required.';
    if (!$time)           $errors[] = 'Time is required.';
    if ($seats < 1 || $seats > 8) $errors[] = 'Seats must be between 1 and 8.';
    if ($price <= 0)      $errors[] = 'Price must be greater than 0.';
    if ($date < date('Y-m-d')) $errors[] = 'Departure date cannot be in the past.';

    if (empty($errors)) {
        if ($ride->createRide($currentUser->getId(), $from, $to, $date, $time, $seats, $price)) {
            $success = "Ride created successfully! Passengers can now book it.";
            // Reset form
            $from = $to = $date = $time = '';
            $seats = $price = '';
        } else {
            $errors[] = "Failed to create ride. Please try again.";
        }
    }
}

// Show driver's existing rides
$myRides = $ride->getDriverRides($currentUser->getId());

$pageTitle = 'Offer a Ride';
include '../include/header.php';
include '../include/sidebar.php';
?>

<div class="fd-main">
  <div class="page-header">
    <h1><i class="fas fa-plus-circle me-2 text-primary"></i>Offer a Ride</h1>
  </div>

  <?php if ($success): ?>
    <div class="alert-fd-success"><i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert-fd-danger"><i class="fas fa-exclamation-circle me-2"></i><?= implode('<br>', $errors) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Create form -->
    <div class="col-12 col-lg-5">
      <div class="fd-card">
        <div class="card-title"><i class="fas fa-car text-primary"></i> New Ride Details</div>
        <form method="POST">
          <div class="mb-3">
            <label class="fd-form-label">Departure Point *</label>
            <input type="text" name="departure_point" class="form-control"
                   placeholder="e.g. Tunis Centre" value="<?= htmlspecialchars($from ?? '') ?>" required>
          </div>
          <div class="mb-3">
            <label class="fd-form-label">Destination *</label>
            <input type="text" name="destination" class="form-control"
                   placeholder="e.g. Sousse" value="<?= htmlspecialchars($to ?? '') ?>" required>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="fd-form-label">Date *</label>
              <input type="date" name="ride_date" class="form-control"
                     min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($date ?? '') ?>" required>
            </div>
            <div class="col-6">
              <label class="fd-form-label">Time *</label>
              <input type="time" name="departure_time" class="form-control"
                     value="<?= htmlspecialchars($time ?? '') ?>" required>
            </div>
          </div>
          <div class="row g-2 mt-1">
            <div class="col-6">
              <label class="fd-form-label">Available Seats *</label>
              <input type="number" name="available_seats" class="form-control"
                     min="1" max="8" placeholder="1–8" value="<?= htmlspecialchars($seats ?? '') ?>" required>
            </div>
            <div class="col-6">
              <label class="fd-form-label">Price / Seat (TND) *</label>
              <input type="number" name="price_per_seat" class="form-control"
                     step="0.50" min="0.50" placeholder="0.00" value="<?= htmlspecialchars($price ?? '') ?>" required>
            </div>
          </div>
          <button type="submit" class="btn-fd-primary btn mt-3 w-100">
            <i class="fas fa-plus me-1"></i>Create Ride
          </button>
        </form>
      </div>
    </div>

    <!-- My rides list -->
    <div class="col-12 col-lg-7">
      <div class="fd-card">
        <div class="card-title"><i class="fas fa-list text-primary"></i> My Rides (<?= count($myRides) ?>)</div>
        <?php if (empty($myRides)): ?>
          <div class="empty-state"><i class="fas fa-car"></i><p>No rides yet — create your first one!</p></div>
        <?php else: ?>
          <?php foreach ($myRides as $r):
            $dt = new DateTime($r['departure_time']);
            $isPast = $r['departure_time'] < date('Y-m-d H:i:s');
          ?>
          <div class="ride-item <?= $isPast || $r['status']==='cancelled' ? 'opacity-75' : '' ?>"
               style="border-left-color: <?= $r['status']==='cancelled' ? 'var(--fd-danger)' : 'var(--fd-primary)' ?>">
            <div class="d-flex justify-content-between flex-wrap gap-2">
              <div>
                <div class="route">
                  <i class="fas fa-map-marker-alt text-danger me-1"></i><?= htmlspecialchars($r['from_location']) ?>
                  <i class="fas fa-arrow-right mx-2 text-muted small"></i>
                  <i class="fas fa-map-marker text-success me-1"></i><?= htmlspecialchars($r['to_location']) ?>
                </div>
                <div class="meta mt-1"><i class="fas fa-clock me-1"></i><?= $dt->format('D, M j, Y \a\t g:i A') ?></div>
                <div class="meta">
                  <i class="fas fa-users me-1"></i><?= $r['booked_count'] ?>/<?= $r['available_seats'] ?> booked
                  &nbsp;·&nbsp;<span class="status-badge <?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span>
                </div>
              </div>
              <div class="text-end">
                <div class="price"><?= number_format($r['price'], 2) ?> TND</div>
                <?php if ($r['status'] === 'active' && !$isPast): ?>
                <form method="POST" action="driver_dashboard.php" onsubmit="return confirm('Cancel this ride?')" class="mt-1">
                  <input type="hidden" name="action" value="cancel_ride">
                  <input type="hidden" name="ride_id" value="<?= $r['id'] ?>">
                  <button class="btn-fd-danger btn btn-sm"><i class="fas fa-times me-1"></i>Cancel</button>
                </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
