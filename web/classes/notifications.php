<?php

class Notifications {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function create(int $userId, string $title, string $body = '', string $type = 'info', string $link = ''): bool {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO notifications (user_id, type, title, body, link) VALUES (?,?,?,?,?)"
            );
            return $stmt->execute([$userId, $type, $title, $body, $link]);
        } catch (PDOException $e) {
            error_log("Notifications::create — " . $e->getMessage());
            return false;
        }
    }

    public function getForUser(int $userId, int $limit = 30): array {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ?"
            );
            $stmt->execute([$userId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    public function markRead(int $userId): void {
        try {
            $this->pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$userId]);
        } catch (PDOException $e) {}
    }

    public function markOneRead(int $id, int $userId): void {
        try {
            $this->pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$id, $userId]);
        } catch (PDOException $e) {}
    }

    public function unreadCount(int $userId): int {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    public static function typeIcon(string $type): string {
        return match($type) {
            'success' => 'check-circle text-success',
            'danger'  => 'exclamation-circle text-danger',
            'warning' => 'exclamation-triangle text-warning',
            default   => 'info-circle text-primary',
        };
    }
}
