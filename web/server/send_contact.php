<?php
/**
 * Contact form handler — saves messages to DB instead of email.
 * No SMTP credentials required.
 */
require_once __DIR__ . '/../server/session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php#contact'); exit();
}

$name    = trim($_POST['name']    ?? '');
$email   = trim($_POST['email']   ?? '');
$message = trim($_POST['message'] ?? '');

if (!$name || !$email || !$message) {
    header('Location: ../index.php?mailsent=0&error=' . urlencode('All fields are required.') . '#contact');
    exit();
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: ../index.php?mailsent=0&error=' . urlencode('Invalid email address.') . '#contact');
    exit();
}

try {
    $db = getDB();

    // Create table if it doesn't exist yet
    $db->exec("CREATE TABLE IF NOT EXISTS contact_messages (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        name     TEXT    NOT NULL,
        email    TEXT    NOT NULL,
        message  TEXT    NOT NULL,
        is_read  INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT (datetime('now'))
    )");

    $db->prepare("INSERT INTO contact_messages (name, email, message) VALUES (?,?,?)")
       ->execute([$name, $email, $message]);

    header('Location: ../index.php?mailsent=1#contact');
} catch (Exception $e) {
    header('Location: ../index.php?mailsent=0&error=' . urlencode('Could not save your message. Please try again.') . '#contact');
}
exit();
