<?php
require_once '../server/session.php';
require_once '../server/language.php';
require_once '../classes/messages.php';

requireRegularUser();

$db        = getDB();
$userId    = (int)$_SESSION['user_id'];
$msgSvc    = new Messages($db);

// Create/get conversation when ?new=userId
if (isset($_GET['new']) && is_numeric($_GET['new'])) {
    $otherId = (int)$_GET['new'];
    if ($otherId !== $userId) {
        $convId = $msgSvc->getOrCreateConversation($userId, $otherId, null);
        if ($convId) {
            header('Location: messages.php?conv=' . $convId);
            exit();
        }
    }
}

// Send message via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send') {
    $convId = (int)($_POST['conv_id'] ?? 0);
    $body   = trim($_POST['body'] ?? '');
    if ($convId && $body && $msgSvc->isMember($convId, $userId)) {
        $msgSvc->send($convId, $userId, $body);
    }
    header('Location: messages.php?conv=' . $convId);
    exit();
}

// Active conversation
$activeConvId = isset($_GET['conv']) && is_numeric($_GET['conv']) ? (int)$_GET['conv'] : 0;
$messages     = [];
$activeConv   = null;

$conversations = $msgSvc->getUserConversations($userId);

if ($activeConvId) {
    // Security: must be a member
    if ($msgSvc->isMember($activeConvId, $userId)) {
        $messages = $msgSvc->getMessages($activeConvId, $userId);
        foreach ($conversations as $c) {
            if ((int)$c['id'] === $activeConvId) { $activeConv = $c; break; }
        }
        // Re-fetch conversations to refresh unread counts after markRead in getMessages
        $conversations = $msgSvc->getUserConversations($userId);
    } else {
        $activeConvId = 0;
    }
}

$pageTitle = 'Messages';
require_once '../include/header.php';
require_once '../include/sidebar.php';

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    return date('d M', strtotime($datetime));
}
?>

<div class="fd-main">
    <div class="page-header">
        <h1><i class="fas fa-comments me-2"></i>Messages</h1>
    </div>

    <div class="row g-0" style="min-height:520px; border:1px solid var(--bs-border-color, #dee2e6); border-radius:.75rem; overflow:hidden;">

        <!-- Conversations list -->
        <div class="col-12 col-md-4 border-end" style="overflow-y:auto; max-height:75vh;">
            <?php if (empty($conversations)): ?>
                <div class="empty-state p-4">
                    <i class="fas fa-comments fa-2x mb-2 text-muted"></i>
                    <p class="text-muted mb-0">No conversations yet.</p>
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($conversations as $conv):
                        $isActive  = ((int)$conv['id'] === $activeConvId);
                        $picSrc    = !empty($conv['other_pic']) ? htmlspecialchars($conv['other_pic']) : '../Src/default.jpg';
                        $lastMsg   = $conv['last_msg'] ?? '';
                        $lastAt    = !empty($conv['last_at']) ? timeAgo($conv['last_at']) : '';
                        $unread    = (int)($conv['unread'] ?? 0);
                    ?>
                        <a href="messages.php?conv=<?= (int)$conv['id'] ?>"
                           class="list-group-item list-group-item-action px-3 py-3 d-flex gap-3 align-items-center<?= $isActive ? ' active' : '' ?>"
                           style="<?= $isActive ? '' : '' ?>">
                            <img src="<?= $picSrc ?>" alt="avatar"
                                 style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                            <div class="flex-grow-1 overflow-hidden">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-semibold text-truncate"><?= htmlspecialchars($conv['other_name'] ?? 'User') ?></span>
                                    <small class="text-muted ms-1" style="white-space:nowrap;"><?= $lastAt ?></small>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <small class="text-muted text-truncate" style="max-width:160px;">
                                        <?= htmlspecialchars(mb_substr($lastMsg, 0, 50)) ?>
                                    </small>
                                    <?php if ($unread > 0): ?>
                                        <span class="badge bg-primary ms-2 rounded-pill"><?= $unread ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Chat area -->
        <div class="col-12 col-md-8 d-flex flex-column" style="max-height:75vh;">
            <?php if (!$activeConvId): ?>
                <div class="empty-state flex-grow-1 d-flex flex-column align-items-center justify-content-center p-4">
                    <i class="fas fa-comment-dots fa-3x mb-3 text-muted"></i>
                    <h6 class="text-muted">Select a conversation</h6>
                    <p class="text-muted small">Choose a conversation from the list to start chatting.</p>
                </div>
            <?php else: ?>
                <!-- Chat header -->
                <div class="p-3 border-bottom d-flex align-items-center gap-2" style="flex-shrink:0;">
                    <?php if ($activeConv): ?>
                        <img src="<?= !empty($activeConv['other_pic']) ? htmlspecialchars($activeConv['other_pic']) : '../Src/default.jpg' ?>"
                             alt="avatar" style="width:38px;height:38px;border-radius:50%;object-fit:cover;">
                        <span class="fw-semibold"><?= htmlspecialchars($activeConv['other_name'] ?? 'User') ?></span>
                    <?php endif; ?>
                </div>

                <!-- Messages -->
                <div class="chat-wrap flex-grow-1 p-3" id="chatMessages" style="overflow-y:auto;">
                    <?php if (empty($messages)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-comment-slash fa-2x mb-2"></i>
                            <p class="mb-0">No messages yet. Say hello!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($messages as $msg):
                            $isMe    = ((int)$msg['sender_id'] === $userId);
                            $timeStr = !empty($msg['created_at']) ? date('H:i', strtotime($msg['created_at'])) : '';
                        ?>
                            <div class="bubble <?= $isMe ? 'me' : 'them' ?>">
                                <?= htmlspecialchars($msg['body']) ?>
                                <span class="time"><?= $timeStr ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Send form -->
                <div class="p-3 border-top" style="flex-shrink:0;">
                    <form method="POST" class="d-flex gap-2" id="sendForm">
                        <input type="hidden" name="action"  value="send">
                        <input type="hidden" name="conv_id" value="<?= $activeConvId ?>">
                        <input type="text" class="form-control" name="body"
                               placeholder="Type a message…" autocomplete="off" required
                               style="border-radius:2rem;">
                        <button type="submit" class="btn-fd-primary px-4" style="border-radius:2rem;white-space:nowrap;">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    // Auto-scroll chat to bottom
    var chat = document.getElementById('chatMessages');
    if (chat) chat.scrollTop = chat.scrollHeight;

    // Submit on Enter (but allow Shift+Enter for newline in textareas)
    var input = document.querySelector('#sendForm input[name="body"]');
    if (input) {
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (input.value.trim()) input.closest('form').submit();
            }
        });
    }
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
