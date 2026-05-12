<?php
require_once '../server/session.php';
require_once '../server/language.php';
require_once '../classes/notifications.php';

requireRegularUser();

$db     = getDB();
$userId = (int)$_SESSION['user_id'];
$notifSvc = new Notifications($db);

// Mark all as read on page load
$notifSvc->markRead($userId);

// Fetch notifications (newest first)
$notifications = $notifSvc->getForUser($userId, 50);

$pageTitle = 'Notifications';
require_once '../include/header.php';
require_once '../include/sidebar.php';

/**
 * Human-readable relative time.
 */
function notifTimeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day' . (floor($diff/86400) > 1 ? 's' : '') . ' ago';
    return date('d M Y', strtotime($datetime));
}
?>

<div class="fd-main">
    <div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <h1><i class="fas fa-bell me-2"></i>Notifications</h1>
            <p class="text-muted mb-0">All your recent activity and updates.</p>
        </div>
        <?php if (!empty($notifications)): ?>
            <form method="POST" action="">
                <input type="hidden" name="mark_all_read" value="1">
                <button type="submit" class="btn-fd-outline">
                    <i class="fas fa-check-double me-1"></i>Mark All Read
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (empty($notifications)): ?>
        <div class="empty-state mt-4">
            <i class="fas fa-bell-slash fa-3x mb-3 text-muted"></i>
            <h5>No Notifications</h5>
            <p class="text-muted">You're all caught up! Check back later for updates.</p>
        </div>
    <?php else: ?>
        <div class="mt-3" id="notifList">
            <?php foreach ($notifications as $notif):
                $wasUnread  = !(bool)$notif['is_read'];
                $iconClass  = Notifications::typeIcon($notif['type'] ?? 'info');
                $timeStr    = !empty($notif['created_at']) ? notifTimeAgo($notif['created_at']) : '';
                $link       = !empty($notif['link']) ? htmlspecialchars($notif['link']) : '#';
                $isLinkable = !empty($notif['link']);
            ?>
                <div class="notif-item<?= $wasUnread ? ' unread' : '' ?>" id="notif-<?= (int)$notif['id'] ?>">
                    <div class="d-flex gap-3 align-items-start">
                        <div class="pt-1" style="flex-shrink:0;font-size:1.25rem;">
                            <i class="fas fa-<?= htmlspecialchars($iconClass) ?>"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="notif-title">
                                <?php if ($isLinkable): ?>
                                    <a href="<?= $link ?>" class="text-decoration-none text-inherit stretched-link">
                                        <?= htmlspecialchars($notif['title'] ?? '') ?>
                                    </a>
                                <?php else: ?>
                                    <?= htmlspecialchars($notif['title'] ?? '') ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($notif['body'])): ?>
                                <div class="notif-body"><?= htmlspecialchars($notif['body']) ?></div>
                            <?php endif; ?>
                            <div class="notif-time"><?= $timeStr ?></div>
                        </div>
                        <?php if ($wasUnread): ?>
                            <span class="badge bg-primary rounded-pill align-self-center ms-auto"
                                  style="flex-shrink:0;font-size:.65rem;">New</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
