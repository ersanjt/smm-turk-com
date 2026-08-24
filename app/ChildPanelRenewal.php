<?php
/**
 * Child panel monthly renewal — recurring revenue.
 */
class ChildPanelRenewal
{
    private Database $db;
    private ChildPanelManager $cpm;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->cpm = new ChildPanelManager();
    }

    /** @return array{renewed: int, suspended: int, reminded: int} */
    public function process(): array
    {
        $renewed = 0;
        $suspended = 0;
        $reminded = 0;
        $price = $this->cpm->monthlyPrice();
        $mail = new Mail();
        $alertDir = ROOT_PATH . '/storage/alerts/child-renewal';
        if (!is_dir($alertDir)) {
            @mkdir($alertDir, 0755, true);
        }

        $soon = $this->db->fetchAll(
            "SELECT cp.*, u.username, u.email, u.balance FROM child_panels cp
             JOIN users u ON u.id = cp.user_id
             WHERE cp.status = 'active' AND cp.provision_status = 'ready'
             AND cp.expires_at IS NOT NULL AND cp.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)"
        );
        foreach ($soon as $panel) {
            $panelId = (int) ($panel['id'] ?? 0);
            $flag = $alertDir . '/remind-' . $panelId . '.txt';
            if (is_file($flag) && (time() - (int) @filemtime($flag)) < 86400 * 3) {
                continue;
            }
            $email = trim((string) ($panel['email'] ?? $panel['admin_email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if ($mail->sendChildPanelRenewalReminder(
                    $email,
                    (string) ($panel['username'] ?? ''),
                    (string) ($panel['domain'] ?? ''),
                    (string) ($panel['expires_at'] ?? ''),
                    $price,
                    (float) ($panel['balance'] ?? 0)
                )) {
                    @touch($flag);
                    $reminded++;
                }
            }
        }

        $due = $this->db->fetchAll(
            "SELECT * FROM child_panels
             WHERE status = 'active' AND provision_status = 'ready'
             AND expires_at IS NOT NULL AND expires_at <= NOW()"
        );
        foreach ($due as $panel) {
            $result = $this->renewPanel((int) $panel['id']);
            if (!empty($result['renewed'])) {
                $renewed++;
            } elseif (!empty($result['suspended'])) {
                $suspended++;
            }
        }

        return ['renewed' => $renewed, 'suspended' => $suspended, 'reminded' => $reminded];
    }

    /** @return array{success: bool, renewed?: bool, suspended?: bool, error?: string} */
    public function renewPanel(int $panelId): array
    {
        $panel = $this->db->fetch('SELECT * FROM child_panels WHERE id = ?', [$panelId]);
        if (!$panel || ($panel['status'] ?? '') !== ChildPanelManager::STATUS_ACTIVE) {
            return ['success' => false, 'error' => 'Panel not found.'];
        }
        $userId = (int) ($panel['user_id'] ?? 0);
        $price = $this->cpm->monthlyPrice();

        $this->db->beginTransaction();
        try {
            $user = $this->db->fetch('SELECT balance, username, email FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if (!$user || (float) $user['balance'] < $price) {
                $this->db->execute(
                    "UPDATE child_panels SET status = ?, updated_at = NOW() WHERE id = ?",
                    [ChildPanelManager::STATUS_SUSPENDED, $panelId]
                );
                $this->db->commit();
                $email = trim((string) ($panel['admin_email'] ?? $user['email'] ?? ''));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    (new Mail())->sendChildPanelSuspended(
                        $email,
                        (string) ($user['username'] ?? ''),
                        (string) ($panel['domain'] ?? ''),
                        $price
                    );
                }
                return ['success' => true, 'suspended' => true];
            }

            $before = (float) $user['balance'];
            $deducted = $this->db->execute(
                'UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?',
                [$price, $userId, $price]
            );
            if ($deducted === 0) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Insufficient balance.'];
            }
            $after = round($before - $price, 4);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
            $this->db->execute(
                'UPDATE child_panels SET expires_at = ?, status = ?, updated_at = NOW() WHERE id = ?',
                [$expiresAt, ChildPanelManager::STATUS_ACTIVE, $panelId]
            );
            $this->db->insert(
                "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, status) VALUES (?, 'order', ?, ?, ?, ?, 'completed')",
                [$userId, -$price, $before, $after, 'Child panel renewal: ' . ($panel['domain'] ?? ''), 'completed']
            );
            $this->db->commit();
            return ['success' => true, 'renewed' => true, 'expires_at' => $expiresAt];
        } catch (Throwable $e) {
            $this->db->rollBack();
            Logger::log('Child panel renewal #' . $panelId . ': ' . $e->getMessage(), 'automation');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
