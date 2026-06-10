<?php
require_once '../server/session.php';
require_once '../classes/notifications.php';
requireOrgLogin();

$org = getCurrentOrg();
if (!$org) {
    logoutOrg();
    header('Location: org_login.php');
    exit();
}

$pdo = getDB();
$tab = $_GET['tab'] ?? 'overview';

// Get members
$membersStmt = $pdo->prepare("
    SELECT * FROM organization_members
    WHERE organization_id = ?
    ORDER BY created_at ASC
");
$membersStmt->execute([$org['id']]);
$members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get ride usage (bookings that applied this org's discount code)
$usageStmt = $pdo->prepare("
    SELECT COUNT(*) as total_bookings, SUM(seats) as total_seats,
           SUM(CASE WHEN status='confirmed' THEN 1 ELSE 0 END) as confirmed,
           SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM bookings
    WHERE UPPER(discount_code) = UPPER(?)
");
$usageStmt->execute([$org['discount_code'] ?? '']);
$usage = $usageStmt->fetch(PDO::FETCH_ASSOC);

// Handle member add
$addMsg = ''; $addErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_member'])) {
    $name  = trim($_POST['member_name'] ?? '');
    $email = trim($_POST['member_email'] ?? '');
    $phone = trim($_POST['member_phone'] ?? '');
    $role  = $_POST['member_role'] ?? 'member';

    if (empty($name)) {
        $addErr = 'Member name is required.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO organization_members (organization_id, name, email, phone, role)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$org['id'], $name, $email ?: null, $phone ?: null, $role]);
            $addMsg = 'Member added successfully.';

            // If this email belongs to a ForsaDrive account, notify that user so
            // they see they've been added and which discount code to use.
            if ($email) {
                $uStmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?)");
                $uStmt->execute([$email]);
                $memberUserId = $uStmt->fetchColumn();
                if ($memberUserId) {
                    $code = $org['discount_code'] ?? '';
                    (new Notifications($pdo))->create(
                        (int)$memberUserId,
                        'You joined ' . $org['name'],
                        "You were added as a member of {$org['name']}."
                            . ($code ? " Use discount code {$code} for {$org['discount_percent']}% off your rides." : ''),
                        'success'
                    );
                    $addMsg = 'Member added — a notification was sent to their ForsaDrive account.';
                }
            }

            // Refresh members
            $membersStmt->execute([$org['id']]);
            $members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $addErr = 'Failed to add member: ' . $e->getMessage();
        }
    }
}

// Handle member delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_member'])) {
    $memberId = (int)$_POST['member_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM organization_members WHERE id = ? AND organization_id = ?");
        $stmt->execute([$memberId, $org['id']]);
        // Refresh members
        $membersStmt->execute([$org['id']]);
        $members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $addErr = 'Failed to remove member.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($org['name']) ?> — Organization Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../Css/index.css?v=<?= time() ?>">
    <style>
        body { background: #0a1628; color: #fff; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
        .navbar {
            background: linear-gradient(135deg, #1a2a4a 0%, #0f1f35 100%);
            padding: 16px 20px;
            border-bottom: 1px solid #253552;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .navbar-brand {
            font-size: 22px;
            font-weight: 800;
            color: #FFA500;
        }
        .navbar-right {
            display: flex;
            gap: 16px;
            align-items: center;
        }
        .navbar-right a, .navbar-right button {
            color: #ccc;
            text-decoration: none;
            font-size: 14px;
            background: none;
            border: none;
            cursor: pointer;
            transition: color 0.3s;
        }
        .navbar-right a:hover, .navbar-right button:hover {
            color: #FFA500;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 20px;
        }
        .header-content h1 {
            font-size: 32px;
            margin: 0 0 8px 0;
        }
        .header-content p {
            color: #aaa;
            margin: 0;
        }
        .header-meta {
            display: flex;
            gap: 20px;
        }
        .meta-item {
            text-align: right;
        }
        .meta-label {
            font-size: 12px;
            color: #888;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .meta-value {
            font-size: 24px;
            font-weight: 700;
            color: #FFA500;
        }
        .tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 24px;
            border-bottom: 1px solid #253552;
            padding-bottom: 0;
        }
        .tab-btn {
            padding: 12px 20px;
            background: none;
            border: none;
            color: #888;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            margin-bottom: -1px;
        }
        .tab-btn.active {
            color: #FFA500;
            border-bottom-color: #FFA500;
        }
        .tab-btn:hover {
            color: #FFA500;
        }
        .card {
            background: #1a2a4a;
            border: 1px solid #253552;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .card-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .code-box {
            background: #0f1f35;
            border: 1px solid #2a4060;
            border-radius: 10px;
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .code-display {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: 2px;
            color: #FFA500;
            font-family: monospace;
        }
        .code-copy-btn {
            padding: 10px 16px;
            background: #FFA500;
            color: #0a1628;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .code-copy-btn:hover {
            transform: scale(1.05);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-box {
            background: #0f1f35;
            border: 1px solid #253552;
            border-radius: 10px;
            padding: 16px;
            text-align: center;
        }
        .stat-label {
            font-size: 12px;
            color: #888;
            margin-bottom: 8px;
        }
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #FFA500;
        }
        .members-list {
            background: #0f1f35;
            border-radius: 10px;
            overflow: hidden;
        }
        .member-item {
            padding: 16px;
            border-bottom: 1px solid #253552;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .member-item:last-child {
            border-bottom: none;
        }
        .member-info {
            flex: 1;
        }
        .member-name {
            font-weight: 600;
            margin-bottom: 4px;
        }
        .member-meta {
            font-size: 12px;
            color: #888;
        }
        .member-role {
            display: inline-block;
            padding: 4px 10px;
            background: #FFA500;
            color: #0a1628;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            margin-right: 8px;
        }
        .delete-btn {
            padding: 6px 12px;
            background: #c33;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: background 0.3s;
        }
        .delete-btn:hover {
            background: #a22;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #ccc;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            background: #0f1f35;
            border: 1px solid #253552;
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
            box-sizing: border-box;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #FFA500;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .submit-btn {
            padding: 12px 20px;
            background: #FFA500;
            color: #0a1628;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .submit-btn:hover {
            transform: translateY(-2px);
        }
        .success-msg {
            background: #0f5f2f;
            border: 1px solid #1a8a45;
            color: #6ff;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 13px;
        }
        .error-msg {
            background: #5f0f0f;
            border: 1px solid #8a1a1a;
            color: #f88;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 13px;
        }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #888;
        }
    </style>
</head>
<body>
<div class="navbar">
    <div class="navbar-brand">
        <i class="fas fa-building"></i> ForsaDrive Organizations
    </div>
    <div class="navbar-right">
        <span><?= htmlspecialchars($org['name']) ?></span>
        <a href="?logout=1" onclick="return confirm('Logout from organization dashboard?')">Logout</a>
    </div>
</div>

<?php
// Handle logout
if (isset($_GET['logout'])) {
    logoutOrg();
    header('Location: org_login.php');
    exit();
}
?>

<div class="container">
    <div class="header">
        <div class="header-content">
            <h1><?= htmlspecialchars($org['name']) ?></h1>
            <p><?= htmlspecialchars($org['type']) ?> • Contact: <?= htmlspecialchars($org['contact_name']) ?></p>
        </div>
        <div class="header-meta">
            <div class="meta-item">
                <div class="meta-label">Status</div>
                <div class="meta-value" style="color: <?= $org['status'] === 'active' || $org['status'] === 'approved' ? '#6f6' : '#f88' ?>;">
                    <?= ucfirst(htmlspecialchars($org['status'])) ?>
                </div>
            </div>
            <div class="meta-item">
                <div class="meta-label">Members</div>
                <div class="meta-value"><?= count($members) ?></div>
            </div>
        </div>
    </div>

    <!-- Discount Code Section -->
    <?php if ($org['status'] === 'active' || $org['status'] === 'approved'): ?>
        <div class="card">
            <div class="card-title">
                <i class="fas fa-ticket-alt" style="color: #FFA500;"></i>
                Your Discount Code
            </div>
            <div class="code-box">
                <div class="code-display"><?= htmlspecialchars($org['discount_code'] ?? 'PENDING') ?></div>
                <button class="code-copy-btn" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($org['discount_code'] ?? '') ?>'); alert('Code copied!');">
                    <i class="fas fa-copy"></i> Copy
                </button>
            </div>
            <p style="color: #aaa; font-size: 13px; margin-top: 12px;">
                Share this code with your members. They'll receive <strong><?= $org['discount_percent'] ?>% off</strong> every ride.
            </p>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn <?= $tab === 'overview' ? 'active' : '' ?>" onclick="window.location.href='?tab=overview'">
            <i class="fas fa-chart-pie"></i> Overview
        </button>
        <button class="tab-btn <?= $tab === 'members' ? 'active' : '' ?>" onclick="window.location.href='?tab=members'">
            <i class="fas fa-users"></i> Members
        </button>
        <button class="tab-btn <?= $tab === 'usage' ? 'active' : '' ?>" onclick="window.location.href='?tab=usage'">
            <i class="fas fa-chart-bar"></i> Usage
        </button>
    </div>

    <!-- Overview Tab -->
    <?php if ($tab === 'overview'): ?>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-label">Discount Percentage</div>
                <div class="stat-value"><?= $org['discount_percent'] ?>%</div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Organization Members</div>
                <div class="stat-value"><?= count($members) ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Total Bookings</div>
                <div class="stat-value"><?= $usage['total_bookings'] ?? 0 ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Total Seats Booked</div>
                <div class="stat-value"><?= $usage['total_seats'] ?? 0 ?></div>
            </div>
        </div>

        <div class="card">
            <div class="card-title"><i class="fas fa-info-circle"></i> Organization Details</div>
            <table style="width: 100%; border-collapse: collapse;">
                <tr style="border-bottom: 1px solid #253552;">
                    <td style="padding: 12px 0; color: #888; width: 150px;">Organization Type</td>
                    <td style="padding: 12px 0;"><?= htmlspecialchars($org['type']) ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #253552;">
                    <td style="padding: 12px 0; color: #888;">Contact Person</td>
                    <td style="padding: 12px 0;"><?= htmlspecialchars($org['contact_name']) ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #253552;">
                    <td style="padding: 12px 0; color: #888;">Contact Email</td>
                    <td style="padding: 12px 0;"><?= htmlspecialchars($org['contact_email']) ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #253552;">
                    <td style="padding: 12px 0; color: #888;">Phone</td>
                    <td style="padding: 12px 0;"><?= htmlspecialchars($org['phone'] ?? '—') ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #253552;">
                    <td style="padding: 12px 0; color: #888;">Address</td>
                    <td style="padding: 12px 0;"><?= htmlspecialchars($org['address'] ?? '—') ?></td>
                </tr>
                <tr>
                    <td style="padding: 12px 0; color: #888;">Applied On</td>
                    <td style="padding: 12px 0;"><?= htmlspecialchars($org['created_at']) ?></td>
                </tr>
            </table>
        </div>
    <?php endif; ?>

    <!-- Members Tab -->
    <?php if ($tab === 'members'): ?>
        <?php if (!empty($addMsg)): ?>
            <div class="success-msg"><?= htmlspecialchars($addMsg) ?></div>
        <?php endif; ?>
        <?php if (!empty($addErr)): ?>
            <div class="error-msg"><?= htmlspecialchars($addErr) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-title"><i class="fas fa-user-plus"></i> Add New Member</div>
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="member_name" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="member_email">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" name="member_phone">
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select name="member_role">
                            <option value="member">Member</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <button type="submit" name="add_member" class="submit-btn">Add Member</button>
            </form>
        </div>

        <div class="card">
            <div class="card-title"><i class="fas fa-list"></i> Members (<?= count($members) ?>)</div>
            <?php if (empty($members)): ?>
                <div class="empty-state">
                    <i class="fas fa-users" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                    <p>No members added yet. Add your first member above.</p>
                </div>
            <?php else: ?>
                <div class="members-list">
                    <?php foreach ($members as $m): ?>
                        <div class="member-item">
                            <div class="member-info">
                                <div class="member-name"><?= htmlspecialchars($m['name']) ?></div>
                                <div class="member-meta">
                                    <?php if (!empty($m['email'])): ?><?= htmlspecialchars($m['email']) ?><br><?php endif; ?>
                                    <?php if (!empty($m['phone'])): ?><?= htmlspecialchars($m['phone']) ?><?php endif; ?>
                                </div>
                            </div>
                            <span class="member-role"><?= htmlspecialchars($m['role']) ?></span>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this member?');">
                                <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                                <button type="submit" name="delete_member" class="delete-btn"><i class="fas fa-trash"></i> Remove</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Usage Tab -->
    <?php if ($tab === 'usage'): ?>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-label">Total Bookings</div>
                <div class="stat-value"><?= $usage['total_bookings'] ?? 0 ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Confirmed</div>
                <div class="stat-value" style="color: #6f6;"><?= $usage['confirmed'] ?? 0 ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Cancelled</div>
                <div class="stat-value" style="color: #f88;"><?= $usage['cancelled'] ?? 0 ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Total Passengers</div>
                <div class="stat-value"><?= $usage['total_seats'] ?? 0 ?></div>
            </div>
        </div>

        <div class="card">
            <div class="card-title"><i class="fas fa-info-circle"></i> Discount Usage Summary</div>
            <p style="color: #aaa; margin-top: 0;">
                Showing all rides that have used your discount code <strong><?= htmlspecialchars($org['discount_code'] ?? 'N/A') ?></strong>.
            </p>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
