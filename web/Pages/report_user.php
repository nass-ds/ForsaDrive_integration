<?php
require_once '../server/session.php';
require_once '../server/language.php';
require_once '../classes/reports.php';
require_once '../classes/publicid.php';
require_once '../classes/profileaccess.php';

requireRegularUser();

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

$errors  = [];
$success = '';
$prefillPid  = trim($_GET['public_id'] ?? '');
$prefillRide = isset($_GET['ride_id']) ? (int)$_GET['ride_id'] : 0;

// On submit
$category    = $_POST['category']    ?? '';
$description = $_POST['description'] ?? '';
$publicId    = $_POST['public_id']   ?? $prefillPid;
$rideId      = isset($_POST['ride_id']) ? (int)$_POST['ride_id'] : ($prefillRide ?: 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$ok, $msg, $rid] = ReportService::create(
        $db, $uid, (string)$publicId, (string)$category, (string)$description, $rideId ?: null
    );
    if ($ok) {
        $success     = $msg;
        $publicId    = $description = $category = '';   // clear after success
        $prefillRide = 0;
    } else {
        $errors[] = $msg;
    }
}

// Quick lookup so we can preview the limited profile for the entered ID.
$preview = null;
if ($prefillPid !== '' || ($publicId ?? '') !== '') {
    $target = PublicIdService::findUser($db, $publicId !== '' ? $publicId : $prefillPid);
    if ($target && (int)$target['id'] !== $uid) {
        $preview = ProfileAccessService::limitedProfile($db, $target);
    }
}

$myReports = ReportService::listMine($db, $uid, 20);

$pageTitle = 'Report a User';
include '../include/header.php';
include '../include/sidebar.php';
?>
<style>
.report-card { border:1px solid #e5e7eb; border-radius:12px; padding:1rem; background:#fff; }
.public-id-chip {
  display:inline-flex; align-items:center; gap:.4rem;
  padding:.25rem .65rem; border-radius:99px;
  background:#eef2ff; color:#3730a3; font-weight:600; font-size:.78rem;
  border:1px solid #c7d2fe; font-family:'Segoe UI Mono','Consolas',monospace;
}
.status-chip { padding:.18rem .55rem; border-radius:99px; font-size:.7rem; font-weight:600; }
.status-pending   { background:#fef9c3; color:#854d0e; }
.status-reviewing { background:#e0f2fe; color:#0369a1; }
.status-resolved  { background:#dcfce7; color:#166534; }
.status-rejected  { background:#fee2e2; color:#991b1b; }
</style>

<div class="fd-main">
  <div class="page-header">
    <h1><i class="fas fa-flag me-2 text-danger"></i>Report a User</h1>
    <p class="text-muted small mb-0">
      Use the user's public ForsaDrive ID to file a report — no need for their email or phone number.
      Our team reviews every report.
    </p>
  </div>

  <?php if ($success): ?>
    <div class="alert-fd-success mb-3"><i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert-fd-danger mb-3"><i class="fas fa-exclamation-circle me-2"></i><?= implode('<br>', array_map('htmlspecialchars',$errors)) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- ── Report form ─────────────────────────────────────────────── -->
    <div class="col-12 col-lg-7">
      <div class="fd-card">
        <div class="card-title"><i class="fas fa-edit text-primary"></i> New Report</div>
        <form method="POST" autocomplete="off">
          <div class="mb-3">
            <label class="fd-form-label">ForsaDrive ID *</label>
            <input type="text" name="public_id" class="form-control"
                   placeholder="FD-D-20001"
                   value="<?= htmlspecialchars($publicId ?: $prefillPid) ?>"
                   style="font-family:'Segoe UI Mono','Consolas',monospace; letter-spacing:.05em" required>
            <div class="form-text">Ask the user for their ForsaDrive ID — it's safe for them to share.</div>
          </div>

          <?php if ($preview): ?>
          <div class="report-card mb-3 d-flex align-items-center gap-3">
            <img src="<?= htmlspecialchars($preview['picture'] ?: '../Src/default.jpg') ?>"
                 onerror="this.src='../Src/default.jpg'" alt=""
                 style="width:48px;height:48px;border-radius:50%;object-fit:cover">
            <div class="flex-grow-1">
              <div class="fw-semibold"><?= htmlspecialchars($preview['first_name'] ?: '—') ?></div>
              <div class="text-muted small">
                <span class="public-id-chip"><i class="fas fa-id-badge"></i><?= htmlspecialchars($preview['public_id']) ?></span>
                <?php if ($preview['is_verified']): ?><span class="ms-2 text-success">✓ Verified</span><?php endif; ?>
              </div>
              <div class="small text-muted mt-1">
                ★ <?= number_format($preview['score'], 1) ?> ·
                <?= (int)$preview['completed_rides'] ?> completed ride<?= $preview['completed_rides']===1?'':'s' ?>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <div class="mb-3">
            <label class="fd-form-label">Category *</label>
            <select name="category" class="form-select" required>
              <option value="">— Select a category —</option>
              <?php foreach (ReportService::CATEGORIES as $code => $label): ?>
                <option value="<?= htmlspecialchars($code) ?>" <?= $category === $code ? 'selected' : '' ?>>
                  <?= htmlspecialchars($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if ($rideId > 0): ?>
          <div class="mb-3">
            <label class="fd-form-label">Related ride</label>
            <input type="text" class="form-control" value="Ride #<?= (int)$rideId ?>" readonly>
            <input type="hidden" name="ride_id" value="<?= (int)$rideId ?>">
          </div>
          <?php else: ?>
          <div class="mb-3">
            <label class="fd-form-label">Related ride <span class="text-muted">(optional)</span></label>
            <input type="number" name="ride_id" class="form-control" min="1" placeholder="Ride ID if applicable">
          </div>
          <?php endif; ?>

          <div class="mb-3">
            <label class="fd-form-label">Description *</label>
            <textarea name="description" class="form-control" rows="5" maxlength="2000"
                      placeholder="Describe what happened. Avoid sharing private contact details — keep it on this platform."
                      required><?= htmlspecialchars($description) ?></textarea>
            <div class="form-text">Up to 2000 characters.</div>
          </div>

          <button type="submit" class="btn-fd-primary btn">
            <i class="fas fa-paper-plane me-1"></i>Submit Report
          </button>
        </form>
      </div>
    </div>

    <!-- ── My past reports ─────────────────────────────────────────── -->
    <div class="col-12 col-lg-5">
      <div class="fd-card">
        <div class="card-title"><i class="fas fa-history text-primary"></i> My Reports</div>
        <?php if (empty($myReports)): ?>
          <p class="text-muted small mb-0">You haven't filed any reports yet.</p>
        <?php else: ?>
          <?php foreach ($myReports as $r): ?>
            <div class="report-card mb-2">
              <div class="d-flex justify-content-between align-items-center">
                <span class="public-id-chip">
                  <i class="fas fa-id-badge"></i><?= htmlspecialchars($r['reported_public_id']) ?>
                </span>
                <span class="status-chip status-<?= htmlspecialchars($r['status']) ?>">
                  <?= htmlspecialchars(ucfirst($r['status'])) ?>
                </span>
              </div>
              <div class="small text-muted mt-1">
                <?= htmlspecialchars(ReportService::categoryLabel($r['category'])) ?>
                · <?= htmlspecialchars($r['created_at']) ?>
              </div>
              <div class="small mt-1" style="white-space:pre-wrap;"><?= htmlspecialchars(mb_strimwidth($r['description'],0,220,'…')) ?></div>
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
