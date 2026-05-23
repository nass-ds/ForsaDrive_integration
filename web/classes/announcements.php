<?php
/**
 * Broadcast announcements (spec §2.4).
 *
 * An admin composes a message for an audience (everyone / drivers / students);
 * it's recorded in `announcements` and fanned out to each recipient as a normal
 * notification, so it shows up in the existing in-app notification feed on both
 * the web and the mobile app (shared DB) without any extra client work.
 */
class Announcement {
    public const AUDIENCES = ['all', 'drivers', 'students'];

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    private function audienceWhere(string $audience): string {
        return match ($audience) {
            'drivers'  => 'is_driver = 1 AND is_admin = 0',
            'students' => 'is_student = 1 AND is_admin = 0',
            default    => 'is_admin = 0',
        };
    }

    public static function audienceLabel(string $audience): string {
        return match ($audience) {
            'drivers'  => 'Drivers',
            'students' => 'Students',
            default    => 'All users',
        };
    }

    /**
     * Send to the chosen audience. Returns the number of recipients reached.
     * Title is prefixed with a megaphone so it stands out in the feed.
     */
    public function broadcast(?int $adminId, string $title, string $body, string $audience): int {
        $title = trim($title);
        $body  = trim($body);
        if ($title === '' || $body === '') return 0;
        if (!in_array($audience, self::AUDIENCES, true)) $audience = 'all';

        $where     = $this->audienceWhere($audience);
        $notifTitle = '📢 ' . $title;

        // One statement fans the announcement out to every targeted user.
        $stmt = $this->db->prepare(
            "INSERT INTO notifications (user_id, type, title, body)
             SELECT id, 'info', ?, ? FROM users WHERE $where"
        );
        $stmt->execute([$notifTitle, $body]);
        $count = $stmt->rowCount();

        $this->db->prepare(
            "INSERT INTO announcements (admin_id, title, body, audience, sent_count)
             VALUES (?,?,?,?,?)"
        )->execute([$adminId, $title, $body, $audience, $count]);

        return $count;
    }

    public function recent(int $limit = 50): array {
        $stmt = $this->db->prepare(
            "SELECT an.*, u.username AS admin_name
             FROM announcements an
             LEFT JOIN users u ON u.id = an.admin_id
             ORDER BY an.created_at DESC
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
