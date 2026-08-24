<?php
/**
 * Fire-and-forget email notifications to users and admin.
 */
class Notify
{
    public static function signup(string $username, string $email, int $userId, bool $viaGoogle = false): void
    {
        try {
            $mail = new Mail();
            if ($mail->adminNotifyOn('signup')) {
                $admin = $mail->adminNotifyEmail();
                if ($admin) {
                    $mail->sendSignupToAdmin($admin, $username, $email, $userId, $viaGoogle);
                }
            }
        } catch (Throwable $e) {
            Logger::log('Admin signup notify failed: ' . $e->getMessage(), 'mail');
        }
    }

    public static function welcome(string $username, string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        try {
            $mail = new Mail();
            $mail->sendWelcome($email, $username);
        } catch (Throwable $e) {
            Logger::log('Welcome email failed for ' . $email . ': ' . $e->getMessage(), 'mail');
        }
    }

    public static function orderPlaced(
        int $orderId,
        string $username,
        string $email,
        string $serviceName,
        int $quantity,
        float $charge,
        string $link
    ): void {
        try {
            $mail = new Mail();
            if ($mail->adminNotifyOn('orders')) {
                $admin = $mail->adminNotifyEmail();
                if ($admin) {
                    $mail->sendOrderToAdmin($admin, $username, $email, $orderId, $serviceName, $quantity, $charge, $link);
                }
            }
        } catch (Throwable $e) {
            Logger::log('Admin order notify failed #' . $orderId . ': ' . $e->getMessage(), 'mail');
        }
    }

    public static function depositPending(
        int $depositId,
        string $username,
        string $email,
        float $amount,
        string $methodLabel,
        string $txHash = ''
    ): void {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $mail = new Mail();
                $mail->sendDepositPendingToUser($email, $username, $depositId, $amount, $methodLabel, $txHash);
            } catch (Throwable $e) {
                Logger::log('User deposit pending email failed #' . $depositId . ': ' . $e->getMessage(), 'mail');
            }
        }

        try {
            $mail = new Mail();
            if ($mail->adminNotifyOn('deposits')) {
                $admin = $mail->adminNotifyEmail();
                if ($admin) {
                    $mail->sendDepositPendingToAdmin($admin, $username, $email, $depositId, $amount, $methodLabel, $txHash);
                }
            }
        } catch (Throwable $e) {
            Logger::log('Admin deposit pending notify failed #' . $depositId . ': ' . $e->getMessage(), 'mail');
        }
    }

    public static function depositCredited(
        int $depositId,
        string $username,
        string $email,
        float $amount,
        float $balanceAfter
    ): void {
        try {
            $mail = new Mail();
            if ($mail->adminNotifyOn('deposits')) {
                $admin = $mail->adminNotifyEmail();
                if ($admin) {
                    $mail->sendDepositCreditedToAdmin($admin, $username, $email, $depositId, $amount, $balanceAfter);
                }
            }
        } catch (Throwable $e) {
            Logger::log('Admin deposit credited notify failed #' . $depositId . ': ' . $e->getMessage(), 'mail');
        }
    }
}
