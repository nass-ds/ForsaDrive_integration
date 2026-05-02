<?php
require_once '../server/session.php';
require_once '../server/language.php';
require_once '../classes/rides.php';
require_once '../classes/notifications.php';

// Redirect if not logged in or not a driver
if (!isLoggedIn() || empty($_SESSION['user_data']['is_driver'])) {
    header('Location: interface.php');
    exit();
}

$db     = getDB();
$userId = (int)$_SESSION['user_data']['id'];
$ride   = new Ride($db);
$notif  = new Notifications($db);

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action']     ?? '';
    $bookingId = (int)($_POST['booking_id'] ?? 0);

    if ($bookingId > 0) {
        // Look up the passenger_id before mutating the booking
        $passengerId = 0;
        $rideInfo    = [];
        try {
            $stmt = $db->prepare(
                "SELECT bk.passenger_id, r.from_location, r.to_location
                 FROM bookings bk
                 JOIN rides r ON r.id = bk.ride_id
                 WHERE bk.id = ? AND r.driver_id = ?"
            );
            $stmt->execute([$bookingId, $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $passengerId = (int)$row['passenger_id'];
                $rideInfo    = $row;
            }
        } catch (Exception $e) {
            error_log("booking_requests: lookup — " . $e->getMessage());
        }

        $route = isset($rideInfo['from_location'])
            ? htmlspecialchars($rideInfo['from_location']) . ' → ' . htmlspecialchars($rideInfo['to_location'])
            : 'your ride';

        if ($action === 'accept' && $ride->acceptBooking($bookingId, $userId)) {
            if ($passengerId > 0) {
                $notif->create(
                    $passengerId,
                    'Booking Accepted',
                    "Your booking for $route has been accepted by the driver.",
                    'success',
                    'my_rides.php'
                );
            }
            header('Location: booking_requests.php?msg=accepted');
            exit();
        }

        if ($action === 'reject' && $ride->rejectBooking($bookingId, $userId)) {
            if ($passengerId > 0) {
                $notif->create(
                    $passengerId,
                    'Booking Rejected',
                    "Your booking request for $route was not accepted.",
                    'danger',
                    'book_ride.php'
                );
            }
            header('Location: booking_requests.php?msg=rejected');
            exit();
        }

        if ($action === 'complete' && $ride->completeBooking($bookingId, $userId)) {
            if ($passengerId > 0) {
                $notif->create(
                    $passengerId,
                    'Ride Completed',
                    "Your trip for $route has been marked as completed. Thank you for riding with ForsaDrive!",
                    'success',
                    'ratings.php'
                );
            }
            header('Location: booking_requests.php?msg=completed');
            exit();
        }

        // Action failed — redirect with error
        header('Location: booking_requests.php?msg=error');
        exit();
    }
}

// ── Load pending booking requests ─────────────────────────────────────────────
$pendingBookings = $ride->getDriverBookingRequests($userId);

// ── Load confirmed bookings ───────────────────────────────────────────────────
$confirmedBookings = [];
try {
    $stmt = $db->prepare(
        "SELECT bk.*, r.from_location, r.to_location, r.departure_time, r.price,
                p.username AS passenger_name, p.score AS passenger_score, p.is_student
         FROM bookings bk
         JOIN rides r ON r.id = bk.ride_id
         JOIN users p ON p.id = bk.passenger_id
         WHERE r.driver_id = ?
           AND bk.status = 'confirmed'
         ORDER BY r.departure_time ASC"
    );
    $stmt->execute([$userId]);
    $confirmedBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("booking_requests: confirmed query — " . $e->getMessage());
}

// ── Flash message from redirect ───────────────────────────────────────────────
$msgMap = [
    'accepted'  => ['Booking accepted successfully.',        'success'],
    'rejected'  => ['Booking rejected.',                     'danger'],
    'completed' => ['Ride marked as completed.',             'success'],
    'error'     => ['Action could not be completed.',        'danger'],
];
$flashMsg  = '';
$flashType = '';
if (!empty($_GET['msg']) && isset($msgMap[$_GET['msg']])) {
    [$flashMsg, $flashType] = $msgMap[$_GET['msg']];
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

// Determine active tab from query string (keep tab after redirect)
$activeTab = $_GET['tab'] ?? 'pending';
if (!in_array($activeTab, ['pending', 'confirmed'])) {
    $activeTab = 'pending';
}

$pageTitle = 'Booking Requests';
require_once '../include/header.php';
require_once '../include/sidebar.php';
?>

<div class="fd-main">

    <!-- Page header -->
    <div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <div>
            <h2 class="mb-0">
                <i class="fas fa-inbox me-2"></i>Booking Requests
                <?php if (count($pendingBookings) > 0): ?>
                <span class="badge bg-danger ms-1"><?= count($pendingBookings) ?></span>
                <?php endif; ?>
            </h2>
            <small class="text-muted">Manage passenger booking requests for your rides</small>
        </div>
        <a href="driver_dashboard.php" class="btn btn-fd-outline btn-sm">
            <i class="fas fa-tachometer-alt me-1"></i> Dashboard
        </a>
    </div>

    <?php if ($flashMsg): ?>
    <div class="alert alert-fd-<?= $flashType ?> alert-dismissible fade show mb-4" role="alert">
        <?= htmlspecialchars($flashMsg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ── Bootstrap Tabs ── -->
    <ul class="nav nav-tabs mb-4" id="bookingTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeTab === 'pending' ? 'active' : '' ?>"
                    id="tab-pending"
                    data-bs-toggle="tab"
                    data-bs-target="#panel-pending"
                    type="button" role="tab">
                <i class="fas fa-hourglass-half me-1"></i> Pending
                <?php if (count($pendingBookings) > 0): ?>
                <span class="badge bg-danger ms-1"><?= count($pendingBookings) ?></span>
                <?php endif; ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeTab === 'confirmed' ? 'active' : '' ?>"
                    id="tab-confirmed"
                    data-bs-toggle="tab"
                    data-bs-target="#panel-confirmed"
                    type="button" role="tab">
                <i class="fas fa-check-circle me-1"></i> Confirmed
                <?php if (count($confirmedBookings) > 0): ?>
                <span class="badge bg-success ms-1"><?= count($confirmedBookings) ?></span>
                <?php endif; ?>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="bookingTabsContent">

        <!-- ══ PENDING TAB ══ -->
        <div class="tab-pane fade <?= $activeTab === 'pending' ? 'show active' : '' ?>"
             id="panel-pending" role="tabpanel">

            <?php if (empty($pendingBookings)): ?>
            <div class="empty-state py-5 text-center">
                <i class="fas fa-check-double fa-3x text-muted mb-3 d-block"></i>
                <p class="text-muted mb-0">No pending booking requests. All caught up!</p>
            </div>
            <?php else: ?>
            <div class="row g-3">
                <?php foreach ($pendingBookings as $bk): ?>
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="fd-card h-100 d-flex flex-column gap-3">

                        <!-- Passenger info -->
                        <div class="d-flex align-items-center gap-3">
                            <div class="flex-shrink-0">
                                <?php $pic = !empty($bk['passenger_pic']) ? htmlspecialchars($bk['passenger_pic']) : '../Src/default.jpg'; ?>
                                <img src="<?= $pic ?>"
                                     alt="<?= htmlspecialchars($bk['passenger_name']) ?>"
                                     class="rounded-circle"
                                     style="width:48px;height:48px;object-fit:cover;">
                            </div>
                            <div class="flex-grow-1 overflow-hidden">
                                <div class="fw-semibold text-truncate">
                                    <?= htmlspecialchars($bk['passenger_name']) ?>
                                    <?php if (!empty($bk['is_student'])): ?>
                                    <span class="badge bg-info text-dark ms-1" style="font-size:.65rem;">Student</span>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex align-items-center gap-1" style="font-size:.8rem;">
                                    <?= renderStars((float)($bk['passenger_score'] ?? 0)) ?>
                                    <span class="text-muted ms-1"><?= number_format((float)($bk['passenger_score'] ?? 0), 1) ?></span>
                                </div>
                            </div>
                            <span class="status-badge bg-warning text-dark flex-shrink-0">Pending</span>
                        </div>

                        <!-- Route -->
                        <div class="small">
                            <div class="fw-semibold mb-1">
                                <i class="fas fa-map-marker-alt text-danger me-1"></i>
                                <?= htmlspecialchars($bk['from_location']) ?>
                                <i class="fas fa-arrow-right mx-1 text-muted"></i>
                                <?= htmlspecialchars($bk['to_location']) ?>
                            </div>
                            <div class="text-muted">
                                <i class="far fa-clock me-1"></i><?= htmlspecialchars($bk['departure_time']) ?>
                            </div>
                        </div>

                        <!-- Details -->
                        <div class="d-flex gap-3 small text-muted">
                            <span><i class="fas fa-users me-1 text-primary"></i><?= (int)$bk['seats'] ?> seat<?= $bk['seats'] > 1 ? 's' : '' ?></span>
                            <span><i class="fas fa-money-bill-wave me-1 text-success"></i><?= number_format((float)($bk['paid_amount'] ?? 0), 2) ?> DZD</span>
                            <span class="ms-auto text-muted"><?= htmlspecialchars(substr($bk['created_at'] ?? '', 0, 10)) ?></span>
                        </div>

                        <!-- Action buttons -->
                        <div class="d-flex gap-2 mt-auto">
                            <form method="POST" class="flex-fill">
                                <input type="hidden" name="action" value="accept">
                                <input type="hidden" name="booking_id" value="<?= (int)$bk['id'] ?>">
                                <button type="submit" class="btn btn-fd-success btn-sm w-100">
                                    <i class="fas fa-check me-1"></i> Accept
                                </button>
                            </form>
                            <form method="POST" class="flex-fill"
                                  onsubmit="return confirm('Reject this booking request?')">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="booking_id" value="<?= (int)$bk['id'] ?>">
                                <button type="submit" class="btn btn-fd-danger btn-sm w-100">
                                    <i class="fas fa-times me-1"></i> Reject
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ══ CONFIRMED TAB ══ -->
        <div class="tab-pane fade <?= $activeTab === 'confirmed' ? 'show active' : '' ?>"
             id="panel-confirmed" role="tabpanel">

            <?php if (empty($confirmedBookings)): ?>
            <div class="empty-state py-5 text-center">
                <i class="fas fa-ticket-alt fa-3x text-muted mb-3 d-block"></i>
                <p class="text-muted mb-0">No confirmed bookings yet.</p>
            </div>
            <?php else: ?>
            <div class="row g-3">
                <?php foreach ($confirmedBookings as $bk): ?>
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="fd-card h-100 d-flex flex-column gap-3">

                        <!-- Passenger info -->
                        <div class="d-flex align-items-center gap-3">
                            <div class="flex-grow-1 overflow-hidden">
                                <div class="fw-semibold text-truncate">
                                    <?= htmlspecialchars($bk['passenger_name']) ?>
                                    <?php if (!empty($bk['is_student'])): ?>
                                    <span class="badge bg-info text-dark ms-1" style="font-size:.65rem;">Student</span>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex align-items-center gap-1" style="font-size:.8rem;">
                                    <?= renderStars((float)($bk['passenger_score'] ?? 0)) ?>
                                    <span class="text-muted ms-1"><?= number_format((float)($bk['passenger_score'] ?? 0), 1) ?></span>
                                </div>
                            </div>
                            <span class="status-badge bg-success text-white flex-shrink-0">Confirmed</span>
                        </div>

                        <!-- Route -->
                        <div class="small">
                            <div class="fw-semibold mb-1">
                                <i class="fas fa-map-marker-alt text-danger me-1"></i>
                                <?= htmlspecialchars($bk['from_location']) ?>
                                <i class="fas fa-arrow-right mx-1 text-muted"></i>
                                <?= htmlspecialchars($bk['to_location']) ?>
                            </div>
                            <div class="text-muted">
                                <i class="far fa-clock me-1"></i><?= htmlspecialchars($bk['departure_time']) ?>
                            </div>
                        </div>

                        <!-- Details -->
                        <div class="d-flex gap-3 small text-muted">
                            <span><i class="fas fa-users me-1 text-primary"></i><?= (int)$bk['seats'] ?> seat<?= $bk['seats'] > 1 ? 's' : '' ?></span>
                            <span><i class="fas fa-money-bill-wave me-1 text-success"></i><?= number_format((float)($bk['paid_amount'] ?? 0), 2) ?> DZD</span>
                        </div>

                        <!-- Mark Complete button -->
                        <div class="mt-auto">
                            <form method="POST"
                                  onsubmit="return confirm('Mark this trip as completed?')">
                                <input type="hidden" name="action" value="complete">
                                <input type="hidden" name="booking_id" value="<?= (int)$bk['id'] ?>">
                                <button type="submit" class="btn btn-fd-primary btn-sm w-100">
                                    <i class="fas fa-flag-checkered me-1"></i> Mark Complete
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /tab-content -->

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Restore active tab from URL hash so browser back/forward works
(function () {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab');
    if (tab) {
        const btn = document.querySelector('#bookingTabs button[data-bs-target="#panel-' + tab + '"]');
        if (btn) {
            const bsTab = new bootstrap.Tab(btn);
            bsTab.show();
        }
    }
})();
</script>
</body>
</html>
