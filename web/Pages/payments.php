<?php
require_once '../server/session.php';
require_once '../server/language.php';
require_once '../classes/users.php';
require_once '../classes/payments.php';
require_once '../classes/promo_codes.php';

requireRegularUser();

$db  = getDB();
$uid = $_SESSION['user_id'];

$userObj  = new User($db);
$userObj->load($uid);
$payments = new Payments($db);

$balance  = $userObj->getBalance();
$currency = 'TND';  // ForsaDrive serves Tunisia only

$msg     = '';
$msgType = '';

// ── Load referral info ─────────────────────────────────────────────────────
$refStmt = $db->prepare("SELECT referral_code, referred_by FROM users WHERE id=?");
$refStmt->execute([$uid]);
$refRow = $refStmt->fetch(PDO::FETCH_ASSOC);

// Generate referral code if missing
if (empty($refRow['referral_code'])) {
    $code = 'FD' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $db->prepare("UPDATE users SET referral_code=? WHERE id=?")->execute([$code, $uid]);
    $refRow['referral_code'] = $code;
}
$myReferralCode = $refRow['referral_code'];

// Count how many users I referred
$refCountStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE referred_by=?");
$refCountStmt->execute([$uid]);
$referralCount = (int)$refCountStmt->fetchColumn();

// ── Simulate top-up (no real payment gateway) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_funds'])) {
    $amount = (float)($_POST['amount'] ?? 0);
    if ($amount <= 0) {
        $msg = 'Amount must be greater than 0.'; $msgType = 'danger';
    } elseif ($amount > 500) {
        $msg = 'Maximum simulated top-up is 500 TND per transaction.'; $msgType = 'danger';
    } else {
        $db->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$amount, $uid]);
        $payments->addPayment($uid, $amount, 'Simulated top-up (demo)');
        $_SESSION['user_data']['balance'] = $balance + $amount;
        header('Location: payments.php?ok=1'); exit();
    }
}

// ── Referral code redemption ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_referral'])) {
    $inputCode = strtoupper(trim($_POST['referral_input'] ?? ''));
    if ($inputCode === '') {
        $msg = 'Please enter a referral code.'; $msgType = 'danger';
    } elseif ($inputCode === $myReferralCode) {
        $msg = 'You cannot use your own referral code.'; $msgType = 'danger';
    } elseif (!empty($refRow['referred_by'])) {
        $msg = 'You have already used a referral code.'; $msgType = 'danger';
    } else {
        $refOwner = $db->prepare("SELECT id FROM users WHERE referral_code=?");
        $refOwner->execute([$inputCode]);
        $ownerRow = $refOwner->fetch(PDO::FETCH_ASSOC);
        if (!$ownerRow) {
            $msg = 'Invalid referral code.'; $msgType = 'danger';
        } else {
            $ownerId = (int)$ownerRow['id'];
            // Give 10 TND to the referrer
            $db->prepare("UPDATE users SET balance = balance + 10 WHERE id = ?")->execute([$ownerId]);
            $payments->addPayment($ownerId, 10, "Referral bonus — new user joined with your code");
            // Give 5 TND welcome bonus to the new user
            $db->prepare("UPDATE users SET balance = balance + 5, referred_by = ? WHERE id = ?")->execute([$ownerId, $uid]);
            $payments->addPayment($uid, 5, "Welcome bonus — referral code redeemed");
            $_SESSION['user_data']['balance'] = $balance + 5;
            header('Location: payments.php?ref=1'); exit();
        }
    }
}

// ── Promo code redemption ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_promo'])) {
    $promoSvc = new PromoCodes($db);
    [$pOk, $pMsg, $pAmt] = $promoSvc->redeem($uid, $_POST['promo_code'] ?? '');
    if ($pOk) {
        $_SESSION['user_data']['balance'] = $balance + $pAmt;
        header('Location: payments.php?promo=' . urlencode($pMsg)); exit();
    }
    $msg = $pMsg; $msgType = 'danger';
}

if (isset($_GET['ok']))    { $msg = 'Funds added successfully!'; $msgType = 'success'; }
if (isset($_GET['ref']))   { $msg = 'Referral code applied! You received 5 TND and your referrer got 10 TND.'; $msgType = 'success'; }
if (isset($_GET['promo'])) { $msg = $_GET['promo']; $msgType = 'success'; }

$history = $payments->getPaymentHistory($uid);

$pageTitle = 'Payments';
include '../include/header.php';
include '../include/sidebar.php';
?>

<div class="fd-main">
  <div class="page-header">
    <h1><i class="fas fa-wallet me-2 text-primary"></i>Payments</h1>
    <span class="text-muted small">Currency: Tunisian Dinar (TND)</span>
  </div>

  <?php if ($msg): ?>
    <div class="alert-fd-<?= $msgType ?>"><i class="fas fa-<?= $msgType==='success' ? 'check' : 'exclamation' ?>-circle me-2"></i><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Left column: balance + top-up + referral -->
    <div class="col-12 col-md-5 col-lg-4">

      <!-- Balance card -->
      <div class="fd-card text-center mb-3" style="background:linear-gradient(135deg,#0a2540,#0d6efd);color:#fff;">
        <div style="opacity:.75;font-size:.85rem;margin-bottom:.25rem;">Current Balance</div>
        <div style="font-size:2.8rem;font-weight:800;line-height:1.1">
          <?= number_format($balance, 3) ?>
        </div>
        <div style="opacity:.8;margin-top:.2rem;">TND</div>
      </div>

      <!-- Simulated top-up -->
      <div class="fd-card mb-3">
        <div class="card-title"><i class="fas fa-plus-circle text-success"></i> Add Funds <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem;">Demo</span></div>
        <p class="text-muted small mb-3">This is a simulated top-up for demonstration purposes. In production, this will connect to a real payment gateway (e.g. Flouci, Konnect).</p>
        <form method="POST">
          <div class="mb-3">
            <label class="fd-form-label">Amount (TND)</label>
            <div class="input-group">
              <span class="input-group-text">TND</span>
              <input type="number" name="amount" class="form-control" min="1" max="500" step="0.500" placeholder="0.000" required>
            </div>
          </div>
          <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ([5, 10, 20, 50] as $q): ?>
            <button type="button" class="btn btn-outline-secondary btn-sm quick-amt" data-val="<?= $q ?>"><?= $q ?> TND</button>
            <?php endforeach; ?>
          </div>
          <button type="submit" name="add_funds" class="btn-fd-success btn w-100">
            <i class="fas fa-coins me-1"></i>Simulate Top-Up
          </button>
        </form>
      </div>

      <!-- Promo code card -->
      <div class="fd-card mb-3">
        <div class="card-title"><i class="fas fa-tags text-primary"></i> Redeem a Promo Code</div>
        <p class="text-muted small mb-2">Have a promo code? Redeem it to add credit to your wallet.</p>
        <form method="POST">
          <div class="input-group">
            <input type="text" name="promo_code" class="form-control" placeholder="e.g. FD3A9C1F" maxlength="24" style="text-transform:uppercase;" required>
            <button type="submit" name="redeem_promo" class="btn-fd-primary btn">Redeem</button>
          </div>
        </form>
      </div>

      <!-- Referral card -->
      <div class="fd-card">
        <div class="card-title"><i class="fas fa-gift text-warning"></i> Referral Program</div>
        <p class="text-muted small mb-2">Share your code and earn <strong>10 TND</strong> for every friend who joins. They get <strong>5 TND</strong> as a welcome bonus.</p>

        <!-- My referral code -->
        <label class="fd-form-label">Your Referral Code</label>
        <div class="input-group mb-3">
          <input type="text" class="form-control fw-bold" id="refCode" value="<?= htmlspecialchars($myReferralCode) ?>" readonly style="letter-spacing:.1em;background:#f8fafc;">
          <button type="button" class="btn btn-outline-secondary" onclick="copyRef()" title="Copy">
            <i class="fas fa-copy" id="copyIcon"></i>
          </button>
        </div>
        <div class="text-muted small mb-3"><i class="fas fa-users me-1"></i><?= $referralCount ?> friend(s) joined using your code.</div>

        <?php if (empty($refRow['referred_by'])): ?>
        <!-- Redeem someone else's code -->
        <form method="POST">
          <label class="fd-form-label">Enter a Friend's Code</label>
          <div class="input-group">
            <input type="text" name="referral_input" class="form-control" placeholder="e.g. FD3A7B2C" maxlength="10" style="text-transform:uppercase;">
            <button type="submit" name="redeem_referral" class="btn-fd-primary btn">Redeem</button>
          </div>
        </form>
        <?php else: ?>
        <div class="alert-fd-info small"><i class="fas fa-check-circle me-1"></i>You already redeemed a referral code.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Transaction history -->
    <div class="col-12 col-md-7 col-lg-8">
      <div class="fd-card">
        <div class="card-title"><i class="fas fa-history text-primary"></i> Transaction History</div>
        <?php if (empty($history)): ?>
          <div class="empty-state"><i class="fas fa-receipt"></i><p>No transactions yet</p></div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr><th>Description</th><th class="text-end">Amount</th><th class="text-end d-none d-sm-table-cell">Date</th></tr>
              </thead>
              <tbody>
                <?php foreach ($history as $tx): ?>
                <tr>
                  <td>
                    <div class="d-flex align-items-center gap-2">
                      <div class="stat-card p-2 rounded-circle <?= $tx['amount'] > 0 ? 'icon green' : 'icon red' ?>" style="width:32px;height:32px;min-width:32px;font-size:.8rem">
                        <i class="fas fa-arrow-<?= $tx['amount'] > 0 ? 'down' : 'up' ?>"></i>
                      </div>
                      <div>
                        <div class="fw-semibold small"><?= htmlspecialchars($tx['description'] ?? 'Transaction') ?></div>
                        <div class="text-muted d-sm-none" style="font-size:.72rem"><?= date('d M Y, H:i', strtotime($tx['created_at'])) ?></div>
                      </div>
                    </div>
                  </td>
                  <td class="text-end fw-bold <?= $tx['amount'] > 0 ? 'text-success' : 'text-danger' ?>">
                    <?= $tx['amount'] > 0 ? '+' : '' ?><?= number_format($tx['amount'], 3) ?> TND
                  </td>
                  <td class="text-end text-muted small d-none d-sm-table-cell">
                    <?= date('d M Y, H:i', strtotime($tx['created_at'])) ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.quick-amt').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelector('input[name=amount]').value = btn.dataset.val;
    });
});
function copyRef() {
    const val = document.getElementById('refCode').value;
    navigator.clipboard.writeText(val).then(() => {
        const icon = document.getElementById('copyIcon');
        icon.className = 'fas fa-check text-success';
        setTimeout(() => icon.className = 'fas fa-copy', 2000);
    });
}
// Force uppercase on referral input
const ri = document.querySelector('input[name=referral_input]');
if (ri) ri.addEventListener('input', function(){ this.value = this.value.toUpperCase(); });
</script>
</body>
</html>
