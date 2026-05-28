<?php
require_once '../server/session.php';
require_once '../server/language.php';
require_once '../classes/complaints.php';
require_once '../classes/notifications.php';
require_once '../classes/sanctions.php';
require_once '../classes/audit_log.php';
require_once '../classes/announcements.php';
require_once '../classes/promo_codes.php';
if (!isLoggedIn() || empty($_SESSION['user_data']['is_admin'])) {
    header('Location: interface.php'); exit();
}

$db    = getDB();
$uid   = $_SESSION['user_id'];
$notif = new Notifications($db);
$comp  = new Complaints($db);
$sanct = new Sanctions($db, $notif);
$audit    = new AuditLog($db);
$announce = new Announcement($db);
$promo    = new PromoCodes($db);

$msg = ''; $msgType = '';
$tab = $_GET['tab'] ?? 'overview';

// ── Legacy-link redirects (keep old URLs working after the sidebar refactor) ──
if ($tab === 'student_queue') {
    header('Location: admin.php?tab=verifications&type=students'); exit();
}
if ($tab === 'vehicle_queue') {
    header('Location: admin.php?tab=verifications&type=drivers'); exit();
}
if ($tab === 'student_domains') {
    header('Location: admin.php?tab=verifications&type=domains'); exit();
}
// Vehicles no longer have a standalone destination — they're viewed through
// their owner's user record. Old links land on the driver list.
if ($tab === 'vehicles') {
    header('Location: admin.php?tab=users&role=driver'); exit();
}

// Public-ID lookup (identification spec) — search bar in the Users tab posts
// here. Resolve the ID to an internal user_id and redirect to their record.
if ($tab === 'user_lookup') {
    require_once '../classes/publicid.php';
    $pid = trim($_GET['public_id'] ?? '');
    if ($pid === '') {
        header('Location: admin.php?tab=users'); exit();
    }
    $target = PublicIdService::findUser($db, $pid);
    if ($target) {
        header('Location: admin.php?tab=user&id=' . (int)$target['id']); exit();
    }
    header('Location: admin.php?tab=users&lookup=missing'); exit();
}

// ── Sub-filters used by the refactored tabs ──────────────────────────────────
$validRoles       = ['all', 'driver', 'student', 'agent', 'admin'];
$roleParam        = $_GET['role'] ?? 'all';
$role             = in_array($roleParam, $validRoles, true) ? $roleParam : 'all';

$validVerifyTypes = ['students', 'drivers', 'organizations', 'domains'];
$typeParam        = $_GET['type'] ?? 'students';
$verifyType       = in_array($typeParam, $validVerifyTypes, true) ? $typeParam : 'students';

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action']    ?? '';
    $targetId = (int)($_POST['target_id'] ?? 0);

    switch ($action) {
        // ── Progressive sanctions (§2.4): Warning → Suspension → Ban ──────────
        case 'warn_user':
            $sanct->warn($targetId, $uid, trim($_POST['reason'] ?? ''));
            $msg = 'Warning issued.'; $msgType = 'success'; $tab = 'users'; break;

        case 'suspend_user':
            $days    = (int)($_POST['days'] ?? Sanctions::MIN_SUSPENSION_DAYS);
            $applied = $sanct->suspend($targetId, $uid, $days, trim($_POST['reason'] ?? ''));
            $msg = "User suspended for {$applied} days."; $msgType = 'success'; $tab = 'users'; break;

        case 'ban_user':
            $sanct->ban($targetId, $uid, trim($_POST['reason'] ?? $_POST['ban_reason'] ?? ''));
            $msg = 'User permanently banned.'; $msgType = 'success'; $tab = 'users'; break;

        case 'unban_user':   // legacy alias kept for old links/forms
        case 'lift_user':
            $sanct->lift($targetId, $uid);
            $msg = 'Sanction lifted — user reinstated.'; $msgType = 'success'; $tab = 'users'; break;

        case 'make_driver':
            $db->prepare("UPDATE users SET is_driver=1 WHERE id=?")->execute([$targetId]);
            $db->prepare("INSERT OR IGNORE INTO driver_profiles (user_id) VALUES (?)")->execute([$targetId]);
            $msg = 'User promoted to driver.'; $msgType = 'success'; $tab = 'users'; break;

        case 'make_agent':
            $db->prepare("UPDATE users SET is_helpdesk_agent=1 WHERE id=?")->execute([$targetId]);
            $notif->create($targetId, 'HelpDesk Agent Role', 'You have been assigned as a HelpDesk support agent.', 'success');
            // Queue drain: newly promoted agents are available by definition
            // (zero active tickets). If anything is queued, hand them the
            // oldest one. assignNextWaitingTicketToAgent uses the same guarded
            // gates, so this is the same "agent newly available" code path
            // as close/resolve and manual-reassign-frees-previous-holder.
            require_once '../classes/helpdesk_assignment.php';
            $picked = HelpdeskAssignmentService::assignNextWaitingTicketToAgent($db, $targetId);
            if ($picked) {
                $notif->create($targetId, 'HelpDesk Ticket Auto-Assigned',
                    'A waiting ticket has been auto-assigned to you.', 'info',
                    '/helpdesk?conv=' . (int)$picked['id']);
                $msg = 'User set as HelpDesk agent. A waiting ticket was assigned to them.';
            } else {
                $msg = 'User set as HelpDesk agent.';
            }
            $msgType = 'success'; $tab = 'users'; break;

        case 'remove_agent':
            $db->prepare("UPDATE users SET is_helpdesk_agent=0 WHERE id=?")->execute([$targetId]);
            $msg = 'Agent role removed.'; $msgType = 'success'; $tab = 'users'; break;

        case 'approve_verification':
            $db->prepare("UPDATE student_verifications SET status='approved',updated_at=datetime('now') WHERE id=?")->execute([$targetId]);
            $sv = $db->prepare("SELECT user_id FROM student_verifications WHERE id=?"); $sv->execute([$targetId]);
            if ($userId = $sv->fetchColumn()) {
                $db->prepare("UPDATE users SET is_student_verified=1, is_student=1 WHERE id=?")->execute([$userId]);
                $notif->create($userId, 'Student Verified ✅', 'Your student status is verified! You now receive 50% discounts.', 'success');
            }
            $msg = 'Verification approved.'; $msgType = 'success'; $tab = 'verifications'; break;

        case 'reject_verification':
            $note = trim($_POST['admin_note'] ?? '');
            $db->prepare("UPDATE student_verifications SET status='rejected',note=?,updated_at=datetime('now') WHERE id=?")->execute([$note, $targetId]);
            $sv = $db->prepare("SELECT user_id FROM student_verifications WHERE id=?"); $sv->execute([$targetId]);
            if ($userId = $sv->fetchColumn()) {
                $notif->create($userId, 'Verification Rejected', "Your student verification was rejected. $note", 'danger');
            }
            $msg = 'Verification rejected.'; $msgType = 'danger'; $tab = 'verifications'; break;

        case 'resolve_complaint':
            $comp->updateStatus($targetId, 'resolved', trim($_POST['admin_note'] ?? ''));
            $msg = 'Complaint resolved.'; $msgType = 'success'; $tab = 'complaints'; break;

        case 'dismiss_complaint':
            $comp->updateStatus($targetId, 'dismissed', trim($_POST['admin_note'] ?? ''));
            $msg = 'Complaint dismissed.'; $msgType = 'success'; $tab = 'complaints'; break;

        case 'review_complaint':
            $comp->updateStatus($targetId, 'in_review', trim($_POST['admin_note'] ?? ''));
            $msg = 'Complaint marked In Review.'; $msgType = 'success'; $tab = 'complaints'; break;

        case 'approve_org':
            $code = strtoupper(substr(md5(uniqid()), 0, 8));
            $db->prepare("UPDATE organizations SET status='active', discount_code=? WHERE id=?")->execute([$code, $targetId]);
            $msg = "Organization approved. Discount code: <strong>$code</strong>"; $msgType = 'success'; $tab = 'organizations'; break;

        case 'reject_org':
            $db->prepare("UPDATE organizations SET status='suspended' WHERE id=?")->execute([$targetId]);
            $msg = 'Organization rejected.'; $msgType = 'danger'; $tab = 'organizations'; break;

        case 'assign_helpdesk':
            // Manual override — admin explicitly chose the agent (may be busy).
            // If this reassign freed the previous holder, the service advances
            // the queue for them via the same guarded gates.
            $convId = (int)($_POST['conv_id'] ?? 0);
            require_once '../classes/helpdesk_assignment.php';
            $res = HelpdeskAssignmentService::manualAssign($db, $convId, $targetId);
            if ($res['result'] === 'assigned') {
                $notif->create($targetId, 'HelpDesk Ticket Assigned',
                    'A support ticket has been manually assigned to you.', 'info',
                    '/helpdesk?conv=' . $convId);
                // Notify the previous holder if a queued ticket was just routed to them.
                if (!empty($res['queued_for_prev']) && !empty($res['previous_agent_id'])) {
                    $notif->create((int)$res['previous_agent_id'], 'HelpDesk Ticket Auto-Assigned',
                        'A waiting ticket has been auto-assigned to you.', 'info',
                        '/helpdesk?conv=' . (int)$res['queued_for_prev']['id']);
                }
                $msg = $res['was_busy']
                    ? 'Ticket manually assigned. Warning: that agent already had an active ticket.'
                    : 'Ticket manually assigned.';
                if (!empty($res['queued_for_prev'])) {
                    $msg .= ' Previous holder picked up the next queued ticket.';
                }
                $msgType = $res['was_busy'] ? 'warning' : 'success';
            } else {
                $msg = 'Selected user is not a HelpDesk agent.'; $msgType = 'danger';
            }
            $tab = 'helpdesk'; break;

        case 'auto_assign_helpdesk':
            // Fallback retry for the rare ticket that stayed open because no
            // agent was available at creation time.
            $convId = (int)($_POST['conv_id'] ?? 0);
            require_once '../classes/helpdesk_assignment.php';
            $res = HelpdeskAssignmentService::autoAssign($db, $convId);
            switch ($res['result']) {
                case 'assigned':
                    $notif->create($res['agent']['id'], 'HelpDesk Ticket Auto-Assigned',
                        'A waiting ticket has been auto-assigned to you.', 'info',
                        '/helpdesk?conv=' . $convId);
                    $msg = 'Ticket auto-assigned to ' . htmlspecialchars($res['agent']['username']) . '.';
                    $msgType = 'success';
                    break;
                case 'no_eligible':
                    $msg = 'No HelpDesk agents exist. Promote a user from the Users tab first.';
                    $msgType = 'danger';
                    break;
                case 'waiting':
                    $msg = 'All agents are currently busy. Ticket is waiting in queue.';
                    $msgType = 'warning';
                    break;
                case 'already_assigned':
                    $msg = 'Ticket is already assigned.'; $msgType = 'info'; break;
                default:
                    $msg = 'Ticket not found.'; $msgType = 'danger';
            }
            $tab = 'helpdesk'; break;

        // Student verification (new flow: users.student_status)
        case 'add_student_domain':
            $domain = strtolower(trim($_POST['domain'] ?? ''));
            $label  = trim($_POST['label'] ?? $domain);
            if ($domain) {
                try {
                    $db->prepare("INSERT INTO student_domains (domain, label) VALUES (?,?)")->execute([$domain, $label]);
                    $msg = "Domain '$domain' added."; $msgType = 'success';
                } catch (PDOException $e) {
                    $msg = "Domain already exists."; $msgType = 'danger';
                }
            }
            $tab = 'student_domains'; break;

        case 'remove_student_domain':
            $db->prepare("DELETE FROM student_domains WHERE id=?")->execute([(int)($_POST['target_id'] ?? 0)]);
            // Auto-update all users whose email now matches no domain
            $db->exec("UPDATE users SET is_student=0, is_student_verified=0 WHERE is_student=1 AND NOT EXISTS (
                SELECT 1 FROM student_domains WHERE LOWER(SUBSTR(users.email, INSTR(users.email,'@')+1)) = student_domains.domain
            )");
            $msg = 'Domain removed.'; $msgType = 'success'; $tab = 'student_domains'; break;

        case 'approve_student':
            $db->prepare("UPDATE users SET student_status='approved', is_student=1, student_verified_at=datetime('now') WHERE id=?")->execute([$targetId]);
            $notif->create($targetId, 'Student Discount Activated ✅', 'Your student verification was approved. You now receive a 50% discount on all rides!', 'success');
            $msg = 'Student approved.'; $msgType = 'success'; $tab = 'student_queue'; break;

        case 'reject_student':
            $note = trim($_POST['admin_note'] ?? 'Invalid institutional email.');
            $db->prepare("UPDATE users SET student_status='rejected', is_student=0 WHERE id=?")->execute([$targetId]);
            $notif->create($targetId, 'Student Verification Rejected', "Your student verification was rejected. Reason: $note. You may resubmit with a valid .edu.tn or .rnu.tn email.", 'danger');
            $msg = 'Student rejected.'; $msgType = 'danger'; $tab = 'student_queue'; break;

        // Driver review — one decision covering the driver's licence + vehicle.
        // (A vehicle submission bundles permis de conduire, carte grise, etc.)
        case 'approve_vehicle':
            $db->prepare("UPDATE vehicles SET verified=1, verification_status='approved', rejection_note=NULL, verified_at=datetime('now') WHERE id=?")->execute([$targetId]);
            $vRow = $db->prepare("SELECT user_id FROM vehicles WHERE id=?"); $vRow->execute([$targetId]);
            if ($vOwner = $vRow->fetchColumn()) {
                $notif->create($vOwner, 'Driver Verified ✅', 'Your licence and vehicle have been verified. You can now offer rides!', 'success');
            }
            $msg = 'Driver review approved.'; $msgType = 'success'; $tab = 'verifications'; $redirectType = 'drivers'; break;

        case 'reject_vehicle':
            $note = trim($_POST['admin_note'] ?? 'Documents unclear.');
            $db->prepare("UPDATE vehicles SET verified=0, verification_status='rejected', rejection_note=? WHERE id=?")->execute([$note, $targetId]);
            $vRow = $db->prepare("SELECT user_id FROM vehicles WHERE id=?"); $vRow->execute([$targetId]);
            if ($vOwner = $vRow->fetchColumn()) {
                $notif->create($vOwner, 'Driver Verification Failed', "Your driver review was rejected. Reason: $note. Please re-upload clear photos of your licence and carte grise.", 'danger');
            }
            $msg = 'Driver review rejected.'; $msgType = 'danger'; $tab = 'verifications'; $redirectType = 'drivers'; break;

        // ── Broadcast announcements (§2.4) ────────────────────────────────────
        case 'broadcast_announcement':
            $aTitle    = trim($_POST['title'] ?? '');
            $aBody     = trim($_POST['body'] ?? '');
            $aAudience = $_POST['audience'] ?? 'all';
            if ($aTitle === '' || $aBody === '') {
                $msg = 'Title and message are required.'; $msgType = 'danger';
            } else {
                $sent = $announce->broadcast($uid, $aTitle, $aBody, $aAudience);
                $msg  = "Announcement sent to {$sent} " . strtolower(Announcement::audienceLabel($aAudience)) . '.';
                $msgType = 'success';
            }
            $tab = 'announcements'; break;

        // ── Promo codes (§2.4) ────────────────────────────────────────────────
        case 'create_promo':
            [$pOk, $pMsg] = $promo->create(
                $uid,
                $_POST['code'] ?? '',
                (float)($_POST['amount'] ?? 0),
                (int)($_POST['max_uses'] ?? 0),
                trim($_POST['expires_at'] ?? '') ?: null
            );
            $msg = $pMsg; $msgType = $pOk ? 'success' : 'danger'; $tab = 'promos'; break;

        case 'toggle_promo':
            $promo->setActive($targetId, !empty($_POST['activate']));
            $msg = !empty($_POST['activate']) ? 'Promo code activated.' : 'Promo code deactivated.';
            $msgType = 'success'; $tab = 'promos'; break;
    }

    // Audit trail (§2.4): record every admin mutation with its outcome.
    if ($action !== '') {
        $audit->log($uid, $action, null, $targetId ?: null, trim(strip_tags($msg)) ?: null);
    }

    // Carry the verifications sub-tab so the reviewer lands back where they acted
    // (and the confirmation message survives, unlike the legacy-redirect hop).
    $typeQS = ($tab === 'verifications' && isset($redirectType)) ? "&type=$redirectType" : '';
    header("Location: admin.php?tab=$tab$typeQS&msg=" . urlencode($msg) . "&mt=$msgType"); exit();
}

if (isset($_GET['msg'])) { $msg = $_GET['msg']; $msgType = $_GET['mt'] ?? 'info'; }

// ── Data ──────────────────────────────────────────────────────────────────────
// A "driver review" is a vehicle submission still awaiting a decision: status
// pending AND the driver actually uploaded at least one document to review.
$driverReviewWhere = "v.verification_status='pending' AND ("
    . "(v.carte_grise IS NOT NULL AND v.carte_grise != '') OR "
    . "(v.permis_conduire IS NOT NULL AND v.permis_conduire != '') OR "
    . "(v.id_card_photo IS NOT NULL AND v.id_card_photo != ''))";

$stats = [
    'users'           => $db->query("SELECT COUNT(*) FROM users WHERE is_admin=0")->fetchColumn(),
    'drivers'         => $db->query("SELECT COUNT(*) FROM users WHERE is_driver=1 AND is_admin=0")->fetchColumn(),
    'rides'           => $db->query("SELECT COUNT(*) FROM rides")->fetchColumn(),
    'bookings'        => $db->query("SELECT COUNT(*) FROM bookings")->fetchColumn(),
    'complaints'      => $db->query("SELECT COUNT(*) FROM complaints WHERE status='open'")->fetchColumn(),
    'pending_ver'     => $db->query("SELECT COUNT(*) FROM student_verifications WHERE status='pending'")->fetchColumn(),
    'pending_student' => $db->query("SELECT COUNT(*) FROM users WHERE student_status='pending'")->fetchColumn(),
    'student_domains' => $db->query("SELECT COUNT(*) FROM student_domains")->fetchColumn(),
    'pending_vehicle' => $db->query("SELECT COUNT(*) FROM vehicles v WHERE $driverReviewWhere")->fetchColumn(),
    'pending_org'     => $db->query("SELECT COUNT(*) FROM organizations WHERE status='pending'")->fetchColumn(),
    'orgs'            => $db->query("SELECT COUNT(*) FROM organizations WHERE status='pending'")->fetchColumn(),
    'vehicles_total'  => $db->query("SELECT COUNT(*) FROM vehicles")->fetchColumn(),
    'revenue'         => $db->query("SELECT COALESCE(SUM(paid_amount),0) FROM bookings WHERE status='completed'")->fetchColumn(),
];
// Combined badge for the new Verifications entry in the sidebar
$stats['pending_total'] = (int)$stats['pending_ver']
                       + (int)$stats['pending_student']
                       + (int)$stats['pending_vehicle']
                       + (int)$stats['pending_org'];

$vtypes         = $db->query("SELECT type, COUNT(*) cnt FROM vehicles GROUP BY type ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
$rstats         = $db->query("SELECT status, COUNT(*) cnt FROM rides GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);

// ── Users — role-filtered ────────────────────────────────────────────────────
$userWheres = [];
if ($role === 'driver')   $userWheres[] = "u.is_driver=1 AND u.is_admin=0";
elseif ($role === 'student') $userWheres[] = "u.is_student=1 AND u.is_admin=0";
elseif ($role === 'agent') $userWheres[] = "u.is_helpdesk_agent=1 AND u.is_admin=0";
elseif ($role === 'admin') $userWheres[] = "u.is_admin=1";
else                       $userWheres[] = "u.is_admin=0";  // 'all' excludes admins by default

$userSql = "SELECT u.*, dp.avg_rating, dp.total_trips
            FROM users u
            LEFT JOIN driver_profiles dp ON dp.user_id=u.id
            WHERE " . implode(' AND ', $userWheres) . "
            ORDER BY u.created_at DESC LIMIT 100";
$users   = $db->query($userSql)->fetchAll(PDO::FETCH_ASSOC);

// ── Sanction history for the listed users (one query, grouped) (§2.4) ────────
$sanctionsByUser = [];   // user_id => [rows newest-first]
$sanctionCounts  = [];   // user_id => [type => count]
$userIds = array_map(fn($u) => (int)$u['id'], $users);
if ($userIds) {
    $in   = implode(',', array_fill(0, count($userIds), '?'));
    $sRows = $db->prepare("SELECT s.*, a.username AS admin_name
                           FROM user_sanctions s
                           LEFT JOIN users a ON a.id = s.admin_id
                           WHERE s.user_id IN ($in)
                           ORDER BY s.created_at DESC");
    $sRows->execute($userIds);
    foreach ($sRows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sanctionsByUser[$r['user_id']][] = $r;
        $sanctionCounts[$r['user_id']][$r['type']] = ($sanctionCounts[$r['user_id']][$r['type']] ?? 0) + 1;
    }
}

// Per-role counters for the filter chips
$roleCounts = [
    'all'     => (int)$db->query("SELECT COUNT(*) FROM users WHERE is_admin=0")->fetchColumn(),
    'driver'  => (int)$db->query("SELECT COUNT(*) FROM users WHERE is_driver=1 AND is_admin=0")->fetchColumn(),
    'student' => (int)$db->query("SELECT COUNT(*) FROM users WHERE is_student=1 AND is_admin=0")->fetchColumn(),
    'agent'   => (int)$db->query("SELECT COUNT(*) FROM users WHERE is_helpdesk_agent=1 AND is_admin=0")->fetchColumn(),
    'admin'   => (int)$db->query("SELECT COUNT(*) FROM users WHERE is_admin=1")->fetchColumn(),
];

// ── User detail page (?tab=user&id=X) ───────────────────────────────────────
// A vehicle is always viewed through its owner, so the detail page loads the
// user, their driver profile and the vehicle that carries the driver's docs.
$detailUser = $detailDriver = null;
$detailVehicles = [];
if ($tab === 'user') {
    $detailId = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT u.*, dp.avg_rating, dp.total_trips, dp.license_number
                          FROM users u
                          LEFT JOIN driver_profiles dp ON dp.user_id = u.id
                          WHERE u.id = ?");
    $stmt->execute([$detailId]);
    $detailUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($detailUser) {
        $detailDriver = ($detailUser['is_driver'] || $detailUser['license_number'] !== null
                         || $detailUser['avg_rating'] !== null);
        $vs = $db->prepare("SELECT * FROM vehicles WHERE user_id = ? ORDER BY created_at DESC");
        $vs->execute([$detailId]);
        $detailVehicles = $vs->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Uploaded docs live in web/Src/ (served via the /ForsaDrive/Src/ Apache alias).
// Stored values may be a bare filename or a path — normalise to ../Src/<basename>.
$docUrl = function (?string $v): ?string {
    if ($v === null || trim($v) === '') return null;
    $v = trim($v);
    if (preg_match('~^https?://~i', $v)) return $v;
    return '../Src/' . rawurlencode(basename(str_replace('\\', '/', $v)));
};
// Shared "driver review" status badge (one decision covers licence + vehicle).
$statusBadge = function (?string $s): string {
    return match ($s) {
        'approved' => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Approved</span>',
        'rejected' => '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Rejected</span>',
        default    => '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pending review</span>',
    };
};

// ── Pending organizations only (for Verifications > Organizations sub-tab) ───
$pendingOrgs = $db->query("SELECT * FROM organizations WHERE status='pending' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$verifications  = $db->query("SELECT sv.*, u.username, u.email, u.governorate FROM student_verifications sv JOIN users u ON u.id=sv.user_id WHERE sv.status='pending' ORDER BY sv.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
$pendingStudents = $db->query("SELECT id, username, email, governorate, created_at FROM users WHERE is_student=0 AND is_student_verified=0 AND email LIKE '%@%' ORDER BY created_at ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$studentDomains  = $db->query("SELECT * FROM student_domains ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);
$pendingDriverReviews = $db->query("SELECT v.*, u.id AS owner_id, u.username AS owner_name, u.email AS owner_email FROM vehicles v JOIN users u ON u.id=v.user_id WHERE $driverReviewWhere ORDER BY v.created_at ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$orgs           = $db->query("SELECT * FROM organizations ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$hdConvs        = $db->query("SELECT hc.*, u.username AS user_name, a.username AS agent_name, (SELECT COUNT(*) FROM helpdesk_messages hm WHERE hm.conv_id=hc.id AND hm.is_read=0 AND hm.sender_type='user') AS unread FROM helpdesk_conversations hc JOIN users u ON u.id=hc.user_id LEFT JOIN users a ON a.id=hc.agent_id ORDER BY hc.updated_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
// Eligible agents — single source of truth shared with HelpdeskAssignmentService
// so the dropdown and the auto-assign service can never disagree.
require_once '../classes/helpdesk_assignment.php';
$agents = HelpdeskAssignmentService::getEligibleAgents($db);
// Per-agent active-ticket count so the UI can warn when a manual override
// targets a busy agent, and the row labels can distinguish "no eligible" from
// "all busy".
$agentActiveCount = [];
foreach ($agents as $ag) {
    $agentActiveCount[(int)$ag['id']] = HelpdeskAssignmentService::getAgentActiveTicketCount($db, (int)$ag['id']);
}
$anyAgentAvailable = false;
foreach ($agentActiveCount as $cnt) {
    if ($cnt === 0) { $anyAgentAvailable = true; break; }
}
$recentActivity = $db->query("SELECT bk.*, r.from_location, r.to_location, p.username AS passenger, dr.username AS driver FROM bookings bk JOIN rides r ON r.id=bk.ride_id JOIN users p ON p.id=bk.passenger_id JOIN users dr ON dr.id=r.driver_id ORDER BY bk.created_at DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);

// ── Complaints (with optional status filter) ─────────────────────────────────
$complaintStatusFilter = $_GET['complaint_status'] ?? '';
$allComplaints = $comp->getAllComplaints($complaintStatusFilter);

// ── Admin tools data (§2.4) ──────────────────────────────────────────────────
$announcements = $announce->recent(50);
$promos        = $promo->all();
$auditLogs     = $audit->recent(150);

$pageTitle = 'Admin Console';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ForsaDrive — Admin Console</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="../Css/app.css" rel="stylesheet">
<style>
/* ── Admin Layout ── */
body { background:#f0f4f8; margin:0; }
.admin-layout { display:flex; min-height:100vh; }

/* Sidebar */
.admin-nav {
    width:240px; background:#0a1628; flex-shrink:0; display:flex;
    flex-direction:column; padding:0; position:fixed; top:0; left:0;
    height:100vh; overflow-y:auto; z-index:1050;
    transition:transform .25s ease;
}
.admin-nav .brand {
    padding:1.1rem 1.25rem .85rem;
    border-bottom:1px solid rgba(255,255,255,.08);
    background: linear-gradient(135deg,#0a1628,#1a2f50);
}
.admin-nav .brand h5 { color:#fff; font-weight:800; margin:0; font-size:1.1rem; letter-spacing:.03em; }
.admin-nav .brand h5 span { color:#f59e0b; }
.admin-nav .brand .badge-admin {
    display:inline-block; background:#ef4444; color:#fff;
    font-size:.55rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase;
    padding:.15rem .5rem; border-radius:20px; margin-top:.3rem;
}
.admin-nav .nav-section {
    padding:.6rem 1.25rem .15rem;
    font-size:.6rem; text-transform:uppercase; letter-spacing:.12em;
    color:rgba(255,255,255,.3); margin-top:.25rem;
}
.admin-nav a {
    display:flex; align-items:center; gap:.65rem;
    color:rgba(255,255,255,.65); padding:.65rem 1.25rem;
    font-size:.82rem; text-decoration:none;
    border-left:3px solid transparent; transition:all .15s;
}
.admin-nav a:hover { background:rgba(255,255,255,.07); color:#fff; }
.admin-nav a.active { background:rgba(245,158,11,.12); color:#f59e0b; border-left-color:#f59e0b; }
.admin-nav a i { width:17px; text-align:center; flex-shrink:0; }
.admin-nav .nav-badge {
    background:#ef4444; color:#fff; border-radius:20px;
    padding:.1rem .45rem; font-size:.6rem; font-weight:700; margin-left:auto;
}
.admin-nav .nav-footer { margin-top:auto; border-top:1px solid rgba(255,255,255,.08); }

/* Mobile topbar */
.admin-topbar {
    display:none; position:fixed; top:0; left:0; right:0; height:56px;
    background:#0a1628; z-index:1049; align-items:center;
    padding:0 1rem; gap:.75rem;
    border-bottom:2px solid #ef4444;
}
.admin-topbar .brand-m { color:#fff; font-weight:800; font-size:1rem; flex:1; }
.admin-topbar .brand-m span { color:#f59e0b; }
.admin-topbar .badge-admin-m {
    background:#ef4444; color:#fff; font-size:.6rem; font-weight:700;
    text-transform:uppercase; padding:.15rem .5rem; border-radius:20px; letter-spacing:.08em;
}

/* Overlay */
.admin-overlay {
    display:none; position:fixed; inset:0; background:rgba(0,0,0,.5);
    z-index:1048;
}
.admin-overlay.show { display:block; }

/* Content area */
.admin-content {
    flex:1; padding:1.75rem; overflow-x:hidden; min-width:0;
    margin-left:240px;
    /* Red accent stripe at top */
    border-top:3px solid #ef4444;
}

/* Admin identity banner */
.admin-identity-bar {
    background:linear-gradient(90deg,#0a1628,#1e3a5f);
    color:#fff; padding:.5rem 1rem;
    display:flex; align-items:center; gap:.75rem;
    border-radius:8px; margin-bottom:1.5rem;
    border-left:4px solid #ef4444;
}
.admin-identity-bar .badge-role {
    background:#ef4444; color:#fff; font-size:.65rem; font-weight:700;
    text-transform:uppercase; padding:.2rem .55rem; border-radius:4px; letter-spacing:.08em;
    flex-shrink:0;
}
.admin-identity-bar .info { font-size:.82rem; color:rgba(255,255,255,.75); }
.admin-identity-bar .info strong { color:#f59e0b; }

@media(max-width:767px){
    .admin-topbar { display:flex; }
    .admin-nav { transform:translateX(-100%); top:56px; height:calc(100vh - 56px); }
    .admin-nav.open { transform:translateX(0); }
    .admin-content { margin-left:0; padding:.85rem; padding-top:calc(56px + .85rem); border-top:none; }
}
</style>
</head>
<body>
<div class="admin-layout">

<!-- Mobile topbar -->
<div class="admin-topbar" id="adminTopbar">
  <button onclick="toggleAdminNav()" style="background:none;border:none;color:rgba(255,255,255,.8);padding:0;font-size:1.1rem;cursor:pointer;">
    <i class="fas fa-bars"></i>
  </button>
  <div class="brand-m">Forsa<span>Drive</span></div>
  <span class="badge-admin-m">Admin</span>
</div>
<div class="admin-overlay" id="adminOverlay" onclick="toggleAdminNav()"></div>

<!-- ── Navigation ────────────────────────────────────────────────────────── -->
<aside class="admin-nav" id="adminNav">
  <div class="brand">
    <h5>Forsa<span>Drive</span></h5>
    <div class="badge-admin"><i class="fas fa-shield-alt me-1"></i>Admin Console</div>
  </div>
  <div class="nav-section">Dashboard</div>
  <a href="?tab=overview"        class="<?= $tab==='overview'?'active':'' ?>"><i class="fas fa-tachometer-alt"></i> Overview</a>
  <a href="?tab=activity"        class="<?= $tab==='activity'?'active':'' ?>"><i class="fas fa-stream"></i> Activity</a>

  <div class="nav-section">Accounts</div>
  <a href="?tab=users"           class="<?= $tab==='users'?'active':'' ?>"><i class="fas fa-users"></i> Users
    <span class="nav-badge"><?= $stats['users'] ?></span></a>
  <a href="?tab=organizations"   class="<?= $tab==='organizations'?'active':'' ?>"><i class="fas fa-building"></i> Organizations
    <?php if ($stats['pending_org']): ?><span class="nav-badge"><?= $stats['pending_org'] ?></span><?php endif; ?></a>
  <a href="?tab=verifications"   class="<?= $tab==='verifications'?'active':'' ?>"><i class="fas fa-id-card"></i> Verifications
    <?php if ($stats['pending_total']): ?><span class="nav-badge"><?= $stats['pending_total'] ?></span><?php endif; ?></a>

  <div class="nav-section">Operations</div>
  <a href="?tab=complaints"      class="<?= $tab==='complaints'?'active':'' ?>"><i class="fas fa-flag"></i> Complaints
    <?php if ($stats['complaints']): ?><span class="nav-badge"><?= $stats['complaints'] ?></span><?php endif; ?></a>
  <a href="?tab=helpdesk"        class="<?= $tab==='helpdesk'?'active':'' ?>"><i class="fas fa-headset"></i> HelpDesk</a>

  <div class="nav-section">System</div>
  <a href="?tab=announcements"   class="<?= $tab==='announcements'?'active':'' ?>"><i class="fas fa-bullhorn"></i> Announcements</a>
  <a href="?tab=promos"          class="<?= $tab==='promos'?'active':'' ?>"><i class="fas fa-tags"></i> Promo Codes</a>
  <a href="?tab=audit"           class="<?= $tab==='audit'?'active':'' ?>"><i class="fas fa-clipboard-list"></i> Audit Log</a>
  <div class="nav-footer">
    <a href="interface.php"><i class="fas fa-home"></i> Main Site</a>
    <a href="../index.php" style="color:rgba(239,68,68,.7)"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </div>
</aside>

<!-- ── Main ──────────────────────────────────────────────────────────────── -->
<main class="admin-content">
  <!-- Admin identity bar -->
  <div class="admin-identity-bar">
    <span class="badge-role"><i class="fas fa-shield-alt me-1"></i>Admin</span>
    <div class="info">Logged in as <strong><?= htmlspecialchars($_SESSION['user_data']['username']) ?></strong> &nbsp;·&nbsp; Full administrative access</div>
    <a href="../index.php" class="ms-auto text-white-50 small text-decoration-none"><i class="fas fa-external-link-alt me-1"></i>Public site</a>
  </div>
  <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <h1 class="fw-bold mb-0" style="color:#0a2540;font-size:1.35rem">
      <?= match($tab) {
        'users'         => '<i class="fas fa-users me-2 text-primary"></i>User Management',
        'verifications' => '<i class="fas fa-id-card me-2 text-primary"></i>Student Verifications',
        'complaints'    => '<i class="fas fa-flag me-2 text-primary"></i>Complaints',
        'organizations' => '<i class="fas fa-building me-2 text-primary"></i>Organizations',
        'helpdesk'      => '<i class="fas fa-headset me-2 text-primary"></i>HelpDesk Console',
        'announcements' => '<i class="fas fa-bullhorn me-2 text-primary"></i>Broadcast Announcements',
        'promos'        => '<i class="fas fa-tags me-2 text-primary"></i>Promo Codes',
        'audit'         => '<i class="fas fa-clipboard-list me-2 text-primary"></i>Audit Log',
        'activity'      => '<i class="fas fa-stream me-2 text-primary"></i>Activity Feed',
        'student_queue'    => '<i class="fas fa-user-graduate me-2 text-warning"></i>Student Verification Queue',
        'student_domains'  => '<i class="fas fa-at me-2 text-success"></i>Student Email Domains',
        'user'          => '<i class="fas fa-user me-2 text-primary"></i>User Record',
        default         => '<i class="fas fa-tachometer-alt me-2 text-primary"></i>Overview',
      } ?>
    </h1>
    <span class="text-muted small d-none d-sm-inline"><i class="fas fa-clock me-1"></i><?= date('D, d M Y') ?></span>
  </div>

  <?php if ($msg): ?>
  <div class="alert-fd-<?= $msgType === 'success' ? 'success' : 'danger' ?> mb-3">
    <i class="fas fa-<?= $msgType==='success'?'check':'exclamation' ?>-circle me-2"></i><?= $msg ?>
  </div>
  <?php endif; ?>

  <?php /* ═══ OVERVIEW ════════════════════════════════════════════════════ */ if ($tab === 'overview'): ?>
  <div class="row g-3 mb-4">
    <?php foreach ([
      ['blue',  'users',            $stats['users'],                         'Total Users'],
      ['green', 'car',              $stats['rides'],                         'Rides Posted'],
      ['amber', 'ticket-alt',       $stats['bookings'],                      'All Bookings'],
      ['green', 'money-bill-wave',  number_format($stats['revenue'],2).' TND','Revenue'],
      ['blue',  'user-tie',         $stats['drivers'],                       'Drivers'],
      ['red',   'flag',             $stats['complaints'],                    'Open Complaints'],
      ['amber', 'user-graduate',    $stats['pending_student'],               'Pending Students'],
      ['red',   'car-side',         $stats['pending_vehicle'],               'Pending Vehicles'],
      ['purple','building',         count($orgs),                            'Organizations'],
    ] as [$c,$i,$v,$l]): ?>
    <div class="col-6 col-sm-4 col-lg-3">
      <div class="stat-card">
        <div class="icon <?= $c ?>"><i class="fas fa-<?= $i ?>"></i></div>
        <div><div class="val"><?= $v ?></div><div class="lbl"><?= $l ?></div></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3">
    <div class="col-12 col-md-6">
      <div class="fd-card">
        <div class="card-title"><i class="fas fa-car text-primary"></i> Fleet by Vehicle Type</div>
        <?php if (empty($vtypes)): ?>
          <p class="text-muted small">No vehicles yet</p>
        <?php else:
          $total_v = array_sum(array_column($vtypes,'cnt'));
          foreach ($vtypes as $vt):
            $pct = round($vt['cnt']/$total_v*100); ?>
          <div class="d-flex align-items-center gap-2 mb-2">
            <div style="width:90px;font-size:.82rem;color:#374151"><?= htmlspecialchars(ucfirst($vt['type'])) ?></div>
            <div class="flex-grow-1 rounded bg-light" style="height:8px">
              <div class="rounded bg-primary" style="width:<?= $pct ?>%;height:8px"></div>
            </div>
            <span class="text-muted small" style="width:24px"><?= $vt['cnt'] ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <div class="col-12 col-md-6">
      <div class="fd-card">
        <div class="card-title"><i class="fas fa-chart-pie text-primary"></i> Ride Status</div>
        <?php foreach ($rstats as $rs): ?>
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="status-badge <?= $rs['status'] ?>"><?= ucfirst($rs['status']) ?></span>
          <strong><?= $rs['cnt'] ?></strong>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php /* ═══ ACTIVITY ════════════════════════════════════════════════════ */ elseif ($tab === 'activity'): ?>
  <div class="fd-card">
    <div class="card-title"><i class="fas fa-stream text-primary"></i> Recent Bookings</div>
    <div class="table-responsive">
      <table class="table table-hover align-middle small mb-0">
        <thead class="table-light">
          <tr><th>#</th><th>Passenger</th><th>Driver</th><th>Route</th><th>Amount</th><th>Status</th><th>Date</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recentActivity as $b): ?>
          <tr>
            <td class="text-muted">#<?= $b['id'] ?></td>
            <td><?= htmlspecialchars($b['passenger']) ?></td>
            <td><?= htmlspecialchars($b['driver']) ?></td>
            <td><?= htmlspecialchars($b['from_location']) ?> → <?= htmlspecialchars($b['to_location']) ?></td>
            <td class="fw-bold"><?= number_format($b['paid_amount'],2) ?> TND</td>
            <td><span class="status-badge <?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span></td>
            <td class="text-muted"><?= date('d M, H:i', strtotime($b['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php /* ═══ USERS ═══════════════════════════════════════════════════════ */ elseif ($tab === 'users'): ?>
  <div class="fd-card">
    <!-- Role filter chips -->
    <div class="d-flex flex-wrap gap-2 mb-3">
      <?php
      $chips = [
        'all'     => ['All',     'fa-users'],
        'driver'  => ['Drivers', 'fa-car'],
        'student' => ['Students','fa-user-graduate'],
        'agent'   => ['Agents',  'fa-headset'],
        'admin'   => ['Admins',  'fa-shield-alt'],
      ];
      foreach ($chips as $key => [$label, $icon]):
        $active = $role === $key;
      ?>
        <a href="?tab=users&role=<?= $key ?>"
           class="btn btn-sm <?= $active ? 'btn-dark' : 'btn-outline-secondary' ?>"
           style="border-radius:20px">
          <i class="fas <?= $icon ?> me-1"></i><?= $label ?>
          <span class="badge ms-1 <?= $active ? 'bg-light text-dark' : 'bg-secondary' ?>"><?= $roleCounts[$key] ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Public-ID lookup (identification spec) — jump straight to a user record. -->
    <form method="GET" class="d-flex flex-wrap gap-2 mb-2" style="max-width:520px">
      <input type="hidden" name="tab" value="user_lookup">
      <input class="form-control" name="public_id" placeholder="Search by ForsaDrive ID, e.g. FD-D-20001"
             value="<?= htmlspecialchars($_GET['public_id'] ?? '') ?>"
             style="font-family:'Segoe UI Mono','Consolas',monospace; letter-spacing:.05em; max-width:340px">
      <button class="btn btn-primary"><i class="fas fa-search me-1"></i>Lookup</button>
    </form>

    <div class="mb-3">
      <input class="form-control" id="userSearch" placeholder="Search name, email, or ForsaDrive ID…" style="max-width:320px">
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle small" id="usersTable">
        <thead class="table-light">
          <tr><th>User</th><th>ForsaDrive ID</th><th>Email</th><th>Roles</th><th>Trips</th><th>Rating</th><th>Joined</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr class="<?= $u['suspended'] ? 'table-danger' : '' ?>">
            <td>
              <div class="d-flex align-items-center gap-2">
                <img src="<?= htmlspecialchars($u['picture'] ?? '../Src/default.jpg') ?>"
                     style="width:32px;height:32px;border-radius:50%;object-fit:cover" alt="">
                <div>
                  <div class="fw-semibold"><a href="?tab=user&id=<?= $u['id'] ?>" class="text-decoration-none text-reset"><?= htmlspecialchars($u['username']) ?></a></div>
                  <div class="text-muted" style="font-size:.7rem"><?= htmlspecialchars($u['governorate'] ?? '') ?></div>
                </div>
              </div>
            </td>
            <td>
              <?php if (!empty($u['public_id'])): ?>
                <span class="badge" style="background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;font-family:'Segoe UI Mono','Consolas',monospace;letter-spacing:.04em">
                  <?= htmlspecialchars($u['public_id']) ?>
                </span>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="text-muted small"><?= htmlspecialchars($u['email']) ?></td>
            <td>
              <?php if ($u['is_admin']): ?><span class="badge bg-dark me-1">Admin</span><?php endif; ?>
              <?php if ($u['is_driver']): ?><span class="badge bg-primary me-1">Driver</span><?php endif; ?>
              <?php if ($u['is_student']): ?><span class="badge bg-info text-dark me-1">Student</span><?php endif; ?>
              <?php if ($u['is_helpdesk_agent']): ?><span class="badge bg-success me-1">Agent</span><?php endif; ?>
              <?php
                $wc = (int)($u['warnings_count'] ?? 0);
                if ($wc > 0): ?>
                  <span class="badge bg-warning text-dark me-1" title="Warnings issued"><i class="fas fa-exclamation-triangle me-1"></i><?= $wc ?></span>
                <?php endif;
                if ($u['suspended']):
                  if (empty($u['suspended_until'])): ?>
                    <span class="badge bg-danger"><i class="fas fa-ban me-1"></i>Banned</span>
                  <?php elseif ($u['suspended_until'] > gmdate('Y-m-d H:i:s')): ?>
                    <span class="badge bg-danger" title="Until <?= htmlspecialchars($u['suspended_until']) ?> UTC"><i class="fas fa-clock me-1"></i>Suspended</span>
                  <?php else: ?>
                    <span class="badge bg-secondary" title="Elapsed — lifts on next login">Suspension ended</span>
                  <?php endif;
                endif; ?>
            </td>
            <td><?= $u['total_trips'] ?? 0 ?></td>
            <td><span class="stars">★</span> <?= number_format($u['avg_rating'] ?? ($u['score'] ?? 5), 1) ?></td>
            <td class="text-muted small"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
            <td class="text-end">
              <div class="d-flex justify-content-end gap-1 flex-wrap">
                <a href="?tab=user&id=<?= $u['id'] ?>" class="btn btn-outline-primary btn-sm" title="View user record">
                  <i class="fas fa-eye"></i>
                </a>
                <button class="btn btn-outline-danger btn-sm" title="Manage sanctions"
                        data-bs-toggle="modal" data-bs-target="#sanctionModal<?= $u['id'] ?>">
                  <i class="fas fa-gavel"></i>
                </button>
                <?php if ($u['suspended']): ?>
                <form method="POST" class="d-inline" title="Lift sanction now">
                  <input type="hidden" name="action" value="lift_user">
                  <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                  <button class="btn btn-success btn-sm"><i class="fas fa-check"></i></button>
                </form>
                <?php endif; ?>

                <?php if (!$u['is_driver']): ?>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="make_driver">
                  <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                  <button class="btn btn-outline-primary btn-sm" title="Make Driver"><i class="fas fa-car"></i></button>
                </form>
                <?php endif; ?>

                <?php if (!$u['is_helpdesk_agent']): ?>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="make_agent">
                  <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                  <button class="btn btn-outline-success btn-sm" title="Make HelpDesk Agent"><i class="fas fa-headset"></i></button>
                </form>
                <?php else: ?>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="remove_agent">
                  <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                  <button class="btn btn-outline-secondary btn-sm" title="Remove Agent Role"><i class="fas fa-headset"></i></button>
                </form>
                <?php endif; ?>
              </div>

              <!-- Progressive sanctions modal (§2.4) -->
              <?php
                $u['_sanction_counts'] = $sanctionCounts[$u['id']] ?? [];
                $suggest = Sanctions::suggestNext($u);
                $history = $sanctionsByUser[$u['id']] ?? [];
                $sLabels = [
                  'warning'    => ['Warning',    'warning text-dark', 'exclamation-triangle'],
                  'suspension' => ['Suspension', 'danger',            'clock'],
                  'ban'        => ['Ban',        'dark',              'ban'],
                  'lift'       => ['Lifted',     'success',           'check'],
                ];
              ?>
              <div class="modal fade text-start" id="sanctionModal<?= $u['id'] ?>" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                  <div class="modal-content">
                    <div class="modal-header" style="background:#0a1628;color:#fff">
                      <h5 class="modal-title"><i class="fas fa-gavel me-2"></i>Sanctions — <?= htmlspecialchars($u['username']) ?></h5>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                      <!-- Current state + suggested next step -->
                      <div class="d-flex flex-wrap gap-4 align-items-center mb-3 p-2 rounded" style="background:#f8fafc">
                        <div><span class="text-muted small d-block">Warnings</span><strong><?= (int)($u['warnings_count'] ?? 0) ?></strong></div>
                        <div><span class="text-muted small d-block">Status</span>
                          <?php if (!$u['suspended']): ?><span class="badge bg-success">Active</span>
                          <?php elseif (empty($u['suspended_until'])): ?><span class="badge bg-danger">Banned</span>
                          <?php else: ?><span class="badge bg-danger">Suspended until <?= htmlspecialchars($u['suspended_until']) ?> UTC</span><?php endif; ?>
                        </div>
                        <div class="ms-auto text-end"><span class="text-muted small d-block">Suggested next step</span>
                          <span class="badge bg-info text-dark text-uppercase"><?= htmlspecialchars($suggest) ?></span></div>
                      </div>

                      <div class="row g-3">
                        <!-- Warning -->
                        <div class="col-md-4">
                          <form method="POST" class="border rounded p-3 h-100 <?= $suggest==='warning'?'border-warning border-2':'' ?>">
                            <input type="hidden" name="action" value="warn_user">
                            <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                            <div class="fw-bold mb-2 text-warning"><i class="fas fa-exclamation-triangle me-1"></i>Warning</div>
                            <textarea name="reason" class="form-control form-control-sm mb-2" rows="2" placeholder="Reason (optional)"></textarea>
                            <button class="btn btn-warning btn-sm w-100">Issue warning</button>
                          </form>
                        </div>
                        <!-- Suspension -->
                        <div class="col-md-4">
                          <form method="POST" class="border rounded p-3 h-100 <?= $suggest==='suspension'?'border-danger border-2':'' ?>"
                                onsubmit="return confirm('Suspend this user?')">
                            <input type="hidden" name="action" value="suspend_user">
                            <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                            <div class="fw-bold mb-2 text-danger"><i class="fas fa-clock me-1"></i>Suspension</div>
                            <select name="days" class="form-select form-select-sm mb-2">
                              <option value="7">7 days</option>
                              <option value="14">14 days</option>
                              <option value="30">30 days</option>
                            </select>
                            <textarea name="reason" class="form-control form-control-sm mb-2" rows="2" placeholder="Reason (optional)"></textarea>
                            <button class="btn btn-danger btn-sm w-100">Suspend (7–30 days)</button>
                          </form>
                        </div>
                        <!-- Ban -->
                        <div class="col-md-4">
                          <form method="POST" class="border rounded p-3 h-100 <?= $suggest==='ban'?'border-dark border-2':'' ?>"
                                onsubmit="return confirm('Permanently ban this user? This blocks all login.')">
                            <input type="hidden" name="action" value="ban_user">
                            <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                            <div class="fw-bold mb-2"><i class="fas fa-ban me-1"></i>Ban</div>
                            <textarea name="reason" class="form-control form-control-sm mb-2" rows="2" placeholder="Reason (optional)"></textarea>
                            <button class="btn btn-dark btn-sm w-100">Ban permanently</button>
                          </form>
                        </div>
                      </div>

                      <?php if ($u['suspended']): ?>
                      <form method="POST" class="mt-3">
                        <input type="hidden" name="action" value="lift_user">
                        <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                        <button class="btn btn-outline-success btn-sm"><i class="fas fa-undo me-1"></i>Lift sanction &amp; reinstate</button>
                      </form>
                      <?php endif; ?>

                      <!-- History -->
                      <hr>
                      <div class="fw-bold small text-uppercase text-muted mb-2"><i class="fas fa-history me-1"></i>Sanction history</div>
                      <?php if (empty($history)): ?>
                        <p class="text-muted small mb-0">No prior sanctions on record.</p>
                      <?php else: ?>
                      <ul class="list-unstyled small mb-0">
                        <?php foreach ($history as $h): [$hl, $hc, $hi] = $sLabels[$h['type']] ?? [$h['type'], 'secondary', 'circle']; ?>
                        <li class="d-flex gap-2 align-items-start mb-2">
                          <span class="badge bg-<?= $hc ?>" style="white-space:nowrap"><i class="fas fa-<?= $hi ?> me-1"></i><?= $hl ?><?= $h['days'] ? ' '.(int)$h['days'].'d' : '' ?></span>
                          <span>
                            <?= htmlspecialchars($h['reason'] ?? '') ?>
                            <span class="text-muted d-block" style="font-size:.7rem">
                              <?= date('d M Y, H:i', strtotime($h['created_at'])) ?><?= $h['admin_name'] ? ' · by '.htmlspecialchars($h['admin_name']) : '' ?>
                            </span>
                          </span>
                        </li>
                        <?php endforeach; ?>
                      </ul>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php /* ═══ VERIFICATIONS (tabbed) ══════════════════════════════════════ */ elseif ($tab === 'verifications'): ?>
  <!-- Sub-tab bar -->
  <ul class="nav nav-pills mb-3 flex-wrap gap-1">
    <?php
    $verTabs = [
      'students'      => ['Students',       'fa-user-graduate', (int)$stats['pending_ver'] + (int)$stats['pending_student']],
      'drivers'       => ['Driver Review',  'fa-id-card',       (int)$stats['pending_vehicle']],
      'organizations' => ['Organizations',  'fa-building',      (int)$stats['pending_org']],
      'domains'       => ['Student Domains','fa-at',            0],
    ];
    foreach ($verTabs as $key => [$label, $icon, $count]):
      $active = $verifyType === $key;
    ?>
      <li class="nav-item">
        <a class="nav-link <?= $active ? 'active' : '' ?>" href="?tab=verifications&type=<?= $key ?>">
          <i class="fas <?= $icon ?> me-1"></i><?= $label ?>
          <?php if ($count > 0): ?><span class="badge bg-danger ms-1"><?= $count ?></span><?php endif; ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php /* ─── Students sub-tab ─── */ if ($verifyType === 'students'): ?>
    <!-- File-upload queue -->
    <h6 class="text-muted small text-uppercase fw-bold mt-3 mb-2"><i class="fas fa-file-image me-1"></i>Card Upload Queue</h6>
    <?php if (empty($verifications)): ?>
      <div class="fd-card p-3 text-center text-muted small">No card uploads awaiting review.</div>
    <?php else: ?>
      <?php foreach ($verifications as $v): ?>
      <div class="fd-card mb-3">
        <div class="d-flex justify-content-between flex-wrap gap-3">
          <div>
            <div class="fw-bold fs-6"><?= htmlspecialchars($v['username']) ?></div>
            <div class="text-muted small"><?= htmlspecialchars($v['email']) ?></div>
            <?php if ($v['governorate']): ?><div class="text-muted small"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($v['governorate']) ?></div><?php endif; ?>
            <div class="text-muted small mt-1"><i class="fas fa-clock me-1"></i>Submitted <?= date('d M Y', strtotime($v['created_at'])) ?></div>
          </div>
          <div class="d-flex gap-2 align-items-start flex-wrap">
            <form method="POST">
              <input type="hidden" name="action" value="approve_verification">
              <input type="hidden" name="target_id" value="<?= $v['id'] ?>">
              <button class="btn-fd-success btn btn-sm"><i class="fas fa-check me-1"></i>Approve</button>
            </form>
            <form method="POST" class="d-flex gap-1 align-items-start">
              <input type="hidden" name="action" value="reject_verification">
              <input type="hidden" name="target_id" value="<?= $v['id'] ?>">
              <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="Reason…" style="width:160px">
              <button class="btn-fd-danger btn btn-sm"><i class="fas fa-times me-1"></i>Reject</button>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- Institutional-email queue -->
    <h6 class="text-muted small text-uppercase fw-bold mt-4 mb-2"><i class="fas fa-graduation-cap me-1"></i>Institutional Email Queue</h6>
    <?php if (empty($pendingStudents)): ?>
      <div class="fd-card p-3 text-center text-muted small">No email-based student verifications pending.</div>
    <?php else: ?>
      <?php foreach ($pendingStudents as $s): ?>
      <div class="fd-card mb-3">
        <div class="d-flex justify-content-between flex-wrap gap-3">
          <div>
            <div class="fw-bold"><?= htmlspecialchars($s['username']) ?></div>
            <div class="text-muted small"><?= htmlspecialchars($s['email']) ?></div>
            <?php if ($s['governorate']): ?>
            <div class="text-muted small"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($s['governorate']) ?></div>
            <?php endif; ?>
            <div class="mt-2">
              <span class="badge bg-primary" style="font-size:.8rem"><i class="fas fa-graduation-cap me-1"></i>Submitted email:</span>
              <code class="ms-2"><?= htmlspecialchars($s['student_email'] ?? '—') ?></code>
            </div>
          </div>
          <div class="d-flex gap-2 align-items-start flex-wrap">
            <form method="POST">
              <input type="hidden" name="action" value="approve_student">
              <input type="hidden" name="target_id" value="<?= $s['id'] ?>">
              <button class="btn-fd-success btn btn-sm"><i class="fas fa-check me-1"></i>Approve</button>
            </form>
            <form method="POST" class="d-flex gap-1 align-items-start">
              <input type="hidden" name="action" value="reject_student">
              <input type="hidden" name="target_id" value="<?= $s['id'] ?>">
              <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="Reason…" style="width:180px">
              <button class="btn-fd-danger btn btn-sm"><i class="fas fa-times me-1"></i>Reject</button>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>

  <?php /* ─── Driver Review sub-tab (licence + vehicle, one decision) ─── */ elseif ($verifyType === 'drivers'): ?>
    <div class="fd-card mb-3 p-3" style="background:#eff6ff;border-left:4px solid #3b82f6">
      <div class="fw-semibold mb-1"><i class="fas fa-info-circle me-2 text-primary"></i>Driver Review Queue</div>
      <div class="small text-muted">Each submission is reviewed once and covers <strong>both the driver's licence and their vehicle</strong>. Check the permis de conduire and carte grise against the plate, then approve or reject. Approving lets the driver offer rides; the decision then shows on the driver's user record.</div>
    </div>
    <?php if (empty($pendingDriverReviews)): ?>
      <div class="empty-state"><i class="fas fa-id-card"></i><p>No driver reviews awaiting a decision</p></div>
    <?php else: ?>
      <?php foreach ($pendingDriverReviews as $v): ?>
      <div class="fd-card mb-3">
        <div class="d-flex justify-content-between flex-wrap gap-3">
          <div class="flex-grow-1">
            <div class="fw-bold">
              <a href="?tab=user&id=<?= (int)$v['owner_id'] ?>" class="text-decoration-none"><?= htmlspecialchars($v['owner_name']) ?></a>
              <span class="text-muted fw-normal small ms-2"><?= htmlspecialchars($v['owner_email']) ?></span>
            </div>
            <div class="mt-2 d-flex flex-wrap gap-2 align-items-center">
              <span class="badge bg-secondary"><?= htmlspecialchars(ucfirst($v['type'] ?? 'car')) ?></span>
              <?php if ($v['make'] ?? ''): ?><span class="text-muted small"><?= htmlspecialchars($v['make']) ?> <?= htmlspecialchars($v['model'] ?? '') ?> <?= htmlspecialchars($v['year'] ?? '') ?></span><?php endif; ?>
              <?php if ($v['plate_number'] ?? ''): ?>
              <span class="badge bg-dark"><i class="fas fa-car-side me-1"></i><?= htmlspecialchars($v['plate_number']) ?></span>
              <?php endif; ?>
              <span class="badge bg-info text-dark"><?= (int)($v['seats'] ?? 0) ?> seats</span>
            </div>

            <div class="row g-3 mt-1">
              <?php
                $reviewDocs = [
                  ['Driver\'s licence (permis)', 'fa-id-card',    $v['permis_conduire'] ?? ''],
                  ['Carte grise',               'fa-file-image',  $v['carte_grise']     ?? ''],
                  ['Vehicle photo',             'fa-car',         $v['car_image'] ?: ($v['photo'] ?? '')],
                ];
                foreach ($reviewDocs as [$dLabel, $dIcon, $dVal]):
                  $url = $docUrl($dVal);
              ?>
              <div class="col-auto">
                <div class="fw-semibold small mb-1"><i class="fas <?= $dIcon ?> me-1 text-primary"></i><?= $dLabel ?></div>
                <?php if ($url): ?>
                <a href="<?= htmlspecialchars($url) ?>" target="_blank">
                  <img src="<?= htmlspecialchars($url) ?>"
                       style="max-height:150px;max-width:240px;border-radius:8px;border:2px solid #e2e8f0;object-fit:contain"
                       onerror="this.outerHTML='<span class=\'text-danger small\'>File not found</span>'"
                       alt="<?= htmlspecialchars($dLabel) ?>">
                </a>
                <?php else: ?>
                <div class="text-muted small fst-italic">Not provided</div>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="d-flex flex-column gap-2 align-items-end">
            <form method="POST" onsubmit="return confirm('Approve this driver? They will be able to offer rides.')">
              <input type="hidden" name="action" value="approve_vehicle">
              <input type="hidden" name="target_id" value="<?= $v['id'] ?>">
              <button class="btn-fd-success btn btn-sm"><i class="fas fa-check me-1"></i>Approve driver</button>
            </form>
            <form method="POST" class="d-flex gap-1 align-items-start">
              <input type="hidden" name="action" value="reject_vehicle">
              <input type="hidden" name="target_id" value="<?= $v['id'] ?>">
              <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="Reason…" style="width:180px">
              <button class="btn-fd-danger btn btn-sm"><i class="fas fa-times me-1"></i>Reject</button>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>

  <?php /* ─── Organizations sub-tab (pending only) ─── */ elseif ($verifyType === 'organizations'): ?>
    <div class="fd-card mb-3 p-3" style="background:#f0fdf4;border-left:4px solid #22c55e">
      <div class="fw-semibold mb-1"><i class="fas fa-info-circle me-2 text-success"></i>Organization Applications</div>
      <div class="small text-muted">Approving generates a unique discount code that the org can share with its members. The full directory of all organizations (active/suspended/pending) is under <a href="?tab=organizations">Accounts → Organizations</a>.</div>
    </div>
    <?php if (empty($pendingOrgs)): ?>
      <div class="empty-state"><i class="fas fa-building"></i><p>No organization applications pending</p></div>
    <?php else: ?>
      <?php foreach ($pendingOrgs as $o): ?>
      <div class="fd-card mb-3">
        <div class="d-flex justify-content-between flex-wrap gap-3">
          <div>
            <div class="fw-bold fs-6"><?= htmlspecialchars($o['name']) ?>
              <span class="badge bg-secondary ms-2"><?= htmlspecialchars(ucfirst($o['type'] ?? 'company')) ?></span>
            </div>
            <div class="text-muted small mt-1">
              <i class="fas fa-user me-1"></i><?= htmlspecialchars($o['contact_name'] ?? $o['contact_person'] ?? '—') ?>
              &nbsp;·&nbsp;
              <i class="fas fa-envelope me-1"></i><?= htmlspecialchars($o['contact_email']) ?>
            </div>
            <?php if ($o['email_domain'] ?? $o['staff_email_domain'] ?? ''): ?>
            <div class="text-muted small mt-1">
              <i class="fas fa-at me-1"></i>@<?= htmlspecialchars($o['email_domain'] ?? $o['staff_email_domain']) ?>
            </div>
            <?php endif; ?>
            <div class="text-muted small mt-1">
              <i class="fas fa-percent me-1"></i>Requested discount: <strong><?= (int)$o['discount_percent'] ?>%</strong>
              &nbsp;·&nbsp;
              <i class="fas fa-clock me-1"></i>Submitted <?= date('d M Y', strtotime($o['created_at'])) ?>
            </div>
          </div>
          <div class="d-flex gap-2 align-items-start flex-wrap">
            <form method="POST">
              <input type="hidden" name="action" value="approve_org">
              <input type="hidden" name="target_id" value="<?= $o['id'] ?>">
              <button class="btn-fd-success btn btn-sm"><i class="fas fa-check me-1"></i>Approve &amp; Generate Code</button>
            </form>
            <form method="POST">
              <input type="hidden" name="action" value="reject_org">
              <input type="hidden" name="target_id" value="<?= $o['id'] ?>">
              <button class="btn-fd-danger btn btn-sm"><i class="fas fa-times me-1"></i>Reject</button>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>

  <?php /* ─── Student Domains sub-tab ─── */ elseif ($verifyType === 'domains'): ?>
  <div class="fd-card mb-3 p-3" style="background:#f0fdf4;border-left:4px solid #22c55e">
    <div class="fw-semibold mb-1"><i class="fas fa-info-circle me-2 text-success"></i>Student Email Domains</div>
    <div class="small text-muted">Users who register with an email from these domains are automatically granted the 50% student discount. Adding or removing a domain here takes effect immediately for new sign-ups. Removing a domain also revokes student status for existing users whose email matches only that domain.</div>
  </div>

  <!-- Add domain form -->
  <div class="fd-card mb-4 p-4">
    <h6 class="fw-bold mb-3"><i class="fas fa-plus-circle me-2 text-success"></i>Add New Domain</h6>
    <form method="POST" class="row g-2 align-items-end">
      <input type="hidden" name="action" value="add_student_domain">
      <div class="col-sm-5">
        <label class="form-label small fw-semibold">Email Domain <span class="text-danger">*</span></label>
        <input type="text" name="domain" class="form-control form-control-sm" placeholder="esprit.tn" required>
      </div>
      <div class="col-sm-4">
        <label class="form-label small fw-semibold">Institution Label</label>
        <input type="text" name="label" class="form-control form-control-sm" placeholder="ESPRIT">
      </div>
      <div class="col-sm-3">
        <button type="submit" class="btn btn-success btn-sm w-100"><i class="fas fa-plus me-1"></i>Add Domain</button>
      </div>
    </form>
  </div>

  <!-- Domain list -->
  <?php if (empty($studentDomains)): ?>
    <div class="empty-state"><i class="fas fa-at"></i><p>No student domains configured</p></div>
  <?php else: ?>
  <div class="fd-card p-0 overflow-hidden">
    <table class="table table-hover mb-0">
      <thead style="background:#f8fafc">
        <tr>
          <th class="ps-4">Domain</th>
          <th>Institution</th>
          <th>Added</th>
          <th class="text-end pe-4">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($studentDomains as $sd): ?>
        <tr>
          <td class="ps-4"><code>@<?= htmlspecialchars($sd['domain']) ?></code></td>
          <td><?= htmlspecialchars($sd['label']) ?></td>
          <td class="text-muted small"><?= date('d M Y', strtotime($sd['created_at'])) ?></td>
          <td class="text-end pe-4">
            <form method="POST" onsubmit="return confirm('Remove this domain? Users with this domain will lose student status.')">
              <input type="hidden" name="action" value="remove_student_domain">
              <input type="hidden" name="target_id" value="<?= $sd['id'] ?>">
              <button class="btn btn-outline-danger btn-sm"><i class="fas fa-trash me-1"></i>Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php endif; /* end verify sub-tabs */ ?>

  <?php /* ═══ USER RECORD (detail) ═══════════════════════════════════════ */ elseif ($tab === 'user'): ?>
  <a href="?tab=users" class="btn btn-outline-secondary btn-sm mb-3"><i class="fas fa-arrow-left me-1"></i>Back to Users</a>

  <?php if (!$detailUser): ?>
    <div class="empty-state"><i class="fas fa-user-slash"></i><p>User not found.</p></div>
  <?php else: ?>
    <!-- ── User identity ── -->
    <div class="fd-card mb-3 p-4">
      <div class="d-flex align-items-center gap-3 flex-wrap">
        <img src="<?= htmlspecialchars($detailUser['picture'] ?? '../Src/default.jpg') ?>"
             style="width:64px;height:64px;border-radius:50%;object-fit:cover" alt="">
        <div class="flex-grow-1">
          <div class="fw-bold fs-5"><?= htmlspecialchars($detailUser['username']) ?></div>
          <?php if (!empty($detailUser['public_id'])): ?>
          <div class="mt-1">
            <span class="badge" style="background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;font-family:'Segoe UI Mono','Consolas',monospace;letter-spacing:.04em;font-size:.78rem">
              <i class="fas fa-id-badge me-1"></i><?= htmlspecialchars($detailUser['public_id']) ?>
            </span>
          </div>
          <?php endif; ?>
          <div class="text-muted small mt-1"><i class="fas fa-envelope me-1"></i><?= htmlspecialchars($detailUser['email']) ?>
            <?php if (!empty($detailUser['governorate'])): ?>
              &nbsp;·&nbsp;<i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($detailUser['governorate']) ?>
            <?php endif; ?>
          </div>
          <?php
            // Reports linked to this user (identification spec) — quick summary
            // so admins can act without leaving the user record.
            $userReportsSent = ReportService::listMine($db, (int)$detailUser['id'], 5);
            $userReportsAgainst = ReportService::listAgainst($db, (int)$detailUser['id']);
            if (!empty($userReportsSent) || !empty($userReportsAgainst)):
          ?>
          <div class="small mt-1">
            <a href="?tab=reports&report_public_id=<?= urlencode($detailUser['public_id'] ?? '') ?>"
               class="text-decoration-none">
              <i class="fas fa-user-shield text-danger me-1"></i>
              <?= count($userReportsAgainst) ?> received · <?= count($userReportsSent) ?> sent
            </a>
          </div>
          <?php endif; ?>
          <div class="mt-2 d-flex flex-wrap gap-1">
            <?php if ($detailUser['is_admin']): ?><span class="badge bg-dark">Admin</span><?php endif; ?>
            <?php if ($detailUser['is_driver']): ?><span class="badge bg-primary">Driver</span><?php endif; ?>
            <?php if ($detailUser['is_student']): ?><span class="badge bg-info text-dark">Student</span><?php endif; ?>
            <?php if ($detailUser['is_helpdesk_agent']): ?><span class="badge bg-success">Agent</span><?php endif; ?>
            <?php if ($detailUser['suspended']): ?>
              <?php if (empty($detailUser['suspended_until'])): ?>
                <span class="badge bg-danger"><i class="fas fa-ban me-1"></i>Banned</span>
              <?php else: ?>
                <span class="badge bg-danger"><i class="fas fa-clock me-1"></i>Suspended</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="text-end text-muted small">
          <div><i class="fas fa-calendar me-1"></i>Joined <?= date('d M Y', strtotime($detailUser['created_at'])) ?></div>
        </div>
      </div>
    </div>

    <?php if ($detailDriver): ?>
    <!-- ── Driver profile (a role this user has) ── -->
    <div class="fd-card mb-3 p-4" style="border-left:4px solid #16a34a">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
        <div class="fw-bold"><i class="fas fa-id-badge me-2 text-success"></i>Driver profile</div>
        <span class="text-muted small">
          <i class="fas fa-star text-warning me-1"></i><?= number_format($detailUser['avg_rating'] ?? 5, 1) ?>
          &nbsp;·&nbsp;<?= (int)($detailUser['total_trips'] ?? 0) ?> trips
        </span>
      </div>
      <div class="small text-muted mb-3">Licence and vehicle are reviewed together as one <strong>Driver review</strong>. Pending submissions are decided under <a href="?tab=verifications&type=drivers">Verifications → Driver Review</a>.</div>

      <?php
        $primary = $detailVehicles[0] ?? null;
        $vStatus = $primary['verification_status'] ?? null;          // shared decision
        if ($primary === null) $vStatus = 'none';
      ?>

      <div class="row g-3">
        <!-- Vehicle card -->
        <div class="col-md-6">
          <div class="border rounded p-3 h-100" style="background:#fffdf5">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div class="fw-bold"><i class="fas fa-car me-2 text-warning"></i>Vehicle</div>
              <?= $primary ? $statusBadge($vStatus) : '<span class="badge bg-secondary">Not submitted</span>' ?>
            </div>
            <?php if ($primary): ?>
              <div class="small mb-2">
                <span class="badge bg-secondary me-1"><?= htmlspecialchars(ucfirst($primary['type'] ?? 'car')) ?></span>
                <?= htmlspecialchars(trim(($primary['make'] ?? '') . ' ' . ($primary['model'] ?? ''))) ?>
                <?php if (!empty($primary['plate_number'])): ?>
                  <span class="badge bg-dark ms-1"><i class="fas fa-car-side me-1"></i><?= htmlspecialchars($primary['plate_number']) ?></span>
                <?php endif; ?>
              </div>
              <div class="d-flex flex-wrap gap-3">
                <?php
                  $vehDocs = [
                    ['Carte grise',  $primary['carte_grise'] ?? ''],
                    ['Vehicle photo', $primary['car_image'] ?: ($primary['photo'] ?? '')],
                  ];
                  foreach ($vehDocs as [$dLabel, $dVal]): $u = $docUrl($dVal); if (!$u) continue; ?>
                  <a href="<?= htmlspecialchars($u) ?>" target="_blank" class="d-inline-block">
                    <div class="text-muted" style="font-size:.7rem"><?= htmlspecialchars($dLabel) ?></div>
                    <img src="<?= htmlspecialchars($u) ?>" style="max-height:90px;max-width:130px;border-radius:6px;border:1px solid #e2e8f0;object-fit:contain"
                         onerror="this.outerHTML='<span class=\'text-danger small\'>missing</span>'" alt="<?= htmlspecialchars($dLabel) ?>">
                  </a>
                <?php endforeach; ?>
              </div>
              <?php if ($vStatus === 'rejected' && !empty($primary['rejection_note'])): ?>
                <div class="alert-fd-danger small mt-2 p-2"><i class="fas fa-exclamation-circle me-1"></i><?= htmlspecialchars($primary['rejection_note']) ?></div>
              <?php endif; ?>
              <?php if (count($detailVehicles) > 1): ?>
                <div class="text-muted small mt-2 fst-italic">+ <?= count($detailVehicles) - 1 ?> more vehicle(s) on file</div>
              <?php endif; ?>
            <?php else: ?>
              <div class="text-muted small fst-italic">This driver has not submitted a vehicle yet.</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Driver's licence card -->
        <div class="col-md-6">
          <div class="border rounded p-3 h-100" style="background:#fffdf5">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div class="fw-bold"><i class="fas fa-id-card me-2 text-warning"></i>Driver's licence</div>
              <?= $primary ? $statusBadge($vStatus) : '<span class="badge bg-secondary">Not submitted</span>' ?>
            </div>
            <div class="small mb-2">
              <span class="text-muted">Licence no.</span>
              <strong><?= htmlspecialchars($detailUser['license_number'] ?? '') ?: '—' ?></strong>
            </div>
            <?php $permUrl = $docUrl($primary['permis_conduire'] ?? ''); ?>
            <?php if ($permUrl): ?>
              <a href="<?= htmlspecialchars($permUrl) ?>" target="_blank" class="d-inline-block">
                <div class="text-muted" style="font-size:.7rem">Permis de conduire</div>
                <img src="<?= htmlspecialchars($permUrl) ?>" style="max-height:90px;max-width:130px;border-radius:6px;border:1px solid #e2e8f0;object-fit:contain"
                     onerror="this.outerHTML='<span class=\'text-danger small\'>missing</span>'" alt="Permis de conduire">
              </a>
            <?php else: ?>
              <div class="text-muted small fst-italic">No licence document on file.</div>
            <?php endif; ?>
            <?php if ($vStatus === 'rejected' && !empty($primary['rejection_note'])): ?>
              <div class="alert-fd-danger small mt-2 p-2"><i class="fas fa-exclamation-circle me-1"></i><?= htmlspecialchars($primary['rejection_note']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; /* driver profile */ ?>
  <?php endif; /* detail user exists */ ?>

  <?php /* ═══ COMPLAINTS ══════════════════════════════════════════════════ */ elseif ($tab === 'complaints'): ?>
  <div class="fd-card">
    <!-- Filter bar -->
    <form method="GET" class="d-flex flex-wrap gap-2 mb-3 align-items-center">
      <input type="hidden" name="tab" value="complaints">
      <select name="complaint_status" class="form-select" style="max-width:180px">
        <?php foreach ([''=>'All statuses','open'=>'Open','in_review'=>'In Review','resolved'=>'Resolved','dismissed'=>'Dismissed'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $complaintStatusFilter === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-primary"><i class="fas fa-filter me-1"></i>Apply</button>
      <a class="btn btn-link" href="?tab=complaints">Reset</a>
    </form>

    <?php if (empty($allComplaints)): ?>
      <div class="empty-state"><i class="fas fa-flag"></i><p>No complaints match these filters.</p></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle small">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>From</th>
            <th>Against</th>
            <th>Type</th>
            <th>Description</th>
            <th>Status</th>
            <th>Date</th>
            <th class="text-end">Update</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($allComplaints as $c):
            $sc = $c['status'];
            $bg = ['open'=>'#fef9c3','in_review'=>'#e0f2fe','resolved'=>'#dcfce7','dismissed'=>'#f3f4f6'][$sc] ?? '#e5e7eb';
            $fg = ['open'=>'#854d0e','in_review'=>'#0369a1','resolved'=>'#166534','dismissed'=>'#6b7280'][$sc] ?? '#374151';
          ?>
          <tr>
            <td class="text-muted">#<?= (int)$c['id'] ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($c['from_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($c['against_name'] ?? '—') ?></td>
            <td>
              <span class="badge bg-secondary">
                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $c['type'] ?? ''))) ?>
              </span>
            </td>
            <td style="max-width:320px">
              <?= htmlspecialchars(mb_strimwidth($c['description'] ?? '', 0, 200, '…')) ?>
              <?php if (!empty($c['admin_note'])): ?>
                <div class="text-muted mt-1 fst-italic" style="font-size:.75rem">
                  <i class="fas fa-comment-alt me-1"></i><?= htmlspecialchars($c['admin_note']) ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge" style="background:<?= $bg ?>;color:<?= $fg ?>">
                <?= ucfirst(str_replace('_', ' ', $sc)) ?>
              </span>
            </td>
            <td class="text-muted small"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
            <td class="text-end">
              <form method="POST" class="d-flex gap-1 align-items-center justify-content-end">
                <input type="hidden" name="target_id" value="<?= (int)$c['id'] ?>">
                <select name="action" class="form-select form-select-sm" style="min-width:120px">
                  <option value="resolve_complaint"  <?= $sc==='resolved'  ? 'selected':'' ?>>Resolved</option>
                  <option value="dismiss_complaint"  <?= $sc==='dismissed' ? 'selected':'' ?>>Dismissed</option>
                  <option value="review_complaint"   <?= $sc==='in_review' ? 'selected':'' ?>>In Review</option>
                </select>
                <input type="text" name="admin_note" class="form-control form-control-sm"
                       placeholder="Note (opt)" style="max-width:160px"
                       value="<?= htmlspecialchars($c['admin_note'] ?? '') ?>">
                <button class="btn btn-primary btn-sm">Save</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php /* ═══ ORGANIZATIONS ════════════════════════════════════════════════ */ elseif ($tab === 'organizations'): ?>
  <?php if (empty($orgs)): ?>
    <div class="empty-state"><i class="fas fa-building"></i><p>No organizations yet</p>
      <p class="small text-muted">Organizations register on the homepage.</p></div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle small">
        <thead class="table-light">
          <tr><th>Organization</th><th>Contact</th><th>Domain</th><th>Discount</th><th>Status</th><th>Code</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($orgs as $o): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= htmlspecialchars($o['name']) ?></div>
              <div class="text-muted" style="font-size:.7rem"><?= htmlspecialchars(ucfirst($o['type'])) ?></div>
            </td>
            <td><?= htmlspecialchars($o['contact_name']) ?><br><span class="text-muted"><?= htmlspecialchars($o['contact_email']) ?></span></td>
            <td class="text-muted">@<?= htmlspecialchars($o['email_domain'] ?? '—') ?></td>
            <td class="fw-bold text-success"><?= $o['discount_percent'] ?>%</td>
            <td><span class="status-badge <?= match($o['status']){ 'active'=>'confirmed','suspended'=>'cancelled',default=>'pending' } ?>"><?= ucfirst($o['status']) ?></span></td>
            <td><code style="font-size:.78rem"><?= htmlspecialchars($o['discount_code'] ?? '—') ?></code></td>
            <td>
              <?php if ($o['status'] === 'pending'): ?>
              <div class="d-flex gap-1">
                <form method="POST">
                  <input type="hidden" name="action" value="approve_org">
                  <input type="hidden" name="target_id" value="<?= $o['id'] ?>">
                  <button class="btn-fd-success btn btn-sm">Approve</button>
                </form>
                <form method="POST">
                  <input type="hidden" name="action" value="reject_org">
                  <input type="hidden" name="target_id" value="<?= $o['id'] ?>">
                  <button class="btn-fd-danger btn btn-sm">Reject</button>
                </form>
              </div>
              <?php else: ?>
              <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php /* ═══ HELPDESK ════════════════════════════════════════════════════ */ elseif ($tab === 'helpdesk'): ?>
  <?php if (empty($hdConvs)): ?>
    <div class="empty-state"><i class="fas fa-headset"></i><p>No support tickets yet</p></div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle small">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>User</th>
            <th>Subject</th>
            <th>Assigned Agent</th>
            <th>Status</th>
            <th>Unread</th>
            <th>Reassign (override)</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($hdConvs as $hc):
            // Auto-assignment is backend behavior — tickets arrive here already assigned.
            // agent_id can only be NULL when no AVAILABLE agent existed at creation time.
            $isUnassigned = empty($hc['agent_id']);
            $method       = $hc['assignment_method'] ?? null;
            // Distinguish the three reasons a ticket can be unassigned (spec):
            //   (a) zero eligible agents exist                 → "No HelpDesk agents exist"
            //   (b) eligible agents exist but all are busy     → "Waiting for available agent"
            //   (c) at least one agent is free now             → Retry button will pick it up
            $unassignedReason = null;
            if ($isUnassigned) {
                if (empty($agents))             $unassignedReason = 'no_eligible';
                elseif (!$anyAgentAvailable)    $unassignedReason = 'all_busy';
                else                             $unassignedReason = 'retryable';
            }
          ?>
          <tr>
            <td class="text-muted">#<?= $hc['id'] ?></td>
            <td class="fw-semibold"><?= htmlspecialchars($hc['user_name']) ?></td>
            <td><?= htmlspecialchars($hc['subject'] ?? 'Support request') ?></td>
            <td>
              <?php if ($isUnassigned): ?>
                <?php if ($unassignedReason === 'no_eligible'): ?>
                  <span class="text-danger small" title="Promote a user to HelpDesk Agent from the Users tab">
                    <i class="fas fa-user-slash me-1"></i>No HelpDesk agents exist
                  </span>
                <?php elseif ($unassignedReason === 'all_busy'): ?>
                  <span class="text-warning small" title="Every agent already has an active ticket — this one will auto-assign as soon as someone resolves theirs">
                    <i class="fas fa-hourglass-half me-1"></i>Waiting for available agent
                  </span>
                <?php else: ?>
                  <span class="text-muted small" title="An agent is free now — use Retry to assign">
                    <i class="fas fa-clock me-1"></i>Unassigned
                  </span>
                <?php endif; ?>
              <?php else: ?>
                <?= htmlspecialchars($hc['agent_name'] ?? '—') ?>
                <?php if ($method === 'auto'): ?>
                  <span class="badge bg-info text-dark ms-1" style="font-size:.65rem" title="Auto-assigned by system">Auto</span>
                <?php elseif ($method === 'manual'): ?>
                  <span class="badge bg-secondary ms-1" style="font-size:.65rem" title="Manually assigned by admin">Manual</span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td>
              <span class="status-badge <?= $hc['status'] === 'open' ? 'pending' : 'confirmed' ?>">
                <?= ucfirst($hc['status']) ?>
              </span>
            </td>
            <td><?= $hc['unread'] > 0 ? "<span class='badge bg-danger'>{$hc['unread']}</span>" : '—' ?></td>
            <td>
              <?php if (!empty($agents)): ?>
                <!-- Manual reassignment — admin override only. Default workflow is automatic at creation.
                     Admin may pick a busy agent on purpose; "(busy)" hint shown next to their name. -->
                <form method="POST" class="d-flex gap-1 align-items-center"
                      onsubmit="var s=this.querySelector('select');var o=s.options[s.selectedIndex];if(o.dataset.busy==='1'&&!confirm('That agent already has an active ticket. Assign anyway?'))return false;">
                  <input type="hidden" name="action"  value="assign_helpdesk">
                  <input type="hidden" name="conv_id" value="<?= $hc['id'] ?>">
                  <select name="target_id" class="form-select form-select-sm" style="min-width:150px">
                    <?php foreach ($agents as $ag):
                      $busy = ($agentActiveCount[(int)$ag['id']] ?? 0) > 0;
                    ?>
                    <option value="<?= $ag['id'] ?>"
                            data-busy="<?= $busy ? '1' : '0' ?>"
                            <?= $hc['agent_id'] == $ag['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($ag['username']) ?><?= $busy ? ' (busy)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-secondary btn-sm" title="Manually reassign (admin override)">
                    <i class="fas fa-user-check"></i>
                  </button>
                </form>
              <?php else: ?>
                <a href="?tab=users" class="text-muted small">No agents — create one first</a>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <?php if ($isUnassigned && $hc['status'] === 'open' && !empty($agents)): ?>
                <!-- Secondary fallback only — present for tickets the backend couldn't auto-assign
                     at creation (no available agent then). Clicking when all agents are still busy
                     surfaces the queued message; clicking when one is free assigns immediately. -->
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action"  value="auto_assign_helpdesk">
                  <input type="hidden" name="conv_id" value="<?= $hc['id'] ?>">
                  <button class="btn btn-link btn-sm text-muted p-0"
                          title="<?= $anyAgentAvailable ? 'Retry — an agent is free now' : 'Retry — all agents are currently busy' ?>">
                    <i class="fas fa-redo me-1"></i>Retry Auto Assignment
                  </button>
                </form>
              <?php endif; ?>
              <a href="helpdesk.php?conv=<?= $hc['id'] ?>" class="btn btn-sm btn-outline-secondary ms-2" title="Open in HelpDesk console">
                <i class="fas fa-external-link-alt"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php /* ═══ ANNOUNCEMENTS ═══════════════════════════════════════════════ */ elseif ($tab === 'announcements'): ?>
  <div class="row g-3">
    <div class="col-12 col-lg-5">
      <div class="fd-card">
        <div class="card-title"><i class="fas fa-bullhorn text-primary"></i> New Announcement</div>
        <form method="POST" onsubmit="return confirm('Send this announcement to the selected audience?')">
          <input type="hidden" name="action" value="broadcast_announcement">
          <div class="mb-2">
            <label class="form-label small fw-semibold">Title <span class="text-danger">*</span></label>
            <input type="text" name="title" class="form-control form-control-sm" maxlength="120" required placeholder="e.g. Scheduled maintenance Sunday">
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Message <span class="text-danger">*</span></label>
            <textarea name="body" class="form-control form-control-sm" rows="4" required placeholder="Write your announcement…"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Audience</label>
            <select name="audience" class="form-select form-select-sm">
              <option value="all">All users</option>
              <option value="drivers">Drivers only</option>
              <option value="students">Students only</option>
            </select>
          </div>
          <button class="btn btn-primary btn-sm w-100"><i class="fas fa-paper-plane me-1"></i>Send announcement</button>
        </form>
        <div class="form-text small mt-2">Recipients see it in their in-app notifications (web and mobile).</div>
      </div>
    </div>
    <div class="col-12 col-lg-7">
      <div class="fd-card">
        <div class="card-title"><i class="fas fa-history text-primary"></i> Sent Announcements</div>
        <?php if (empty($announcements)): ?>
          <p class="text-muted small mb-0">No announcements sent yet.</p>
        <?php else: foreach ($announcements as $an): ?>
          <div class="border-bottom py-2">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div class="fw-semibold"><?= htmlspecialchars($an['title']) ?></div>
              <span class="badge bg-secondary flex-shrink-0"><?= htmlspecialchars(Announcement::audienceLabel($an['audience'])) ?></span>
            </div>
            <div class="small text-muted"><?= nl2br(htmlspecialchars($an['body'])) ?></div>
            <div class="text-muted" style="font-size:.7rem">
              <i class="fas fa-users me-1"></i><?= (int)$an['sent_count'] ?> recipient(s)
              · <?= date('d M Y, H:i', strtotime($an['created_at'])) ?><?= $an['admin_name'] ? ' · by '.htmlspecialchars($an['admin_name']) : '' ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <?php /* ═══ PROMO CODES ═════════════════════════════════════════════════ */ elseif ($tab === 'promos'): ?>
  <div class="fd-card mb-4">
    <div class="card-title"><i class="fas fa-tags text-primary"></i> Create Promo Code</div>
    <form method="POST" class="row g-2 align-items-end">
      <input type="hidden" name="action" value="create_promo">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold">Code</label>
        <input type="text" name="code" class="form-control form-control-sm" placeholder="(auto if blank)" style="text-transform:uppercase">
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold">Credit (TND) <span class="text-danger">*</span></label>
        <input type="number" name="amount" step="0.5" min="0.5" max="1000" class="form-control form-control-sm" required>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold">Max uses</label>
        <input type="number" name="max_uses" min="0" value="0" class="form-control form-control-sm" title="0 = unlimited">
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold">Expires</label>
        <input type="date" name="expires_at" class="form-control form-control-sm">
      </div>
      <div class="col-sm-2">
        <button class="btn btn-success btn-sm w-100"><i class="fas fa-plus me-1"></i>Create</button>
      </div>
    </form>
    <div class="form-text small mt-1">Blank code is auto-generated · max uses 0 = unlimited · blank expiry = never · each user can redeem a code once.</div>
  </div>

  <div class="fd-card p-0 overflow-hidden">
    <table class="table table-hover mb-0 align-middle">
      <thead style="background:#f8fafc"><tr>
        <th class="ps-4">Code</th><th>Credit</th><th>Uses</th><th>Expires</th><th>Status</th><th class="text-end pe-4">Action</th>
      </tr></thead>
      <tbody>
      <?php if (empty($promos)): ?>
        <tr><td colspan="6" class="text-center text-muted small py-3">No promo codes yet.</td></tr>
      <?php else: foreach ($promos as $p):
        $expired  = !empty($p['expires_at']) && strtotime($p['expires_at']) <= time();
        $maxedOut = (int)$p['max_uses'] > 0 && (int)$p['used_count'] >= (int)$p['max_uses'];
        $live     = $p['is_active'] && !$expired && !$maxedOut;
      ?>
        <tr>
          <td class="ps-4"><code><?= htmlspecialchars($p['code']) ?></code></td>
          <td class="fw-semibold"><?= number_format($p['amount'],2) ?> TND</td>
          <td><?= (int)$p['used_count'] ?><?= (int)$p['max_uses'] > 0 ? ' / '.(int)$p['max_uses'] : '' ?></td>
          <td class="small text-muted"><?= !empty($p['expires_at']) ? date('d M Y', strtotime($p['expires_at'])) : '—' ?></td>
          <td>
            <?php if ($live): ?><span class="badge bg-success">Active</span>
            <?php elseif (empty($p['is_active'])): ?><span class="badge bg-secondary">Disabled</span>
            <?php elseif ($expired): ?><span class="badge bg-warning text-dark">Expired</span>
            <?php else: ?><span class="badge bg-warning text-dark">Used up</span><?php endif; ?>
          </td>
          <td class="text-end pe-4">
            <form method="POST" class="d-inline">
              <input type="hidden" name="action" value="toggle_promo">
              <input type="hidden" name="target_id" value="<?= $p['id'] ?>">
              <input type="hidden" name="activate" value="<?= $p['is_active'] ? '' : '1' ?>">
              <button class="btn btn-sm <?= $p['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                <?= $p['is_active'] ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php /* ═══ AUDIT LOG ═══════════════════════════════════════════════════ */ elseif ($tab === 'audit'): ?>
  <div class="fd-card p-0 overflow-hidden">
    <table class="table table-hover mb-0 align-middle small">
      <thead style="background:#f8fafc"><tr>
        <th class="ps-4">When</th><th>Admin</th><th>Action</th><th>Target</th><th>Details</th>
      </tr></thead>
      <tbody>
      <?php if (empty($auditLogs)): ?>
        <tr><td colspan="5" class="text-center text-muted py-3">No audit entries yet.</td></tr>
      <?php else: foreach ($auditLogs as $log): ?>
        <tr>
          <td class="ps-4 text-muted" style="white-space:nowrap"><?= date('d M Y, H:i', strtotime($log['created_at'])) ?></td>
          <td><?= htmlspecialchars($log['admin_name'] ?? '—') ?></td>
          <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($log['action']) ?></span></td>
          <td class="text-muted"><?= $log['target_id'] ? '#'.(int)$log['target_id'] : '—' ?></td>
          <td><?= htmlspecialchars($log['summary'] ?? '') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const s = document.getElementById('userSearch');
if (s) s.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#usersTable tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

function toggleAdminNav() {
    const nav = document.getElementById('adminNav');
    const overlay = document.getElementById('adminOverlay');
    nav.classList.toggle('open');
    overlay.classList.toggle('show');
}
</script>
</body>
</html>
