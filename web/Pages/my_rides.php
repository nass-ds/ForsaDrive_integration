<?php
require_once '../server/session.php';
require_once '../server/language.php';
require_once '../classes/rides.php';
require_once '../classes/ratings.php';
require_once '../classes/notifications.php';

if (!isLoggedIn()) { header('Location: login.php'); exit(); }

$db   = getDB();
$ride = new Ride($db);
$uid  = $_SESSION['user_id'];
$ud   = $_SESSION['user_data'];

$errors  = [];
$success = '';

// Handle cancel booking
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $bookingId = (int)($_POST['booking_id'] ?? 0);

    if ($action === 'cancel' && $bookingId) {
        if ($ride->cancelBooking($bookingId, $uid)) {
            // Refund amount
            $stmt = $db->prepare("SELECT paid_amount, ride_id FROM bookings WHERE id=? AND passenger_id=?");
            $stmt->execute([$bookingId, $uid]);
            $bk = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($bk) {
                $db->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$bk['paid_amount'], $uid]);
                $_SESSION['user_data']['balance'] = ($ud['balance'] ?? 0) + $bk['paid_amount'];
                $success = "Booking cancelled. " . number_format($bk['paid_amount'], 2) . " TND refunded.";
            } else {
                $success = "Booking cancelled.";
            }
        } else {
            $errors[] = "Could not cancel booking.";
        }
    } elseif ($action === 'rate' && $bookingId) {
        $score   = (int)($_POST['score'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');
        if ($score >= 1 && $score <= 5) {
            $stmt = $db->prepare("SELECT ride_id, passenger_id FROM bookings WHERE id=? AND passenger_id=? AND status='completed'");
            $stmt->execute([$bookingId, $uid]);
            $bk = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($bk) {
                // get driver
                $stmt2 = $db->prepare("SELECT driver_id FROM rides WHERE id=?");
                $stmt2->execute([$bk['ride_id']]);
                $r = $stmt2->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $rating = new Rating($db, null, $bk['ride_id'], $uid, $r['driver_id'], $score, $comment);
                    if ($rating->save()) {
                        $notif = new Notifications($db);
                        $notif->create($r['driver_id'], 'New Rating Received', $ud['username'] . " gave you a {$score}★ rating.", 'success', 'ratings.php');
                        $success = "Rating submitted!";
                    } else {
                        $errors[] = "Rating already submitted for this ride.";
                    }
                }
            }
        } else {
            $errors[] = "Please select a score (1–5).";
        }
    }
}

$myRides = $ride->getUserRides($uid);

// Separate into tabs
$upcoming  = array_filter($myRides, fn($r) => in_array($r['booking_status'], ['pending','confirmed']) && $r['departure_time'] > date('Y-m-d H:i:s'));
$past      = array_filter($myRides, fn($r) => $r['booking_status'] === 'completed' || $r['departure_time'] <= date('Y-m-d H:i:s'));
$cancelled = array_filter($myRides, fn($r) => $r['booking_status'] === 'cancelled');

$pageTitle = 'My Rides';
include '../include/header.php';
include '../include/sidebar.php';
?>

<div class="fd-main">
  <div class="page-header">
    <h1><i class="fas fa-car me-2 text-primary"></i>My Rides</h1>
    <a href="book_ride.php" class="btn-fd-primary btn"><i class="fas fa-search me-1"></i>Find a Ride</a>
  </div>

  <?php if ($success): ?>
    <div class="alert-fd-success"><i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert-fd-danger"><i class="fas fa-exclamation-circle me-2"></i><?= implode('<br>', $errors) ?></div>
  <?php endif; ?>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-3" id="rideTabs">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#upcoming">
      Upcoming <span class="badge bg-primary ms-1"><?= count($upcoming) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#past">
      Past <span class="badge bg-secondary ms-1"><?= count($past) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#cancelled">
      Cancelled <span class="badge bg-danger ms-1"><?= count($cancelled) ?></span></a></li>
  </ul>

  <div class="tab-content">
    <!-- Upcoming -->
    <div class="tab-pane fade show active" id="upcoming">
      <?php if (empty($upcoming)): ?>
        <div class="empty-state"><i class="fas fa-calendar-times"></i><p>No upcoming rides</p>
          <a href="book_ride.php" class="btn-fd-primary btn btn-sm">Find a Ride</a></div>
      <?php else: ?>
        <?php foreach ($upcoming as $r):
          $dt = new DateTime($r['departure_time']); ?>
          <div class="ride-item">
            <div class="d-flex justify-content-between flex-wrap gap-2">
              <div>
                <div class="route">
                  <i class="fas fa-map-marker-alt text-danger me-1"></i><?= htmlspecialchars($r['from_location']) ?>
                  <i class="fas fa-arrow-right mx-2 text-muted small"></i>
                  <i class="fas fa-map-marker text-success me-1"></i><?= htmlspecialchars($r['to_location']) ?>
                </div>
                <div class="meta mt-1"><i class="fas fa-clock me-1"></i><?= $dt->format('D, M j, Y \a\t g:i A') ?></div>
                <div class="meta"><i class="fas fa-user me-1"></i><?= htmlspecialchars($r['driver_name']) ?>
                  ★<?= number_format($r['driver_score'] ?? 5, 1) ?></div>
                <div class="mt-1">
                  <span class="status-badge <?= htmlspecialchars($r['booking_status']) ?>"><?= ucfirst($r['booking_status']) ?></span>
                  <span class="ms-2 small text-muted"><?= $r['booked_seats'] ?> seat(s)</span>
                </div>
              </div>
              <div class="d-flex flex-column align-items-end gap-2">
                <div class="price"><?= number_format($r['paid_amount'], 2) ?> TND</div>
                <form method="POST" onsubmit="return confirm('Cancel this booking?')">
                  <input type="hidden" name="action" value="cancel">
                  <input type="hidden" name="booking_id" value="<?= $r['booking_id'] ?>">
                  <button class="btn-fd-danger btn btn-sm"><i class="fas fa-times me-1"></i>Cancel</button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Past -->
    <div class="tab-pane fade" id="past">
      <?php if (empty($past)): ?>
        <div class="empty-state"><i class="fas fa-history"></i><p>No past rides yet</p></div>
      <?php else: ?>
        <?php
        $ratingService = new Rating($db);
        foreach ($past as $r):
          $dt = new DateTime($r['departure_time']);
          $alreadyRated = $ratingService->hasRated($r['id'], $uid);
        ?>
          <div class="ride-item">
            <div class="d-flex justify-content-between flex-wrap gap-2">
              <div>
                <div class="route">
                  <i class="fas fa-map-marker-alt text-danger me-1"></i><?= htmlspecialchars($r['from_location']) ?>
                  <i class="fas fa-arrow-right mx-2 text-muted small"></i>
                  <i class="fas fa-map-marker text-success me-1"></i><?= htmlspecialchars($r['to_location']) ?>
                </div>
                <div class="meta mt-1"><i class="fas fa-clock me-1"></i><?= $dt->format('D, M j, Y') ?></div>
                <div class="meta"><i class="fas fa-user me-1"></i><?= htmlspecialchars($r['driver_name']) ?></div>
                <div class="mt-1">
                  <span class="status-badge <?= htmlspecialchars($r['booking_status']) ?>"><?= ucfirst($r['booking_status']) ?></span>
                </div>
              </div>
              <div class="d-flex flex-column align-items-end gap-2">
                <div class="price"><?= number_format($r['paid_amount'], 2) ?> TND</div>
                <?php if ($r['booking_status'] === 'completed' && !$alreadyRated): ?>
                <button class="btn-fd-outline btn btn-sm" data-bs-toggle="modal" data-bs-target="#rateModal<?= $r['booking_id'] ?>">
                  <i class="fas fa-star me-1"></i>Rate Driver
                </button>
                <!-- Rate Modal -->
                <div class="modal fade" id="rateModal<?= $r['booking_id'] ?>" tabindex="-1">
                  <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                      <div class="modal-header"><h5 class="modal-title">Rate your driver</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                      <form method="POST">
                        <div class="modal-body">
                          <input type="hidden" name="action" value="rate">
                          <input type="hidden" name="booking_id" value="<?= $r['booking_id'] ?>">
                          <div class="mb-3">
                            <label class="fd-form-label">Score</label>
                            <div class="d-flex gap-2">
                              <?php for ($s=1;$s<=5;$s++): ?>
                              <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="score" value="<?=$s?>" id="s<?=$s?>_<?=$r['booking_id']?>" required>
                                <label class="form-check-label stars" for="s<?=$s?>_<?=$r['booking_id']?>"><?=$s?>★</label>
                              </div>
                              <?php endfor; ?>
                            </div>
                          </div>
                          <div class="mb-3">
                            <label class="fd-form-label">Comment (optional)</label>
                            <textarea name="comment" class="form-control" rows="3" placeholder="How was your ride?"></textarea>
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                          <button type="submit" class="btn-fd-primary btn">Submit Rating</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
                <?php elseif ($alreadyRated): ?>
                <span class="small text-muted"><i class="fas fa-check-circle text-success me-1"></i>Rated</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Cancelled -->
    <div class="tab-pane fade" id="cancelled">
      <?php if (empty($cancelled)): ?>
        <div class="empty-state"><i class="fas fa-ban"></i><p>No cancelled rides</p></div>
      <?php else: ?>
        <?php foreach ($cancelled as $r):
          $dt = new DateTime($r['departure_time']); ?>
          <div class="ride-item" style="border-left-color: var(--fd-danger);">
            <div class="route">
              <i class="fas fa-map-marker-alt text-danger me-1"></i><?= htmlspecialchars($r['from_location']) ?>
              <i class="fas fa-arrow-right mx-2 text-muted small"></i>
              <i class="fas fa-map-marker text-success me-1"></i><?= htmlspecialchars($r['to_location']) ?>
            </div>
            <div class="meta mt-1"><i class="fas fa-clock me-1"></i><?= $dt->format('D, M j, Y') ?></div>
            <div class="mt-1"><span class="status-badge cancelled">Cancelled</span></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
