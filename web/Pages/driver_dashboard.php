<?php
require_once '../server/session.php';
require_once '../server/language.php';
require_once '../classes/rides.php';

requireDriver();

$db     = getDB();
$userId = (int)$_SESSION['user_data']['id'];
$ride   = new Ride($db);

// ── POST actions ─────────────────────────────────────────────────────────────
$actionMsg  = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel_ride') {
        $rideId = (int)($_POST['ride_id'] ?? 0);
        if ($rideId > 0 && $ride->cancelRide($rideId, $userId)) {
            $actionMsg  = 'Ride cancelled successfully.';
            $actionType = 'success';
        } else {
            $actionMsg  = 'Could not cancel ride.';
            $actionType = 'danger';
        }
    }

    if ($action === 'complete_booking') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        if ($bookingId > 0 && $ride->completeBooking($bookingId, $userId)) {
            $actionMsg  = 'Booking marked as completed.';
            $actionType = 'success';
        } else {
            $actionMsg  = 'Could not complete booking.';
            $actionType = 'danger';
        }
    }
}

// ── Load driver profile KPIs ──────────────────────────────────────────────────
$profile = ['avg_rating' => 0, 'total_trips' => 0, 'total_earnings' => 0, 'reliability' => 0];
try {
    $stmt = $db->prepare("SELECT avg_rating, total_trips, total_earnings, reliability FROM driver_profiles WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $profile = $row;
    }
} catch (Exception $e) {
    error_log("driver_dashboard: profile query — " . $e->getMessage());
}

// ── Active rides ──────────────────────────────────────────────────────────────
$allRides    = $ride->getDriverRides($userId);
$activeRides = array_filter($allRides, fn($r) => $r['status'] === 'active');

// ── Recent bookings (last 10, show 10) ────────────────────────────────────────
$recentBookings = [];
try {
    $stmt = $db->prepare(
        "SELECT bk.*, r.from_location, r.to_location,
                p.username AS passenger_name
         FROM bookings bk
         JOIN rides r ON r.id = bk.ride_id
         JOIN users p ON p.id = bk.passenger_id
         WHERE r.driver_id = ?
         ORDER BY bk.created_at DESC
         LIMIT 10"
    );
    $stmt->execute([$userId]);
    $recentBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("driver_dashboard: recent bookings query — " . $e->getMessage());
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function renderStars(float $rating): string {
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        $html .= $i <= round($rating)
            ? '<i class="fas fa-star text-warning"></i>'
            : '<i class="far fa-star text-warning"></i>';
    }
    return $html;
}

function bookingStatusBadge(string $status): string {
    return match($status) {
        'confirmed'  => '<span class="status-badge bg-success text-white">Confirmed</span>',
        'completed'  => '<span class="status-badge bg-primary text-white">Completed</span>',
        'cancelled'  => '<span class="status-badge bg-danger text-white">Cancelled</span>',
        'pending'    => '<span class="status-badge bg-warning text-dark">Pending</span>',
        default      => '<span class="status-badge bg-secondary text-white">' . htmlspecialchars($status) . '</span>',
    };
}

$pageTitle = 'Driver Dashboard';
require_once '../include/header.php';
require_once '../include/sidebar.php';
?>

<div class="fd-main">

    <!-- Page header -->
    <div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <div>
            <h2 class="mb-0"><i class="fas fa-chart-line me-2"></i>Driver Dashboard</h2>
            <small class="text-muted">Welcome back, <?= htmlspecialchars($_SESSION['user_data']['username'] ?? 'Driver') ?></small>
        </div>
        <a href="offre_ride.php" class="btn btn-fd-primary">
            <i class="fas fa-plus-circle me-1"></i> New Ride
        </a>
    </div>

    <?php if ($actionMsg): ?>
    <div class="alert alert-fd-<?= $actionType ?> alert-dismissible fade show mb-4" role="alert">
        <?= htmlspecialchars($actionMsg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ── KPI Stat Cards ── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card text-center">
                <div class="stat-icon"><i class="fas fa-road fa-2x text-primary"></i></div>
                <div class="stat-value"><?= (int)$profile['total_trips'] ?></div>
                <div class="stat-label">Total Trips</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card text-center">
                <div class="stat-icon"><i class="fas fa-wallet fa-2x text-success"></i></div>
                <div class="stat-value"><?= number_format((float)$profile['total_earnings'], 2) ?> <small>DZD</small></div>
                <div class="stat-label">Total Earnings</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card text-center">
                <div class="stat-icon mb-1"><i class="fas fa-star fa-2x text-warning"></i></div>
                <div class="stat-value"><?= number_format((float)$profile['avg_rating'], 1) ?></div>
                <div class="d-flex justify-content-center mb-1">
                    <?= renderStars((float)$profile['avg_rating']) ?>
                </div>
                <div class="stat-label">Avg Rating</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card text-center">
                <div class="stat-icon"><i class="fas fa-shield-alt fa-2x text-info"></i></div>
                <div class="stat-value"><?= number_format((float)$profile['reliability'], 0) ?>%</div>
                <div class="stat-label">Reliability</div>
            </div>
        </div>
    </div>

    <!-- ── Active Rides ── -->
    <div class="fd-card mb-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="mb-0"><i class="fas fa-car me-2 text-primary"></i>My Active Rides</h5>
            <span class="badge bg-primary rounded-pill"><?= count($activeRides) ?></span>
        </div>

        <?php if (empty($activeRides)): ?>
        <div class="empty-state py-4">
            <i class="fas fa-car-side fa-3x text-muted mb-3 d-block text-center"></i>
            <p class="text-center text-muted mb-3">You have no active rides right now.</p>
            <div class="text-center">
                <a href="offre_ride.php" class="btn btn-fd-primary btn-sm">
                    <i class="fas fa-plus-circle me-1"></i> Create a Ride
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($activeRides as $r): ?>
            <div class="col-12 col-md-6">
                <div class="ride-item d-flex flex-column gap-2 h-100">
                    <div class="d-flex align-items-start justify-content-between gap-2">
                        <div>
                            <div class="fw-semibold">
                                <i class="fas fa-map-marker-alt text-danger me-1"></i>
                                <?= htmlspecialchars($r['from_location']) ?>
                                <i class="fas fa-arrow-right mx-1 text-muted"></i>
                                <?= htmlspecialchars($r['to_location']) ?>
                            </div>
                            <small class="text-muted">
                                <i class="far fa-clock me-1"></i>
                                <?= htmlspecialchars($r['departure_time']) ?>
                            </small>
                        </div>
                        <span class="status-badge bg-success text-white flex-shrink-0">Active</span>
                    </div>
                    <div class="d-flex gap-3 text-muted small">
                        <span><i class="fas fa-tag me-1 text-success"></i><?= number_format((float)$r['price'], 2) ?> DZD</span>
                        <span><i class="fas fa-users me-1 text-info"></i><?= (int)$r['seats_left'] ?> / <?= (int)$r['available_seats'] ?> seats left</span>
                        <span><i class="fas fa-ticket-alt me-1 text-warning"></i><?= (int)$r['booked_count'] ?> booked</span>
                    </div>
                    <div class="d-flex gap-2 mt-auto pt-1">
                        <a href="booking_requests.php" class="btn btn-fd-outline btn-sm flex-fill">
                            <i class="fas fa-inbox me-1"></i> Requests
                        </a>
                        <form method="POST" class="flex-fill" onsubmit="return confirm('Cancel this ride? All pending bookings will be affected.')">
                            <input type="hidden" name="action" value="cancel_ride">
                            <input type="hidden" name="ride_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn btn-fd-danger btn-sm w-100">
                                <i class="fas fa-times-circle me-1"></i> Cancel
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Recent Bookings ── -->
    <div class="fd-card">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="mb-0"><i class="fas fa-list-alt me-2 text-primary"></i>Recent Bookings</h5>
            <a href="booking_requests.php" class="btn btn-fd-outline btn-sm">View All</a>
        </div>

        <?php if (empty($recentBookings)): ?>
        <div class="empty-state py-4">
            <i class="fas fa-inbox fa-3x text-muted mb-3 d-block text-center"></i>
            <p class="text-center text-muted">No bookings yet.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Passenger</th>
                        <th class="d-none d-sm-table-cell">Route</th>
                        <th class="d-none d-md-table-cell">Date</th>
                        <th>Status</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end d-none d-md-table-cell">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentBookings as $bk): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($bk['passenger_name']) ?></div>
                            <small class="text-muted d-sm-none">
                                <?= htmlspecialchars($bk['from_location']) ?> → <?= htmlspecialchars($bk['to_location']) ?>
                            </small>
                        </td>
                        <td class="d-none d-sm-table-cell small text-muted">
                            <?= htmlspecialchars($bk['from_location']) ?>
                            <i class="fas fa-arrow-right mx-1"></i>
                            <?= htmlspecialchars($bk['to_location']) ?>
                        </td>
                        <td class="d-none d-md-table-cell small text-muted">
                            <?= htmlspecialchars(substr($bk['created_at'] ?? '', 0, 16)) ?>
                        </td>
                        <td><?= bookingStatusBadge($bk['status']) ?></td>
                        <td class="text-end fw-semibold text-success">
                            <?= number_format((float)($bk['paid_amount'] ?? 0), 2) ?> DZD
                        </td>
                        <td class="text-end d-none d-md-table-cell">
                            <?php if ($bk['status'] === 'confirmed'): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="complete_booking">
                                <input type="hidden" name="booking_id" value="<?= (int)$bk['id'] ?>">
                                <button type="submit" class="btn btn-fd-success btn-sm">
                                    <i class="fas fa-check me-1"></i> Complete
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
