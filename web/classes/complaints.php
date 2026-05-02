<?php

class Complaints {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function submit(int $fromUserId, ?int $againstUserId, ?int $rideId, string $type, string $description, ?string $evidencePath = null): bool {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO complaints (from_user_id, against_user_id, ride_id, type, description, evidence, status, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,'open',datetime('now'),datetime('now'))"
            );
            return $stmt->execute([$fromUserId, $againstUserId, $rideId, $type, $description, $evidencePath]);
        } catch (PDOException $e) {
            error_log("Complaints::submit — " . $e->getMessage());
            return false;
        }
    }

    public function getUserComplaints(int $userId): array {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT c.*,
                        u.username AS against_name,
                        r.from_location, r.to_location
                 FROM complaints c
                 LEFT JOIN users u ON u.id = c.against_user_id
                 LEFT JOIN rides r ON r.id = c.ride_id
                 WHERE c.from_user_id = ?
                 ORDER BY c.created_at DESC"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Complaints::getUserComplaints — " . $e->getMessage());
            return [];
        }
    }

    public function getAllComplaints(string $status = ''): array {
        try {
            $where = $status ? "WHERE c.status = ?" : '';
            $params = $status ? [$status] : [];
            $stmt = $this->pdo->prepare(
                "SELECT c.*,
                        fu.username AS from_name,
                        au.username AS against_name,
                        r.from_location, r.to_location
                 FROM complaints c
                 LEFT JOIN users fu ON fu.id = c.from_user_id
                 LEFT JOIN users au ON au.id = c.against_user_id
                 LEFT JOIN rides r  ON r.id  = c.ride_id
                 $where
                 ORDER BY c.created_at DESC"
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Complaints::getAllComplaints — " . $e->getMessage());
            return [];
        }
    }

    public function updateStatus(int $id, string $status, string $note = ''): bool {
        $allowed = ['open','in_review','resolved','dismissed'];
        if (!in_array($status, $allowed)) return false;
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE complaints SET status=?, admin_note=?, updated_at=datetime('now') WHERE id=?"
            );
            return $stmt->execute([$status, $note, $id]);
        } catch (PDOException $e) {
            error_log("Complaints::updateStatus — " . $e->getMessage());
            return false;
        }
    }
}
