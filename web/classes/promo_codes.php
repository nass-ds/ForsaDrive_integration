<?php
/**
 * Promo codes (spec §2.4).
 *
 * A promo code grants a fixed wallet credit (TND) when redeemed — the same
 * mechanism as the existing deposit/referral bonuses. Codes can be capped by
 * total uses and/or an expiry date, are once-per-user, and can be deactivated.
 */
class PromoCodes {
    public function __construct(private PDO $db) {}

    public static function generateCode(): string {
        return 'FD' . strtoupper(bin2hex(random_bytes(3))); // e.g. FD3A9C1F
    }

    /** Create a code (auto-generates one if $code is blank). Returns [ok, message, code]. */
    public function create(?int $adminId, string $code, float $amount, int $maxUses, ?string $expiresAt): array {
        $code = strtoupper(trim($code));
        if ($code === '') $code = self::generateCode();
        if ($amount <= 0)    return [false, 'Amount must be greater than 0.', $code];
        if ($amount > 1000)  return [false, 'Amount per code is capped at 1000 TND.', $code];
        if ($maxUses < 0)    $maxUses = 0;
        $exp = ($expiresAt !== null && trim($expiresAt) !== '') ? trim($expiresAt) : null;

        try {
            $this->db->prepare(
                "INSERT INTO promo_codes (code, amount, max_uses, expires_at, created_by)
                 VALUES (?,?,?,?,?)"
            )->execute([$code, $amount, $maxUses, $exp, $adminId]);
            return [true, "Promo code {$code} created.", $code];
        } catch (PDOException $e) {
            return [false, "Code '{$code}' already exists.", $code];
        }
    }

    public function setActive(int $id, bool $active): void {
        $this->db->prepare("UPDATE promo_codes SET is_active=? WHERE id=?")
                 ->execute([$active ? 1 : 0, $id]);
    }

    public function all(): array {
        return $this->db->query("SELECT * FROM promo_codes ORDER BY created_at DESC")
                        ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Redeem a code for a user → credits the wallet. Returns [ok, message, amount].
     * Atomic: validates active / expiry / usage-cap / once-per-user, then credits
     * the balance, logs a payment, records the redemption and bumps used_count.
     */
    public function redeem(int $userId, string $code): array {
        $code = strtoupper(trim($code));
        if ($code === '') return [false, 'Please enter a promo code.', 0.0];

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM promo_codes WHERE code=?");
            $stmt->execute([$code]);
            $promo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$promo) {
                $this->db->rollBack();
                return [false, 'Invalid promo code.', 0.0];
            }
            if (empty($promo['is_active'])) {
                $this->db->rollBack();
                return [false, 'This promo code is no longer active.', 0.0];
            }
            if (!empty($promo['expires_at'])) {
                $chk = $this->db->prepare("SELECT (expires_at <= datetime('now')) FROM promo_codes WHERE id=?");
                $chk->execute([$promo['id']]);
                if ($chk->fetchColumn()) {
                    $this->db->rollBack();
                    return [false, 'This promo code has expired.', 0.0];
                }
            }
            if ((int)$promo['max_uses'] > 0 && (int)$promo['used_count'] >= (int)$promo['max_uses']) {
                $this->db->rollBack();
                return [false, 'This promo code has reached its usage limit.', 0.0];
            }

            // Once per user (also guaranteed by UNIQUE(promo_id,user_id)).
            $dup = $this->db->prepare("SELECT 1 FROM promo_redemptions WHERE promo_id=? AND user_id=?");
            $dup->execute([$promo['id'], $userId]);
            if ($dup->fetchColumn()) {
                $this->db->rollBack();
                return [false, 'You have already redeemed this code.', 0.0];
            }

            $amount = (float)$promo['amount'];
            $this->db->prepare("INSERT INTO promo_redemptions (promo_id, user_id, amount) VALUES (?,?,?)")
                     ->execute([$promo['id'], $userId, $amount]);
            $this->db->prepare("UPDATE promo_codes SET used_count = used_count + 1 WHERE id=?")
                     ->execute([$promo['id']]);
            $this->db->prepare("UPDATE users SET balance = balance + ? WHERE id=?")
                     ->execute([$amount, $userId]);
            $this->db->prepare("INSERT INTO payments (user_id, amount, type, description) VALUES (?,?,?,?)")
                     ->execute([$userId, $amount, 'promo', "Promo code {$code} redeemed"]);

            $this->db->commit();
            return [true, "Promo code applied — {$amount} TND added to your wallet.", $amount];
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("PromoCodes::redeem — " . $e->getMessage());
            return [false, 'Could not redeem this code. Please try again.', 0.0];
        }
    }
}
