<?php
require_once '../server/session.php';
require_once '../server/language.php';

if (!isLoggedIn()) { header('Location: login.php'); exit(); }

$db  = getDB();
$uid = $_SESSION['user_data']['id'];
$ud  = $_SESSION['user_data'];
$isAgent = !empty($ud['is_helpdesk_agent']);
$isAdmin = !empty($ud['is_admin']);
$isSupervisor = $isAgent || $isAdmin;

// ── Bot knowledge base ────────────────────────────────────────────────────────
function botReply(string $msg): string {
    $msg = strtolower(trim($msg));
    $rules = [
        ['keywords'=>['book','réserver','reserve','ride','trajet'],
         'reply'=>"To book a ride, go to **Search Rides** in the sidebar, find a ride that suits you and click **Book Now**. Make sure you have enough balance in your wallet first."],
        ['keywords'=>['cancel','annul'],
         'reply'=>"To cancel a booking, go to **My Rides → Upcoming** and click Cancel. Your balance will be refunded automatically within minutes."],
        ['keywords'=>['balance','wallet','argent','money','recharge','top','fund'],
         'reply'=>"To add funds, go to **Payments** and use the top-up form. Quick amounts (10, 20, 50, 100 TND) are available. Funds are credited instantly."],
        ['keywords'=>['password','mot de passe','reset','forgot','oublié'],
         'reply'=>"To change your password, go to **Settings → Security**. If you've forgotten it, click **Talk to an Agent** below for manual assistance."],
        ['keywords'=>['driver','conducteur','offer','propose','create','créer'],
         'reply'=>"To offer a ride, you must be a registered driver. Check **Settings → Profile** for your role. Then use **Create Ride** in the sidebar's Driver section."],
        ['keywords'=>['vehicle','voiture','car','vehicule','véhicule','matricul','plate'],
         'reply'=>"Manage your vehicles under **My Vehicles** in the sidebar. You can add cars, vans, or bikes with features like AC, luggage capacity, and accessibility."],
        ['keywords'=>['student','étudiant','discount','réduction','50'],
         'reply'=>"Students get a **50% discount** on all rides! Submit your institutional email (.edu.tn / .rnu.tn) under **Settings → Student Verification** for admin approval."],
        ['keywords'=>['rating','avis','review','note','star','étoile'],
         'reply'=>"After a completed ride, rate your driver from **My Rides → Past** tab. Ratings help keep our community safe and trustworthy."],
        ['keywords'=>['complaint','plainte','report','signaler','problem','problème'],
         'reply'=>"To file a complaint, go to **Complaints** in the sidebar. Our team reviews all complaints within 24–48 hours."],
        ['keywords'=>['region','governorate','gouvernorat','tunisie','tunisia','city','ville'],
         'reply'=>"ForsaDrive covers all **24 governorates of Tunisia**. Update your location under **Settings → Location** to see rides near you first."],
        ['keywords'=>['message','chat','contact','agent','support','help','aide'],
         'reply'=>"You're in the HelpDesk! Type your question and I'll try to help. Click **Talk to an Agent** below if you need a human."],
        ['keywords'=>['hello','hi','bonjour','salut','hey','salam','مرحبا'],
         'reply'=>"Hello! I'm the ForsaDrive HelpBot. Ask me anything about booking rides, managing your account, or platform features!"],
        ['keywords'=>['thanks','merci','thank','شكرا'],
         'reply'=>"You're welcome! Is there anything else I can help you with?"],
    ];
    foreach ($rules as $rule) {
        foreach ($rule['keywords'] as $kw) {
            if (str_contains($msg, $kw)) return $rule['reply'];
        }
    }
    return "I'm not sure about that. You can ask about: **booking rides**, **payments**, **ratings**, **complaints**, **vehicles**, **student discounts**, or **account settings**. Or click **Talk to an Agent** for human support.";
}

// ── Migrations (safe, idempotent) ─────────────────────────────────────────────
try { $db->exec("ALTER TABLE helpdesk_conversations ADD COLUMN priority TEXT DEFAULT 'normal'"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE helpdesk_conversations ADD COLUMN agent_initiated INTEGER DEFAULT 0"); } catch(Exception $e) {}

// ── POST handler ──────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';
$convId = (int)($_GET['conv'] ?? $_POST['conv_id'] ?? 0);
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // New ticket (user opens)
    if ($action === 'new_ticket') {
        $subject  = trim($_POST['subject'] ?? 'Support request');
        $firstMsg = trim($_POST['message'] ?? '');
        if (!$firstMsg) { $error = 'Please describe your issue.'; }
        else {
            $db->prepare("INSERT INTO helpdesk_conversations (user_id, subject, status) VALUES (?,?,'open')")
               ->execute([$uid, $subject]);
            $cid = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO helpdesk_messages (conv_id, sender_id, sender_type, body) VALUES (?,?,?,?)")
               ->execute([$cid, $uid, 'user', $firstMsg]);
            $db->prepare("INSERT INTO helpdesk_messages (conv_id, sender_id, sender_type, body) VALUES (?,?,?,?)")
               ->execute([$cid, null, 'bot', botReply($firstMsg)]);
            $db->prepare("UPDATE helpdesk_conversations SET updated_at=datetime('now') WHERE id=?")
               ->execute([$cid]);
            header("Location: helpdesk.php?conv=$cid"); exit();
        }

    // Agent starts a conversation with any user
    } elseif ($action === 'start_with_user' && $isSupervisor) {
        $targetUser = (int)($_POST['target_user'] ?? 0);
        $subject    = trim($_POST['subject'] ?? 'Message from HelpDesk');
        $firstMsg   = trim($_POST['message'] ?? '');
        if ($targetUser && $firstMsg) {
            $db->prepare("INSERT INTO helpdesk_conversations (user_id, agent_id, subject, status, agent_initiated) VALUES (?,?,?,'assigned',1)")
               ->execute([$targetUser, $uid, $subject]);
            $cid = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO helpdesk_messages (conv_id, sender_id, sender_type, body) VALUES (?,?,?,?)")
               ->execute([$cid, $uid, 'agent', $firstMsg]);
            $db->prepare("UPDATE helpdesk_conversations SET updated_at=datetime('now') WHERE id=?")
               ->execute([$cid]);
            // Notify the user
            $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?,?,?)")
               ->execute([$targetUser, 'helpdesk', 'A support agent has sent you a message. Open HelpDesk to reply.']);
            header("Location: helpdesk.php?conv=$cid"); exit();
        }

    // Send message
    } elseif ($action === 'send' && $convId) {
        $body = trim($_POST['body'] ?? '');
        if ($body) {
            $stmt = $db->prepare("SELECT * FROM helpdesk_conversations WHERE id=?");
            $stmt->execute([$convId]);
            $conv = $stmt->fetch(PDO::FETCH_ASSOC);
            $canSend = $conv && (
                $conv['user_id'] == $uid ||
                $conv['agent_id'] == $uid ||
                $isSupervisor
            ) && !in_array($conv['status'], ['resolved', 'closed']);

            if ($canSend) {
                $sType = $isSupervisor ? 'agent' : 'user';
                $db->prepare("INSERT INTO helpdesk_messages (conv_id, sender_id, sender_type, body) VALUES (?,?,?,?)")
                   ->execute([$convId, $uid, $sType, $body]);
                $db->prepare("UPDATE helpdesk_conversations SET updated_at=datetime('now') WHERE id=?")->execute([$convId]);
                // Auto-assign agent when they reply
                if ($isSupervisor && empty($conv['agent_id'])) {
                    $db->prepare("UPDATE helpdesk_conversations SET agent_id=?, status='assigned', updated_at=datetime('now') WHERE id=?")
                       ->execute([$uid, $convId]);
                }
                // Bot reply only for unassigned user-sent messages
                if (!$isSupervisor && empty($conv['agent_id'])) {
                    $botMsg = botReply($body);
                    $db->prepare("INSERT INTO helpdesk_messages (conv_id, sender_id, sender_type, body) VALUES (?,?,?,?)")
                       ->execute([$convId, null, 'bot', $botMsg]);
                }
            }
            header("Location: helpdesk.php?conv=$convId"); exit();
        }

    // Request human agent
    } elseif ($action === 'request_agent' && $convId) {
        $db->prepare("INSERT INTO helpdesk_messages (conv_id, sender_id, sender_type, body) VALUES (?,?,?,?)")
           ->execute([$convId, null, 'bot', "You've requested a human agent. Our team will be with you shortly. Average response time: 1–4 hours during business days."]);
        $db->prepare("UPDATE helpdesk_conversations SET status='open', updated_at=datetime('now') WHERE id=?")->execute([$convId]);
        header("Location: helpdesk.php?conv=$convId"); exit();

    // Assign ticket to self (agent)
    } elseif ($action === 'assign' && $convId && $isSupervisor) {
        $db->prepare("UPDATE helpdesk_conversations SET agent_id=?, status='assigned', updated_at=datetime('now') WHERE id=?")
           ->execute([$uid, $convId]);
        header("Location: helpdesk.php?conv=$convId"); exit();

    // Set priority (agent)
    } elseif ($action === 'set_priority' && $convId && $isSupervisor) {
        $prio = in_array($_POST['priority'], ['low','normal','high','urgent']) ? $_POST['priority'] : 'normal';
        $db->prepare("UPDATE helpdesk_conversations SET priority=?, updated_at=datetime('now') WHERE id=?")->execute([$prio, $convId]);
        header("Location: helpdesk.php?conv=$convId"); exit();

    // Resolve/close
    } elseif ($action === 'close' && $convId && $isSupervisor) {
        $db->prepare("UPDATE helpdesk_conversations SET status='resolved', updated_at=datetime('now') WHERE id=?")->execute([$convId]);
        header("Location: helpdesk.php"); exit();

    // Reopen
    } elseif ($action === 'reopen' && $convId && $isSupervisor) {
        $db->prepare("UPDATE helpdesk_conversations SET status='open', updated_at=datetime('now') WHERE id=?")->execute([$convId]);
        header("Location: helpdesk.php?conv=$convId"); exit();
    }
}

// ── Load conversations ────────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'all';
$validStatuses = ['all','open','assigned','resolved'];
if (!in_array($statusFilter, $validStatuses)) $statusFilter = 'all';

if ($isSupervisor) {
    $where  = $statusFilter !== 'all' ? "AND hc.status=?" : '';
    $params = $statusFilter !== 'all' ? [$statusFilter] : [];
    $sql = "SELECT hc.*, u.username AS user_name, u.picture AS user_pic,
                   a.username AS agent_name
            FROM helpdesk_conversations hc
            JOIN users u ON u.id = hc.user_id
            LEFT JOIN users a ON a.id = hc.agent_id
            WHERE 1=1 $where
            ORDER BY
              CASE hc.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END,
              hc.updated_at DESC
            LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $convs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $statOpen     = (int)$db->query("SELECT COUNT(*) FROM helpdesk_conversations WHERE status IN ('open')")->fetchColumn();
    $statAssigned = (int)$db->query("SELECT COUNT(*) FROM helpdesk_conversations WHERE status='assigned'")->fetchColumn();
    $statToday    = (int)$db->query("SELECT COUNT(*) FROM helpdesk_conversations WHERE status='resolved' AND date(updated_at)=date('now')")->fetchColumn();
    $statTotal    = (int)$db->query("SELECT COUNT(*) FROM helpdesk_conversations")->fetchColumn();
    $statUsers    = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();

    // User list for search / contact panel
    $userSearch = trim($_GET['usearch'] ?? '');
    if ($userSearch) {
        $uStmt = $db->prepare("SELECT id, username, email, picture, is_driver, is_admin, is_helpdesk_agent FROM users WHERE username LIKE ? OR email LIKE ? ORDER BY username LIMIT 30");
        $uStmt->execute(["%$userSearch%", "%$userSearch%"]);
    } else {
        $uStmt = $db->query("SELECT id, username, email, picture, is_driver, is_admin, is_helpdesk_agent FROM users ORDER BY username LIMIT 30");
    }
    $userList = $uStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $db->prepare("SELECT hc.*, u.username AS user_name FROM helpdesk_conversations hc JOIN users u ON u.id=hc.user_id WHERE hc.user_id=? ORDER BY hc.updated_at DESC");
    $stmt->execute([$uid]);
    $convs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Active conversation ───────────────────────────────────────────────────────
$activeConv = null;
$messages   = [];
if ($convId) {
    $stmt = $db->prepare("SELECT hc.*, u.username AS user_name, a.username AS agent_name
                          FROM helpdesk_conversations hc
                          JOIN users u ON u.id = hc.user_id
                          LEFT JOIN users a ON a.id = hc.agent_id
                          WHERE hc.id=?");
    $stmt->execute([$convId]);
    $activeConv = $stmt->fetch(PDO::FETCH_ASSOC);
    // Access check
    if ($activeConv && !$isSupervisor && $activeConv['user_id'] != $uid) {
        $activeConv = null;
    }
    if ($activeConv) {
        // Mark read
        $db->prepare("UPDATE helpdesk_messages SET is_read=1 WHERE conv_id=? AND sender_id!=?")->execute([$convId, $uid]);
        $stmt = $db->prepare("SELECT hm.*, u.username AS sender_name, u.picture AS sender_pic
                              FROM helpdesk_messages hm
                              LEFT JOIN users u ON u.id=hm.sender_id
                              WHERE hm.conv_id=? ORDER BY hm.created_at ASC");
        $stmt->execute([$convId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ────────────────────────────────────────────────────────────────────────────
// RENDER: agent console is a completely separate layout
// ────────────────────────────────────────────────────────────────────────────
if ($isSupervisor):
$_lang = $GLOBALS['selectedLang'] ?? 'en';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($_lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HelpDesk Console — ForsaDrive</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="../Css/app.css" rel="stylesheet">
<style>
:root { --hd-bg:#0f172a; --hd-sidebar:#1e293b; --hd-border:#334155; --hd-accent:#3b82f6; }
*,*::before,*::after { box-sizing:border-box; }
html,body { height:100%; margin:0; background:#f1f5f9; font-family:'Inter',system-ui,sans-serif; font-size:.875rem; }

/* ── Console shell ── */
.hd-shell { display:flex; flex-direction:column; height:100vh; }
.hd-navbar {
    background:var(--hd-bg); color:#fff; display:flex; align-items:center;
    padding:.6rem 1.25rem; gap:1rem; flex-shrink:0; border-bottom:1px solid var(--hd-border);
}
.hd-navbar .brand { font-weight:700; font-size:1.1rem; }
.hd-navbar .brand span { color:var(--hd-accent); }
.hd-navbar .spacer { flex:1; }
.hd-agent-badge { background:rgba(59,130,246,.18); color:#93c5fd; border-radius:20px;
    padding:.2rem .75rem; font-size:.72rem; font-weight:600; letter-spacing:.03em; }
.hd-navbar a { color:#94a3b8; text-decoration:none; font-size:.8rem; }
.hd-navbar a:hover { color:#fff; }

/* ── Stats bar ── */
.hd-stats { background:#fff; border-bottom:1px solid #e2e8f0; display:flex; flex-shrink:0; }
.hd-stat { flex:1; text-align:center; padding:.75rem .5rem; border-right:1px solid #e2e8f0; }
.hd-stat:last-child { border-right:none; }
.hd-stat .val { font-size:1.5rem; font-weight:700; line-height:1; }
.hd-stat .lbl { font-size:.68rem; color:#64748b; margin-top:.2rem; text-transform:uppercase; letter-spacing:.04em; }
.hd-stat.c-red .val { color:#ef4444; }
.hd-stat.c-amber .val { color:#f59e0b; }
.hd-stat.c-green .val { color:#22c55e; }
.hd-stat.c-blue .val { color:#3b82f6; }
.hd-stat.c-purple .val { color:#8b5cf6; }

/* ── Three-pane layout ── */
.hd-body { flex:1; display:flex; overflow:hidden; }
.hd-queue { width:280px; flex-shrink:0; border-right:1px solid #e2e8f0; background:#fff; display:flex; flex-direction:column; }
.hd-center { flex:1; display:flex; flex-direction:column; background:#f8fafc; }
.hd-users { width:260px; flex-shrink:0; border-left:1px solid #e2e8f0; background:#fff; display:flex; flex-direction:column; }

/* ── Queue panel ── */
.hd-panel-head { padding:.75rem 1rem; border-bottom:1px solid #e2e8f0; font-weight:600; font-size:.8rem;
    text-transform:uppercase; letter-spacing:.05em; color:#64748b; background:#f8fafc; flex-shrink:0; }
.hd-filters { padding:.5rem .75rem; border-bottom:1px solid #e2e8f0; display:flex; gap:.35rem; flex-wrap:wrap; flex-shrink:0; }
.hd-filter-btn { border:1px solid #e2e8f0; background:#fff; color:#64748b; padding:.2rem .6rem;
    border-radius:20px; font-size:.7rem; cursor:pointer; transition:all .15s; }
.hd-filter-btn.active { background:var(--hd-accent); border-color:var(--hd-accent); color:#fff; }
.hd-ticket-list { flex:1; overflow-y:auto; }
.hd-ticket {
    padding:.75rem 1rem; border-bottom:1px solid #f1f5f9; cursor:pointer;
    transition:background .12s; display:block; text-decoration:none; color:inherit;
}
.hd-ticket:hover { background:#f8fafc; }
.hd-ticket.active { background:#eff6ff; border-left:3px solid var(--hd-accent); }
.hd-ticket .t-subject { font-weight:600; font-size:.8rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hd-ticket .t-meta { font-size:.68rem; color:#94a3b8; margin-top:.2rem; }
.hd-ticket .t-user { font-size:.72rem; color:#475569; margin-top:.1rem; }
.prio-dot { display:inline-block; width:7px; height:7px; border-radius:50%; margin-right:.35rem; }
.prio-urgent { background:#ef4444; }
.prio-high    { background:#f97316; }
.prio-normal  { background:#94a3b8; }
.prio-low     { background:#d1d5db; }

/* ── Chat panel ── */
.hd-conv-header { padding:.85rem 1.25rem; border-bottom:1px solid #e2e8f0; background:#fff;
    display:flex; align-items:center; gap:.75rem; flex-shrink:0; flex-wrap:wrap; }
.hd-conv-header .conv-title { font-weight:700; font-size:.9rem; }
.hd-conv-header .conv-meta { font-size:.7rem; color:#64748b; }
.hd-conv-actions { display:flex; gap:.4rem; margin-left:auto; flex-wrap:wrap; }
.chat-messages { flex:1; overflow-y:auto; padding:1rem 1.25rem; display:flex; flex-direction:column; gap:.75rem; }
.bubble {
    max-width:72%; padding:.6rem .9rem; border-radius:12px; font-size:.82rem; line-height:1.45;
    position:relative; word-break:break-word;
}
.bubble.me { background:var(--hd-accent); color:#fff; align-self:flex-end; border-bottom-right-radius:3px; }
.bubble.bot { background:#f1f5f9; color:#374151; align-self:flex-start; border-bottom-left-radius:3px; }
.bubble.them { background:#fff; color:#1e293b; align-self:flex-start; border:1px solid #e2e8f0; border-bottom-left-radius:3px; }
.bubble .bname { font-size:.65rem; font-weight:600; margin-bottom:.25rem; opacity:.7; }
.bubble .btime { font-size:.62rem; margin-top:.3rem; opacity:.55; }
.bubble.me .btime { text-align:right; }
.hd-input-bar { padding:.75rem 1rem; border-top:1px solid #e2e8f0; background:#fff; display:flex; gap:.5rem; flex-shrink:0; }
.hd-input-bar textarea { flex:1; resize:none; border:1px solid #e2e8f0; border-radius:8px;
    padding:.5rem .75rem; font-size:.82rem; line-height:1.4; font-family:inherit; }
.hd-input-bar textarea:focus { outline:none; border-color:var(--hd-accent); box-shadow:0 0 0 3px rgba(59,130,246,.12); }
.hd-btn-send { background:var(--hd-accent); color:#fff; border:none; border-radius:8px;
    padding:.5rem .9rem; cursor:pointer; transition:background .15s; font-size:.85rem; }
.hd-btn-send:hover { background:#2563eb; }
.hd-empty { flex:1; display:flex; align-items:center; justify-content:center; flex-direction:column;
    gap:.5rem; color:#94a3b8; }
.hd-empty i { font-size:2.5rem; opacity:.3; }

/* ── Users panel ── */
.hd-user-search { padding:.5rem .75rem; border-bottom:1px solid #e2e8f0; flex-shrink:0; }
.hd-user-search form { display:flex; gap:.35rem; }
.hd-user-search input { flex:1; border:1px solid #e2e8f0; border-radius:6px;
    padding:.3rem .6rem; font-size:.78rem; }
.hd-user-list { flex:1; overflow-y:auto; }
.hd-user-item { padding:.6rem .85rem; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; gap:.5rem; }
.hd-user-item img { width:30px; height:30px; border-radius:50%; object-fit:cover; }
.hd-user-item .uname { font-weight:600; font-size:.78rem; }
.hd-user-item .urole { font-size:.65rem; color:#94a3b8; }
.hd-user-item .contact-btn { margin-left:auto; flex-shrink:0; }

/* ── Status badges ── */
.s-open     { background:#fef3c7; color:#b45309; }
.s-assigned { background:#dbeafe; color:#1d4ed8; }
.s-resolved { background:#dcfce7; color:#166534; }
.s-closed   { background:#f1f5f9; color:#64748b; }
.hd-badge { display:inline-flex; align-items:center; padding:.15rem .55rem;
    border-radius:20px; font-size:.65rem; font-weight:600; letter-spacing:.02em; }

/* ── Modals ── */
.hd-modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; align-items:center; justify-content:center; }
.hd-modal-backdrop.show { display:flex; }
.hd-modal { background:#fff; border-radius:12px; padding:1.5rem; width:100%; max-width:480px; box-shadow:0 20px 60px rgba(0,0,0,.2); }
.hd-modal h5 { margin:0 0 1rem; font-size:1rem; font-weight:700; }
.hd-modal .form-control { border:1px solid #e2e8f0; border-radius:8px; padding:.5rem .75rem;
    font-size:.82rem; width:100%; margin-bottom:.75rem; font-family:inherit; }
.hd-modal .form-control:focus { outline:none; border-color:var(--hd-accent); box-shadow:0 0 0 3px rgba(59,130,246,.12); }
.hd-modal-footer { display:flex; gap:.5rem; justify-content:flex-end; margin-top:1rem; }

@media(max-width:900px) {
    .hd-users { display:none; }
    .hd-queue { width:240px; }
}
@media(max-width:600px) {
    .hd-queue { width:100%; border-right:none; display:none; }
    .hd-queue.mobile-show { display:flex; position:fixed; inset:0; z-index:500; }
}
</style>
</head>
<body>
<div class="hd-shell">

  <!-- Navbar -->
  <nav class="hd-navbar">
    <span class="brand">Forsa<span>Drive</span></span>
    <span style="color:#475569;font-size:.8rem">/ HelpDesk Console</span>
    <span class="spacer"></span>
    <span class="hd-agent-badge">
      <i class="fas fa-headset me-1"></i>
      <?= $isAdmin ? 'Admin' : 'Agent' ?>: <?= htmlspecialchars($ud['username']) ?>
    </span>
    <a href="interface.php"><i class="fas fa-home me-1"></i>Dashboard</a>
    <?php if ($isAdmin): ?>
    <a href="admin.php"><i class="fas fa-shield-alt me-1"></i>Admin</a>
    <?php endif; ?>
    <a href="../index.php?logout=1" class="text-danger-emphasis"><i class="fas fa-sign-out-alt me-1"></i>Logout</a>
  </nav>

  <!-- Stats bar -->
  <div class="hd-stats">
    <div class="hd-stat c-red">
      <div class="val"><?= $statOpen ?></div>
      <div class="lbl">Open</div>
    </div>
    <div class="hd-stat c-amber">
      <div class="val"><?= $statAssigned ?></div>
      <div class="lbl">Assigned</div>
    </div>
    <div class="hd-stat c-green">
      <div class="val"><?= $statToday ?></div>
      <div class="lbl">Resolved Today</div>
    </div>
    <div class="hd-stat c-blue">
      <div class="val"><?= $statTotal ?></div>
      <div class="lbl">All Tickets</div>
    </div>
    <div class="hd-stat c-purple">
      <div class="val"><?= $statUsers ?></div>
      <div class="lbl">Users</div>
    </div>
  </div>

  <!-- Main body: 3 panes -->
  <div class="hd-body">

    <!-- LEFT: Ticket queue -->
    <div class="hd-queue">
      <div class="hd-panel-head"><i class="fas fa-list-ul me-2"></i>Ticket Queue</div>
      <div class="hd-filters">
        <?php foreach (['all'=>'All','open'=>'Open','assigned'=>'Assigned','resolved'=>'Resolved'] as $s=>$l): ?>
        <a href="?status=<?= $s ?><?= $convId ? "&conv=$convId" : '' ?>"
           class="hd-filter-btn <?= $statusFilter===$s ? 'active' : '' ?>"><?= $l ?></a>
        <?php endforeach; ?>
      </div>
      <div class="hd-ticket-list">
        <?php if (empty($convs)): ?>
        <div class="p-3 text-center text-muted small">No tickets match this filter.</div>
        <?php else: ?>
        <?php foreach ($convs as $c):
            $prioClass = 'prio-' . ($c['priority'] ?? 'normal');
            $statusClass = 's-' . ($c['status'] ?? 'open');
        ?>
        <a href="helpdesk.php?conv=<?= $c['id'] ?>&status=<?= $statusFilter ?>"
           class="hd-ticket <?= $convId==$c['id'] ? 'active' : '' ?>">
          <div class="t-subject">
            <span class="prio-dot <?= $prioClass ?>"></span>
            <?= htmlspecialchars(mb_substr($c['subject'] ?? 'Support request', 0, 40)) ?>
          </div>
          <div class="t-user"><i class="fas fa-user me-1 opacity-50"></i><?= htmlspecialchars($c['user_name'] ?? '') ?>
            <?php if ($c['agent_name']): ?>
              <span class="text-muted"> → <?= htmlspecialchars($c['agent_name']) ?></span>
            <?php endif; ?>
          </div>
          <div class="t-meta d-flex gap-2 align-items-center">
            <span class="hd-badge <?= $statusClass ?>"><?= ucfirst($c['status']) ?></span>
            <span><?= date('d M H:i', strtotime($c['updated_at'] ?? 'now')) ?></span>
          </div>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- CENTER: Chat -->
    <div class="hd-center">
      <?php if ($activeConv): ?>

      <!-- Conversation header -->
      <div class="hd-conv-header">
        <div>
          <div class="conv-title"><?= htmlspecialchars($activeConv['subject'] ?? 'Support request') ?></div>
          <div class="conv-meta">
            Ticket #<?= $activeConv['id'] ?>
            &nbsp;·&nbsp; User: <strong><?= htmlspecialchars($activeConv['user_name']) ?></strong>
            <?php if ($activeConv['agent_name']): ?>
              &nbsp;·&nbsp; Agent: <strong><?= htmlspecialchars($activeConv['agent_name']) ?></strong>
            <?php endif; ?>
          </div>
        </div>
        <div class="hd-conv-actions">
          <!-- Priority -->
          <form method="POST" class="d-flex gap-1">
            <input type="hidden" name="action" value="set_priority">
            <input type="hidden" name="conv_id" value="<?= $convId ?>">
            <select name="priority" class="form-select form-select-sm" onchange="this.form.submit()" style="font-size:.72rem;width:auto">
              <?php foreach (['low'=>'Low','normal'=>'Normal','high'=>'High','urgent'=>'Urgent'] as $p=>$pl): ?>
              <option value="<?= $p ?>" <?= ($activeConv['priority']??'normal')===$p?'selected':'' ?>><?= $pl ?></option>
              <?php endforeach; ?>
            </select>
          </form>
          <!-- Assign to me -->
          <?php if (empty($activeConv['agent_id']) || $activeConv['agent_id'] != $uid): ?>
          <form method="POST">
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="conv_id" value="<?= $convId ?>">
            <button class="btn btn-sm btn-outline-primary" style="font-size:.72rem">
              <i class="fas fa-hand-pointer me-1"></i>Assign to me
            </button>
          </form>
          <?php endif; ?>
          <!-- Resolve/Reopen -->
          <?php if (in_array($activeConv['status'], ['resolved','closed'])): ?>
          <form method="POST">
            <input type="hidden" name="action" value="reopen">
            <input type="hidden" name="conv_id" value="<?= $convId ?>">
            <button class="btn btn-sm btn-outline-warning" style="font-size:.72rem">
              <i class="fas fa-undo me-1"></i>Reopen
            </button>
          </form>
          <?php else: ?>
          <form method="POST">
            <input type="hidden" name="action" value="close">
            <input type="hidden" name="conv_id" value="<?= $convId ?>">
            <button class="btn btn-sm btn-success" style="font-size:.72rem">
              <i class="fas fa-check me-1"></i>Resolve
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <!-- Messages -->
      <div class="chat-messages" id="chatMessages">
        <?php foreach ($messages as $m):
          $isMe  = $m['sender_id'] == $uid;
          $isBot = $m['sender_type'] === 'bot';
          $cls   = $isMe ? 'me' : ($isBot ? 'bot' : 'them');
        ?>
        <div class="bubble <?= $cls ?>">
          <?php if ($isBot): ?>
          <div class="bname"><i class="fas fa-robot me-1"></i>HelpBot</div>
          <?php elseif (!$isMe && $m['sender_name']): ?>
          <div class="bname"><i class="fas fa-user me-1"></i><?= htmlspecialchars($m['sender_name']) ?></div>
          <?php endif; ?>
          <div><?= nl2br(htmlspecialchars($m['body'])) ?></div>
          <div class="btime"><?= date('d M, H:i', strtotime($m['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($messages)): ?>
        <div class="text-center text-muted small mt-4">No messages yet. Send the first message below.</div>
        <?php endif; ?>
      </div>

      <!-- Input bar -->
      <?php if (!in_array($activeConv['status'], ['resolved','closed'])): ?>
      <div class="hd-input-bar">
        <form method="POST" class="d-flex gap-2 w-100">
          <input type="hidden" name="action" value="send">
          <input type="hidden" name="conv_id" value="<?= $convId ?>">
          <textarea name="body" id="chatInput" rows="2" placeholder="Reply as agent… (Enter to send, Shift+Enter for newline)" required></textarea>
          <button type="submit" class="hd-btn-send"><i class="fas fa-paper-plane"></i></button>
        </form>
      </div>
      <?php else: ?>
      <div class="hd-input-bar justify-content-center text-muted small">
        <i class="fas fa-lock me-2"></i>This ticket is resolved.
        <form method="POST" class="d-inline ms-2">
          <input type="hidden" name="action" value="reopen">
          <input type="hidden" name="conv_id" value="<?= $convId ?>">
          <button class="btn btn-sm btn-link p-0">Reopen</button>
        </form>
      </div>
      <?php endif; ?>

      <?php else: ?>
      <div class="hd-empty">
        <i class="fas fa-comments"></i>
        <div style="font-weight:600">Select a ticket to view the conversation</div>
        <div style="font-size:.78rem">or use the Users panel to contact someone directly</div>
      </div>
      <?php endif; ?>
    </div>

    <!-- RIGHT: Users panel -->
    <div class="hd-users">
      <div class="hd-panel-head"><i class="fas fa-users me-2"></i>Contact User</div>
      <div class="hd-user-search">
        <form method="GET" action="helpdesk.php">
          <?php if ($convId): ?><input type="hidden" name="conv" value="<?= $convId ?>"><?php endif; ?>
          <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
          <input type="text" name="usearch" placeholder="Search users…"
                 value="<?= htmlspecialchars($userSearch) ?>" id="userSearchInput">
          <button type="submit" style="background:var(--hd-accent);color:#fff;border:none;border-radius:6px;padding:.3rem .6rem;cursor:pointer;font-size:.78rem">
            <i class="fas fa-search"></i>
          </button>
        </form>
      </div>
      <div class="hd-user-list">
        <?php foreach ($userList as $u): ?>
        <div class="hd-user-item">
          <img src="<?= htmlspecialchars($u['picture'] ?: '../Src/default.jpg') ?>"
               onerror="this.src='../Src/default.jpg'" alt="">
          <div>
            <div class="uname"><?= htmlspecialchars($u['username']) ?></div>
            <div class="urole">
              <?php
                $roles = [];
                if (!empty($u['is_admin'])) $roles[] = 'Admin';
                if (!empty($u['is_helpdesk_agent'])) $roles[] = 'Agent';
                if (!empty($u['is_driver'])) $roles[] = 'Driver';
                if (empty($roles)) $roles[] = 'User';
                echo implode(' · ', $roles);
              ?>
            </div>
          </div>
          <button class="btn btn-sm btn-outline-primary contact-btn"
                  style="font-size:.65rem;padding:.15rem .5rem;"
                  onclick="openContactModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>')">
            <i class="fas fa-comment me-1"></i>Contact
          </button>
        </div>
        <?php endforeach; ?>
        <?php if (empty($userList)): ?>
        <div class="p-3 text-center text-muted small">No users found.</div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /.hd-body -->
</div><!-- /.hd-shell -->

<!-- Contact User Modal -->
<div class="hd-modal-backdrop" id="contactModal">
  <div class="hd-modal">
    <h5><i class="fas fa-comment-dots me-2 text-primary"></i>Open Conversation with <span id="modalUsername"></span></h5>
    <form method="POST" action="helpdesk.php">
      <input type="hidden" name="action" value="start_with_user">
      <input type="hidden" name="target_user" id="modalUserId">
      <input type="text" name="subject" class="form-control" placeholder="Subject" required>
      <textarea name="message" class="form-control" rows="4" placeholder="Type your message…" required></textarea>
      <div class="hd-modal-footer">
        <button type="button" class="btn btn-sm btn-secondary" onclick="closeContactModal()">Cancel</button>
        <button type="submit" class="btn btn-sm btn-primary">Send & Open Conversation</button>
      </div>
    </form>
  </div>
</div>

<script>
// Auto-scroll chat
const cm = document.getElementById('chatMessages');
if (cm) cm.scrollTop = cm.scrollHeight;

// Enter to send
const ci = document.getElementById('chatInput');
if (ci) ci.addEventListener('keydown', e => {
    if (e.key==='Enter' && !e.shiftKey) { e.preventDefault(); ci.closest('form').requestSubmit(); }
});

// Contact modal
function openContactModal(userId, username) {
    document.getElementById('modalUserId').value = userId;
    document.getElementById('modalUsername').textContent = username;
    document.getElementById('contactModal').classList.add('show');
}
function closeContactModal() {
    document.getElementById('contactModal').classList.remove('show');
}
document.getElementById('contactModal').addEventListener('click', function(e) {
    if (e.target === this) closeContactModal();
});

// Live user search as-you-type (debounced)
const usi = document.getElementById('userSearchInput');
if (usi) {
    let t;
    usi.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(() => usi.closest('form').requestSubmit(), 400);
    });
}
</script>
</body>
</html>
<?php
// ────────────────────────────────────────────────────────────────────────────
// RENDER: regular user-facing helpdesk
// ────────────────────────────────────────────────────────────────────────────
else:
$pageTitle = 'HelpDesk & Support';
include '../include/header.php';
include '../include/sidebar.php';
?>
<div class="fd-main">
  <div class="page-header">
    <div>
      <h1><i class="fas fa-headset me-2 text-primary"></i>HelpDesk &amp; Support</h1>
      <p class="text-muted mb-0 small">Ask a question or open a support ticket.</p>
    </div>
    <?php if (empty($convs) || !$convId): ?>
    <button class="btn-fd-primary btn" data-bs-toggle="modal" data-bs-target="#newTicketModal">
      <i class="fas fa-plus me-1"></i>New Ticket
    </button>
    <?php endif; ?>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="row g-3" style="min-height:500px">
    <!-- Left: ticket list / quick help -->
    <div class="col-12 col-md-4">
      <?php if (empty($convs)): ?>
      <!-- FAQ quick help for first-timers -->
      <div class="fd-card mb-3">
        <div class="card-title"><i class="fas fa-robot text-primary"></i> HelpBot — Quick Answers</div>
        <p class="small text-muted mb-3">Tap a question to get an instant answer, or open a ticket below.</p>
        <?php foreach ([
          'How do I book a ride?',
          'How do I add balance?',
          'How do I become a driver?',
          'How do I get a student discount?',
          'How do I file a complaint?',
        ] as $faq): ?>
        <button class="btn btn-outline-secondary btn-sm w-100 text-start mb-1 faq-btn"
                data-q="<?= htmlspecialchars($faq) ?>">
          <i class="fas fa-question-circle me-2 text-primary"></i><?= $faq ?>
        </button>
        <?php endforeach; ?>
        <div id="faqAnswer" class="alert-fd-info mt-3 small" style="display:none"></div>
      </div>
      <?php else: ?>
      <div class="fd-card p-0 overflow-hidden">
        <div class="p-3 border-bottom fw-semibold small d-flex justify-content-between align-items-center">
          <span><i class="fas fa-ticket-alt me-2 text-primary"></i>My Tickets</span>
          <span class="badge bg-primary"><?= count($convs) ?></span>
        </div>
        <?php foreach ($convs as $c): ?>
        <a href="helpdesk.php?conv=<?= $c['id'] ?>"
           class="d-block p-3 border-bottom text-decoration-none <?= $convId==$c['id']?'bg-light':'' ?>">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="fw-semibold small text-dark text-truncate" style="max-width:60%">
              <?= htmlspecialchars(mb_substr($c['subject']??'Support request',0,35)) ?>
            </div>
            <span class="status-badge <?= in_array($c['status'],['open','assigned'])?'pending':'confirmed' ?>" style="font-size:.62rem;flex-shrink:0">
              <?= ucfirst($c['status']) ?>
            </span>
          </div>
          <div class="text-muted" style="font-size:.7rem"><?= date('d M Y, H:i', strtotime($c['updated_at']??'now')) ?></div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Always show new ticket shortcut -->
      <?php if (!empty($convs)): ?>
      <div class="mt-2 text-center">
        <button class="btn-fd-outline btn btn-sm w-100" data-bs-toggle="modal" data-bs-target="#newTicketModal">
          <i class="fas fa-plus me-1"></i>Open New Ticket
        </button>
      </div>
      <?php endif; ?>
    </div>

    <!-- Right: chat -->
    <div class="col-12 col-md-8 d-flex flex-column">
      <?php if ($activeConv): ?>
      <!-- Chat header -->
      <div class="fd-card p-3 d-flex justify-content-between align-items-center flex-wrap gap-2"
           style="border-radius:12px 12px 0 0;border-bottom:1px solid #e5e7eb;margin-bottom:0">
        <div>
          <div class="fw-bold small"><?= htmlspecialchars($activeConv['subject']??'Support request') ?></div>
          <div class="text-muted" style="font-size:.72rem">
            <span class="status-badge <?= in_array($activeConv['status'],['open','assigned'])?'pending':'confirmed' ?>">
              <?= ucfirst($activeConv['status']) ?>
            </span>
            &nbsp;Ticket #<?= $activeConv['id'] ?>
            <?php if ($activeConv['agent_name']): ?>
              &nbsp;·&nbsp;<i class="fas fa-user-tie me-1"></i>Agent: <?= htmlspecialchars($activeConv['agent_name']) ?>
            <?php elseif ($activeConv['status']==='open'): ?>
              &nbsp;·&nbsp;<span class="text-warning small"><i class="fas fa-clock me-1"></i>Awaiting agent</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Messages -->
      <div id="chatWrap"
           style="background:#fff;border:1px solid #e5e7eb;border-top:none;max-height:420px;overflow-y:auto;padding:.75rem;display:flex;flex-direction:column;gap:.6rem">
        <?php foreach ($messages as $m):
          $isMe  = $m['sender_id'] == $uid;
          $isBot = $m['sender_type'] === 'bot';
          $cls   = $isMe ? 'me' : ($isBot ? '' : 'them');
        ?>
        <div class="bubble <?= $cls ?>"
             style="<?= !$isMe&&!$isBot ? 'background:#eff6ff;align-self:flex-start;border-bottom-left-radius:3px' : ($isBot ? 'background:#f3f4f6;align-self:flex-start;border-bottom-left-radius:3px;color:#374151' : '') ?>">
          <?php if ($isBot): ?>
          <div style="font-size:.65rem;font-weight:600;margin-bottom:.2rem;color:#6b7280"><i class="fas fa-robot me-1"></i>HelpBot</div>
          <?php elseif (!$isMe): ?>
          <div style="font-size:.65rem;font-weight:600;margin-bottom:.2rem;color:#1d4ed8"><i class="fas fa-user-tie me-1"></i><?= htmlspecialchars($m['sender_name']??'Agent') ?></div>
          <?php endif; ?>
          <div style="font-size:.82rem;line-height:1.45"><?= nl2br(htmlspecialchars($m['body'])) ?></div>
          <div style="font-size:.62rem;margin-top:.3rem;opacity:.55;<?= $isMe?'text-align:right':'' ?>"><?= date('H:i', strtotime($m['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Input -->
      <?php if (!in_array($activeConv['status'], ['resolved','closed'])): ?>
      <div class="fd-card p-3 d-flex flex-column gap-2"
           style="border-radius:0 0 12px 12px;border-top:1px solid #e5e7eb;margin-top:0">
        <form method="POST" class="d-flex gap-2">
          <input type="hidden" name="action" value="send">
          <input type="hidden" name="conv_id" value="<?= $convId ?>">
          <textarea name="body" class="form-control form-control-sm" rows="2" id="chatInput"
                    placeholder="Type your message… (Enter to send)" required></textarea>
          <div class="d-flex flex-column gap-1">
            <button type="submit" class="btn-fd-primary btn btn-sm h-100">
              <i class="fas fa-paper-plane"></i>
            </button>
          </div>
        </form>
        <?php if (empty($activeConv['agent_id'])): ?>
        <form method="POST" class="d-flex justify-content-end">
          <input type="hidden" name="action" value="request_agent">
          <input type="hidden" name="conv_id" value="<?= $convId ?>">
          <button type="submit" class="btn btn-sm btn-outline-secondary" style="font-size:.72rem">
            <i class="fas fa-user-headset me-1"></i>Talk to a human agent
          </button>
        </form>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="p-3 text-center text-muted small bg-white"
           style="border-radius:0 0 12px 12px;border:1px solid #e5e7eb;border-top:none">
        <i class="fas fa-lock me-1"></i>This ticket is resolved.
        <a href="#" class="ms-2" data-bs-toggle="modal" data-bs-target="#newTicketModal">Open a new ticket</a>
      </div>
      <?php endif; ?>

      <?php else: ?>
      <div class="fd-card h-100 d-flex align-items-center justify-content-center text-center p-4">
        <div>
          <i class="fas fa-headset text-primary" style="font-size:3rem;opacity:.35"></i>
          <p class="fw-semibold mt-3 text-muted">
            <?= empty($convs) ? 'Start a ticket to chat with our HelpBot or a support agent.' : 'Select a ticket or open a new one.' ?>
          </p>
          <button class="btn-fd-primary btn mt-2" data-bs-toggle="modal" data-bs-target="#newTicketModal">
            <i class="fas fa-plus me-1"></i>New Ticket
          </button>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- New Ticket Modal -->
<div class="modal fade" id="newTicketModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-headset me-2 text-primary"></i>Open Support Ticket</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="action" value="new_ticket">
          <div class="mb-3">
            <label class="fd-form-label">Subject *</label>
            <input name="subject" class="form-control" placeholder="Brief description of your issue" required>
          </div>
          <div class="mb-3">
            <label class="fd-form-label">Your message *</label>
            <textarea name="message" class="form-control" rows="4"
                      placeholder="Describe your problem in detail…" required></textarea>
          </div>
          <div class="alert-fd-info small">
            <i class="fas fa-robot me-1"></i>HelpBot will respond instantly. Click "Talk to a human agent" inside the chat if you need a person.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-fd-primary btn">Start Conversation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-scroll
const cw = document.getElementById('chatWrap');
if (cw) cw.scrollTop = cw.scrollHeight;

// Enter to send
const ci = document.getElementById('chatInput');
if (ci) ci.addEventListener('keydown', e => {
    if (e.key==='Enter' && !e.shiftKey) { e.preventDefault(); ci.closest('form').requestSubmit(); }
});

// FAQ quick answers
const faqs = {
    'How do I book a ride?': 'Go to <strong>Search Rides</strong> in the sidebar, filter by date/location and click <strong>Book Now</strong>. You need enough balance in your wallet.',
    'How do I add balance?': 'Go to <strong>Payments</strong> in the sidebar and use the top-up form. Quick amounts (10, 20, 50, 100 TND) are available.',
    'How do I become a driver?': 'You must register as a driver during signup. Then add your vehicle under <strong>My Vehicles</strong> to activate driver mode.',
    'How do I get a student discount?': 'Submit your institutional email (.edu.tn / .rnu.tn) under <strong>Settings → Student Verification</strong>. An admin will approve it within 1–2 business days.',
    'How do I file a complaint?': 'Go to <strong>Complaints</strong> in the sidebar, describe the issue and submit. Our team reviews all complaints within 24–48 hours.',
};
document.querySelectorAll('.faq-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const ans = document.getElementById('faqAnswer');
        ans.innerHTML = '<strong>' + btn.dataset.q + '</strong><br>' + (faqs[btn.dataset.q] || 'Open a ticket for more help.');
        ans.style.display = 'block';
    });
});
</script>
</body>
</html>
<?php endif; ?>
