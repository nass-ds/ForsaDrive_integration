<?php
require_once '../server/session.php';
require_once '../server/language.php';
require_once '../classes/vehicules.php';

if (!isLoggedIn()) { header('Location: login.php'); exit(); }

$db      = getDB();
$uid     = $_SESSION['user_id'];
$ud      = $_SESSION['user_data'];
$veh     = new Vehicle($db);
$errors  = [];
$success = '';

// ── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $d = [
            'id'            => (int)($_POST['vehicle_id'] ?? 0) ?: null,
            'type'          => $_POST['type']          ?? 'car',
            'make'          => trim($_POST['make']      ?? ''),
            'model'         => trim($_POST['model']     ?? ''),
            'year'          => (int)($_POST['year']     ?? date('Y')),
            'color'         => trim($_POST['color']     ?? ''),
            'plate_number'  => strtoupper(trim($_POST['plate_number'] ?? '')),
            'seats'         => max(1, (int)($_POST['seats'] ?? 4)),
            'has_ac'        => isset($_POST['has_ac'])        ? 1 : 0,
            'has_wifi'      => isset($_POST['has_wifi'])      ? 1 : 0,
            'is_accessible' => isset($_POST['is_accessible']) ? 1 : 0,
            'luggage'       => $_POST['luggage']        ?? 'medium',
            'max_weight_kg' => (float)($_POST['max_weight_kg'] ?? 0),
            'notes'         => trim($_POST['notes']     ?? ''),
        ];

        if (!array_key_exists($d['type'], Vehicle::TYPES))
            $errors[] = 'Invalid vehicle type.';
        if (!$d['make'])
            $errors[] = 'Make/brand is required.';
        if ($d['year'] < 1990 || $d['year'] > (int)date('Y') + 1)
            $errors[] = 'Please enter a valid year.';
        if (!$d['plate_number'])
            $errors[] = 'Plate number is required.';
        if ($d['seats'] < 1 || $d['seats'] > 50)
            $errors[] = 'Seats must be between 1 and 50.';

        if (empty($errors)) {
            if ($veh->save($d, $uid)) {
                // Also mark user as driver if they weren't already
                if (!$ud['is_driver']) {
                    $db->prepare("UPDATE users SET is_driver=1 WHERE id=?")->execute([$uid]);
                    $db->prepare("INSERT OR IGNORE INTO driver_profiles (user_id) VALUES (?)")->execute([$uid]);
                    $_SESSION['user_data']['is_driver'] = true;
                }
                $success = $d['id'] ? 'Vehicle updated!' : 'Vehicle added!';
            } else {
                $errors[] = 'Could not save vehicle. Plate number may already be registered.';
            }
        }

    } elseif ($action === 'delete') {
        $vid = (int)($_POST['vehicle_id'] ?? 0);
        if ($veh->delete($vid, $uid)) $success = 'Vehicle removed.';
        else $errors[] = 'Could not delete vehicle.';
    }
}

$vehicles = $veh->getByUser($uid);
// Pre-fill edit data
$editing = null;
if (isset($_GET['edit'])) {
    $editing = $veh->getById((int)$_GET['edit']);
    if ($editing && $editing['user_id'] != $uid) $editing = null;
}

$pageTitle = 'My Vehicles';
include '../include/header.php';
include '../include/sidebar.php';
?>

<div class="fd-main">
  <div class="page-header">
    <h1><i class="fas fa-car me-2 text-primary"></i>My Vehicles</h1>
    <?php if (!$editing): ?>
    <a href="vehicles.php?add=1" class="btn-fd-primary btn">
      <i class="fas fa-plus me-1"></i>Add Vehicle
    </a>
    <?php endif; ?>
  </div>

  <?php if ($success): ?>
    <div class="alert-fd-success"><i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert-fd-danger"><i class="fas fa-exclamation-circle me-2"></i><?= implode('<br>', $errors) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Form: add/edit -->
    <?php if ($editing || isset($_GET['add'])): ?>
    <div class="col-12 col-lg-5">
      <div class="fd-card">
        <div class="card-title">
          <i class="fas fa-<?= $editing ? 'edit' : 'plus-circle' ?> text-primary"></i>
          <?= $editing ? 'Edit Vehicle' : 'Add New Vehicle' ?>
        </div>
        <form method="POST">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="vehicle_id" value="<?= $editing['id'] ?? '' ?>">

          <!-- Type -->
          <div class="mb-3">
            <label class="fd-form-label">Vehicle Type *</label>
            <div class="row g-2" id="typeCards">
              <?php foreach (Vehicle::TYPES as $k => $label):
                $sel = ($editing['type'] ?? 'car') === $k; ?>
              <div class="col-6 col-sm-4">
                <input type="radio" name="type" value="<?= $k ?>" id="t_<?= $k ?>"
                       class="d-none type-radio" <?= $sel ? 'checked' : '' ?>>
                <label for="t_<?= $k ?>"
                       class="d-block text-center p-2 rounded border type-label <?= $sel ? 'border-primary bg-primary text-white' : 'border-secondary' ?>"
                       style="cursor:pointer;font-size:.8rem">
                  <i class="fas <?= Vehicle::typeIcon($k) ?> d-block mb-1 fs-5"></i><?= $label ?>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="row g-2">
            <div class="col-6">
              <label class="fd-form-label">Make / Brand *</label>
              <input name="make" class="form-control" placeholder="e.g. Toyota"
                     value="<?= htmlspecialchars($editing['make'] ?? '') ?>" required>
            </div>
            <div class="col-6">
              <label class="fd-form-label">Model</label>
              <input name="model" class="form-control" placeholder="e.g. Corolla"
                     value="<?= htmlspecialchars($editing['model'] ?? '') ?>">
            </div>
            <div class="col-4">
              <label class="fd-form-label">Year</label>
              <input name="year" type="number" class="form-control"
                     min="1990" max="<?= date('Y')+1 ?>" placeholder="<?= date('Y') ?>"
                     value="<?= htmlspecialchars($editing['year'] ?? date('Y')) ?>">
            </div>
            <div class="col-4">
              <label class="fd-form-label">Color</label>
              <input name="color" class="form-control" placeholder="White"
                     value="<?= htmlspecialchars($editing['color'] ?? '') ?>">
            </div>
            <div class="col-4">
              <label class="fd-form-label">Seats *</label>
              <input name="seats" type="number" class="form-control" min="1" max="50"
                     value="<?= htmlspecialchars($editing['seats'] ?? 4) ?>" required>
            </div>
          </div>

          <div class="mb-3 mt-2">
            <label class="fd-form-label">Plate Number (Matricule) *</label>
            <input name="plate_number" class="form-control" placeholder="e.g. 123 TUN 4567"
                   style="font-family:monospace;letter-spacing:.1em;text-transform:uppercase"
                   value="<?= htmlspecialchars($editing['plate_number'] ?? '') ?>" required>
          </div>

          <div class="mb-3">
            <label class="fd-form-label">Luggage Capacity</label>
            <select name="luggage" class="form-select">
              <?php foreach (Vehicle::LUGGAGE as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= ($editing['luggage'] ?? 'medium') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="fd-form-label">Max Passenger Weight (kg) <span class="text-muted small">0 = no limit</span></label>
            <input name="max_weight_kg" type="number" class="form-control" min="0" max="5000"
                   value="<?= htmlspecialchars($editing['max_weight_kg'] ?? 0) ?>">
          </div>

          <!-- Checkboxes -->
          <div class="mb-3">
            <label class="fd-form-label">Amenities</label>
            <div class="d-flex flex-wrap gap-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="has_ac" id="ac"
                       <?= !empty($editing['has_ac']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="ac"><i class="fas fa-snowflake me-1 text-info"></i>Air Conditioning</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="has_wifi" id="wifi"
                       <?= !empty($editing['has_wifi']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="wifi"><i class="fas fa-wifi me-1 text-success"></i>Wi-Fi</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_accessible" id="acc"
                       <?= !empty($editing['is_accessible']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="acc"><i class="fas fa-wheelchair me-1 text-warning"></i>Wheelchair Accessible</label>
              </div>
            </div>
          </div>

          <div class="mb-3">
            <label class="fd-form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2"
                      placeholder="Anything passengers should know..."><?= htmlspecialchars($editing['notes'] ?? '') ?></textarea>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn-fd-primary btn flex-fill">
              <i class="fas fa-save me-1"></i><?= $editing ? 'Update' : 'Add Vehicle' ?>
            </button>
            <a href="vehicles.php" class="btn-fd-outline btn">Cancel</a>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Vehicle list -->
    <div class="col-12 <?= ($editing || isset($_GET['add'])) ? 'col-lg-7' : '' ?>">
      <?php if (empty($vehicles)): ?>
        <div class="empty-state">
          <i class="fas fa-car-side"></i>
          <p class="fw-semibold">No vehicles registered yet</p>
          <a href="vehicles.php?add=1" class="btn-fd-primary btn btn-sm mt-2">Add Your First Vehicle</a>
        </div>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach ($vehicles as $v): ?>
          <div class="col-12 col-md-6 col-xl-4">
            <div class="fd-card h-100 position-relative">
              <!-- Type badge -->
              <span class="badge bg-primary position-absolute top-0 end-0 m-2 rounded-pill" style="font-size:.7rem">
                <?= Vehicle::TYPES[$v['type']] ?? $v['type'] ?>
              </span>

              <div class="text-center mb-3 pt-1">
                <?php if (!empty($v['photo'])): ?>
                  <img src="<?= htmlspecialchars($v['photo']) ?>" alt="Vehicle"
                       style="width:100%;max-height:130px;object-fit:cover;border-radius:8px;margin-bottom:.5rem;">
                <?php else: ?>
                  <i class="fas <?= Vehicle::typeIcon($v['type']) ?> text-primary" style="font-size:2.5rem"></i>
                <?php endif; ?>
              </div>

              <div class="fw-bold fs-6 mb-1">
                <?= htmlspecialchars(trim("{$v['make']} {$v['model']}")) ?>
                <span class="text-muted fw-normal"><?= $v['year'] ? "({$v['year']})" : '' ?></span>
              </div>

              <div class="text-muted small mb-2" style="font-family:monospace;letter-spacing:.08em">
                <i class="fas fa-id-card me-1"></i><?= htmlspecialchars($v['plate_number'] ?? '—') ?>
              </div>

              <!-- Specs pills -->
              <div class="d-flex flex-wrap gap-1 mb-3">
                <span class="badge bg-light text-dark border">
                  <i class="fas fa-users me-1"></i><?= $v['seats'] ?> seats
                </span>
                <?php if ($v['color']): ?>
                <span class="badge bg-light text-dark border"><?= htmlspecialchars($v['color']) ?></span>
                <?php endif; ?>
                <?php if (!empty($v['has_ac'])): ?>
                <span class="badge bg-info text-white"><i class="fas fa-snowflake me-1"></i>AC</span>
                <?php endif; ?>
                <?php if (!empty($v['has_wifi'])): ?>
                <span class="badge bg-success text-white"><i class="fas fa-wifi me-1"></i>WiFi</span>
                <?php endif; ?>
                <?php if (!empty($v['is_accessible'])): ?>
                <span class="badge bg-warning text-dark"><i class="fas fa-wheelchair me-1"></i>Accessible</span>
                <?php endif; ?>
                <?php if ($v['luggage'] && $v['luggage'] !== 'none'): ?>
                <span class="badge bg-secondary">
                  <i class="fas fa-suitcase me-1"></i><?= Vehicle::LUGGAGE[$v['luggage']] ?? $v['luggage'] ?>
                </span>
                <?php endif; ?>
              </div>

              <?php if ($v['notes']): ?>
              <p class="text-muted small fst-italic mb-3">"<?= htmlspecialchars($v['notes']) ?>"</p>
              <?php endif; ?>

              <div class="d-flex gap-2 mt-auto">
                <a href="vehicles.php?edit=<?= $v['id'] ?>" class="btn-fd-outline btn btn-sm flex-fill">
                  <i class="fas fa-edit me-1"></i>Edit
                </a>
                <form method="POST" onsubmit="return confirm('Remove this vehicle?')" class="flex-fill">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="vehicle_id" value="<?= $v['id'] ?>">
                  <button class="btn-fd-danger btn btn-sm w-100">
                    <i class="fas fa-trash me-1"></i>Remove
                  </button>
                </form>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Type card toggle highlight
document.querySelectorAll('.type-radio').forEach(radio => {
    radio.addEventListener('change', () => {
        document.querySelectorAll('.type-label').forEach(l => {
            l.classList.remove('border-primary','bg-primary','text-white');
            l.classList.add('border-secondary');
        });
        const lbl = radio.nextElementSibling;
        lbl.classList.add('border-primary','bg-primary','text-white');
        lbl.classList.remove('border-secondary');
    });
});
</script>
</body>
</html>
