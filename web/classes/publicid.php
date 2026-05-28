<?php
/**
 * PublicIdService — owns the public ForsaDrive ID lifecycle.
 *
 * Format: FD-{role}-{seq}
 *   P = passenger   start 10001
 *   D = driver      start 20001
 *   A = admin       start 30001
 *   H = helpdesk    start 40001
 *
 * Rules: unique, immutable, public, never exposes internal IDs. Generated once
 * at registration; backfilled for legacy rows by autoMigrate.
 */
final class PublicIdService
{
    public const PREFIX_PASSENGER = 'FD-P-';
    public const PREFIX_DRIVER    = 'FD-D-';
    public const PREFIX_ADMIN     = 'FD-A-';
    public const PREFIX_HELPDESK  = 'FD-H-';

    /** Lowest seq number per role — anchors the ranges from the spec. */
    private const SEED_START = [
        self::PREFIX_PASSENGER => 10001,
        self::PREFIX_DRIVER    => 20001,
        self::PREFIX_ADMIN     => 30001,
        self::PREFIX_HELPDESK  => 40001,
    ];

    /** Choose the right prefix for a user row (or role hints from signup). */
    public static function prefixForRole(array $u): string
    {
        if (!empty($u['is_admin']))           return self::PREFIX_ADMIN;
        if (!empty($u['is_helpdesk_agent']))  return self::PREFIX_HELPDESK;
        if (!empty($u['is_driver']))          return self::PREFIX_DRIVER;
        return self::PREFIX_PASSENGER;
    }

    /**
     * Issue a unique public_id for a user. Idempotent — if the user already has
     * one, it's returned unchanged. Safe to call after role changes (won't
     * rewrite a passenger's ID to a driver ID; the public_id is immutable).
     */
    public static function ensure(PDO $pdo, int $userId): string
    {
        $row = $pdo->prepare("SELECT id, is_admin, is_helpdesk_agent, is_driver, public_id FROM users WHERE id = ?");
        $row->execute([$userId]);
        $u = $row->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            throw new RuntimeException("PublicIdService: user $userId not found");
        }
        if (!empty($u['public_id'])) {
            return (string)$u['public_id'];
        }
        $public = self::generateUnique($pdo, self::prefixForRole($u));
        $pdo->prepare("UPDATE users SET public_id = ? WHERE id = ?")->execute([$public, $userId]);
        return $public;
    }

    /**
     * Fill in public_id for every user that doesn't have one yet. Returns the
     * number of rows updated. Driven by the user's current role.
     */
    public static function backfillAll(PDO $pdo): int
    {
        $rows = $pdo->query("SELECT id, is_admin, is_helpdesk_agent, is_driver
                             FROM users
                             WHERE public_id IS NULL OR public_id = ''")->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;
        foreach ($rows as $u) {
            $public = self::generateUnique($pdo, self::prefixForRole($u));
            $upd = $pdo->prepare("UPDATE users SET public_id = ? WHERE id = ?");
            if ($upd->execute([$public, (int)$u['id']])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Resolve a public_id to its user row. Returns null when not found.
     * Trims + uppercases so case differences in user input are forgiving.
     */
    public static function findUser(PDO $pdo, string $publicId): ?array
    {
        $publicId = self::normalize($publicId);
        if ($publicId === '') return null;
        $stmt = $pdo->prepare("SELECT * FROM users WHERE public_id = ?");
        $stmt->execute([$publicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Strip whitespace, uppercase the role letter; preserve digit sequence. */
    public static function normalize(string $s): string
    {
        $s = strtoupper(trim($s));
        // Common typo: spaces between segments. Allow "FD - P - 10001" too.
        $s = preg_replace('/\s+/', '', $s);
        return $s;
    }

    /** True if the string looks like a ForsaDrive public ID. */
    public static function isValidFormat(string $s): bool
    {
        return (bool)preg_match('/^FD-[PDAH]-\d{4,}$/', self::normalize($s));
    }

    /**
     * Generate the next unique ID for `$prefix`. Optimistic loop — collisions
     * are extremely unlikely (sequence comes from MAX+1) but the unique index
     * still enforces correctness if two signups race.
     */
    private static function generateUnique(PDO $pdo, string $prefix): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $next = self::nextSeq($pdo, $prefix) + $attempt;
            $candidate = $prefix . $next;
            $chk = $pdo->prepare("SELECT 1 FROM users WHERE public_id = ?");
            $chk->execute([$candidate]);
            if (!$chk->fetchColumn()) {
                return $candidate;
            }
        }
        // Last-resort fallback: append random suffix. Still respects the
        // prefix so the role letter stays correct.
        return $prefix . self::nextSeq($pdo, $prefix) . '-' . substr(bin2hex(random_bytes(2)), 0, 3);
    }

    /** Highest current seq for `$prefix`, +1. Starts at the role's SEED_START. */
    private static function nextSeq(PDO $pdo, string $prefix): int
    {
        $stmt = $pdo->prepare("SELECT public_id FROM users WHERE public_id LIKE ?");
        $stmt->execute([$prefix . '%']);
        $max = 0;
        while ($val = $stmt->fetchColumn()) {
            $tail = substr((string)$val, strlen($prefix));
            // Tail may include random suffix from the fallback path — split on '-'.
            $num = (int)explode('-', $tail)[0];
            if ($num > $max) $max = $num;
        }
        $seed = self::SEED_START[$prefix] ?? 10001;
        return max($seed, $max + 1);
    }
}
