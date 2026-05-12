<?php
require_once '../server/session.php';
require_once '../server/language.php';
require_once '../classes/complaints.php';

requireRegularUser();

$db     = getDB();
$userId = (int)$_SESSION['user_id'];
$complaints = new Complaints($db);

$flashSuccess = '';
$flashError   = '';

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type           = trim($_POST['type'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $againstUserId  = !empty($_POST['against_user_id']) ? (int)$_POST['against_user_id'] : null;
    $rideId         = !empty($_POST['ride_id'])         ? (int)$_POST['ride_id']         : null;

    $allowed = ['rude_behavior','cancellation','fraud','safety','other'];
    if (!in_array($type, $allowed)) {
        $flashError = 'Please select a valid complaint type.';
    } elseif (mb_strlen($description) < 20) {
        $flashError = 'Description must be at least 20 characters.';
    } else {
        if ($complaints->submit($userId, $againstUserId, $rideId, $type, $description, null)) {
            $flashSuccess = 'Your complaint has been submitted successfully.';
        } else {
            $flashError = 'Failed to submit complaint. Please try again.';
        }
    }
}

$userComplaints = $complaints->getUserComplaints($userId);

$pageTitle = 'Complaints';
require_once '../include/header.php';
require_once '../include/sidebar.php';

$typeLabels = [
    'rude_behavior' => 'Rude Behavior',
    'cancellation'  => 'Cancellation Issue',
    'fraud'         => 'Fraud',
    'safety'        => 'Safety Concern',
    'other'         => 'Other',
];

// Determine active tab
$activeTab = (!empty($flashError) || (isset($_POST['type']))) ? 'submit' : 'list';
if ($flashSuccess) $activeTab = 'list';
?>

<div class="fd-main">
    <div class="page-header">
        <h1><i class="fas fa-flag me-2"></i>Complaints</h1>
        <p class="text-muted mb-0">Report issues or review your submitted complaints.</p>
    </div>

    <?php if ($flashSuccess): ?>
        <div class="alert-fd-success mb-3">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($flashSuccess) ?>
        </div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="alert-fd-danger mb-3">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($flashError) ?>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" id="complaintsTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeTab === 'list' ? 'active' : '' ?>"
                    id="list-tab" data-bs-toggle="tab" data-bs-target="#tab-list"
                    type="button" role="tab">
                <i class="fas fa-list me-1"></i>My Complaints
                <?php if (count($userComplaints) > 0): ?>
                    <span class="badge bg-secondary ms-1"><?= count($userComplaints) ?></span>
                <?php endif; ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeTab === 'submit' ? 'active' : '' ?>"
                    id="submit-tab" data-bs-toggle="tab" data-bs-target="#tab-submit"
                    type="button" role="tab">
                <i class="fas fa-plus-circle me-1"></i>Submit New
            </button>
        </li>
    </ul>

    <div class="tab-content" id="complaintsTabContent">

        <!-- My Complaints Tab -->
        <div class="tab-pane fade <?= $activeTab === 'list' ? 'show active' : '' ?>"
             id="tab-list" role="tabpanel">
            <?php if (empty($userComplaints)): ?>
                <div class="empty-state">
                    <i class="fas fa-flag fa-3x mb-3 text-muted"></i>
                    <h5>No Complaints Yet</h5>
                    <p class="text-muted">You haven't submitted any complaints. Use the "Submit New" tab to report an issue.</p>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($userComplaints as $c): ?>
                        <?php
                            $statusClass = match($c['status']) {
                                'open'      => 'open',
                                'in_review' => 'in_review',
                                'resolved'  => 'resolved',
                                'dismissed' => 'dismissed',
                                default     => 'open',
                            };
                            $typeLabel = $typeLabels[$c['type']] ?? ucfirst(str_replace('_', ' ', $c['type']));
                            $dateStr   = !empty($c['created_at']) ? date('d M Y', strtotime($c['created_at'])) : '—';
                        ?>
                        <div class="col-12">
                            <div class="fd-card ride-item">
                                <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-2">
                                    <div>
                                        <span class="fw-semibold"><?= htmlspecialchars($typeLabel) ?></span>
                                        <?php if (!empty($c['against_name'])): ?>
                                            <span class="text-muted ms-2 small">
                                                against <strong><?= htmlspecialchars($c['against_name']) ?></strong>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($c['from_location']) && !empty($c['to_location'])): ?>
                                            <span class="text-muted ms-2 small">
                                                · <?= htmlspecialchars($c['from_location']) ?> → <?= htmlspecialchars($c['to_location']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="status-badge <?= $statusClass ?>">
                                            <?= ucfirst(str_replace('_', ' ', $c['status'])) ?>
                                        </span>
                                        <small class="text-muted"><?= $dateStr ?></small>
                                    </div>
                                </div>
                                <p class="text-muted mb-2" style="font-size:.9rem;">
                                    <?= htmlspecialchars(mb_substr($c['description'], 0, 200)) ?>
                                    <?= mb_strlen($c['description']) > 200 ? '…' : '' ?>
                                </p>
                                <?php if (!empty($c['admin_note']) && in_array($c['status'], ['resolved','dismissed'])): ?>
                                    <div class="alert alert-info py-2 px-3 mb-0" style="font-size:.85rem;">
                                        <i class="fas fa-comment-dots me-1"></i>
                                        <strong>Admin note:</strong> <?= htmlspecialchars($c['admin_note']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Submit New Tab -->
        <div class="tab-pane fade <?= $activeTab === 'submit' ? 'show active' : '' ?>"
             id="tab-submit" role="tabpanel">
            <div class="fd-card" style="max-width:640px;">
                <h5 class="mb-3"><i class="fas fa-pen-to-square me-2"></i>New Complaint</h5>
                <form method="POST" novalidate>
                    <div class="mb-3">
                        <label class="fd-form-label" for="type">Complaint Type <span class="text-danger">*</span></label>
                        <select class="form-select" name="type" id="type" required>
                            <option value="" disabled selected>— Select type —</option>
                            <?php foreach ($typeLabels as $val => $label): ?>
                                <option value="<?= $val ?>"
                                    <?= (isset($_POST['type']) && $_POST['type'] === $val) ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="fd-form-label" for="description">
                            Description <span class="text-danger">*</span>
                            <small class="text-muted fw-normal">(min. 20 characters)</small>
                        </label>
                        <textarea class="form-control" name="description" id="description"
                                  rows="5" minlength="20" required
                                  placeholder="Describe what happened in detail..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                        <div class="form-text" id="descCounter">0 characters</div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="fd-form-label" for="against_user_id">Against User ID <small class="text-muted fw-normal">(optional)</small></label>
                            <input type="number" class="form-control" name="against_user_id" id="against_user_id"
                                   min="1" placeholder="e.g. 42"
                                   value="<?= htmlspecialchars($_POST['against_user_id'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="fd-form-label" for="ride_id">Ride ID <small class="text-muted fw-normal">(optional)</small></label>
                            <input type="number" class="form-control" name="ride_id" id="ride_id"
                                   min="1" placeholder="e.g. 7"
                                   value="<?= htmlspecialchars($_POST['ride_id'] ?? '') ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn-fd-primary w-100">
                        <i class="fas fa-paper-plane me-2"></i>Submit Complaint
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const ta = document.getElementById('description');
    const counter = document.getElementById('descCounter');
    if (ta && counter) {
        function update() {
            const len = ta.value.length;
            counter.textContent = len + ' character' + (len !== 1 ? 's' : '');
            counter.style.color = len < 20 ? 'var(--bs-danger, #dc3545)' : 'var(--bs-secondary, #6c757d)';
        }
        ta.addEventListener('input', update);
        update();
    }
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
