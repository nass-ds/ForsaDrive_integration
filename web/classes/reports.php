<?php
/**
 * ReportService — create/list/manage user reports keyed by public ForsaDrive ID.
 *
 * - A normal user creates reports with `reported_public_id`. The service
 *   resolves it to the internal `reported_user_id` server-side; the API never
 *   trusts a client-supplied user id.
 * - A normal user only sees their OWN submitted reports.
 * - Admin & HelpDesk Agents see every report and can update status.
 * - Self-reports are rejected.
 */
final class ReportService
{
    public const CATEGORIES = [
        'bad_behavior'   => 'Bad behavior',
        'fake_profile'   => 'Fake profile',
        'unsafe_ride'    => 'Unsafe ride',
        'payment_issue'  => 'Payment issue',
        'late_driver'    => 'Late driver',
        'wrong_vehicle'  => 'Wrong vehicle',
        'harassment'     => 'Harassment',
        'no_show'        => 'No show',
        'other'          => 'Other',
    ];

    public const STATUSES = ['pending', 'reviewing', 'resolved', 'rejected'];

    /**
     * Create a report. Returns [ok, message, reportId].
     */
    public static function create(
        PDO $pdo,
        int $reporterId,
        string $reportedPublicId,
        string $category,
        string $description,
        ?int $rideId = null
    ): array {
        require_once __DIR__ . '/publicid.php';

        $reportedPublicId = PublicIdService::normalize($reportedPublicId);
        if ($reportedPublicId === '') {
            return [false, 'ForsaDrive ID is required.', null];
        }
        if (!isset(self::CATEGORIES[$category])) {
            return [false, 'Invalid report category.', null];
        }
        $description = trim($description);
        if ($description === '') {
            return [false, 'Please describe the issue.', null];
        }
        if (strlen($description) > 2000) {
            return [false, 'Description must be 2000 characters or less.', null];
        }

        $target = PublicIdService::findUser($pdo, $reportedPublicId);
        if (!$target) {
            // Spec: report still allowed by public_id even after 48h, but the
            // ID must actually exist. Surface a friendly error.
            return [false, 'No ForsaDrive user has that ID.', null];
        }
        if ((int)$target['id'] === $reporterId) {
            return [false, 'You cannot report yourself.', null];
        }

        // ride_id is optional; only accept it if it actually involves both users
        // (passenger-driver pair) to prevent attaching random rides.
        $boundRideId = null;
        if ($rideId !== null) {
            $check = $pdo->prepare("
                SELECT 1 FROM rides r
                LEFT JOIN bookings b ON b.ride_id = r.id
                WHERE r.id = :ride
                  AND (
                       (r.driver_id = :reporter AND b.passenger_id = :target)
                    OR (r.driver_id = :target   AND b.passenger_id = :reporter)
                  )
                LIMIT 1
            ");
            $check->execute([
                ':ride'     => $rideId,
                ':reporter' => $reporterId,
                ':target'   => (int)$target['id'],
            ]);
            if ($check->fetchColumn()) {
                $boundRideId = $rideId;
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO reports
                (reporter_id, reported_user_id, reported_public_id, ride_id,
                 category, description, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', datetime('now'), datetime('now'))
        ");
        $stmt->execute([
            $reporterId,
            (int)$target['id'],
            $reportedPublicId,
            $boundRideId,
            $category,
            $description,
        ]);
        $id = (int)$pdo->lastInsertId();
        return [true, 'Report submitted. Our team will review it.', $id];
    }

    /** Reports submitted BY the given user (own-history view). */
    public static function listMine(PDO $pdo, int $reporterId, int $limit = 100): array
    {
        $stmt = $pdo->prepare("
            SELECT r.id, r.reported_public_id, r.category, r.description, r.status,
                   r.created_at, r.updated_at, r.ride_id
            FROM reports r
            WHERE r.reporter_id = ?
            ORDER BY r.created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $reporterId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Admin/helpdesk: list all reports, optionally filter by status / public_id. */
    public static function listAll(PDO $pdo, array $filters = []): array
    {
        $where  = [];
        $params = [];
        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where[] = 'r.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['public_id'])) {
            require_once __DIR__ . '/publicid.php';
            $where[] = 'r.reported_public_id = :pub';
            $params[':pub'] = PublicIdService::normalize($filters['public_id']);
        }
        $clause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sql = "
            SELECT r.*,
                   reporter.username AS reporter_username,
                   reporter.public_id AS reporter_public_id,
                   reported.username AS reported_username
            FROM reports r
            LEFT JOIN users reporter ON reporter.id = r.reporter_id
            LEFT JOIN users reported ON reported.id = r.reported_user_id
            $clause
            ORDER BY
              CASE r.status WHEN 'pending' THEN 0 WHEN 'reviewing' THEN 1 ELSE 2 END,
              r.created_at DESC
            LIMIT 500
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Reports concerning a specific reported_user_id — admin/helpdesk only. */
    public static function listAgainst(PDO $pdo, int $targetUserId): array
    {
        $stmt = $pdo->prepare("
            SELECT r.*, reporter.username AS reporter_username,
                   reporter.public_id AS reporter_public_id
            FROM reports r
            LEFT JOIN users reporter ON reporter.id = r.reporter_id
            WHERE r.reported_user_id = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$targetUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Admin/helpdesk action — change a report's status with optional note. */
    public static function updateStatus(
        PDO $pdo,
        int $reportId,
        int $adminId,
        string $newStatus,
        string $note = ''
    ): array {
        if (!in_array($newStatus, self::STATUSES, true)) {
            return [false, 'Invalid status.'];
        }
        $stmt = $pdo->prepare("
            UPDATE reports
            SET status = ?, admin_note = ?, handled_by = ?, handled_at = datetime('now'),
                updated_at = datetime('now')
            WHERE id = ?
        ");
        $stmt->execute([$newStatus, $note ?: null, $adminId, $reportId]);
        return [$stmt->rowCount() > 0, $stmt->rowCount() > 0 ? 'Report updated.' : 'Report not found.'];
    }

    /** Human label for a category code, e.g. for emails / UI. */
    public static function categoryLabel(string $code): string
    {
        return self::CATEGORIES[$code] ?? ucfirst(str_replace('_', ' ', $code));
    }
}
