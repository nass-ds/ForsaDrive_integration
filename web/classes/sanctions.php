<?php
/**
 * Progressive admin sanctions (spec §2.4).
 *
 * Escalation ladder: Warning → Suspension (7–30 days) → Ban (permanent).
 *
 * State lives on the users row:
 *   - suspended         1 while the account is locked out (suspension or ban).
 *   - suspended_until    end of a temporary suspension; NULL = permanent ban.
 *                        A past value is an expired suspension and is lifted
 *                        automatically on the next login (expire-on-read).
 *   - warnings_count     running tally, used to suggest the next step.
 *
 * Every action also appends a row to user_sanctions, the per-user audit trail.
 *
 * The static enforcement helpers (applyExpiry / lockoutMessage) carry no
 * dependency on Notifications so the login paths can reuse them cheaply.
 */
class Sanctions {
    public const MIN_SUSPENSION_DAYS = 7;
    public const MAX_SUSPENSION_DAYS = 30;

    private PDO $db;
    private $notif;  // Notifications|null

    public function __construct(PDO $db, $notif = null) {
        $this->db    = $db;
        $this->notif = $notif;
    }

    // ── Enforcement (static, dependency-free) ────────────────────────────────

    /**
     * Lift an expired temporary suspension in place. Mutates $user and the DB so
     * a user whose 7–30 day window has elapsed can log in again. No-op for active
     * suspensions, permanent bans, and unsanctioned accounts.
     */
    public static function applyExpiry(PDO $db, array &$user): void {
        if (empty($user['suspended']) || empty($user['suspended_until'])) {
            return;
        }
        $stmt = $db->prepare(
            "UPDATE users SET suspended=0, suspended_until=NULL, ban_reason=NULL
             WHERE id=? AND suspended=1 AND suspended_until IS NOT NULL
                   AND suspended_until <= datetime('now')"
        );
        $stmt->execute([$user['id']]);
        if ($stmt->rowCount() > 0) {
            self::log($db, (int)$user['id'], null, 'lift', 'Suspension period elapsed', null, null);
            $user['suspended']       = 0;
            $user['suspended_until'] = null;
            $user['ban_reason']      = null;
        }
    }

    /**
     * Message to show a locked-out user, or null if they may log in.
     * Call applyExpiry() first so elapsed suspensions are already cleared.
     */
    public static function lockoutMessage(array $user): ?string {
        if (empty($user['suspended'])) {
            return null;
        }
        $reason = trim((string)($user['ban_reason'] ?? '')) ?: 'No reason provided.';
        if (empty($user['suspended_until'])) {
            return "Your account has been permanently banned. Reason: {$reason}";
        }
        $until = date('d M Y, H:i', strtotime((string)$user['suspended_until'])) . ' UTC';
        return "Your account is suspended until {$until}. Reason: {$reason}";
    }

    // ── Admin actions ────────────────────────────────────────────────────────

    public function warn(int $userId, ?int $adminId, string $reason): void {
        $reason = $reason !== '' ? $reason : 'Policy violation';
        $this->db->prepare(
            "UPDATE users SET warnings_count = COALESCE(warnings_count,0) + 1 WHERE id=? AND is_admin=0"
        )->execute([$userId]);
        self::log($this->db, $userId, $adminId, 'warning', $reason, null, null);
        $this->notify($userId, 'Warning Issued ⚠️',
            "You have received a formal warning. Reason: {$reason}. Repeated violations may lead to a temporary suspension.",
            'warning');
    }

    /** Returns the clamped number of days actually applied. */
    public function suspend(int $userId, ?int $adminId, int $days, string $reason): int {
        $days   = max(self::MIN_SUSPENSION_DAYS, min(self::MAX_SUSPENSION_DAYS, $days));
        $reason = $reason !== '' ? $reason : 'Policy violation';
        $this->db->prepare(
            "UPDATE users SET suspended=1, suspended_until=datetime('now', ?), ban_reason=?
             WHERE id=? AND is_admin=0"
        )->execute(["+{$days} days", $reason, $userId]);

        $exp = $this->db->prepare("SELECT suspended_until FROM users WHERE id=?");
        $exp->execute([$userId]);
        $expiresAt = $exp->fetchColumn() ?: null;

        self::log($this->db, $userId, $adminId, 'suspension', $reason, $days, $expiresAt);
        $this->notify($userId, 'Account Suspended ⛔',
            "Your account has been suspended for {$days} days. Reason: {$reason}. A further violation may result in a permanent ban.",
            'danger');
        return $days;
    }

    public function ban(int $userId, ?int $adminId, string $reason): void {
        $reason = $reason !== '' ? $reason : 'Policy violation';
        $this->db->prepare(
            "UPDATE users SET suspended=1, suspended_until=NULL, ban_reason=? WHERE id=? AND is_admin=0"
        )->execute([$reason, $userId]);
        self::log($this->db, $userId, $adminId, 'ban', $reason, null, null);
        $this->notify($userId, 'Account Banned 🚫',
            "Your account has been permanently banned. Reason: {$reason}.", 'danger');
    }

    public function lift(int $userId, ?int $adminId): void {
        $this->db->prepare(
            "UPDATE users SET suspended=0, suspended_until=NULL, ban_reason=NULL WHERE id=?"
        )->execute([$userId]);
        self::log($this->db, $userId, $adminId, 'lift', 'Reinstated by admin', null, null);
        $this->notify($userId, 'Account Reinstated ✅',
            'Your account has been reinstated. Welcome back!', 'success');
    }

    // ── Read helpers ─────────────────────────────────────────────────────────

    /** Most recent sanction entries for a user (newest first). */
    public function history(int $userId, int $limit = 20): array {
        $stmt = $this->db->prepare(
            "SELECT s.*, a.username AS admin_name
             FROM user_sanctions s
             LEFT JOIN users a ON a.id = s.admin_id
             WHERE s.user_id = ?
             ORDER BY s.created_at DESC
             LIMIT ?"
        );
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Suggest the next step on the ladder given current state.
     * Returns one of 'warning' | 'suspension' | 'ban'.
     */
    public static function suggestNext(array $user): string {
        if (!empty($user['suspended']) && empty($user['suspended_until'])) {
            return 'ban';                          // already banned — nothing harsher
        }
        $hasServedSuspension = self::countOfType($user, 'suspension') > 0;
        if ($hasServedSuspension) return 'ban';
        if ((int)($user['warnings_count'] ?? 0) >= 1) return 'suspension';
        return 'warning';
    }

    /** Optional precomputed per-type counts may be attached to $user as _sanction_counts. */
    private static function countOfType(array $user, string $type): int {
        $counts = $user['_sanction_counts'] ?? [];
        return (int)($counts[$type] ?? 0);
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    private static function log(PDO $db, int $userId, ?int $adminId, string $type,
                                ?string $reason, ?int $days, ?string $expiresAt): void {
        try {
            $db->prepare(
                "INSERT INTO user_sanctions (user_id, admin_id, type, reason, days, expires_at)
                 VALUES (?,?,?,?,?,?)"
            )->execute([$userId, $adminId, $type, $reason, $days, $expiresAt]);
        } catch (PDOException $e) {
            error_log("Sanctions::log — " . $e->getMessage());
        }
    }

    private function notify(int $userId, string $title, string $body, string $type): void {
        if ($this->notif) {
            $this->notif->create($userId, $title, $body, $type);
        }
    }
}
