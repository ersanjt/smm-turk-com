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

    /**
     * Drop checkout intents that never received a TxHash.
     * These rows are created when a user picks an amount, before any payment.
     */
    public function expireAbandonedCheckouts(int $hours = 24): int
    {
        $hours = max(1, $hours);
        $cutoff = date('Y-m-d H:i:s', time() - ($hours * 3600));
        $updated = $this->db->execute(
            "UPDATE transactions
             SET status = 'failed'
             WHERE type = 'deposit'
               AND status = 'pending'
               AND (reference IS NULL OR TRIM(reference) = '')
               AND created_at < ?",
            [$cutoff]
        );
        if ($updated > 0) {
            Logger::log(
                'Expired ' . $updated . ' abandoned deposit checkout(s) older than ' . $hours . 'h',
                'deposits'
            );
        }
        return $updated;
    }

    /**
     * One reminder per unpaid checkout (no TxHash), after the user has had time to pay.
     * Safe to call from cron: a claim file prevents a second send.
     */
    public function remindUnpaidCheckouts(int $minAgeHours = 2, int $limit = 30): int
    {
        $minAgeHours = max(1, $minAgeHours);
        $limit = max(1, min(100, $limit));
        $cutoff = date('Y-m-d H:i:s', time() - ($minAgeHours * 3600));
        $rows = $this->db->fetchAll(
            "SELECT t.id, t.amount, t.description, u.username, u.email
             FROM transactions t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.type = 'deposit'
               AND t.status = 'pending'
               AND (t.reference IS NULL OR TRIM(t.reference) = '')
               AND t.created_at < ?
             ORDER BY t.created_at ASC
             LIMIT " . $limit,
            [$cutoff]
        );
        if ($rows === []) {
            return 0;
        }

        $alertDir = ROOT_PATH . '/storage/alerts/unpaid-deposit';
        if (!is_dir($alertDir) && !@mkdir($alertDir, 0755, true) && !is_dir($alertDir)) {
            Logger::log('Unpaid deposit reminder dir missing: ' . $alertDir, 'mail');
            return 0;
        }

        $sent = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $email = trim((string) ($row['email'] ?? ''));
            if ($id < 1 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $flag = $alertDir . '/' . $id . '.txt';
            $handle = @fopen($flag, 'x');
            if ($handle === false) {
                continue;
            }
            fwrite($handle, date('c'));
            fclose($handle);

            $method = $this->methodLabelFromDescription((string) ($row['description'] ?? ''));
            $ok = false;
            try {
                $ok = $this->mail->sendDepositUnpaidReminder(
                    $email,
                    (string) ($row['username'] ?? ''),
                    $id,
                    (float) ($row['amount'] ?? 0),
                    $method
                );
            } catch (Throwable $e) {
                Logger::log('Unpaid deposit reminder failed #' . $id . ': ' . $e->getMessage(), 'mail');
            }

            if (!$ok) {
                @unlink($flag);
                Logger::log(
                    'Unpaid deposit reminder not sent #' . $id . ' to ' . $email
                    . ($this->mail->getLastError() ? ': ' . $this->mail->getLastError() : ''),
                    'mail'
                );
                continue;
            }

            $sent++;
            Logger::log('Unpaid deposit reminder sent #' . $id . ' to ' . $email, 'mail');
        }

        return $sent;
    }

    private function methodLabelFromDescription(string $description): string
    {
        $parts = preg_split('/\s+—\s+/u', $description, 2);
        if (is_array($parts) && isset($parts[1]) && trim($parts[1]) !== '') {
            return trim($parts[1]);
        }
        $description = trim($description);
        return $description !== '' ? $description : 'Crypto';
    }

    /** Admin: reject a pending deposit (even if a TxHash was submitted). Does not change user balance. */
    public function rejectPendingDeposit(int $transactionId): array {
        $tx = $this->db->fetch(
            "SELECT id, user_id, amount, reference, status FROM transactions WHERE id = ? AND type = 'deposit'",
            [$transactionId]
        );
        if (!$tx) {
            return ['success' => false, 'error' => 'Deposit not found.'];
        }
        if ((string) ($tx['status'] ?? '') !== 'pending') {
            return ['success' => false, 'error' => 'Only pending deposits can be rejected.'];
        }

        $updated = $this->db->execute(
            "UPDATE transactions SET status = 'failed' WHERE id = ? AND type = 'deposit' AND status = 'pending'",
            [$transactionId]
        );
        if ($updated === 0) {
            return ['success' => false, 'error' => 'Deposit already processed.'];
        }

        Logger::log(
            'Admin rejected pending deposit #' . $transactionId
            . ' user#' . (int) $tx['user_id']
            . ' $' . number_format((float) $tx['amount'], 2)
            . (trim((string) ($tx['reference'] ?? '')) !== '' ? ' (had TxHash)' : ''),
            'deposits'
        );

        return ['success' => true, 'amount' => (float) $tx['amount']];
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

        if (trim((string) ($tx['reference'] ?? '')) === '') {
            return [
                'success' => false,
                'error' => 'No TxHash on this deposit. The user started checkout but did not submit a payment. Reject it, or add balance from Users only after the funds are in your wallet.',
            ];
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

        if (class_exists('GoogleAcquisition', false) && $type === 'deposit') {
            try {
                (new GoogleAcquisition())->trackFirstDeposit($userId, $amount);
            } catch (Throwable $e) {
                /* best effort */
            }
        }

        return [
            'success' => true,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'email_sent' => $emailSent,
            'bonus' => (float) ($bonusResult['bonus'] ?? 0),
        ];
    }
}
