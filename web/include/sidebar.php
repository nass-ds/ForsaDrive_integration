<?php
require_once __DIR__ . '/../server/session.php';
require_once __DIR__ . '/../server/language.php';

$username   = 'Guest';
$profilePic = '../Src/default.jpg';
$userRole   = 'Guest';
$isDriver   = false;
$isAdmin    = false;
$userId     = 0;
$unreadNotif = 0;
$unreadMsg   = 0;

if (isLoggedIn() && isset($_SESSION['user_data'])) {
    $ud = $_SESSION['user_data'];
    $username   = $ud['username'] ?? 'User';
    $isDriver   = !empty($ud['is_driver']);
    $isAdmin    = !empty($ud['is_admin']);
    $userId     = $ud['id'] ?? 0;

    // Always read fresh picture from DB so it shows after upload without logout
    try {
        $db = getDB();
        $picStmt = $db->prepare("SELECT picture FROM users WHERE id=?");
        $picStmt->execute([$userId]);
        $picRow = $picStmt->fetch(PDO::FETCH_ASSOC);
        $profilePic = (!empty($picRow['picture'])) ? $picRow['picture'] : '../Src/default.jpg';
        // Sync back to session
        $_SESSION['user_data']['profile_picture'] = $profilePic;
    } catch (Exception $e) {
        $profilePic = $ud['profile_picture'] ?? '../Src/default.jpg';
    }

    $roles = [];
    if (!empty($ud['is_student']))  $roles[] = 'Student';
    if ($isDriver)                  $roles[] = 'Driver';
    $roles[] = 'Passenger';
    $userRole = implode(' & ', $roles);

    // Unread counts
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
        $stmt->execute([$userId]);
        $unreadNotif = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT COUNT(*) FROM messages m
            JOIN conversations c ON c.id = m.conversation_id
            WHERE m.is_read=0 AND m.sender_id != ?
              AND (c.user_a=? OR c.user_b=?)
        ");
        $stmt->execute([$userId, $userId, $userId]);
        $unreadMsg = (int)$stmt->fetchColumn();
    } catch (Exception $e) { /* silently ignore */ }
}

$cur = basename($_SERVER['PHP_SELF']);
function navLink(string $href, string $icon, string $label, string $cur, string $page, int $badge = 0): string {
    $active = ($cur === $page) ? ' active' : '';
    $b = $badge > 0 ? "<span class='badge-pill'>$badge</span>" : '';
    return "<a href=\"$href\" class=\"nav-link$active\"><i class=\"fas fa-$icon\"></i> $label $b</a>";
}
?>

<!-- Mobile top bar -->
<div class="fd-topbar" id="fdTopbar">
    <button class="btn btn-link p-0 text-white me-2" id="sidebarToggle">
        <i class="fas fa-bars fa-lg"></i>
    </button>
    <span class="brand">Forsa<span>Drive</span></span>
    <?php if (isLoggedIn()): ?>
    <a href="notifications.php" class="notif-btn">
        <i class="fas fa-bell"></i>
        <?php if ($unreadNotif > 0): ?><span class="dot"></span><?php endif; ?>
    </a>
    <?php endif; ?>
</div>

<!-- Overlay -->
<div class="fd-overlay" id="fdOverlay"></div>

<!-- Sidebar -->
<aside class="fd-sidebar" id="fdSidebar">
    <div class="sidebar-brand">
        <h4>Forsa<span>Drive</span></h4>
        <div style="font-size:.72rem;color:rgba(255,255,255,.5);margin-top:.2rem;">Carpooling Platform</div>
    </div>

    <?php if (isLoggedIn()): ?>
    <div class="profile-box">
        <img src="<?= htmlspecialchars($profilePic) ?>" alt="Profile">
        <div>
            <div class="name"><?= htmlspecialchars($username) ?></div>
            <div class="role"><?= htmlspecialchars($userRole) ?></div>
        </div>
    </div>
    <?php endif; ?>

    <nav class="mt-2 pb-4">
        <div class="nav-section-label">Main</div>
        <?= navLink('interface.php', 'home', 'Dashboard', $cur, 'interface.php') ?>
        <?= navLink('book_ride.php', 'search', 'Search Rides', $cur, 'book_ride.php') ?>
        <?= navLink('my_rides.php', 'car', 'My Rides', $cur, 'my_rides.php') ?>

        <?php if ($isDriver): ?>
        <div class="nav-section-label">Driver</div>
        <?= navLink('driver_dashboard.php', 'chart-line', 'Driver Dashboard', $cur, 'driver_dashboard.php') ?>
        <?= navLink('offre_ride.php', 'plus-circle', 'Create Ride', $cur, 'offre_ride.php') ?>
        <?= navLink('booking_requests.php', 'inbox', 'Booking Requests', $cur, 'booking_requests.php', $unreadNotif) ?>
        <?php endif; ?>

        <div class="nav-section-label">Account</div>
        <?= navLink('payments.php', 'wallet', 'Payments', $cur, 'payments.php') ?>
        <?= navLink('ratings.php', 'star', 'Ratings', $cur, 'ratings.php') ?>
        <?= navLink('vehicles.php', 'car-side', 'My Vehicles', $cur, 'vehicles.php') ?>
        <?= navLink('complaints.php', 'flag', 'Complaints', $cur, 'complaints.php') ?>
        <?= navLink('messages.php', 'comments', 'Messages', $cur, 'messages.php', $unreadMsg) ?>
        <?= navLink('notifications.php', 'bell', 'Notifications', $cur, 'notifications.php', $unreadNotif) ?>
        <?= navLink('helpdesk.php', 'headset', 'Help &amp; Support', $cur, 'helpdesk.php') ?>
        <?= navLink('settings.php', 'cog', 'Settings', $cur, 'settings.php') ?>

        <?php if ($isAdmin): ?>
        <div class="nav-section-label">Admin</div>
        <?= navLink('admin.php', 'shield-alt', 'Admin Panel', $cur, 'admin.php') ?>
        <?php endif; ?>

        <!-- Language switcher -->
        <div class="nav-section-label">Language</div>
        <div class="px-3 pb-2 d-flex gap-1">
            <?php foreach ($GLOBALS['languages'] as $code => $info): ?>
            <a href="<?= htmlspecialchars(langUrl($code)) ?>"
               class="btn btn-sm <?= ($GLOBALS['selectedLang'] ?? 'en') === $code ? 'btn-primary' : 'btn-outline-secondary' ?>"
               style="flex:1;padding:.25rem;font-size:.72rem;" title="<?= $info['name'] ?>">
                <?= $info['flag'] ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="nav-section-label" style="margin-top:auto"></div>
        <a href="../index.php?logout=1" class="nav-link text-danger-emphasis">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>
</aside>

<script>
(function(){
    const sidebar  = document.getElementById('fdSidebar');
    const overlay  = document.getElementById('fdOverlay');
    const toggle   = document.getElementById('sidebarToggle');
    if (!toggle) return;
    function open()  { sidebar.classList.add('open'); overlay.classList.add('show'); }
    function close() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }
    toggle.addEventListener('click', () => sidebar.classList.contains('open') ? close() : open());
    overlay.addEventListener('click', close);
})();
</script>
