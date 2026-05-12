<?php
require_once __DIR__ . '/../server/session.php';
require_once __DIR__ . '/../server/language.php';
require_once __DIR__ . '/../classes/rides.php';

requireRegularUser();

$db   = getDB();
$ud   = $_SESSION['user_data'];
$uid  = $ud['id'];
$ride = new Ride($db);

// Get user's governorate for local ride filtering
$userRow = $db->prepare("SELECT governorate, municipality, is_student, student_status FROM users WHERE id=?");
$userRow->execute([$uid]);
$userGeo = $userRow->fetch(PDO::FETCH_ASSOC);
$userGov = $userGeo['governorate'] ?? '';
// Student: only show discount if approved
$isStudentApproved = (!empty($ud['is_student']) && ($userGeo['student_status'] ?? '') === 'approved');

$filters = [
    'from'  => trim($_GET['from']  ?? ''),
    'to'    => trim($_GET['to']    ?? ''),
    'date'  => trim($_GET['date']  ?? ''),
    'seats' => trim($_GET['seats'] ?? ''),
    'sort'  => trim($_GET['sort']  ?? ''),
];
$hasFilter = $filters['from'] || $filters['to'] || $filters['date'] || $filters['seats'];

// Load all available rides
$availableRides = $ride->getAvailableRides($filters);

// If no filter applied, split into "Near You" and "Other"
$localRides = $otherRides = [];
if (!$hasFilter && $userGov) {
    foreach ($availableRides as $r) {
        $fromGov = explode(',', $r['from_location'])[0] ?? '';
        $toGov   = explode(',', $r['to_location'])[0]   ?? '';
        if (stripos($fromGov, $userGov) !== false || stripos($toGov, $userGov) !== false) {
            $localRides[] = $r;
        } else {
            $otherRides[] = $r;
        }
    }
} else {
    $otherRides = $availableRides;
}

$upcomingRides  = $ride->getUpcomingRides($uid);
$completedRides = $ride->getCompletedRidesCount($uid);
$isDriver       = !empty($ud['is_driver']);
$isStudent      = $isStudentApproved;

$pageTitle = 'Dashboard';
include __DIR__ . '/../include/header.php';
include __DIR__ . '/../include/sidebar.php';
?>

<div class="fd-main">
  <!-- Page header -->
  <div class="page-header">
    <div>
      <h1><i class="fas fa-home me-2 text-primary"></i>Dashboard</h1>
      <p class="text-muted mb-0 small">Welcome back, <?= htmlspecialchars($ud['username']) ?>!</p>
    </div>
    <?php if ($isDriver): ?>
    <a href="offre_ride.php" class="btn-fd-primary btn">
      <i class="fas fa-plus me-1"></i>Offer a Ride
    </a>
    <?php endif; ?>
  </div>

  <!-- Stats -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="icon blue"><i class="fas fa-calendar-check"></i></div>
        <div><div class="val"><?= count($upcomingRides) ?></div><div class="lbl">Upcoming</div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="icon green"><i class="fas fa-check-circle"></i></div>
        <div><div class="val"><?= $completedRides ?></div><div class="lbl">Completed</div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="icon amber"><i class="fas fa-wallet"></i></div>
        <div><div class="val"><?= number_format($ud['balance'] ?? 0, 2) ?></div><div class="lbl">Balance</div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="icon purple"><i class="fas fa-star"></i></div>
        <div><div class="val"><?= number_format($ud['score'] ?? 5, 1) ?></div><div class="lbl">Rating</div></div>
      </div>
    </div>
  </div>

  <!-- Search bar -->
  <div class="fd-card mb-4">
    <div class="card-title"><i class="fas fa-search"></i> Search Rides</div>
    <form method="GET" action="">
      <div class="row g-2">
        <div class="col-12 col-md-3">
          <input class="form-control" name="from" placeholder="From..." value="<?= htmlspecialchars($filters['from']) ?>">
        </div>
        <div class="col-12 col-md-3">
          <input class="form-control" name="to" placeholder="To..." value="<?= htmlspecialchars($filters['to']) ?>">
        </div>
        <div class="col-6 col-md-2">
          <input class="form-control" type="date" name="date" value="<?= htmlspecialchars($filters['date']) ?>">
        </div>
        <div class="col-6 col-md-1">
          <input class="form-control" type="number" name="seats" placeholder="Seats" min="1" value="<?= htmlspecialchars($filters['seats']) ?>">
        </div>
        <div class="col-6 col-md-2">
          <select class="form-select" name="sort">
            <option value="">Sort: Time</option>
            <option value="price_asc"  <?= $filters['sort']==='price_asc'  ? 'selected':'' ?>>Price ↑</option>
            <option value="price_desc" <?= $filters['sort']==='price_desc' ? 'selected':'' ?>>Price ↓</option>
            <option value="seats"      <?= $filters['sort']==='seats'      ? 'selected':'' ?>>Seats</option>
          </select>
        </div>
        <div class="col-6 col-md-1 d-flex gap-1">
          <button type="submit" class="btn-fd-primary btn flex-fill"><i class="fas fa-search"></i></button>
          <?php if ($hasFilter): ?>
          <a href="interface.php" class="btn-fd-outline btn"><i class="fas fa-times"></i></a>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>

  <!-- Upcoming rides -->
  <?php if (!empty($upcomingRides)): ?>
  <div class="fd-card mb-4">
    <div class="card-title"><i class="fas fa-calendar-alt text-primary"></i> My Upcoming Rides</div>
    <?php foreach ($upcomingRides as $r):
      $dt = new DateTime($r['departure_time']); ?>
      <div class="ride-item">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
          <div>
            <div class="route">
              <i class="fas fa-map-marker-alt text-danger me-1"></i><?= htmlspecialchars($r['from_location']) ?>
              <i class="fas fa-arrow-right mx-2 text-muted small"></i>
              <i class="fas fa-map-marker text-success me-1"></i><?= htmlspecialchars($r['to_location']) ?>
            </div>
            <div class="meta mt-1">
              <i class="fas fa-clock me-1"></i><?= $dt->format('D, M j, Y \a\t g:i A') ?>
              &nbsp;·&nbsp;<i class="fas fa-user me-1"></i><?= htmlspecialchars($r['driver_name']) ?>
              ★<?= number_format($r['driver_score'] ?? 5, 1) ?>
            </div>
            <div class="mt-1">
              <span class="status-badge <?= htmlspecialchars($r['booking_status'] ?? 'confirmed') ?>">
                <?= ucfirst($r['booking_status'] ?? 'confirmed') ?>
              </span>
              <?php if ($isStudent): ?><span class="status-badge confirmed ms-1">Student 50% off</span><?php endif; ?>
            </div>
          </div>
          <div class="text-end">
            <div class="price"><?= number_format($r['price'], 2) ?> TND</div>
            <?php if ($isStudent): ?>
            <div class="small text-success"><?= number_format($r['price'] * 0.5, 2) ?> TND</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php
  // Render ride card helper
  function rideCard(array $r, bool $isStudent): string {
      $dt = new DateTime($r['departure_time']);
      $price = number_format($r['price'], 2);
      $discPrice = number_format($r['price'] * 0.5, 2);
      ob_start(); ?>
      <div class="ride-item">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
          <div class="flex-grow-1">
            <div class="route">
              <i class="fas fa-map-marker-alt text-danger me-1"></i><?= htmlspecialchars($r['from_location']) ?>
              <i class="fas fa-arrow-right mx-2 text-muted small"></i>
              <i class="fas fa-map-marker text-success me-1"></i><?= htmlspecialchars($r['to_location']) ?>
            </div>
            <div class="meta mt-1">
              <i class="fas fa-clock me-1"></i><?= $dt->format('D, M j, Y \a\t g:i A') ?>
              &nbsp;·&nbsp;<i class="fas fa-car me-1"></i><?= htmlspecialchars($r['vehicle_type'] ?? 'Car') ?>
            </div>
            <div class="meta">
              <i class="fas fa-user me-1"></i><?= htmlspecialchars($r['driver_name']) ?>
              <span class="stars ms-1"><?= str_repeat('★', min(5, (int)round($r['driver_score'] ?? 5))) ?></span>
              <?= number_format($r['driver_score'] ?? 5, 1) ?>
              &nbsp;·&nbsp;<i class="fas fa-users me-1"></i><?= $r['seats_left'] ?> seats left
            </div>
            <div class="mt-2">
              <a href="book_ride.php?id=<?= $r['id'] ?>" class="btn-fd-primary btn btn-sm">
                <i class="fas fa-check me-1"></i>Book Now
              </a>
            </div>
          </div>
          <div class="text-end">
            <div class="price"><?= $price ?> TND</div>
            <?php if ($isStudent): ?>
            <div class="small text-success fw-bold"><?= $discPrice ?> TND</div>
            <div class="small text-muted">Student 50% off</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
  <?php return ob_get_clean();
  }
  ?>

  <!-- Rides near you -->
  <?php if (!$hasFilter && !empty($localRides)): ?>
  <div class="fd-card mb-4">
    <div class="card-title">
      <i class="fas fa-map-marker-alt text-danger"></i>
      Rides Near You
      <span class="badge bg-danger ms-2"><?= count($localRides) ?></span>
      <?php if ($userGov): ?><span class="text-muted small fw-normal ms-2"><?= htmlspecialchars($userGov) ?></span><?php endif; ?>
    </div>
    <?php foreach ($localRides as $r) echo rideCard($r, $isStudent); ?>
  </div>
  <?php endif; ?>

  <!-- All / other rides -->
  <div class="fd-card">
    <div class="card-title">
      <i class="fas fa-car text-primary"></i>
      <?= (!$hasFilter && !empty($localRides)) ? 'Other Rides' : 'Available Rides' ?>
      <?php if ($hasFilter): ?>
        <span class="badge bg-primary ms-2"><?= count($otherRides) ?> results</span>
      <?php endif; ?>
    </div>
    <?php if (empty($otherRides) && empty($localRides)): ?>
      <div class="empty-state">
        <i class="fas fa-car-side"></i>
        <p class="fw-semibold">No rides available</p>
        <p class="small"><?= $hasFilter ? 'Try adjusting your search filters.' : 'Check back soon!' ?></p>
      </div>
    <?php elseif (empty($otherRides) && !empty($localRides)): ?>
      <div class="empty-state"><i class="fas fa-globe"></i><p class="small">No rides outside your area right now.</p></div>
    <?php else: ?>
      <?php foreach ($otherRides as $r) echo rideCard($r, $isStudent); ?>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
