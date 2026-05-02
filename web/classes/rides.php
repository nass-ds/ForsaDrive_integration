<?php
class Ride {
    private $pdo;
    private $id;
    private $driverId;
    private $fromLocation;
    private $toLocation;
    private $departureTime;
    private $price;
    private $availableSeats;
    private $status;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function load(int $rideId): bool {
        if ($rideId <= 0) return false;
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM rides WHERE id = :id");
            $stmt->bindParam(':id', $rideId, PDO::PARAM_INT);
            $stmt->execute();
            if ($ride = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->id             = $ride['id'];
                $this->driverId       = $ride['driver_id'];
                $this->fromLocation   = $ride['from_location'];
                $this->toLocation     = $ride['to_location'];
                $this->departureTime  = $ride['departure_time'];
                $this->price          = $ride['price'];
                $this->availableSeats = $ride['available_seats'];
                $this->status         = $ride['status'];
                return true;
            }
            return false;
        } catch (PDOException $e) {
            error_log("Ride::load — " . $e->getMessage());
            return false;
        }
    }

    public function getBookedSeats(int $rideId): int {
        if ($rideId <= 0) return 0;
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(seats),0) FROM bookings WHERE ride_id=? AND status IN ('confirmed','completed')"
            );
            $stmt->execute([$rideId]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    /** Available rides with driver info and seat count */
    public function getAvailableRides(array $filters = []): array {
        try {
            $where  = ["r.status = 'active'", "(r.available_seats - COALESCE(b.booked_seats,0)) > 0"];
            $params = [];

            if (!empty($filters['from'])) {
                $where[]  = "LOWER(r.from_location) LIKE ?";
                $params[] = '%' . strtolower($filters['from']) . '%';
            }
            if (!empty($filters['to'])) {
                $where[]  = "LOWER(r.to_location) LIKE ?";
                $params[] = '%' . strtolower($filters['to']) . '%';
            }
            if (!empty($filters['date'])) {
                $where[]  = "DATE(r.departure_time) = ?";
                $params[] = $filters['date'];
            }
            if (!empty($filters['seats'])) {
                $where[]  = "(r.available_seats - COALESCE(b.booked_seats,0)) >= ?";
                $params[] = (int)$filters['seats'];
            }

            $whereStr = 'WHERE ' . implode(' AND ', $where);
            $orderBy  = match($filters['sort'] ?? '') {
                'price_asc'   => 'r.price ASC',
                'price_desc'  => 'r.price DESC',
                'seats'       => 'seats_left DESC',
                default       => 'r.departure_time ASC',
            };

            $sql = "
                SELECT r.*,
                       (r.available_seats - COALESCE(b.booked_seats, 0)) AS seats_left,
                       u.username  AS driver_name,
                       u.score     AS driver_score,
                       u.picture   AS driver_pic,
                       v.type      AS vehicle_type
                FROM rides r
                LEFT JOIN (
                    SELECT ride_id, SUM(seats) AS booked_seats
                    FROM bookings WHERE status IN ('confirmed','completed')
                    GROUP BY ride_id
                ) b ON b.ride_id = r.id
                JOIN users u ON u.id = r.driver_id
                LEFT JOIN vehicles v ON v.id = r.vehicle_id
                $whereStr
                ORDER BY $orderBy
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Ride::getAvailableRides — " . $e->getMessage());
            return [];
        }
    }

    /** Rides booked by a passenger (with booking info) */
    public function getUserRides(int $userId): array {
        if ($userId <= 0) return [];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT r.*, bk.id AS booking_id, bk.status AS booking_status,
                        bk.seats AS booked_seats, bk.paid_amount,
                        u.username AS driver_name, u.score AS driver_score
                 FROM rides r
                 JOIN bookings bk ON bk.ride_id = r.id
                 JOIN users u ON u.id = r.driver_id
                 WHERE bk.passenger_id = ?
                 ORDER BY r.departure_time DESC"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Ride::getUserRides — " . $e->getMessage());
            return [];
        }
    }

    /** Rides created by a driver */
    public function getDriverRides(int $driverId): array {
        if ($driverId <= 0) return [];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT r.*,
                        (r.available_seats - COALESCE(b.booked_seats,0)) AS seats_left,
                        COALESCE(b.booked_seats,0) AS booked_count
                 FROM rides r
                 LEFT JOIN (
                     SELECT ride_id, SUM(seats) AS booked_seats
                     FROM bookings WHERE status IN ('confirmed','completed')
                     GROUP BY ride_id
                 ) b ON b.ride_id = r.id
                 WHERE r.driver_id = ?
                 ORDER BY r.departure_time DESC"
            );
            $stmt->execute([$driverId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    /** Pending booking requests for all rides of a driver */
    public function getDriverBookingRequests(int $driverId): array {
        if ($driverId <= 0) return [];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT bk.*, r.from_location, r.to_location, r.departure_time, r.price,
                        p.username AS passenger_name, p.score AS passenger_score, p.picture AS passenger_pic,
                        p.is_student
                 FROM bookings bk
                 JOIN rides r ON r.id = bk.ride_id
                 JOIN users p ON p.id = bk.passenger_id
                 WHERE r.driver_id = ?
                   AND bk.status = 'pending'
                 ORDER BY bk.created_at ASC"
            );
            $stmt->execute([$driverId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Ride::getDriverBookingRequests — " . $e->getMessage());
            return [];
        }
    }

    /** Driver accept a booking */
    public function acceptBooking(int $bookingId, int $driverId): bool {
        try {
            $this->pdo->beginTransaction();
            // Verify booking belongs to driver's ride
            $stmt = $this->pdo->prepare(
                "SELECT bk.*, r.available_seats,
                        (r.available_seats - COALESCE(b2.cnt,0)) AS seats_left
                 FROM bookings bk
                 JOIN rides r ON r.id = bk.ride_id
                 LEFT JOIN (SELECT ride_id, SUM(seats) cnt FROM bookings WHERE status='confirmed' GROUP BY ride_id) b2
                   ON b2.ride_id = r.id
                 WHERE bk.id=? AND r.driver_id=? AND bk.status='pending'"
            );
            $stmt->execute([$bookingId, $driverId]);
            $bk = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$bk) { $this->pdo->rollBack(); return false; }
            if ($bk['seats_left'] < $bk['seats']) { $this->pdo->rollBack(); return false; }

            $this->pdo->prepare("UPDATE bookings SET status='confirmed' WHERE id=?")->execute([$bookingId]);
            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Ride::acceptBooking — " . $e->getMessage());
            return false;
        }
    }

    /** Driver reject a booking */
    public function rejectBooking(int $bookingId, int $driverId): bool {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE bookings SET status='cancelled'
                 WHERE id=? AND status='pending'
                   AND ride_id IN (SELECT id FROM rides WHERE driver_id=?)"
            );
            return $stmt->execute([$bookingId, $driverId]) && $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Ride::rejectBooking — " . $e->getMessage());
            return false;
        }
    }

    /** Cancel a booking (by passenger) */
    public function cancelBooking(int $bookingId, int $passengerId): bool {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE bookings SET status='cancelled' WHERE id=? AND passenger_id=? AND status IN ('pending','confirmed')"
            );
            return $stmt->execute([$bookingId, $passengerId]) && $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /** Mark booking as completed */
    public function completeBooking(int $bookingId, int $driverId): bool {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE bookings SET status='completed'
                 WHERE id=? AND status='confirmed'
                   AND ride_id IN (SELECT id FROM rides WHERE driver_id=?)"
            );
            return $stmt->execute([$bookingId, $driverId]) && $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    public function getUpcomingRides(int $userId): array {
        if ($userId <= 0) return [];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT r.*, bk.id AS booking_id, bk.status AS booking_status,
                        u.username AS driver_name, u.score AS driver_score
                 FROM rides r
                 JOIN bookings bk ON r.id = bk.ride_id
                 JOIN users u ON u.id = r.driver_id
                 WHERE bk.passenger_id = ?
                   AND r.departure_time > datetime('now')
                   AND r.status = 'active'
                   AND bk.status IN ('pending','confirmed')
                 ORDER BY r.departure_time ASC"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getCompletedRidesCount(int $userId): int {
        if ($userId <= 0) return 0;
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM bookings WHERE passenger_id=? AND status='completed'"
            );
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    public function createRide(int $driverId, string $from, string $to, string $date, string $time, int $seats, float $price): bool {
        try {
            $dt = trim($date . ' ' . $time);
            $stmt = $this->pdo->prepare(
                "INSERT INTO rides (driver_id, vehicle_id, from_location, to_location, departure_time, price, available_seats, status)
                 VALUES (?,1,?,?,?,?,?,'active')"
            );
            return $stmt->execute([$driverId, $from, $to, $dt, $price, $seats]);
        } catch (PDOException $e) {
            error_log("Ride::createRide — " . $e->getMessage());
            return false;
        }
    }

    public function cancelRide(int $rideId, int $driverId): bool {
        try {
            $stmt = $this->pdo->prepare("UPDATE rides SET status='cancelled' WHERE id=? AND driver_id=?");
            return $stmt->execute([$rideId, $driverId]) && $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    // Getters
    public function getId(): ?int        { return $this->id; }
    public function getDriverId(): ?int  { return $this->driverId; }
    public function getFromLocation(): ?string { return $this->fromLocation; }
    public function getToLocation(): ?string   { return $this->toLocation; }
    public function getDepartureTime(): ?string { return $this->departureTime; }
    public function getPrice(): ?float   { return $this->price; }
    public function getAvailableSeats(): ?int { return $this->availableSeats; }
    public function getStatus(): ?string { return $this->status; }
}
