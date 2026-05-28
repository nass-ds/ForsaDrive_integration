<?php
/**
 * HelpdeskAssignmentService
 *
 * One agent handles one active ticket at a time.
 *
 * Vocabulary:
 *   ELIGIBLE  = users with is_helpdesk_agent = 1 (allowed to receive tickets)
 *   AVAILABLE = ELIGIBLE AND has zero active tickets
 *   ACTIVE    = status IN ('open','assigned')
 *   FINISHED  = status IN ('resolved','closed')
 *
 * The DB is shared between the PHP web stack and the Dart mobile API; both can
 * auto-assign at the same time. Every write goes through a single guarded
 * conditional UPDATE that atomically enforces (a) the ticket is still
 * unassigned and (b) the chosen agent still has zero active tickets. If the
 * UPDATE affects zero rows, the agent was claimed by the other runtime and we
 * advance to the next candidate. The Dart helper mirrors this exact pattern.
 */
final class HelpdeskAssignmentService {

    private const ACTIVE_STATUSES_SQL = "('open','assigned')";

    /* ───────────────────────── Eligibility / availability ───────────────────── */

    /**
     * Single source of truth for "who is allowed to receive tickets".
     * Used by the auto-assign service AND the admin Reassign dropdown
     * AND the Retry fallback — so they can never diverge.
     */
    public static function getEligibleAgents(PDO $pdo): array {
        return $pdo->query(
            "SELECT id, username, email
             FROM users
             WHERE is_helpdesk_agent = 1
             ORDER BY username"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getAgentActiveTicketCount(PDO $pdo, int $agentId): int {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM helpdesk_conversations
             WHERE agent_id = ? AND status IN " . self::ACTIVE_STATUSES_SQL
        );
        $stmt->execute([$agentId]);
        return (int)$stmt->fetchColumn();
    }

    public static function isAgentAvailable(PDO $pdo, int $agentId): bool {
        return self::getAgentActiveTicketCount($pdo, $agentId) === 0;
    }

    /**
     * Candidate ordering for auto-assignment: AVAILABLE agents (zero active
     * tickets), prioritised by least-recently-assigned. Fresh agents (no
     * `assigned_at` history) go first so new hires get pulled into rotation.
     */
    public static function findAvailableAgents(PDO $pdo): array {
        return $pdo->query(
            "SELECT u.id, u.username, u.email,
                    (SELECT MAX(assigned_at) FROM helpdesk_conversations
                     WHERE agent_id = u.id) AS last_assigned
             FROM users u
             WHERE u.is_helpdesk_agent = 1
               AND NOT EXISTS (
                 SELECT 1 FROM helpdesk_conversations hc
                 WHERE hc.agent_id = u.id AND hc.status IN ('open','assigned')
               )
             ORDER BY (last_assigned IS NULL) DESC,
                      last_assigned ASC,
                      u.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ───────────────────────── Assignment ───────────────────────────────────── */

    /**
     * Auto-assign a ticket. Returns:
     *   ['result' => 'assigned'|'waiting'|'no_eligible'|'already_assigned'|'not_found',
     *    'agent'  => ?array]
     *
     * Single guarded UPDATE per candidate — see class doc for race semantics.
     */
    public static function autoAssign(PDO $pdo, int $ticketId): array {
        $ticketStmt = $pdo->prepare(
            "SELECT agent_id, status FROM helpdesk_conversations WHERE id = ?"
        );
        $ticketStmt->execute([$ticketId]);
        $current = $ticketStmt->fetch(PDO::FETCH_ASSOC);
        if ($current === false) {
            return ['result' => 'not_found', 'agent' => null];
        }
        if (!empty($current['agent_id'])) {
            return ['result' => 'already_assigned', 'agent' => null];
        }

        if (empty(self::getEligibleAgents($pdo))) {
            return ['result' => 'no_eligible', 'agent' => null];
        }

        $candidates = self::findAvailableAgents($pdo);
        if (empty($candidates)) {
            return ['result' => 'waiting', 'agent' => null];
        }

        $update = $pdo->prepare(
            "UPDATE helpdesk_conversations
             SET agent_id          = :agent_id,
                 status            = 'assigned',
                 assignment_method = 'auto',
                 assigned_at       = datetime('now'),
                 updated_at        = datetime('now')
             WHERE id = :ticket_id
               AND agent_id IS NULL
               AND NOT EXISTS (
                 SELECT 1 FROM helpdesk_conversations
                 WHERE agent_id = :agent_id_check
                   AND status IN ('open','assigned')
               )"
        );

        foreach ($candidates as $candidate) {
            $update->execute([
                ':agent_id'       => (int)$candidate['id'],
                ':ticket_id'      => $ticketId,
                ':agent_id_check' => (int)$candidate['id'],
            ]);
            if ($update->rowCount() > 0) {
                return ['result' => 'assigned', 'agent' => $candidate];
            }
            // 0 rows: either the ticket was claimed by another runtime or
            // this candidate just became busy. Re-read the ticket to decide.
            $ticketStmt->execute([$ticketId]);
            $now = $ticketStmt->fetch(PDO::FETCH_ASSOC);
            if ($now && !empty($now['agent_id'])) {
                return ['result' => 'already_assigned', 'agent' => null];
            }
            // Ticket still null → candidate became busy → try the next one.
        }
        return ['result' => 'waiting', 'agent' => null];
    }

    /**
     * Manual override. Admin may target a busy agent.
     *
     * If this reassign frees the previous holder (different agent), we drain
     * the queue for them too — "agent newly available" must be handled the
     * same way no matter which event produced it (close, promote, reassign).
     *
     * Returns:
     *   result            : 'assigned' | 'not_eligible'
     *   agent             : ?array (the new agent row)
     *   was_busy          : bool   (the new agent already had an active ticket)
     *   previous_agent_id : ?int   (whoever held the ticket before this call)
     *   queued_for_prev   : ?array (ticket row handed to previous holder, or null)
     */
    public static function manualAssign(PDO $pdo, int $ticketId, int $agentId): array {
        $stmt = $pdo->prepare(
            "SELECT id, username, email FROM users WHERE id = ? AND is_helpdesk_agent = 1"
        );
        $stmt->execute([$agentId]);
        $agent = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$agent) {
            return [
                'result' => 'not_eligible',
                'agent' => null,
                'was_busy' => false,
                'previous_agent_id' => null,
                'queued_for_prev' => null,
            ];
        }

        $wasBusy = !self::isAgentAvailable($pdo, $agentId);

        // Capture the previous holder BEFORE the UPDATE so we can drain the
        // queue for them if this reassign freed them.
        $prev = $pdo->prepare("SELECT agent_id FROM helpdesk_conversations WHERE id = ?");
        $prev->execute([$ticketId]);
        $previousAgentId = (int)($prev->fetchColumn() ?: 0);

        $pdo->prepare(
            "UPDATE helpdesk_conversations
             SET agent_id          = ?,
                 status            = 'assigned',
                 assignment_method = 'manual',
                 assigned_at       = datetime('now'),
                 updated_at        = datetime('now')
             WHERE id = ?"
        )->execute([$agentId, $ticketId]);

        // Queue advance for the previous holder, if they exist and differ
        // from the new agent. assignNextWaitingTicketToAgent has its own
        // zero-active gate — if the previous holder still has other active
        // tickets (common when admin reshuffles a multi-ticket agent), the
        // gate fails and nothing happens. Safe to call unconditionally.
        $queuedForPrev = null;
        if ($previousAgentId > 0 && $previousAgentId !== $agentId) {
            $queuedForPrev = self::assignNextWaitingTicketToAgent($pdo, $previousAgentId);
        }

        return [
            'result'            => 'assigned',
            'agent'             => $agent,
            'was_busy'          => $wasBusy,
            'previous_agent_id' => $previousAgentId > 0 ? $previousAgentId : null,
            'queued_for_prev'   => $queuedForPrev,
        ];
    }

    /* ───────────────────────── Queue advance ────────────────────────────────── */

    /**
     * Pick the oldest waiting ticket (status='open', agent_id IS NULL) and
     * give it to a freshly-freed agent. Returns the assigned ticket row or null.
     *
     * 0-rows handling: the guarded UPDATE can return 0 rows for two reasons,
     * and we must distinguish them so a freed agent isn't left idle next to
     * a waiting queue because of a race:
     *
     *   (a) the agent became busy between our availability check and the
     *       UPDATE (another runtime gave them a ticket) → bail entirely.
     *   (b) the SPECIFIC waiting ticket we picked was claimed by another
     *       runtime in the meantime → the agent is still free, so loop and
     *       pick the next-oldest waiting ticket. Since the just-claimed
     *       ticket now has agent_id != NULL, the same SELECT will naturally
     *       skip it on the next iteration.
     *
     * The loop is bounded by MAX_QUEUE_DRAIN_ATTEMPTS to prevent runaway
     * under extreme contention. In practice 1–2 iterations is the worst case.
     */
    private const MAX_QUEUE_DRAIN_ATTEMPTS = 20;

    public static function assignNextWaitingTicketToAgent(PDO $pdo, int $agentId): ?array {
        $eligibleCheck = $pdo->prepare(
            "SELECT id FROM users WHERE id = ? AND is_helpdesk_agent = 1"
        );
        $eligibleCheck->execute([$agentId]);
        if (!$eligibleCheck->fetchColumn()) {
            return null;
        }

        $find = $pdo->prepare(
            "SELECT id, subject FROM helpdesk_conversations
             WHERE agent_id IS NULL AND status = 'open'
             ORDER BY created_at ASC
             LIMIT 1"
        );
        $upd = $pdo->prepare(
            "UPDATE helpdesk_conversations
             SET agent_id          = :agent_id,
                 status            = 'assigned',
                 assignment_method = 'auto',
                 assigned_at       = datetime('now'),
                 updated_at        = datetime('now')
             WHERE id = :ticket_id
               AND agent_id IS NULL
               AND NOT EXISTS (
                 SELECT 1 FROM helpdesk_conversations
                 WHERE agent_id = :agent_id_check
                   AND status IN ('open','assigned')
               )"
        );

        for ($attempt = 0; $attempt < self::MAX_QUEUE_DRAIN_ATTEMPTS; $attempt++) {
            // Re-check availability EACH iteration. Authoritative SQL count —
            // never trust a flag derived from "the resolve event just fired".
            if (!self::isAgentAvailable($pdo, $agentId)) {
                return null; // case (a): agent became busy
            }

            $find->execute();
            $waiting = $find->fetch(PDO::FETCH_ASSOC);
            if (!$waiting) {
                return null; // queue empty
            }

            $upd->execute([
                ':agent_id'       => $agentId,
                ':ticket_id'      => (int)$waiting['id'],
                ':agent_id_check' => $agentId,
            ]);
            if ($upd->rowCount() > 0) {
                return $waiting;
            }
            // 0 rows. Top of loop re-checks availability — if (a), we bail;
            // if (b) the just-claimed ticket now has agent_id set, so the
            // same SELECT returns the next-oldest waiting one.
        }
        return null;
    }

    /**
     * Hook to call AFTER a ticket transitions to a finished status.
     * Looks up whoever owned the ticket and hands them the next queued one.
     * No-op if the closed ticket had no agent.
     */
    public static function onTicketClosedOrResolved(PDO $pdo, int $closedTicketId): ?array {
        $stmt = $pdo->prepare(
            "SELECT agent_id FROM helpdesk_conversations WHERE id = ?"
        );
        $stmt->execute([$closedTicketId]);
        $agentId = (int)$stmt->fetchColumn();
        if ($agentId <= 0) {
            return null;
        }
        return self::assignNextWaitingTicketToAgent($pdo, $agentId);
    }

    /* ───────────────────────── Compatibility shim ───────────────────────────── */

    /**
     * Kept for the existing workload-debug call site, if any.
     */
    public static function getAgentWorkload(PDO $pdo, int $agentId): int {
        return self::getAgentActiveTicketCount($pdo, $agentId);
    }
}
