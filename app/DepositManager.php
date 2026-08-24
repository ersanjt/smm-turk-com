<?php
/**
 * Credit user balance for deposits and notify by email.
 */
class DepositManager {
    private Database $db;
    private Mail $mail;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->mail = new Mail();
    }

    /** Approve a pending crypto deposit transaction. */
    public function approvePendingDeposit(int $transactionId): array {
        return $this->approveDeposit($transactionId, false);
    }

    /** Admin: approve a failed deposit that already has an on-chain TxHash. */
    public function approveFailedDeposit(int $transactionId): array {
        return $this->approveDeposit($transactionId, true);
    }

    private function approveDeposit(int $transactionId, bool $allowFailed): array {
        $tx = $this->db->fetch(
            "SELECT id, user_id, amount, description, reference, status FROM transactions WHERE id = ? AND type = 'deposit'",
            [$transactionId]
        );
        if (!$tx) {
            return ['success' => false, 'error' => 'Deposit not found.'];
        }
        $status = (string) ($tx['status'] ?? '');
        if ($status === 'completed') {
            return ['success' => false, 'error' => 'Deposit already processed.'];
        }
        if ($status === 'failed') {
            if (!$allowFailed) {
                return ['success' => false, 'error' => 'Deposit not found or already processed.'];
            }
            if (trim((string) ($tx['reference'] ?? '')) === '') {
                return ['success' => false, 'error' => 'Failed deposit has no TxHash — credit the user manually instead.'];
            }
        } elseif ($status !== 'pending') {
            return ['success' => false, 'error' => 'Deposit not found or already processed.'];
        }

        return $this->creditUser(
            (int) $tx['user_id'],
            (float) $tx['amount'],
            'deposit',
            $tx['description'] ?? 'Crypto deposit',
            $transactionId
        );
    }

    /**
     * Add funds to a user account and send confirmation email.
     * @param int|null $existingTransactionId Pending deposit row to mark completed; null creates admin credit row.
     */
    public function creditUser(int $userId, float $amount, string $type = 'deposit', string $description = 'Deposit', ?int $existingTransactionId = null): array {
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Invalid amount.'];
        }

        $this->db->beginTransaction();
        try {
            if ($existingTransactionId) {
                $tx = $this->db->fetch(
                    "SELECT id, user_id, amount, status FROM transactions WHERE id = ? AND type = 'deposit' FOR UPDATE",
                    [$existingTransactionId]
                );
                $txStatus = (string) ($tx['status'] ?? '');
                if (!$tx || (int) $tx['user_id'] !== $userId || !in_array($txStatus, ['pending', 'failed'], true)) {
                    $this->db->rollBack();
                    return ['success' => false, 'error' => 'Deposit not found or already processed.'];
                }
                $amount = (float) $tx['amount'];
            }

            $user = $this->db->fetch(
                "SELECT id, username, email, balance FROM users WHERE id = ? FOR UPDATE",
                [$userId]
            );
            if (!$user) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'User not found.'];
            }

            $balanceBefore = (float) $user['balance'];
            $balanceAfter = round($balanceBefore + $amount, 4);

            $this->db->execute("UPDATE users SET balance = balance + ? WHERE id = ?", [$amount, $userId]);

            if ($existingTransactionId) {
                $updated = $this->db->execute(
                    "UPDATE transactions SET status = 'completed', balance_after = ? WHERE id = ? AND status IN ('pending', 'failed')",
                    [$balanceAfter, $existingTransactionId]
                );
                if ($updated === 0) {
                    $this->db->rollBack();
                    return ['success' => false, 'error' => 'Deposit already processed.'];
                }
            } else {
                $this->db->insert(
                    "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, status) VALUES (?, ?, ?, ?, ?, ?, 'completed')",
                    [$userId, $type, $amount, $balanceBefore, $balanceAfter, $description]
                );
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            Logger::log("Deposit credit failed user#{$userId}: " . $e->getMessage(), 'deposits');
            return ['success' => false, 'error' => 'Failed to credit balance. Please try again.'];
        }

        $emailSent = $this->mail->sendDepositConfirmed(
            $user['email'],
            $user['username'],
            $amount,
            $balanceAfter,
            $existingTransactionId
        );

        if ($existingTransactionId) {
            Notify::depositCredited(
                $existingTransactionId,
                $user['username'],
                $user['email'],
                $amount,
                $balanceAfter
            );
        }

        $couponCode = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $couponCode = $_SESSION['deposit_coupon'] ?? null;
            unset($_SESSION['deposit_coupon']);
        }
        $bonusResult = (new RevenueEngine())->applyDepositBonus($userId, $amount, is_string($couponCode) ? $couponCode : null);

        return [
            'success' => true,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'email_sent' => $emailSent,
            'bonus' => (float) ($bonusResult['bonus'] ?? 0),
        ];
    }
}
