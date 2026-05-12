<?php
require_once __DIR__ . '/../server/session.php';
require_once __DIR__ . '/../server/language.php';

requireRegularUser();

$db  = getDB();
$ud  = $_SESSION['user_data'];
$uid = (int)$ud['id'];
$isAdmin = !empty($ud['is_admin']);

// ── Form actions (post / like / boost / delete / comment) ────────────────────
$flash = ''; $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // ── Create post ───────────────────────────────────────────────────────
        if (isset($_POST['create_post'])) {
            $type   = $_POST['type'] ?? 'driver_offer';
            if (!in_array($type, ['driver_offer','passenger_request','group_post'], true)) {
                throw new RuntimeException('Invalid post type.');
            }
            $content = trim($_POST['content'] ?? '');
            if ($content === '') throw new RuntimeException('Content cannot be empty.');
            if (mb_strlen($content) > 2000) throw new RuntimeException('Content too long.');

            $stmt = $db->prepare("
                INSERT INTO feed_posts
                    (user_id, type, content, from_location, to_location, departure_date, seats)
                VALUES (?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $uid, $type, $content,
                trim($_POST['from_location'] ?? '') ?: null,
                trim($_POST['to_location']   ?? '') ?: null,
                trim($_POST['departure_date']?? '') ?: null,
                max(1, min(8, (int)($_POST['seats'] ?? 1))),
            ]);
            $flash = 'Post created!'; $flashType = 'success';
        }

        // ── Toggle like ───────────────────────────────────────────────────────
        elseif (isset($_POST['like_post'])) {
            $postId = (int)$_POST['like_post'];
            $exists = $db->prepare("SELECT 1 FROM feed_posts WHERE id=?");
            $exists->execute([$postId]);
            if (!$exists->fetchColumn()) throw new RuntimeException('Post not found.');

            $del = $db->prepare("DELETE FROM feed_likes WHERE post_id=? AND user_id=?");
            $del->execute([$postId, $uid]);
            if ($del->rowCount() === 0) {
                $db->prepare("INSERT INTO feed_likes (post_id, user_id) VALUES (?,?)")
                    ->execute([$postId, $uid]);
            }
            $db->prepare("
                UPDATE feed_posts SET likes_count = (SELECT COUNT(*) FROM feed_likes WHERE post_id=?)
                WHERE id=?
            ")->execute([$postId, $postId]);
        }

        // ── Delete post ───────────────────────────────────────────────────────
        elseif (isset($_POST['delete_post'])) {
            $postId = (int)$_POST['delete_post'];
            $check  = $db->prepare("SELECT user_id FROM feed_posts WHERE id=?");
            $check->execute([$postId]);
            $owner = $check->fetchColumn();
            if ($owner === false) throw new RuntimeException('Post not found.');
            if ((int)$owner !== $uid && !$isAdmin) throw new RuntimeException('Not allowed.');
            $db->prepare("DELETE FROM feed_posts WHERE id=?")->execute([$postId]);
            $flash = 'Post deleted.'; $flashType = 'success';
        }

        // ── Boost post (5 TND) ───────────────────────────────────────────────
        elseif (isset($_POST['boost_post'])) {
            $postId = (int)$_POST['boost_post'];
            $check  = $db->prepare("SELECT user_id, is_boosted FROM feed_posts WHERE id=?");
            $check->execute([$postId]);
            $r = $check->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new RuntimeException('Post not found.');
            if ((int)$r['user_id'] !== $uid) throw new RuntimeException('You can only boost your own posts.');
            if ((int)$r['is_boosted'] === 1) throw new RuntimeException('Already boosted.');

            $bal = (float)$db->query("SELECT balance FROM users WHERE id=$uid")->fetchColumn();
            if ($bal < 5.0) throw new RuntimeException('Insufficient balance — boosting costs 5 TND.');

            $db->beginTransaction();
            try {
                $db->prepare("UPDATE users SET balance = balance - 5 WHERE id=?")->execute([$uid]);
                $db->prepare("INSERT INTO payments (user_id, amount, type, description, ref_id)
                              VALUES (?, -5, 'charge', 'Feed post boost', ?)")
                    ->execute([$uid, $postId]);
                $db->prepare("UPDATE feed_posts SET is_boosted=1, boosted_at=datetime('now') WHERE id=?")
                    ->execute([$postId]);
                $db->commit();
            } catch (Throwable $e) { $db->rollBack(); throw $e; }
            $_SESSION['user_data']['balance'] = $bal - 5;
            $flash = 'Post boosted!'; $flashType = 'success';
        }

        // ── Add comment ──────────────────────────────────────────────────────
        elseif (isset($_POST['add_comment'])) {
            $postId = (int)($_POST['post_id'] ?? 0);
            $body   = trim($_POST['comment_body'] ?? '');
            if ($body === '') throw new RuntimeException('Comment cannot be empty.');
            if (mb_strlen($body) > 1000) throw new RuntimeException('Comment too long.');
            $exists = $db->prepare("SELECT 1 FROM feed_posts WHERE id=?");
            $exists->execute([$postId]);
            if (!$exists->fetchColumn()) throw new RuntimeException('Post not found.');

            $db->prepare("INSERT INTO feed_comments (post_id, user_id, body) VALUES (?,?,?)")
                ->execute([$postId, $uid, $body]);
            $db->prepare("
                UPDATE feed_posts SET comments_count = (SELECT COUNT(*) FROM feed_comments WHERE post_id=?)
                WHERE id=?
            ")->execute([$postId, $postId]);
        }

        // Always redirect after POST so refresh doesn't replay
        $qs = http_build_query(array_filter([
            'type' => $_GET['type'] ?? null,
            'open' => $_POST['post_id'] ?? null,
        ]));
        header('Location: feed.php' . ($qs ? "?$qs" : ''));
        exit();

    } catch (Throwable $e) {
        $flash = $e->getMessage(); $flashType = 'danger';
    }
}

// ── Read filter & posts ──────────────────────────────────────────────────────
$tabs = [
    'all'               => ['All',              'fa-stream'],
    'driver_offer'      => ['Ride Offers',      'fa-car'],
    'passenger_request' => ['Looking for Ride', 'fa-user-tag'],
    'group_post'        => ['Group Rides',      'fa-users'],
];
$type = $_GET['type'] ?? 'all';
if (!isset($tabs[$type])) $type = 'all';
$openComments = (int)($_GET['open'] ?? 0);

$sql = "
    SELECT p.*,
           u.username       AS author_name,
           u.picture        AS author_pic,
           u.score          AS author_score,
           u.is_student     AS author_is_student,
           EXISTS(SELECT 1 FROM feed_likes l WHERE l.post_id = p.id AND l.user_id = ?) AS is_liked
    FROM feed_posts p
    JOIN users u ON u.id = p.user_id
";
$params = [$uid];
if ($type !== 'all') {
    $sql .= " WHERE p.type = ?";
    $params[] = $type;
}
$sql .= " ORDER BY p.is_boosted DESC, p.created_at DESC LIMIT 100";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Helpers ──────────────────────────────────────────────────────────────────
function feedTimeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', strtotime($datetime));
}
function feedTypeConfig(string $type): array {
    return match($type) {
        'driver_offer'      => ['fa-car',         '#0d6efd', 'Ride Offer'],
        'passenger_request' => ['fa-user-tag',    '#f97316', 'Looking for Ride'],
        'group_post'        => ['fa-users',       '#9333ea', 'Group Ride'],
        default             => ['fa-newspaper',   '#6b7280', 'Post'],
    };
}

$pageTitle = 'Community Feed';
include __DIR__ . '/../include/header.php';
include __DIR__ . '/../include/sidebar.php';
?>

<style>
    .feed-tabs { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .feed-tabs a {
        display: inline-flex; align-items: center; gap: .35rem;
        padding: .45rem 1rem; border-radius: 999px;
        background: #f8fafc; border: 1px solid #e5e7eb;
        text-decoration: none; color: #334155;
        font-size: .85rem; font-weight: 600;
    }
    .feed-tabs a.active {
        background: #0d6efd; color: #fff; border-color: #0d6efd;
    }
    .post-card {
        background: #fff; border: 1px solid #e5e7eb; border-radius: 14px;
        margin-bottom: 1rem; overflow: hidden;
    }
    .post-card.boosted { border-color: #f59e0b; box-shadow: 0 0 0 1px #f59e0b inset; }
    .post-boosted-banner {
        background: #fef3c7; color: #92400e;
        font-size: .72rem; font-weight: 700;
        padding: .25rem .85rem;
        display: flex; align-items: center; gap: .3rem;
    }
    .post-card .post-body { padding: 1rem 1.1rem; }
    .post-avatar {
        width: 42px; height: 42px; border-radius: 50%;
        object-fit: cover; background: #e5e7eb;
    }
    .post-author { font-weight: 700; font-size: .92rem; color: #0a1628; }
    .post-meta   { color: #64748b; font-size: .76rem; }
    .post-type-badge {
        display: inline-flex; align-items: center; gap: .25rem;
        padding: .2rem .55rem; border-radius: 6px;
        font-size: .68rem; font-weight: 700;
    }
    .student-badge {
        background: #dcfce7; color: #166534;
        padding: 0 .35rem; border-radius: 4px;
        font-size: .65rem; font-weight: 700;
    }
    .post-content {
        font-size: .95rem; line-height: 1.45;
        white-space: pre-wrap; word-break: break-word;
        margin: .6rem 0;
        color: #1f2937;
    }
    .route-box {
        background: #f8fafc; border: 1px solid #e5e7eb;
        border-radius: 10px; padding: .6rem .8rem;
        font-size: .85rem;
    }
    .route-box .from { color: #16a34a; font-weight: 600; }
    .route-box .to   { color: #dc2626; font-weight: 600; }
    .route-box .extra { color: #64748b; font-size: .78rem; margin-top: .35rem; }
    .post-actions {
        display: flex; align-items: center; gap: .35rem; flex-wrap: wrap;
        margin-top: .75rem;
        padding-top: .65rem; border-top: 1px solid #f1f5f9;
    }
    .post-actions button, .post-actions a {
        background: transparent; border: 0;
        padding: .35rem .65rem; border-radius: 8px;
        font-size: .82rem; color: #64748b; font-weight: 600;
        display: inline-flex; align-items: center; gap: .3rem;
        cursor: pointer; text-decoration: none;
    }
    .post-actions button:hover, .post-actions a:hover { background: #f1f5f9; }
    .post-actions .liked { color: #dc2626; }
    .post-actions .boost-btn { color: #b45309; }
    .post-actions .book-btn {
        margin-left: auto;
        background: #0d6efd; color: #fff;
        padding: .35rem .85rem;
    }
    .post-actions .book-btn:hover { background: #0a58ca; color: #fff; }

    .comments-block {
        background: #f8fafc; border-top: 1px solid #f1f5f9;
        padding: .75rem 1.1rem;
    }
    .comments-block .comment {
        display: flex; gap: .55rem; margin-bottom: .65rem; align-items: flex-start;
    }
    .comments-block .comment .name { font-weight: 700; font-size: .82rem; color: #0a1628; }
    .comments-block .comment .body { font-size: .85rem; color: #1f2937; }
    .comments-block .comment .when { font-size: .7rem; color: #64748b; margin-left: .25rem; }
    .comments-block .add-comment {
        display: flex; gap: .35rem; margin-top: .5rem;
    }
    .comments-block .add-comment input {
        flex: 1; border-radius: 999px;
        border: 1px solid #cbd5e1; padding: .4rem .9rem;
        background: #fff; font-size: .85rem;
    }
    .comments-block .add-comment button {
        background: #0d6efd; color: #fff; border: 0;
        border-radius: 999px; padding: .4rem .9rem;
        font-size: .8rem; font-weight: 700; cursor: pointer;
    }

    /* Create-post fab */
    .fab-create {
        position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 1000;
        background: #0d6efd; color: #fff;
        padding: .9rem 1.25rem; border-radius: 999px;
        font-weight: 700; box-shadow: 0 10px 24px rgba(13,110,253,.35);
        cursor: pointer; border: 0;
        display: inline-flex; align-items: center; gap: .4rem;
    }
</style>

<div class="fd-main">
    <div class="page-header d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="m-0"><i class="fas fa-stream text-primary me-2"></i>Community Feed</h1>
            <p class="text-muted mb-0 small">Share rides, ask for one, or join a group ride.</p>
        </div>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($flashType ?: 'info') ?> py-2 small">
            <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="feed-tabs">
        <?php foreach ($tabs as $key => [$label, $icon]): ?>
            <a href="feed.php?type=<?= urlencode($key) ?>"
               class="<?= $type === $key ? 'active' : '' ?>">
                <i class="fas <?= $icon ?>"></i> <?= htmlspecialchars($label) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Posts -->
    <?php if (empty($posts)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-stream fa-3x mb-3 d-block"></i>
            <h5>No posts yet</h5>
            <p class="small">Be the first to share a ride or request one!</p>
            <button class="btn btn-primary" onclick="document.getElementById('createPostModal').style.display='flex'">
                <i class="fas fa-pen me-1"></i> Create a Post
            </button>
        </div>
    <?php else: ?>
        <?php foreach ($posts as $p):
            [$tIcon, $tColor, $tLabel] = feedTypeConfig($p['type']);
            $isOwner = ((int)$p['user_id'] === $uid);
            $pic = $p['author_pic'] ?: '../Src/default.jpg';
            $pic = (strpos($pic, 'http') === 0) ? $pic : ('../' . ltrim($pic, '/'));
            // The DB stores e.g. "../Src/foo.jpg" — we're already in /Pages so use as-is
            $picSrc = (strpos(($p['author_pic'] ?? ''), 'http') === 0)
                ? $p['author_pic']
                : ($p['author_pic'] ?: '../Src/default.jpg');
        ?>
        <article class="post-card <?= $p['is_boosted'] ? 'boosted' : '' ?>">

            <?php if ($p['is_boosted']): ?>
                <div class="post-boosted-banner">
                    <i class="fas fa-bolt"></i> Featured
                </div>
            <?php endif; ?>

            <div class="post-body">
                <!-- Author row -->
                <div class="d-flex align-items-start gap-2">
                    <img class="post-avatar" src="<?= htmlspecialchars($picSrc) ?>"
                         alt="" onerror="this.src='../Src/default.jpg'">
                    <div class="flex-grow-1">
                        <div class="post-author">
                            <?= htmlspecialchars($p['author_name']) ?>
                            <?php if ((int)$p['author_is_student'] === 1): ?>
                                <span class="student-badge ms-1">Student</span>
                            <?php endif; ?>
                        </div>
                        <div class="post-meta">
                            <i class="fas fa-star text-warning"></i>
                            <?= number_format((float)$p['author_score'], 1) ?>
                            · <?= feedTimeAgo($p['created_at']) ?>
                        </div>
                    </div>
                    <span class="post-type-badge"
                          style="background: <?= $tColor ?>1A; color: <?= $tColor ?>;">
                        <i class="fas <?= $tIcon ?>"></i> <?= htmlspecialchars($tLabel) ?>
                    </span>
                    <?php if ($isOwner || $isAdmin): ?>
                        <form method="POST" onsubmit="return confirm('Delete this post?')" class="ms-1">
                            <button name="delete_post" value="<?= (int)$p['id'] ?>"
                                    class="btn btn-sm btn-link text-danger p-0" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Content -->
                <div class="post-content"><?= htmlspecialchars($p['content']) ?></div>

                <!-- Route info -->
                <?php if (!empty($p['from_location']) || !empty($p['to_location'])): ?>
                <div class="route-box">
                    <?php if (!empty($p['from_location'])): ?>
                        <div><span class="from"><i class="fas fa-circle-dot"></i></span>
                            <?= htmlspecialchars($p['from_location']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($p['to_location'])): ?>
                        <div><span class="to"><i class="fas fa-location-dot"></i></span>
                            <?= htmlspecialchars($p['to_location']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($p['departure_date']) || !empty($p['seats'])): ?>
                        <div class="extra">
                            <?php if (!empty($p['departure_date'])): ?>
                                <i class="far fa-calendar"></i> <?= htmlspecialchars($p['departure_date']) ?>
                            <?php endif; ?>
                            <?php if (!empty($p['seats'])): ?>
                                &nbsp;·&nbsp; <i class="fas fa-chair"></i> <?= (int)$p['seats'] ?> seat(s)
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Action buttons -->
                <div class="post-actions">
                    <form method="POST" class="d-inline">
                        <button name="like_post" value="<?= (int)$p['id'] ?>"
                                class="<?= $p['is_liked'] ? 'liked' : '' ?>" title="Like">
                            <i class="<?= $p['is_liked'] ? 'fas' : 'far' ?> fa-heart"></i>
                            <?= (int)$p['likes_count'] ?>
                        </button>
                    </form>
                    <a href="?type=<?= urlencode($type) ?>&open=<?= (int)$p['id'] ?>#post-<?= (int)$p['id'] ?>" title="Comments">
                        <i class="far fa-comment"></i> <?= (int)$p['comments_count'] ?>
                    </a>
                    <?php if ($isOwner && (int)$p['is_boosted'] !== 1): ?>
                        <form method="POST" class="d-inline"
                              onsubmit="return confirm('Boost this post for 5 TND from your wallet?')">
                            <button name="boost_post" value="<?= (int)$p['id'] ?>" class="boost-btn" title="Boost (5 TND)">
                                <i class="fas fa-bolt"></i> Boost
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if (!empty($p['ride_id'])): ?>
                        <a class="book-btn" href="book_ride.php?id=<?= (int)$p['ride_id'] ?>">
                            Book Now <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Comments (open if ?open=<id>) -->
            <?php if ($openComments === (int)$p['id']):
                $cStmt = $db->prepare("
                    SELECT c.*, u.username AS author_name, u.picture AS author_pic
                    FROM feed_comments c JOIN users u ON u.id = c.user_id
                    WHERE c.post_id = ? ORDER BY c.created_at ASC LIMIT 200
                ");
                $cStmt->execute([(int)$p['id']]);
                $comments = $cStmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <div class="comments-block" id="post-<?= (int)$p['id'] ?>">
                <?php if (empty($comments)): ?>
                    <div class="text-muted small mb-2">No comments yet. Be the first!</div>
                <?php else: foreach ($comments as $c):
                    $cPic = $c['author_pic'] ?: '../Src/default.jpg';
                ?>
                    <div class="comment">
                        <img class="post-avatar" style="width:30px;height:30px"
                             src="<?= htmlspecialchars($cPic) ?>" alt=""
                             onerror="this.src='../Src/default.jpg'">
                        <div>
                            <span class="name"><?= htmlspecialchars($c['author_name']) ?></span>
                            <span class="when">· <?= feedTimeAgo($c['created_at']) ?></span>
                            <div class="body"><?= nl2br(htmlspecialchars($c['body'])) ?></div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
                <form method="POST" class="add-comment">
                    <input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
                    <input type="text" name="comment_body" maxlength="1000"
                           placeholder="Write a comment..." required autofocus>
                    <button name="add_comment" value="1">Send</button>
                </form>
            </div>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    <?php endif; ?>

    <button class="fab-create" onclick="document.getElementById('createPostModal').style.display='flex'">
        <i class="fas fa-pen"></i> New Post
    </button>
</div>

<!-- Create-post modal -->
<div id="createPostModal" style="
    display:none; position:fixed; inset:0; z-index:1100;
    background:rgba(10,22,40,.55);
    align-items:center; justify-content:center;
    padding:1rem;">
    <div style="background:#fff; border-radius:14px; max-width:520px; width:100%; max-height:90vh; overflow-y:auto; padding:1.5rem;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="m-0"><i class="fas fa-pen me-1 text-primary"></i> Create a Post</h4>
            <button class="btn btn-link text-muted p-0" onclick="document.getElementById('createPostModal').style.display='none'">
                <i class="fas fa-times fa-lg"></i>
            </button>
        </div>
        <form method="POST">
            <input type="hidden" name="create_post" value="1">

            <label class="form-label small fw-bold">Post type</label>
            <div class="d-flex gap-2 mb-3">
                <?php foreach ([
                    ['driver_offer',      'fa-car',      'Offering a Ride',  '#0d6efd'],
                    ['passenger_request', 'fa-user-tag', 'Looking for Ride', '#f97316'],
                    ['group_post',        'fa-users',    'Group Ride',       '#9333ea'],
                ] as [$val, $ic, $lbl, $col]): ?>
                    <label class="flex-fill text-center" style="cursor:pointer;">
                        <input type="radio" name="type" value="<?= $val ?>"
                               <?= $val === 'driver_offer' ? 'checked' : '' ?>
                               style="display:none" onchange="
                            document.querySelectorAll('[data-post-type]').forEach(el=>el.style.border='1px solid #e5e7eb');
                            this.parentNode.querySelector('[data-post-type]').style.border='2px solid <?= $col ?>';
                        ">
                        <div data-post-type style="
                            padding:.75rem; border-radius:10px;
                            border:<?= $val === 'driver_offer' ? '2px solid '.$col : '1px solid #e5e7eb' ?>;
                            font-size:.78rem; font-weight:600; color:<?= $col ?>;">
                            <i class="fas <?= $ic ?> d-block mb-1"></i>
                            <?= htmlspecialchars($lbl) ?>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>

            <label class="form-label small fw-bold">Content</label>
            <textarea name="content" class="form-control mb-3" rows="3" required maxlength="2000"
                placeholder="Describe your ride / request / group ride..."></textarea>

            <div class="row g-2 mb-2">
                <div class="col-6">
                    <input class="form-control" name="from_location" placeholder="From">
                </div>
                <div class="col-6">
                    <input class="form-control" name="to_location" placeholder="To">
                </div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-7">
                    <input type="date" class="form-control" name="departure_date">
                </div>
                <div class="col-5">
                    <input type="number" class="form-control" name="seats"
                           min="1" max="8" value="1" placeholder="Seats">
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100">
                <i class="fas fa-paper-plane me-1"></i> Post
            </button>
        </form>
    </div>
</div>

<script>
    // Close modal on backdrop click
    document.getElementById('createPostModal').addEventListener('click', function (e) {
        if (e.target === this) this.style.display = 'none';
    });
</script>
