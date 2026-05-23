<?php
/**
 * Forgot-password flow (spec §2.2).
 *
 * Issues short-lived, single-use reset tokens. Only a sha256 hash of the token
 * is stored; the raw token travels in the reset link. There is no SMTP in this
 * project (see server/send_contact.php), so the caller surfaces the link
 * on-screen in demo mode — the row is equally ready to be emailed once an SMTP
 * service is configured.
 *
 * Password policy mirrors web/Pages/signup.php exactly.
 */
class PasswordReset {
    public const TOKEN_TTL_MIN   = 60;
    public const MIN_PASSWORD_LEN = 8;

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Create a reset token for $email if an account exists. Any earlier unused
     * tokens for that user are invalidated first. Returns
     * ['token' => raw, 'user_id' => int, 'username' => str] or null if no account.
     */
    public function createToken(string $email): ?array {
        $email = strtolower(trim($email));
        $stmt  = $this->db->prepare("SELECT id, username FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return null;

        $this->db->prepare(
            "UPDATE password_resets SET used_at = datetime('now') WHERE user_id = ? AND used_at IS NULL"
        )->execute([$user['id']]);

        $raw  = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $this->db->prepare(
            "INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (?, ?, datetime('now', ?))"
        )->execute([$user['id'], $hash, '+' . self::TOKEN_TTL_MIN . ' minutes']);

        return ['token' => $raw, 'user_id' => (int)$user['id'], 'username' => $user['username']];
    }

    /** Reset row joined with the user if $rawToken is unused and unexpired, else null. */
    public function validate(string $rawToken): ?array {
        $rawToken = trim($rawToken);
        if ($rawToken === '') return null;
        $hash = hash('sha256', $rawToken);
        $stmt = $this->db->prepare(
            "SELECT pr.*, u.email, u.username
             FROM password_resets pr
             JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > datetime('now')
             LIMIT 1"
        );
        $stmt->execute([$hash]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Validate the token and set the new password. Returns [bool ok, string message].
     * On success the token (and any other pending tokens) are consumed and the
     * user's active API tokens are revoked so a stolen session can't outlive the reset.
     */
    public function consume(string $rawToken, string $password, string $confirm): array {
        $row = $this->validate($rawToken);
        if (!$row) {
            return [false, 'This reset link is invalid or has expired. Please request a new one.'];
        }
        if ($err = self::validatePassword($password, $confirm)) {
            return [false, $err];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $row['user_id']]);
            $this->db->prepare("UPDATE password_resets SET used_at = datetime('now') WHERE user_id = ? AND used_at IS NULL")
                     ->execute([$row['user_id']]);
            // Revoke active API tokens (forces a fresh login on mobile). Table is
            // created by the Dart API; guard in case it is absent.
            try { $this->db->prepare("DELETE FROM api_tokens WHERE user_id = ?")->execute([$row['user_id']]); }
            catch (PDOException $e) { /* table may not exist on a web-only DB */ }
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("PasswordReset::consume — " . $e->getMessage());
            return [false, 'Could not update your password. Please try again.'];
        }
        return [true, 'Your password has been reset. You can now sign in.'];
    }

    /** Same rules as signup: ≥8 chars, one uppercase, one digit, confirmation match. */
    public static function validatePassword(string $password, string $confirm): ?string {
        if (strlen($password) < self::MIN_PASSWORD_LEN) return 'Password must be at least 8 characters.';
        if (!preg_match('/[A-Z]/', $password))          return 'Password must contain at least one uppercase letter.';
        if (!preg_match('/[0-9]/', $password))          return 'Password must contain at least one number.';
        if ($password !== $confirm)                     return 'Passwords do not match.';
        return null;
    }

    /** Build the absolute reset link for a raw token from the current request. */
    public static function buildLink(string $rawToken): string {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/')), '/');
        return "{$scheme}://{$host}{$dir}/reset_password.php?token=" . urlencode($rawToken);
    }
}
