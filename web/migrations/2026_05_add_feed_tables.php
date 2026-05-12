<?php
/**
 * Migration: add community-feed tables (feed_posts, feed_likes, feed_comments).
 * Safe to re-run — uses CREATE TABLE IF NOT EXISTS.
 *
 * Web:  http://localhost/ForsaDrive/migrations/2026_05_add_feed_tables.php
 * CLI:  php migrations/2026_05_add_feed_tables.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/database.php';

$db  = new Database();
$pdo = $db->getConnection();
$pdo->exec("PRAGMA foreign_keys = ON");

$ok = []; $err = [];

function step(PDO $p, string $sql, string $label, array &$ok, array &$err): void {
    try { $p->exec($sql); $ok[] = $label; }
    catch (PDOException $e) { $err[] = "$label — " . $e->getMessage(); }
}

step($pdo, "
CREATE TABLE IF NOT EXISTS feed_posts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type            TEXT NOT NULL DEFAULT 'driver_offer'
                    CHECK (type IN ('driver_offer','passenger_request','group_post')),
    content         TEXT NOT NULL,
    from_location   TEXT,
    to_location     TEXT,
    departure_date  DATE,
    seats           INTEGER DEFAULT 1,
    ride_id         INTEGER REFERENCES rides(id) ON DELETE SET NULL,
    is_boosted      INTEGER DEFAULT 0,
    boosted_at      DATETIME,
    likes_count     INTEGER DEFAULT 0,
    comments_count  INTEGER DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create feed_posts", $ok, $err);

step($pdo, "
CREATE TABLE IF NOT EXISTS feed_likes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id     INTEGER NOT NULL REFERENCES feed_posts(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(post_id, user_id)
)", "Create feed_likes", $ok, $err);

step($pdo, "
CREATE TABLE IF NOT EXISTS feed_comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id     INTEGER NOT NULL REFERENCES feed_posts(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body        TEXT NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
)", "Create feed_comments", $ok, $err);

$indexes = [
    "CREATE INDEX IF NOT EXISTS idx_feed_user           ON feed_posts(user_id, created_at)",
    "CREATE INDEX IF NOT EXISTS idx_feed_type           ON feed_posts(type, is_boosted DESC, created_at DESC)",
    "CREATE INDEX IF NOT EXISTS idx_feed_likes_post     ON feed_likes(post_id)",
    "CREATE INDEX IF NOT EXISTS idx_feed_comments_post  ON feed_comments(post_id, created_at)",
];
foreach ($indexes as $idx) step($pdo, $idx, "Index", $ok, $err);

if (PHP_SAPI === 'cli') {
    echo "Migration 2026_05_add_feed_tables\n";
    echo str_repeat('-', 40) . "\n";
    foreach ($ok as $m)  echo " [OK]  $m\n";
    foreach ($err as $m) echo " [ERR] $m\n";
    echo str_repeat('-', 40) . "\n";
    echo count($ok) . " succeeded · " . count($err) . " failed\n";
    exit(empty($err) ? 0 : 1);
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>ForsaDrive — Migration</title>
<style>
body{font-family:'Segoe UI',sans-serif;background:#f0f4f8;margin:0;padding:2rem;}
.card{max-width:560px;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.12);padding:2rem;}
h2{margin:0 0 .5rem;color:#0a1628;}
.ok{color:#166534;}.ok::before{content:'✅ ';}
.err{color:#991b1b;}.err::before{content:'❌ ';}
ul{list-style:none;padding:0;margin:1rem 0;font-size:.9rem;}
</style></head>
<body><div class="card">
<h2>🚀 Migration: add feed tables</h2>
<ul>
<?php foreach ($ok as $m) echo "<li class='ok'>".htmlspecialchars($m)."</li>"; ?>
<?php foreach ($err as $m) echo "<li class='err'>".htmlspecialchars($m)."</li>"; ?>
</ul>
<p><strong><?= count($ok) ?></strong> succeeded · <strong><?= count($err) ?></strong> failed</p>
<a href="../Pages/home.php">→ Back to Home</a>
</div></body></html>
