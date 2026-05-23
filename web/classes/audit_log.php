<?php
/**
 * Append-only audit trail of admin actions (spec §2.4).
 * Every mutating action in the admin console is recorded here so there's a
 * who/what/when record independent of the affected row.
 */
class AuditLog {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function log(?int $adminId, string $action, ?string $targetType = null,
                        ?int $targetId = null, ?string $summary = null): void {
        try {
            $this->db->prepare(
                "INSERT INTO audit_logs (admin_id, action, target_type, target_id, summary)
                 VALUES (?,?,?,?,?)"
            )->execute([$adminId, $action, $targetType, $targetId, $summary]);
        } catch (PDOException $e) {
            error_log("AuditLog::log — " . $e->getMessage());
        }
    }

    public function recent(int $limit = 100): array {
        $stmt = $this->db->prepare(
            "SELECT a.*, u.username AS admin_name
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.admin_id
             ORDER BY a.created_at DESC
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
