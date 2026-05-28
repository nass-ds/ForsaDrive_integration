<?php
/**
 * ProfileAccessService — decides what a viewer is allowed to see about a target.
 *
 * Spec rules (identification feature):
 *   - Before payment / confirmed booking → LIMITED profile only.
 *   - Active confirmed ride OR up to 48h after completion → FULL profile.
 *   - After 48h → back to LIMITED.
 *   - Admin / HelpDesk Agent → FULL profile, no time gate.
 *   - Reports always allowed by public_id; never expose private data via report path.
 *
 * Backend MUST enforce these. Frontend hides UI but security is here.
 */
final class ProfileAccessService
{
    /** Hours after completion during which full profile remains visible. */
    public const POST_RIDE_WINDOW_HOURS = 48;

    /**
     * Does `viewer` have full-profile access to `target`?
     *
     * @param array   $viewer  user row (must include id, is_admin, is_helpdesk_agent)
     * @param array   $target  user row (the person being looked at)
     * @param int|null $rideId optional ride_id binding the access; if omitted the
     *                         service searches for ANY qualifying confirmed booking
     *                         between the two users.
     */
    public static function canViewFull(PDO $pdo, array $viewer, array $target, ?int $rideId = null): bool
    {
        // Self → always full.
        if ((int)$viewer['id'] === (int)$target['id']) return true;
        // Admin / helpdesk override — they manage reports & sanctions and need
        // the full picture; never time-gated.
        if (!empty($viewer['is_admin']) || !empty($viewer['is_helpdesk_agent'])) return true;

        // The viewer must have either booked a ride from the target (target=driver),
        // or be the driver of a ride that the target booked (target=passenger).
        // Either way, payment status must be confirmed/completed and within the
        // 48-hour window if the ride is already completed.
        $params = [
            ':viewer' => (int)$viewer['id'],
            ':target' => (int)$target['id'],
            ':hours'  => self::POST_RIDE_WINDOW_HOURS,
        ];
        $rideClause = '';
        if ($rideId !== null) {
            $rideClause = ' AND r.id = :ride';
            $params[':ride'] = $rideId;
        }
        $sql = "
            SELECT 1
            FROM bookings b
            JOIN rides r ON r.id = b.ride_id
            WHERE b.status IN ('confirmed', 'completed')
              AND (
                    (b.passenger_id = :viewer AND r.driver_id    = :target)
                 OR (b.passenger_id = :target AND r.driver_id    = :viewer)
              )
              AND (
                    r.status IN ('confirmed', 'in_progress', 'active', 'open')
                 OR (r.status = 'completed' AND COALESCE(r.completed_at, r.departure_time)
                     > datetime('now', printf('-%d hours', :hours)))
              )
              $rideClause
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Limited public profile — safe to share before payment / booking.
     * Strict allowlist: only the fields the spec permits.
     */
    public static function limitedProfile(PDO $pdo, array $u): array
    {
        $firstName = self::firstNameOnly($u['username'] ?? '');
        // Number of completed rides (as driver + as passenger), gives a trust signal.
        $completed = (int)$pdo->prepare("
            SELECT (
              (SELECT COUNT(*) FROM bookings b WHERE b.passenger_id = :uid AND b.status = 'completed')
            + (SELECT COUNT(*) FROM rides r    WHERE r.driver_id    = :uid AND r.status = 'completed')
            ) AS n
        ");
        $completed->execute([':uid' => (int)$u['id']]);
        $completedRides = (int)$completed->fetchColumn();

        // Verified badge: driver licence+vehicle verified, or student verified.
        $verified = false;
        if (!empty($u['is_driver'])) {
            $vs = $pdo->prepare("SELECT 1 FROM vehicles WHERE user_id = ? AND verified = 1 LIMIT 1");
            $vs->execute([(int)$u['id']]);
            $verified = (bool)$vs->fetchColumn();
        } elseif (!empty($u['is_student_verified'])) {
            $verified = true;
        }

        // Vehicle make/model — published, not private. Plate stays in full profile.
        $vehicle = null;
        if (!empty($u['is_driver'])) {
            $vq = $pdo->prepare("SELECT make, model FROM vehicles WHERE user_id = ? ORDER BY id DESC LIMIT 1");
            $vq->execute([(int)$u['id']]);
            $vrow = $vq->fetch(PDO::FETCH_ASSOC);
            if ($vrow) {
                $vehicle = [
                    'make'  => $vrow['make']  ?: null,
                    'model' => $vrow['model'] ?: null,
                ];
            }
        }

        return [
            'public_id'        => (string)($u['public_id'] ?? ''),
            'first_name'       => $firstName,
            'picture'          => $u['picture'] ?? null,
            'score'            => (float)($u['score'] ?? 5.0),
            'completed_rides'  => $completedRides,
            'is_driver'        => (bool)($u['is_driver'] ?? false),
            'is_verified'      => $verified,
            'vehicle'          => $vehicle, // make/model only — no plate
            'access_level'     => 'limited',
        ];
    }

    /**
     * Full ride-related profile. Caller MUST have checked canViewFull() first.
     * Includes contact + plate; never includes documents (those are admin-only).
     */
    public static function fullProfile(PDO $pdo, array $u, ?int $rideId = null): array
    {
        $limited = self::limitedProfile($pdo, $u);
        // Plate is part of full profile when the role is driver.
        $plate = null;
        if (!empty($u['is_driver'])) {
            $vq = $pdo->prepare("SELECT plate_number, year, color FROM vehicles WHERE user_id = ? ORDER BY id DESC LIMIT 1");
            $vq->execute([(int)$u['id']]);
            $vrow = $vq->fetch(PDO::FETCH_ASSOC);
            if ($vrow) {
                $plate = $vrow['plate_number'] ?: null;
                if (is_array($limited['vehicle'])) {
                    $limited['vehicle']['plate'] = $plate;
                    $limited['vehicle']['year']  = $vrow['year']  ?? null;
                    $limited['vehicle']['color'] = $vrow['color'] ?? null;
                }
            }
        }
        return array_merge($limited, [
            'full_name'   => $u['username'] ?? null,
            'phone'       => $u['phone']    ?? null,
            'email'       => $u['email']    ?? null,
            'governorate' => $u['governorate'] ?? null,
            'ride_id'     => $rideId,
            'access_level' => 'full',
            'access_expires_at' => self::computeExpiry($pdo, (int)$u['id'], $rideId),
        ]);
    }

    /**
     * Return the moment when the current full-profile access window will end
     * for the given (target, ride). Null if there's no completed ride yet
     * (i.e. access is still tied to an active booking).
     */
    public static function computeExpiry(PDO $pdo, int $targetUserId, ?int $rideId): ?string
    {
        if (!$rideId) return null;
        $stmt = $pdo->prepare("SELECT status, completed_at, departure_time FROM rides WHERE id = ?");
        $stmt->execute([$rideId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        if ($r['status'] !== 'completed') return null;
        $ts = $r['completed_at'] ?: $r['departure_time'];
        if (!$ts) return null;
        try {
            $d = new DateTime($ts);
            $d->modify('+' . self::POST_RIDE_WINDOW_HOURS . ' hours');
            return $d->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }

    /** "Mohamed Ali" → "Mohamed". Used to avoid leaking surnames in limited view. */
    private static function firstNameOnly(string $full): string
    {
        $full = trim($full);
        if ($full === '') return '';
        $parts = preg_split('/\s+/', $full);
        return $parts[0] ?? $full;
    }
}
