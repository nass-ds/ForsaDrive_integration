<?php

class Messages {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /** Get or create a conversation between two users (optionally linked to a ride) */
    public function getOrCreateConversation(int $userA, int $userB, ?int $rideId = null): ?int {
        if ($userA === $userB) return null;
        // Normalise order so UNIQUE constraint works both ways
        $a = min($userA, $userB);
        $b = max($userA, $userB);
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id FROM conversations WHERE user_a=? AND user_b=? AND (ride_id=? OR (ride_id IS NULL AND ? IS NULL))"
            );
            $stmt->execute([$a, $b, $rideId, $rideId]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) return (int)$row['id'];

            $stmt = $this->pdo->prepare(
                "INSERT INTO conversations (user_a, user_b, ride_id) VALUES (?,?,?)"
            );
            $stmt->execute([$a, $b, $rideId]);
            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log("Messages::getOrCreateConversation — " . $e->getMessage());
            return null;
        }
    }

    /** All conversations for a user with last message preview */
    public function getUserConversations(int $userId): array {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT c.*,
                        CASE WHEN c.user_a=? THEN u2.username ELSE u1.username END AS other_name,
                        CASE WHEN c.user_a=? THEN u2.picture  ELSE u1.picture  END AS other_pic,
                        CASE WHEN c.user_a=? THEN c.user_b    ELSE c.user_a    END AS other_id,
                        m.body AS last_msg,
                        m.created_at AS last_at,
                        (SELECT COUNT(*) FROM messages WHERE conversation_id=c.id AND is_read=0 AND sender_id!=?) AS unread
                 FROM conversations c
                 JOIN users u1 ON u1.id = c.user_a
                 JOIN users u2 ON u2.id = c.user_b
                 LEFT JOIN messages m ON m.id = (
                     SELECT id FROM messages WHERE conversation_id=c.id ORDER BY created_at DESC LIMIT 1
                 )
                 WHERE c.user_a=? OR c.user_b=?
                 ORDER BY COALESCE(m.created_at, c.created_at) DESC"
            );
            $stmt->execute([$userId,$userId,$userId,$userId,$userId,$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Messages::getUserConversations — " . $e->getMessage());
            return [];
        }
    }

    /** Messages in a conversation */
    public function getMessages(int $convId, int $userId): array {
        try {
            // Mark as read
            $stmt = $this->pdo->prepare("UPDATE messages SET is_read=1 WHERE conversation_id=? AND sender_id!=?");
            $stmt->execute([$convId, $userId]);

            $stmt = $this->pdo->prepare(
                "SELECT m.*, u.username AS sender_name
                 FROM messages m
                 JOIN users u ON u.id = m.sender_id
                 WHERE m.conversation_id=?
                 ORDER BY m.created_at ASC"
            );
            $stmt->execute([$convId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Messages::getMessages — " . $e->getMessage());
            return [];
        }
    }

    /** Send a message */
    public function send(int $convId, int $senderId, string $body): bool {
        $body = trim($body);
        if (!$body) return false;
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO messages (conversation_id, sender_id, body) VALUES (?,?,?)"
            );
            return $stmt->execute([$convId, $senderId, $body]);
        } catch (PDOException $e) {
            error_log("Messages::send — " . $e->getMessage());
            return false;
        }
    }

    /** Check user is part of conversation */
    public function isMember(int $convId, int $userId): bool {
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM conversations WHERE id=? AND (user_a=? OR user_b=?)");
            $stmt->execute([$convId, $userId, $userId]);
            return (bool)$stmt->fetch();
        } catch (PDOException $e) {
            return false;
        }
    }
}
