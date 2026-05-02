<?php

class Rating {
    private PDO $pdo;
    public $id;
    public $ride_id;
    public $from_user_id;
    public $to_user_id;
    public $score;
    public $comment;
    public $created_at;

    public function __construct(PDO $pdo = null, $id = null, $ride_id = null, $from_user_id = null,
                                $to_user_id = null, $score = null, $comment = null) {
        $this->pdo          = $pdo;
        $this->id           = $id;
        $this->ride_id      = $ride_id;
        $this->from_user_id = $from_user_id;
        $this->to_user_id   = $to_user_id;
        $this->score        = $score;
        $this->comment      = $comment;
        $this->created_at   = date('Y-m-d H:i:s');
    }

    /** Ratings received by a user */
    public function getUserRatings(int $userId): array {
        if (!$this->pdo || $userId <= 0) return [];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT r.*, u.username AS rater_name
                 FROM ratings r
                 JOIN users u ON u.id = r.from_user_id
                 WHERE r.to_user_id = ?
                 ORDER BY r.created_at DESC
                 LIMIT 50"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getUserRatings: " . $e->getMessage());
            return [];
        }
    }

    /** Ratings given by a user */
    public function getGivenRatings(int $userId): array {
        if (!$this->pdo || $userId <= 0) return [];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT r.*, u.username AS rated_name
                 FROM ratings r
                 JOIN users u ON u.id = r.to_user_id
                 WHERE r.from_user_id = ?
                 ORDER BY r.created_at DESC
                 LIMIT 50"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getGivenRatings: " . $e->getMessage());
            return [];
        }
    }

    /** Check if user already rated a ride */
    public function hasRated(int $rideId, int $fromUserId): bool {
        if (!$this->pdo) return false;
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM ratings WHERE ride_id=? AND from_user_id=?");
            $stmt->execute([$rideId, $fromUserId]);
            return (bool)$stmt->fetch();
        } catch (PDOException $e) {
            return false;
        }
    }

    /** Save rating and update driver avg_rating */
    public function save(): bool {
        if (!$this->pdo) return false;
        if ($this->score < 1 || $this->score > 5) return false;
        if (!$this->ride_id || !$this->from_user_id || !$this->to_user_id) return false;

        try {
            $this->pdo->beginTransaction();

            if ($this->id) {
                $stmt = $this->pdo->prepare(
                    "UPDATE ratings SET score=?, comment=? WHERE id=?"
                );
                $stmt->execute([$this->score, $this->comment, $this->id]);
            } else {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO ratings (ride_id, from_user_id, to_user_id, score, comment, created_at)
                     VALUES (?,?,?,?,?,?)"
                );
                $stmt->execute([
                    $this->ride_id, $this->from_user_id, $this->to_user_id,
                    $this->score, $this->comment, $this->created_at
                ]);
                $this->id = $this->pdo->lastInsertId();
            }

            // Update avg_rating in driver_profiles (if recipient is a driver)
            $stmt = $this->pdo->prepare(
                "UPDATE driver_profiles
                 SET avg_rating = (
                     SELECT AVG(CAST(score AS REAL)) FROM ratings WHERE to_user_id = ?
                 ),
                 total_trips = (
                     SELECT COUNT(DISTINCT ride_id) FROM bookings
                     WHERE passenger_id = ? AND status = 'completed'
                 )
                 WHERE user_id = ?"
            );
            $stmt->execute([$this->to_user_id, $this->to_user_id, $this->to_user_id]);

            // Also update users.score
            $stmt = $this->pdo->prepare(
                "UPDATE users SET score = (SELECT AVG(CAST(score AS REAL)) FROM ratings WHERE to_user_id = ?)
                 WHERE id = ?"
            );
            $stmt->execute([$this->to_user_id, $this->to_user_id]);

            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Rating::save — " . $e->getMessage());
            return false;
        }
    }

    public static function find(PDO $pdo, int $id): ?Rating {
        try {
            $stmt = $pdo->prepare("SELECT * FROM ratings WHERE id=?");
            $stmt->execute([$id]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                return new Rating($pdo, $row['id'], $row['ride_id'], $row['from_user_id'],
                    $row['to_user_id'], $row['score'], $row['comment']);
            }
        } catch (PDOException $e) {
            error_log("Rating::find — " . $e->getMessage());
        }
        return null;
    }

    /** Average rating for a user */
    public function getAvgRating(int $userId): float {
        if (!$this->pdo) return 5.0;
        try {
            $stmt = $this->pdo->prepare("SELECT AVG(CAST(score AS REAL)) FROM ratings WHERE to_user_id=?");
            $stmt->execute([$userId]);
            return round((float)$stmt->fetchColumn() ?: 5.0, 1);
        } catch (PDOException $e) {
            return 5.0;
        }
    }
}
