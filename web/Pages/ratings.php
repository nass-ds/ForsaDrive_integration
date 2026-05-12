<?php
require_once '../server/session.php';
require_once '../server/language.php';
require_once '../classes/ratings.php';

requireRegularUser();

$db     = getDB();
$uid    = $_SESSION['user_id'];
$rating = new Rating($db);

$received = $rating->getUserRatings($uid);
$given    = $rating->getGivenRatings($uid);
$avg      = $rating->getAvgRating($uid);

$pageTitle = 'Ratings';
include '../include/header.php';
include '../include/sidebar.php';
?>

<div class="fd-main">
  <div class="page-header">
    <h1><i class="fas fa-star me-2 text-warning"></i>Ratings & Reviews</h1>
  </div>

  <!-- Summary card -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="icon amber"><i class="fas fa-star"></i></div>
        <div><div class="val"><?= number_format($avg, 1) ?></div><div class="lbl">Avg Rating</div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="icon blue"><i class="fas fa-inbox"></i></div>
        <div><div class="val"><?= count($received) ?></div><div class="lbl">Received</div></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="icon green"><i class="fas fa-paper-plane"></i></div>
        <div><div class="val"><?= count($given) ?></div><div class="lbl">Given</div></div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#received">
      Received <span class="badge bg-warning text-dark ms-1"><?= count($received) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#given">
      Given <span class="badge bg-secondary ms-1"><?= count($given) ?></span></a></li>
  </ul>

  <div class="tab-content">
    <div class="tab-pane fade show active" id="received">
      <?php if (empty($received)): ?>
        <div class="empty-state"><i class="fas fa-star-half-alt"></i><p>No ratings received yet</p></div>
      <?php else: ?>
        <?php foreach ($received as $r): ?>
          <div class="fd-card mb-2">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
              <div>
                <div class="fw-semibold"><i class="fas fa-user-circle me-1 text-muted"></i><?= htmlspecialchars($r['rater_name'] ?? 'Anonymous') ?></div>
                <?php if (!empty($r['comment'])): ?>
                <p class="text-muted fst-italic mb-1 small">"<?= htmlspecialchars($r['comment']) ?>"</p>
                <?php endif; ?>
                <div class="small text-muted"><i class="fas fa-clock me-1"></i><?= htmlspecialchars($r['created_at']) ?></div>
              </div>
              <div class="stars fs-5">
                <?php for ($i=1; $i<=5; $i++): ?>
                  <i class="fa<?= $i <= ($r['score'] ?? 0) ? 's' : 'r' ?> fa-star"></i>
                <?php endfor; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="tab-pane fade" id="given">
      <?php if (empty($given)): ?>
        <div class="empty-state"><i class="fas fa-star"></i><p>You haven't rated anyone yet</p>
          <a href="my_rides.php" class="btn-fd-primary btn btn-sm">View Past Rides</a></div>
      <?php else: ?>
        <?php foreach ($given as $r): ?>
          <div class="fd-card mb-2">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
              <div>
                <div class="fw-semibold"><i class="fas fa-user-circle me-1 text-muted"></i><?= htmlspecialchars($r['rated_name'] ?? 'Unknown') ?></div>
                <?php if (!empty($r['comment'])): ?>
                <p class="text-muted fst-italic mb-1 small">"<?= htmlspecialchars($r['comment']) ?>"</p>
                <?php endif; ?>
                <div class="small text-muted"><i class="fas fa-clock me-1"></i><?= htmlspecialchars($r['created_at']) ?></div>
              </div>
              <div class="stars fs-5">
                <?php for ($i=1; $i<=5; $i++): ?>
                  <i class="fa<?= $i <= ($r['score'] ?? 0) ? 's' : 'r' ?> fa-star"></i>
                <?php endfor; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
