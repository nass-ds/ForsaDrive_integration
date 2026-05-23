<?php
require_once '../server/session.php';
require_once '../server/language.php';
require_once '../classes/rides.php';

requireDriver();

$db     = getDB();
$userId = (int)$_SESSION['user_data']['id'];
$ride   = new Ride($db);

// ── POST actions ─────────────────────────────────────────────────────────────
$actionMsg  = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel_ride') {
        $rideId = (int)($_POST['ride_id'] ?? 0);
        if ($rideId > 0 && $ride->cancelRide($rideId, $userId)) {
            $actionMsg  = 'Ride cancelled successfully.';
            $actionType = 'success';
        } else {
            $actionMsg  = 'Could not cancel ride.';
            $actionType = 'danger';
        }
    }

    if ($action === 'complete_booking') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        if ($bookingId > 0 && $ride->completeBooking($bookingId, $userId)) {
            $actionMsg  = 'Booking marked as completed.';
            $actionType = 'success';
        } else {
            $actionMsg  = 'Could not complete booking.';
            $actionType = 'danger';
        }
    }
}

// ── Load driver profile KPIs ──────────────────────────────────────────────────
$profile = ['avg_rating' => 0, 'total_trips' => 0, 'total_earnings' => 0, 'reliability' => 0];
try {
    $stmt = $db->prepare("SELECT avg_rating, total_trips, total_earnings, reliability FROM driver_profiles WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $profile = $row;
    }
} catch (Exception $e) {
    error_log("driver_dashboard: profile query — " . $e->getMessage());
}

// ── Active rides ──────────────────────────────────────────────────────────────
$allRides    = $ride->getDriverRides($userId);
$activeRides = array_filter($allRides, fn($r) => $r['status'] === 'active');

// ── Recent bookings (last 10, show 10) ────────────────────────────────────────
$recentBookings = [];
try {
    $stmt = $db->prepare(
        "SELECT bk.*, r.from_location, r.to_location,
                p.username AS passenger_name
         FROM bookings bk
         JOIN rides r ON r.id = bk.ride_id
         JOIN users p ON p.id = bk.passenger_id
         WHERE r.driver_id = ?
         ORDER BY bk.created_at DESC
         LIMIT 10"
    );
    $stmt->execute([$userId]);
    $recentBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("driver_dashboard: recent bookings query — " . $e->getMessage());
}

// ── Tunisian city/governorate coordinates (24 governorates + main cities) ────
$TN_COORDS = [
    'tunis'         => [36.8065, 10.1815],  'ariana'      => [36.8625, 10.1956],
    'ben arous'     => [36.7531, 10.2189],  'manouba'     => [36.8101, 10.0972],
    'nabeul'        => [36.4513, 10.7357],  'hammamet'    => [36.4006, 10.6167],
    'zaghouan'      => [36.4028, 10.1429],
    'bizerte'       => [37.2744, 9.8739],   'beja'        => [36.7256, 9.1817],
    'jendouba'      => [36.5011, 8.7800],   'kef'         => [36.1675, 8.7100],
    'le kef'        => [36.1675, 8.7100],
    'siliana'       => [36.0844, 9.3708],
    'sousse'        => [35.8256, 10.6411],  'monastir'    => [35.7780, 10.8262],
    'mahdia'        => [35.5047, 11.0622],  'sfax'        => [34.7406, 10.7603],
    'kairouan'      => [35.6781, 10.0964],  'kasserine'   => [35.1675, 8.8364],
    'sidi bouzid'   => [35.0381, 9.4858],
    'gabes'         => [33.8814, 10.0982],  'medenine'    => [33.3550, 10.5000],
    'djerba'        => [33.8076, 10.8451],  'zarzis'      => [33.5039, 11.1122],
    'tataouine'     => [32.9297, 10.4519],  'gafsa'       => [34.4250, 8.7842],
    'tozeur'        => [33.9197, 8.1335],   'kebili'      => [33.7050, 8.9690],
];

function geocodeTN(string $location, array $TN_COORDS): ?array {
    $key = strtolower(trim($location));
    if (isset($TN_COORDS[$key])) return $TN_COORDS[$key];
    // Substring match (e.g. "Tunis Centre" → "tunis")
    foreach ($TN_COORDS as $city => $coords) {
        if (str_contains($key, $city)) return $coords;
    }
    return null;
}

// ── Build map data: active rides with coordinates + confirmed passengers ──────
$mapRides = [];
foreach ($activeRides as $r) {
    $fromCoords = geocodeTN($r['from_location'] ?? '', $TN_COORDS);
    $toCoords   = geocodeTN($r['to_location']   ?? '', $TN_COORDS);
    if (!$fromCoords || !$toCoords) continue;  // skip if we can't locate

    // Get confirmed passengers for this ride
    $passengers = [];
    try {
        $st = $db->prepare(
            "SELECT u.username, bk.seats, bk.status
             FROM bookings bk
             JOIN users u ON u.id = bk.passenger_id
             WHERE bk.ride_id = ? AND bk.status IN ('confirmed','pending')
             ORDER BY bk.created_at"
        );
        $st->execute([$r['id']]);
        $passengers = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $mapRides[] = [
        'id'             => (int)$r['id'],
        'from_location'  => $r['from_location'],
        'to_location'    => $r['to_location'],
        'from_lat'       => $fromCoords[0],
        'from_lng'       => $fromCoords[1],
        'to_lat'         => $toCoords[0],
        'to_lng'         => $toCoords[1],
        'departure_time' => $r['departure_time'],
        'price'          => (float)$r['price'],
        'seats_left'     => (int)$r['seats_left'],
        'passengers'     => $passengers,
    ];
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function renderStars(float $rating): string {
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        $html .= $i <= round($rating)
            ? '<i class="fas fa-star text-warning"></i>'
            : '<i class="far fa-star text-warning"></i>';
    }
    return $html;
}

function bookingStatusBadge(string $status): string {
    return match($status) {
        'confirmed'  => '<span class="status-badge bg-success text-white">Confirmed</span>',
        'completed'  => '<span class="status-badge bg-primary text-white">Completed</span>',
        'cancelled'  => '<span class="status-badge bg-danger text-white">Cancelled</span>',
        'pending'    => '<span class="status-badge bg-warning text-dark">Pending</span>',
        default      => '<span class="status-badge bg-secondary text-white">' . htmlspecialchars($status) . '</span>',
    };
}

$pageTitle = 'Driver Dashboard';
require_once '../include/header.php';
require_once '../include/sidebar.php';
?>

<!-- Leaflet.js for the navigation map -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<div class="fd-main">

    <!-- Page header -->
    <div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <div>
            <h2 class="mb-0"><i class="fas fa-chart-line me-2"></i>Driver Dashboard</h2>
            <small class="text-muted">Welcome back, <?= htmlspecialchars($_SESSION['user_data']['username'] ?? 'Driver') ?></small>
        </div>
        <a href="offre_ride.php" class="btn btn-fd-primary">
            <i class="fas fa-plus-circle me-1"></i> New Ride
        </a>
    </div>

    <?php if ($actionMsg): ?>
    <div class="alert alert-fd-<?= $actionType ?> alert-dismissible fade show mb-4" role="alert">
        <?= htmlspecialchars($actionMsg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ── KPI Stat Cards ── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card text-center">
                <div class="stat-icon"><i class="fas fa-road fa-2x text-primary"></i></div>
                <div class="stat-value"><?= (int)$profile['total_trips'] ?></div>
                <div class="stat-label">Total Trips</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card text-center">
                <div class="stat-icon"><i class="fas fa-wallet fa-2x text-success"></i></div>
                <div class="stat-value"><?= number_format((float)$profile['total_earnings'], 2) ?> <small>DZD</small></div>
                <div class="stat-label">Total Earnings</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card text-center">
                <div class="stat-icon mb-1"><i class="fas fa-star fa-2x text-warning"></i></div>
                <div class="stat-value"><?= number_format((float)$profile['avg_rating'], 1) ?></div>
                <div class="d-flex justify-content-center mb-1">
                    <?= renderStars((float)$profile['avg_rating']) ?>
                </div>
                <div class="stat-label">Avg Rating</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card text-center">
                <div class="stat-icon"><i class="fas fa-shield-alt fa-2x text-info"></i></div>
                <div class="stat-value"><?= number_format((float)$profile['reliability'], 0) ?>%</div>
                <div class="stat-label">Reliability</div>
            </div>
        </div>
    </div>

    <!-- ── Active Rides ── -->
    <div class="fd-card mb-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="mb-0"><i class="fas fa-car me-2 text-primary"></i>My Active Rides</h5>
            <span class="badge bg-primary rounded-pill"><?= count($activeRides) ?></span>
        </div>

        <?php if (empty($activeRides)): ?>
        <div class="empty-state py-4">
            <i class="fas fa-car-side fa-3x text-muted mb-3 d-block text-center"></i>
            <p class="text-center text-muted mb-3">You have no active rides right now.</p>
            <div class="text-center">
                <a href="offre_ride.php" class="btn btn-fd-primary btn-sm">
                    <i class="fas fa-plus-circle me-1"></i> Create a Ride
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($activeRides as $r): ?>
            <div class="col-12 col-md-6">
                <div class="ride-item d-flex flex-column gap-2 h-100">
                    <div class="d-flex align-items-start justify-content-between gap-2">
                        <div>
                            <div class="fw-semibold">
                                <i class="fas fa-map-marker-alt text-danger me-1"></i>
                                <?= htmlspecialchars($r['from_location']) ?>
                                <i class="fas fa-arrow-right mx-1 text-muted"></i>
                                <?= htmlspecialchars($r['to_location']) ?>
                            </div>
                            <small class="text-muted">
                                <i class="far fa-clock me-1"></i>
                                <?= htmlspecialchars($r['departure_time']) ?>
                            </small>
                        </div>
                        <span class="status-badge bg-success text-white flex-shrink-0">Active</span>
                    </div>
                    <div class="d-flex gap-3 text-muted small">
                        <span><i class="fas fa-tag me-1 text-success"></i><?= number_format((float)$r['price'], 2) ?> DZD</span>
                        <span><i class="fas fa-users me-1 text-info"></i><?= (int)$r['seats_left'] ?> / <?= (int)$r['available_seats'] ?> seats left</span>
                        <span><i class="fas fa-ticket-alt me-1 text-warning"></i><?= (int)$r['booked_count'] ?> booked</span>
                    </div>
                    <div class="d-flex gap-2 mt-auto pt-1">
                        <a href="booking_requests.php" class="btn btn-fd-outline btn-sm flex-fill">
                            <i class="fas fa-inbox me-1"></i> Requests
                        </a>
                        <form method="POST" class="flex-fill" onsubmit="return confirm('Cancel this ride? All pending bookings will be affected.')">
                            <input type="hidden" name="action" value="cancel_ride">
                            <input type="hidden" name="ride_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn btn-fd-danger btn-sm w-100">
                                <i class="fas fa-times-circle me-1"></i> Cancel
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Navigation Map (Leaflet.js) ── -->
    <div class="fd-card mb-4">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <h5 class="mb-0">
                <i class="fas fa-map-marked-alt me-2 text-primary"></i>Navigation
                <small class="text-muted fw-normal ms-2">Points de départ & arrivée des passagers</small>
            </h5>
            <div class="d-flex gap-3 small text-muted">
                <span><i class="fas fa-circle text-success"></i> Départ</span>
                <span><i class="fas fa-circle text-danger"></i> Destination</span>
            </div>
        </div>

        <?php if (empty($mapRides)): ?>
        <div class="empty-state py-4 text-center">
            <i class="fas fa-map fa-3x text-muted mb-3 d-block"></i>
            <p class="text-muted mb-0">
                <?php if (empty($activeRides)): ?>
                    Aucun trajet actif à afficher sur la carte.
                <?php else: ?>
                    Localisation des trajets indisponible (villes non reconnues).
                <?php endif; ?>
            </p>
        </div>
        <?php else: ?>
        <div id="driverMap" style="height: 380px; border-radius: 12px; overflow: hidden; border: 1px solid #e5e7eb;"></div>
        <div class="text-muted small mt-2">
            <i class="fas fa-info-circle me-1"></i>
            Cliquez sur un marqueur pour voir les passagers confirmés et les détails du trajet.
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Recent Bookings ── -->
    <div class="fd-card">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="mb-0"><i class="fas fa-list-alt me-2 text-primary"></i>Recent Bookings</h5>
            <a href="booking_requests.php" class="btn btn-fd-outline btn-sm">View All</a>
        </div>

        <?php if (empty($recentBookings)): ?>
        <div class="empty-state py-4">
            <i class="fas fa-inbox fa-3x text-muted mb-3 d-block text-center"></i>
            <p class="text-center text-muted">No bookings yet.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Passenger</th>
                        <th class="d-none d-sm-table-cell">Route</th>
                        <th class="d-none d-md-table-cell">Date</th>
                        <th>Status</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end d-none d-md-table-cell">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentBookings as $bk): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($bk['passenger_name']) ?></div>
                            <small class="text-muted d-sm-none">
                                <?= htmlspecialchars($bk['from_location']) ?> → <?= htmlspecialchars($bk['to_location']) ?>
                            </small>
                        </td>
                        <td class="d-none d-sm-table-cell small text-muted">
                            <?= htmlspecialchars($bk['from_location']) ?>
                            <i class="fas fa-arrow-right mx-1"></i>
                            <?= htmlspecialchars($bk['to_location']) ?>
                        </td>
                        <td class="d-none d-md-table-cell small text-muted">
                            <?= htmlspecialchars(substr($bk['created_at'] ?? '', 0, 16)) ?>
                        </td>
                        <td><?= bookingStatusBadge($bk['status']) ?></td>
                        <td class="text-end fw-semibold text-success">
                            <?= number_format((float)($bk['paid_amount'] ?? 0), 2) ?> DZD
                        </td>
                        <td class="text-end d-none d-md-table-cell">
                            <?php if ($bk['status'] === 'confirmed'): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="complete_booking">
                                <input type="hidden" name="booking_id" value="<?= (int)$bk['id'] ?>">
                                <button type="submit" class="btn btn-fd-success btn-sm">
                                    <i class="fas fa-check me-1"></i> Complete
                                </button>
                            </form>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<?php if (!empty($mapRides)): ?>
<script>
(function() {
    const rides = <?= json_encode($mapRides, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // Custom colored markers (CSS-based — no external assets)
    const greenIcon = L.divIcon({
        className: 'fd-marker',
        html: '<div style="background:#22c55e;width:22px;height:22px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3);"><div style="background:#fff;width:8px;height:8px;border-radius:50%;margin:5px;"></div></div>',
        iconSize: [22, 22], iconAnchor: [11, 22], popupAnchor: [0, -20]
    });
    const redIcon = L.divIcon({
        className: 'fd-marker',
        html: '<div style="background:#ef4444;width:22px;height:22px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3);"><div style="background:#fff;width:8px;height:8px;border-radius:50%;margin:5px;"></div></div>',
        iconSize: [22, 22], iconAnchor: [11, 22], popupAnchor: [0, -20]
    });

    // Initialize map centered on Tunisia
    const map = L.map('driverMap', { scrollWheelZoom: false }).setView([34.5, 9.5], 6);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap'
    }).addTo(map);

    const bounds = [];

    rides.forEach(r => {
        // Build passengers HTML
        let passengersHtml = '';
        if (r.passengers && r.passengers.length > 0) {
            passengersHtml = '<div style="margin-top:8px;padding-top:8px;border-top:1px solid #eee;"><strong style="font-size:11px;color:#666;">PASSAGERS:</strong>';
            r.passengers.forEach(p => {
                const statusColor = p.status === 'confirmed' ? '#22c55e' : '#f59e0b';
                passengersHtml += `<div style="font-size:12px;margin-top:4px;"><span style="display:inline-block;width:6px;height:6px;background:${statusColor};border-radius:50%;margin-right:6px;"></span>${escapeHtml(p.username)} <span style="color:#999;">(${p.seats} siège${p.seats > 1 ? 's' : ''})</span></div>`;
            });
            passengersHtml += '</div>';
        } else {
            passengersHtml = '<div style="margin-top:6px;font-size:11px;color:#999;font-style:italic;">Aucun passager confirmé</div>';
        }

        // Departure marker (green)
        const fromPopup = `
            <div style="min-width:200px;">
                <div style="font-weight:700;color:#22c55e;margin-bottom:4px;">
                    <i style="font-style:normal;">📍</i> DÉPART
                </div>
                <div style="font-size:14px;font-weight:600;">${escapeHtml(r.from_location)}</div>
                <div style="font-size:12px;color:#666;margin-top:4px;">
                    🕐 ${escapeHtml(r.departure_time)}<br>
                    💰 ${r.price.toFixed(2)} DZD/siège<br>
                    🪑 ${r.seats_left} place${r.seats_left > 1 ? 's' : ''} restante${r.seats_left > 1 ? 's' : ''}
                </div>
                ${passengersHtml}
            </div>`;
        L.marker([r.from_lat, r.from_lng], { icon: greenIcon })
            .addTo(map)
            .bindPopup(fromPopup);

        // Destination marker (red)
        const toPopup = `
            <div style="min-width:180px;">
                <div style="font-weight:700;color:#ef4444;margin-bottom:4px;">
                    <i style="font-style:normal;">🏁</i> DESTINATION
                </div>
                <div style="font-size:14px;font-weight:600;">${escapeHtml(r.to_location)}</div>
                <div style="font-size:12px;color:#666;margin-top:4px;">
                    De: ${escapeHtml(r.from_location)}
                </div>
            </div>`;
        L.marker([r.to_lat, r.to_lng], { icon: redIcon })
            .addTo(map)
            .bindPopup(toPopup);

        // Route line (dashed blue)
        L.polyline([[r.from_lat, r.from_lng], [r.to_lat, r.to_lng]], {
            color: '#0d6efd',
            weight: 3,
            opacity: 0.7,
            dashArray: '8, 6'
        }).addTo(map);

        bounds.push([r.from_lat, r.from_lng], [r.to_lat, r.to_lng]);
    });

    // Fit map to show all markers
    if (bounds.length > 0) {
        map.fitBounds(bounds, { padding: [40, 40] });
    }

    // Enable scroll-zoom only after user clicks on the map
    map.on('click', () => map.scrollWheelZoom.enable());
    map.on('mouseout', () => map.scrollWheelZoom.disable());

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }
})();
</script>
<?php endif; ?>

</body>
</html>
