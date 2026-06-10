<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/database.php';
require_once __DIR__ . '/../classes/users.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Init PDO et User une fois pour toutes
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $db = new Database(); // Ce fichier doit retourner un PDO
        $pdo = $db->getConnection(); // À adapter selon ta classe Database
    }
    return $pdo;
}

function getCurrentUser(): ?User {
    if (!isset($_SESSION['user_id'])) return null;

    $user = new User(getDB());
    return $user->load($_SESSION['user_id']) ? $user : null;
}

function loginUser(User $user): void {
    $_SESSION['user_id'] = $user->getId();
    $_SESSION['user_data'] = $user->toArray();
}

function logoutUser(): void {
    $_SESSION = [];
    session_destroy();
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function isAdmin(): bool {
    return isLoggedIn() && !empty($_SESSION['user_data']['is_admin']);
}

// Organization session functions
function isOrgLoggedIn(): bool {
    return isset($_SESSION['org_id']);
}

function getCurrentOrg(): ?array {
    if (!isset($_SESSION['org_id'])) return null;

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
    $stmt->execute([$_SESSION['org_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function loginOrg(array $org): void {
    $_SESSION['org_id'] = $org['id'];
    $_SESSION['org_data'] = $org;
}

function logoutOrg(): void {
    unset($_SESSION['org_id']);
    unset($_SESSION['org_data']);
}

function requireOrgLogin(string $redirect = '../Pages/org_login.php'): void {
    if (!isOrgLoggedIn()) {
        header("Location: $redirect");
        exit();
    }
}

// Use at the top of every normal user page.
// Admins are always redirected to admin.php — they must use a separate account for regular features.
function requireRegularUser(string $loginRedirect = 'login.php', string $adminRedirect = 'admin.php'): void {
    if (!isLoggedIn()) { header("Location: $loginRedirect"); exit(); }
    if (isAdmin())     { header("Location: $adminRedirect"); exit(); }
}

// Use at the top of driver-only pages.
function requireDriver(string $loginRedirect = 'login.php', string $adminRedirect = 'admin.php'): void {
    requireRegularUser($loginRedirect, $adminRedirect);
    if (empty($_SESSION['user_data']['is_driver'])) {
        header("Location: home.php"); exit();
    }
}
function refreshUserInSession(User $currentUser): void {
    if (!isLoggedIn()) {
        return;
    }
    
    // Verify the user in session matches the provided user object
    if ($_SESSION['user_id'] !== $currentUser->getId()) {
        return;
    }
    
    // Update the user data in session
    $_SESSION['user_data'] = $currentUser->toArray();
}