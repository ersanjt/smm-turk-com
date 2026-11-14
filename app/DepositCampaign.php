<?php
/**
 * One-time owner campaign: cancel unfinished orders, turn on a real 20% deposit bonus,
 * then email each registered account once. Safe to call from cron until it finishes.
 */
class DepositCampaign
{
    private const KEY = 'deposit20-20261114';

    public static function run(): array
    {
        if (function_exists('is_child_panel') && is_child_panel()) {
            return ['skipped' => 'child'];
        }
        $dir = ROOT_PATH . '/storage/campaigns/' . self::KEY;
        if (is_file($dir . '/done.txt')) {
            return ['done' => true];
        }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['error' => 'campaign dir'];
        }

        $db = Database::getInstance();
        $cancelled = 0;
        $cancelFailed = 0;
        if (!is_file($dir . '/actions.txt')) {
            $db->setSetting('deposit_bonus_percent', '20');
            $db->setSetting('deposit_bonus_first_only', '0');
            $orders = new OrderManager();
            $rows = $db->fetchAll(
                "SELECT id FROM orders WHERE status IN ('Pending','Processing','In progress') ORDER BY id ASC"
            );
            foreach ($rows as $row) {
                $result = $orders->cancelOrder((int) $row['id'], true);
                if (!empty($result['success'])) {
                    $cancelled++;
                } else {
                    $cancelFailed++;
                }
            }
            file_put_contents($dir . '/actions.txt', date('c') . " cancelled={$cancelled} failed={$cancelFailed}\n");
        }

        $sent = 0;
        $failed = 0;
        $mail = new Mail();
        $base = defined('SITE_URL') && SITE_URL !== '' ? rtrim((string) SITE_URL, '/') : 'https://smm-turk.com';
        $link = $base . '/funds';
        $users = $db->fetchAll(
            "SELECT id, username, email FROM users
             WHERE role = 'user' AND status IN ('active','pending') AND email IS NOT NULL AND email != ''
             ORDER BY id ASC"
        );
        foreach ($users as $user) {
            if ($sent >= 25) {
                break;
            }
            $id = (int) ($user['id'] ?? 0);
            $email = trim((string) ($user['email'] ?? ''));
            if ($id < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $claim = $dir . '/mail-' . $id . '.txt';
            if (is_file($claim)) {
                continue;
            }
            $name = trim((string) ($user['username'] ?? ''));
            if ($name === '') {
                $name = 'merhaba';
            }
            $ok = $mail->send(
                $email,
                'Yatırıma %20 ekstra bakiye',
                self::plain($name, $link),
                self::html($name, $link),
                'tr'
            );
            if (!$ok) {
                $failed++;
                Logger::log('Deposit promo not sent user#' . $id . ': ' . ($mail->getLastError() ?? 'unknown'), 'mail');
                continue;
            }
            file_put_contents($claim, date('c'));
            $sent++;
        }

        $remaining = 0;
        foreach ($users as $user) {
            $id = (int) ($user['id'] ?? 0);
            $email = trim((string) ($user['email'] ?? ''));
            if ($id < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (!is_file($dir . '/mail-' . $id . '.txt')) {
                $remaining++;
            }
        }
        if ($remaining === 0) {
            file_put_contents($dir . '/done.txt', date('c') . "\n");
        }

        return [
            'cancelled' => $cancelled,
            'cancel_failed' => $cancelFailed,
            'sent' => $sent,
            'failed' => $failed,
            'remaining' => $remaining,
        ];
    }

    private static function plain(string $name, string $link): string
    {
        return "Merhaba {$name},\n\n"
            . "Bakiye yüklediğinizde, yüklediğiniz tutarın %20’si ekstra olarak hesabınıza eklenir. "
            . "Örneğin 10 USD yatırırsanız 12 USD bakiye görürsünüz.\n\n"
            . "Yatırım: {$link}\n\n"
            . "When you add funds, that deposit is credited with an extra 20%.\n\n"
            . "İyi günler,\nSMM Türk\n";
    }

    private static function html(string $name, string $link): string
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        return '<p>Merhaba ' . $safeName . ',</p>'
            . '<p>Bakiye yüklediğinizde, yüklediğiniz tutarın <strong>%20’si ekstra</strong> olarak hesabınıza eklenir. '
            . 'Örneğin 10 USD yatırırsanız 12 USD bakiye görürsünüz.</p>'
            . '<p><a href="' . $safeLink . '">Bakiye yükle</a></p>'
            . '<p>When you add funds, that deposit is credited with an extra 20%.</p>'
            . '<p>İyi günler,<br>SMM Türk</p>';
    }
}
