<?php
/**
 * ForsaDrive REST API — front controller
 * All requests in web/api/ are routed here via .htaccess
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/helpers.php';

// Derive path relative to /api/
$scriptDir = dirname($_SERVER['SCRIPT_NAME']); // e.g. /ForsaDrive/api
$uri       = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path      = '/' . ltrim(substr($uri, strlen($scriptDir)), '/');
$method    = $_SERVER['REQUEST_METHOD'];

// Strip trailing slash except root
$path = ($path !== '/') ? rtrim($path, '/') : '/';

// Route segments
$segments = explode('/', ltrim($path, '/'));
$group    = $segments[0] ?? '';

switch ($group) {
    case 'auth':
        require __DIR__ . '/auth.php';
        break;
    case 'rides':
        require __DIR__ . '/rides.php';
        break;
    case 'bookings':
        require __DIR__ . '/bookings.php';
        break;
    case 'users':
    case 'vehicles':
    case 'profile':
    case 'student':
        require __DIR__ . '/users.php';
        break;
    case 'payments':
        require __DIR__ . '/payments.php';
        break;
    case 'notifications':
        require __DIR__ . '/notifications.php';
        break;
    case 'complaints':
        require __DIR__ . '/complaints.php';
        break;
    case 'ratings':
        require __DIR__ . '/ratings.php';
        break;
    case 'feed':
        require __DIR__ . '/feed.php';
        break;
    case 'admin':
        require __DIR__ . '/admin_api.php';
        break;
    case 'reports':
        require __DIR__ . '/reports.php';
        break;
    default:
        json_error('Not found', 404);
}
