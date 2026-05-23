<?php
/**
 * /feed/* endpoints — community feed (mirrors mobile FeedScreen).
 *
 *   GET  /feed/                      list posts (optional ?type=)
 *   POST /feed/                      create a post
 *   POST /feed/like                  toggle like {post_id}
 *   POST /feed/delete                delete own post {post_id}
 *   POST /feed/boost                 boost a post {post_id, tier}      (tiered §3.2)
 *   POST /feed/boost-ride            boost a ride to the feed {ride_id, tier}
 *   GET  /feed/comments              list comments for ?post_id=
 *   POST /feed/comment               add a comment {post_id, body}
 */

require_once __DIR__ . '/../server/boost_tiers.php';

$action = $segments[1] ?? '';
$user   = auth_user();
$uid    = (int)$user['id'];

// Resolve the picture column the way the mobile expects (full URL or null).
function feed_pic(?string $raw): ?string {
    if (!$raw) return null;
    if (preg_match('#^https?://#i', $raw)) return $raw;
    // Strip the ../Src/ prefix that the web stores so mobile can prepend its uploads base
    return preg_replace('#^\.\./#', '', $raw);
}

function fetch_post(PDO $pdo, int $postId, int $viewerId): ?array {
    $stmt = $pdo->prepare("
        SELECT p.*,
               " . boost_active_sql('p') . " AS is_boosted_active,
               u.username      AS author_name,
               u.picture       AS author_pic,
               u.score         AS author_score,
               u.is_student    AS author_is_student,
               EXISTS(SELECT 1 FROM feed_likes l WHERE l.post_id = p.id AND l.user_id = ?) AS is_liked
        FROM feed_posts p
        JOIN users u ON u.id = p.user_id
        WHERE p.id = ?
    ");
    $stmt->execute([$viewerId, $postId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['id']          = (int)$row['id'];
    $row['user_id']     = (int)$row['user_id'];
    $row['seats']       = (int)$row['seats'];
    // is_boosted reflects the *active* state (expired boosts read as 0)
    $row['is_boosted']  = (int)$row['is_boosted_active'];
    unset($row['is_boosted_active']);
    $row['likes_count'] = (int)$row['likes_count'];
    $row['comments_count'] = (int)$row['comments_count'];
    $row['is_liked']    = (bool)$row['is_liked'];
    $row['author_score']= (float)$row['author_score'];
    $row['author_pic']  = feed_pic($row['author_pic']);
    $row['author_is_student'] = (int)$row['author_is_student'];
    if ($row['ride_id'] !== null) $row['ride_id'] = (int)$row['ride_id'];
    return $row;
}

/**
 * Charge the user the tier price and (re)boost their post for the tier's
 * duration, all in one transaction. Returns the new boost_expires_at string.
 * Sends a JSON error and exits on insufficient balance or DB failure.
 */
function boost_post(PDO $pdo, int $uid, int $postId, array $tier): string {
    $price = (float)$tier['price'];
    $bal   = (float)$pdo->query("SELECT balance FROM users WHERE id=$uid")->fetchColumn();
    if ($bal < $price) {
        json_error(sprintf('Insufficient balance — this boost costs %s TND', rtrim(rtrim(number_format($price, 2), '0'), '.')), 402);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")
            ->execute([$price, $uid]);
        $pdo->prepare("INSERT INTO payments (user_id, amount, type, description, ref_id)
                       VALUES (?, ?, 'charge', ?, ?)")
            ->execute([$uid, -$price, 'Feed boost (' . $tier['label'] . ')', $postId]);
        $pdo->prepare("
            UPDATE feed_posts
            SET is_boosted = 1,
                boost_tier = ?,
                boosted_at = datetime('now'),
                boost_expires_at = datetime('now', ?)
            WHERE id = ?
        ")->execute([$tier['key'] ?? null, $tier['sql_modifier'], $postId]);
        $expiresAt = (string)$pdo->query("SELECT boost_expires_at FROM feed_posts WHERE id=$postId")->fetchColumn();
        $pdo->commit();
        return $expiresAt;
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('Boost failed: ' . $e->getMessage(), 500);
    }
    return ''; // unreachable
}

$pdo = db();

switch ($method . ':' . $action) {

    // ── List posts ─────────────────────────────────────────────────────
    case 'GET:': {
        $type = $_GET['type'] ?? null;
        $sql  = "
            SELECT p.*,
                   " . boost_active_sql('p') . " AS is_boosted_active,
                   u.username       AS author_name,
                   u.picture        AS author_pic,
                   u.score          AS author_score,
                   u.is_student     AS author_is_student,
                   EXISTS(SELECT 1 FROM feed_likes l WHERE l.post_id = p.id AND l.user_id = ?) AS is_liked
            FROM feed_posts p
            JOIN users u ON u.id = p.user_id
        ";
        $params = [$uid];
        if ($type && in_array($type, ['driver_offer','passenger_request','group_post'], true)) {
            $sql .= " WHERE p.type = ?";
            $params[] = $type;
        }
        $sql .= " ORDER BY is_boosted_active DESC, p.created_at DESC LIMIT 100";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $posts = array_map(function ($r) {
            $r['id']             = (int)$r['id'];
            $r['user_id']        = (int)$r['user_id'];
            $r['seats']          = (int)$r['seats'];
            // is_boosted reflects the *active* state (expired boosts read as 0)
            $r['is_boosted']     = (int)$r['is_boosted_active'];
            unset($r['is_boosted_active']);
            $r['likes_count']    = (int)$r['likes_count'];
            $r['comments_count'] = (int)$r['comments_count'];
            $r['is_liked']       = (bool)$r['is_liked'];
            $r['author_score']   = (float)$r['author_score'];
            $r['author_pic']     = feed_pic($r['author_pic']);
            $r['author_is_student'] = (int)$r['author_is_student'];
            if ($r['ride_id'] !== null) $r['ride_id'] = (int)$r['ride_id'];
            return $r;
        }, $rows);

        json_ok(['posts' => $posts]);
    }

    // ── Create post ────────────────────────────────────────────────────
    case 'POST:': {
        $b = require_fields(['type', 'content']);
        $type = $b['type'];
        if (!in_array($type, ['driver_offer','passenger_request','group_post'], true)) {
            json_error('Invalid post type', 422);
        }
        $content = trim($b['content']);
        if ($content === '') json_error('Content cannot be empty', 422);
        if (mb_strlen($content) > 2000) json_error('Content too long (max 2000 chars)', 422);

        $stmt = $pdo->prepare("
            INSERT INTO feed_posts
              (user_id, type, content, from_location, to_location, departure_date, seats, ride_id)
            VALUES (?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $uid, $type, $content,
            $b['from_location']  ?? null,
            $b['to_location']    ?? null,
            $b['departure_date'] ?? null,
            isset($b['seats']) ? max(1, min(8, (int)$b['seats'])) : 1,
            isset($b['ride_id']) ? (int)$b['ride_id'] : null,
        ]);

        $post = fetch_post($pdo, (int)$pdo->lastInsertId(), $uid);
        json_ok(['post' => $post], 201);
    }

    // ── Toggle like ────────────────────────────────────────────────────
    case 'POST:like': {
        $b = require_fields(['post_id']);
        $postId = (int)$b['post_id'];

        // Make sure post exists
        $exists = $pdo->prepare("SELECT 1 FROM feed_posts WHERE id=?");
        $exists->execute([$postId]);
        if (!$exists->fetchColumn()) json_error('Post not found', 404);

        $del = $pdo->prepare("DELETE FROM feed_likes WHERE post_id=? AND user_id=?");
        $del->execute([$postId, $uid]);
        $liked = false;
        if ($del->rowCount() === 0) {
            $pdo->prepare("INSERT INTO feed_likes (post_id, user_id) VALUES (?,?)")
                ->execute([$postId, $uid]);
            $liked = true;
        }
        // Sync counter
        $pdo->prepare("
            UPDATE feed_posts
            SET likes_count = (SELECT COUNT(*) FROM feed_likes WHERE post_id = ?)
            WHERE id = ?
        ")->execute([$postId, $postId]);

        $count = (int)$pdo->query("SELECT likes_count FROM feed_posts WHERE id=$postId")->fetchColumn();
        json_ok(['is_liked' => $liked, 'likes_count' => $count]);
    }

    // ── Delete own post (admins can delete any) ───────────────────────
    case 'POST:delete': {
        $b = require_fields(['post_id']);
        $postId = (int)$b['post_id'];

        $check = $pdo->prepare("SELECT user_id FROM feed_posts WHERE id=?");
        $check->execute([$postId]);
        $ownerId = $check->fetchColumn();
        if ($ownerId === false) json_error('Post not found', 404);
        if ((int)$ownerId !== $uid && empty($user['is_admin'])) {
            json_error('Forbidden', 403);
        }
        $pdo->prepare("DELETE FROM feed_posts WHERE id=?")->execute([$postId]);
        json_ok(['deleted' => true]);
    }

    // ── Boost a post (tiered §3.2, deducts from wallet) ───────────────
    case 'POST:boost': {
        $b = require_fields(['post_id', 'tier']);
        $postId = (int)$b['post_id'];
        $tier   = boost_tier($b['tier'] ?? null);
        if (!$tier) json_error('Invalid boost tier', 422);

        $check = $pdo->prepare("
            SELECT user_id, " . boost_active_sql() . " AS is_boosted_active
            FROM feed_posts p WHERE id = ?
        ");
        $check->execute([$postId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_error('Post not found', 404);
        if ((int)$row['user_id'] !== $uid) json_error('You can only boost your own posts', 403);
        if ((int)$row['is_boosted_active'] === 1) json_error('Post is already boosted', 409);

        $expiresAt = boost_post($pdo, $uid, $postId, $tier);
        $newBal = (float)$pdo->query("SELECT balance FROM users WHERE id=$uid")->fetchColumn();
        json_ok([
            'boosted'          => true,
            'new_balance'      => $newBal,
            'boost_tier'       => $b['tier'],
            'boost_expires_at' => $expiresAt,
        ]);
    }

    // ── Boost a ride to the feed (tiered §3.2) ────────────────────────
    // Finds the driver's existing feed post for the ride, or creates one,
    // then boosts it. {ride_id, tier}
    case 'POST:boost-ride': {
        $b = require_fields(['ride_id', 'tier']);
        $rideId = (int)$b['ride_id'];
        $tier   = boost_tier($b['tier'] ?? null);
        if (!$tier) json_error('Invalid boost tier', 422);

        $rideStmt = $pdo->prepare(
            "SELECT id, driver_id, from_location, to_location, departure_time
             FROM rides WHERE id = ?"
        );
        $rideStmt->execute([$rideId]);
        $ride = $rideStmt->fetch(PDO::FETCH_ASSOC);
        if (!$ride) json_error('Ride not found', 404);
        if ((int)$ride['driver_id'] !== $uid) json_error('You can only boost your own rides', 403);

        // Find an existing feed post for this ride, or create one
        $find = $pdo->prepare("
            SELECT id, " . boost_active_sql() . " AS is_boosted_active
            FROM feed_posts p WHERE ride_id = ? AND user_id = ?
        ");
        $find->execute([$rideId, $uid]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $postId = (int)$existing['id'];
            if ((int)$existing['is_boosted_active'] === 1) json_error('This ride is already boosted', 409);
        } else {
            $content = sprintf('Ride: %s → %s', $ride['from_location'], $ride['to_location']);
            $pdo->prepare("
                INSERT INTO feed_posts
                  (user_id, type, content, from_location, to_location, departure_date, ride_id)
                VALUES (?, 'driver_offer', ?, ?, ?, ?, ?)
            ")->execute([
                $uid, $content,
                $ride['from_location'], $ride['to_location'],
                $ride['departure_time'] ? substr($ride['departure_time'], 0, 10) : null,
                $rideId,
            ]);
            $postId = (int)$pdo->lastInsertId();
        }

        $expiresAt = boost_post($pdo, $uid, $postId, $tier);
        $newBal = (float)$pdo->query("SELECT balance FROM users WHERE id=$uid")->fetchColumn();
        json_ok([
            'boosted'          => true,
            'post_id'          => $postId,
            'new_balance'      => $newBal,
            'boost_tier'       => $b['tier'],
            'boost_expires_at' => $expiresAt,
        ]);
    }

    // ── List comments for a post ──────────────────────────────────────
    case 'GET:comments': {
        $postId = (int)($_GET['post_id'] ?? 0);
        if ($postId <= 0) json_error('post_id is required', 422);

        $stmt = $pdo->prepare("
            SELECT c.id, c.post_id, c.user_id, c.body, c.created_at,
                   u.username AS author_name,
                   u.picture  AS author_pic
            FROM feed_comments c
            JOIN users u ON u.id = c.user_id
            WHERE c.post_id = ?
            ORDER BY c.created_at ASC
            LIMIT 200
        ");
        $stmt->execute([$postId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $comments = array_map(function ($r) {
            $r['id']         = (int)$r['id'];
            $r['post_id']    = (int)$r['post_id'];
            $r['user_id']    = (int)$r['user_id'];
            $r['author_pic'] = feed_pic($r['author_pic']);
            return $r;
        }, $rows);
        json_ok(['comments' => $comments]);
    }

    // ── Add comment ───────────────────────────────────────────────────
    case 'POST:comment': {
        $b = require_fields(['post_id', 'body']);
        $postId = (int)$b['post_id'];
        $body   = trim($b['body']);
        if ($body === '') json_error('Comment cannot be empty', 422);
        if (mb_strlen($body) > 1000) json_error('Comment too long (max 1000 chars)', 422);

        // Make sure post exists
        $exists = $pdo->prepare("SELECT 1 FROM feed_posts WHERE id=?");
        $exists->execute([$postId]);
        if (!$exists->fetchColumn()) json_error('Post not found', 404);

        $pdo->prepare("INSERT INTO feed_comments (post_id, user_id, body) VALUES (?,?,?)")
            ->execute([$postId, $uid, $body]);
        $cid = (int)$pdo->lastInsertId();

        // Sync counter
        $pdo->prepare("
            UPDATE feed_posts
            SET comments_count = (SELECT COUNT(*) FROM feed_comments WHERE post_id = ?)
            WHERE id = ?
        ")->execute([$postId, $postId]);

        json_ok(['id' => $cid], 201);
    }

    default:
        json_error('Not found', 404);
}
