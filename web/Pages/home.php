<?php
require_once __DIR__ . '/../server/session.php';
require_once __DIR__ . '/../server/language.php';
require_once __DIR__ . '/../classes/rides.php';
require_once __DIR__ . '/../classes/notifications.php';

requireRegularUser();

$db  = getDB();
$ud  = $_SESSION['user_data'];
$uid = (int)$ud['id'];

$rideSvc  = new Ride($db);
$notifSvc = new Notifications($db);

// Stats (same source as interface.php)
$upcomingRides  = $rideSvc->getUpcomingRides($uid);
$completedRides = $rideSvc->getCompletedRidesCount($uid);
$unreadNotif    = $notifSvc->unreadCount($uid);

// Preview of available rides (first 5; same filter as the dashboard would show on load)
$previewRides = array_slice($rideSvc->getAvailableRides([]), 0, 5);

$isDriver = !empty($ud['is_driver']);

// Student approved status drives the 50% discount line on ride cards
$stuStmt = $db->prepare("SELECT student_status FROM users WHERE id=?");
$stuStmt->execute([$uid]);
$isStudent = (($stuStmt->fetchColumn() ?: 'none') === 'approved');

// Greeting follows the local hour, matching mobile l10n.goodMorning/Afternoon/Evening
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning'
          : ($hour < 17 ? 'Good afternoon' : 'Good evening');

// Quick-route chips mirror mobile _quickRouteData
$quickRoutes = [
    ['Tunis',    'Sousse'],
    ['Tunis',    'Sfax'],
    ['Ariana',   'Bizerte'],
    ['La Marsa', 'Nabeul'],
];

$pageTitle = 'Home';
include __DIR__ . '/../include/header.php';
include __DIR__ . '/../include/sidebar.php';
?>

<style>
    /* Home-specific styling — keeps page distinct from interface.php */
    .home-hero {
        background: linear-gradient(135deg, #0a2540 0%, #0d3b6e 60%, #1a5276 100%);
        border-radius: 18px;
        padding: 2rem 1.75rem;
        color: #fff;
        margin-bottom: 1.5rem;
        position: relative;
        overflow: hidden;
    }
    .home-hero::after {
        content: '';
        position: absolute;
        right: -60px; top: -60px;
        width: 220px; height: 220px;
        background: radial-gradient(circle, rgba(245,158,11,.18), transparent 70%);
        border-radius: 50%;
    }
    .home-hero h1 {
        font-size: 1.6rem;
        font-weight: 800;
        margin: 0;
        position: relative;
        z-index: 1;
    }
    .home-hero .sub {
        opacity: .7;
        font-size: .9rem;
        margin-top: .25rem;
        position: relative;
        z-index: 1;
    }
    .home-hero .hero-search {
        background: rgba(255,255,255,.08);
        border: 1px solid rgba(255,255,255,.12);
        border-radius: 14px;
        padding: 1rem;
        margin-top: 1.25rem;
        backdrop-filter: blur(6px);
        position: relative;
        z-index: 1;
    }
    .home-hero .hero-search .form-control {
        background: rgba(255,255,255,.95);
        border: 0;
    }
    .quick-actions {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 12px;
        margin-bottom: 1.5rem;
    }
    .qa-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 1.1rem;
        text-decoration: none;
        color: inherit;
        transition: transform .15s, box-shadow .15s;
        display: block;
    }
    .qa-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0,0,0,.08);
        color: inherit;
    }
    .qa-card .qa-icon {
        width: 44px; height: 44px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.1rem;
        margin-bottom: .6rem;
    }
    .qa-card.blue   .qa-icon { background: rgba(13,110,253,.12);  color: #0d6efd; }
    .qa-card.amber  .qa-icon { background: rgba(245,158,11,.12);  color: #f59e0b; }
    .qa-card.green  .qa-icon { background: rgba(34,197,94,.12);   color: #16a34a; }
    .qa-card.purple .qa-icon { background: rgba(168,85,247,.12);  color: #9333ea; }
    .qa-card .qa-label { font-weight: 700; font-size: .9rem; }

    .route-chip {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        border-radius: 999px;
        padding: .5rem 1rem;
        font-size: .85rem;
        font-weight: 600;
        color: #334155;
        text-decoration: none;
        margin: 0 .4rem .5rem 0;
        transition: all .15s;
    }
    .route-chip:hover {
        background: #fef3c7;
        border-color: #f59e0b;
        color: #92400e;
    }

    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin: 1.5rem 0 .75rem;
    }
    .section-header h3 {
        font-size: 1.1rem;
        font-weight: 700;
        margin: 0;
        color: #0a2540;
    }
    .section-header a {
        font-size: .85rem;
        font-weight: 600;
        color: #0d6efd;
        text-decoration: none;
    }
    .section-header a:hover { text-decoration: underline; }
</style>

<div class="fd-main">

    <!-- Hero greeting + hero search -->
    <section class="home-hero">
        <h1><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($ud['username']) ?>!</h1>
        <div class="sub">Where would you like to go today?</div>

        <form class="hero-search" method="GET" action="interface.php">
            <div class="row g-2 align-items-center">
                <div class="col-12 col-md-5">
                    <input class="form-control" name="from" placeholder="From (e.g. Tunis Centre)">
                </div>
                <div class="col-12 col-md-5">
                    <input class="form-control" name="to" placeholder="To (e.g. Sousse)">
                </div>
                <div class="col-12 col-md-2">
                    <button type="submit" class="btn btn-warning w-100 fw-bold">
                        <i class="fas fa-search me-1"></i>Search
                    </button>
                </div>
            </div>
        </form>
    </section>

    <!-- Quick actions -->
    <div class="quick-actions">
        <a class="qa-card blue" href="interface.php">
            <div class="qa-icon"><i class="fas fa-search-location"></i></div>
            <div class="qa-label">Find a Ride</div>
        </a>
        <?php if ($isDriver): ?>
        <a class="qa-card green" href="offre_ride.php">
            <div class="qa-icon"><i class="fas fa-plus-circle"></i></div>
            <div class="qa-label">Offer a Ride</div>
        </a>
        <?php endif; ?>
        <a class="qa-card amber" href="my_rides.php">
            <div class="qa-icon"><i class="fas fa-history"></i></div>
            <div class="qa-label">My Rides</div>
        </a>
        <a class="qa-card purple" href="payments.php">
            <div class="qa-icon"><i class="fas fa-wallet"></i></div>
            <div class="qa-label">Wallet</div>
        </a>
    </div>

    <!-- Stats banner (same data as interface.php so they stay in sync) -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="icon blue"><i class="fas fa-calendar-check"></i></div>
                <div>
                    <div class="val"><?= count($upcomingRides) ?></div>
                    <div class="lbl">Upcoming</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="icon green"><i class="fas fa-check-circle"></i></div>
                <div>
                    <div class="val"><?= (int)$completedRides ?></div>
                    <div class="lbl">Completed</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="icon amber"><i class="fas fa-wallet"></i></div>
                <div>
                    <div class="val"><?= number_format($ud['balance'] ?? 0, 2) ?></div>
                    <div class="lbl">Balance (TND)</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="icon purple"><i class="fas fa-star"></i></div>
                <div>
                    <div class="val"><?= number_format($ud['score'] ?? 5, 1) ?></div>
                    <div class="lbl">Rating</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Popular route chips — mirror mobile _quickRouteData -->
    <div class="section-header">
        <h3><i class="fas fa-route text-warning me-1"></i> Popular Routes</h3>
    </div>
    <div class="mb-3">
        <?php foreach ($quickRoutes as [$from, $to]): ?>
            <a class="route-chip"
               href="interface.php?from=<?= urlencode($from) ?>&amp;to=<?= urlencode($to) ?>">
                <i class="fas fa-map-marker-alt text-danger"></i>
                <?= htmlspecialchars($from) ?>
                <i class="fas fa-arrow-right small text-muted"></i>
                <?= htmlspecialchars($to) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Available rides preview -->
    <div class="section-header">
        <h3><i class="fas fa-car-side text-primary me-1"></i> Available Rides</h3>
        <a href="interface.php">See all <i class="fas fa-arrow-right small"></i></a>
    </div>

    <?php if (empty($previewRides)): ?>
        <div class="fd-card text-center py-4">
            <i class="fas fa-search fa-2x text-muted mb-2"></i>
            <div class="text-muted mb-2">No rides available right now.</div>
            <a href="interface.php" class="btn-fd-outline btn btn-sm">
                <i class="fas fa-search me-1"></i>Search all rides
            </a>
        </div>
    <?php else: ?>
        <div class="fd-card">
            <?php foreach ($previewRides as $r):
                $dt = new DateTime($r['departure_time']);
                $price = number_format($r['price'], 2);
                $discPrice = number_format($r['price'] * 0.5, 2);
            ?>
            <div class="ride-item">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div class="flex-grow-1">
                        <div class="route">
                            <i class="fas fa-map-marker-alt text-danger me-1"></i><?= htmlspecialchars($r['from_location']) ?>
                            <i class="fas fa-arrow-right mx-2 text-muted small"></i>
                            <i class="fas fa-map-marker text-success me-1"></i><?= htmlspecialchars($r['to_location']) ?>
                        </div>
                        <div class="meta mt-1">
                            <i class="fas fa-clock me-1"></i><?= $dt->format('D, M j, Y \a\t g:i A') ?>
                            &nbsp;·&nbsp;<i class="fas fa-car me-1"></i><?= htmlspecialchars($r['vehicle_type'] ?? 'Car') ?>
                        </div>
                        <div class="meta">
                            <i class="fas fa-user me-1"></i><?= htmlspecialchars($r['driver_name']) ?>
                            <span class="stars ms-1"><?= str_repeat('★', min(5, (int)round($r['driver_score'] ?? 5))) ?></span>
                            <?= number_format($r['driver_score'] ?? 5, 1) ?>
                            &nbsp;·&nbsp;<i class="fas fa-users me-1"></i><?= (int)$r['seats_left'] ?> seats left
                        </div>
                        <div class="mt-2">
                            <a href="book_ride.php?id=<?= (int)$r['id'] ?>" class="btn-fd-primary btn btn-sm">
                                <i class="fas fa-check me-1"></i>Book Now
                            </a>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="price"><?= $price ?> TND</div>
                        <?php if ($isStudent): ?>
                            <div class="small text-success fw-bold"><?= $discPrice ?> TND</div>
                            <div class="small text-muted">Student 50% off</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div><!-- /.fd-main -->

<?php
$footer = __DIR__ . '/../include/footer.php';
if (is_file($footer)) include $footer;
?>
