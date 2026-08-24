<?php
/**
 * Report child panel end-user registrations to the parent SMM Turk site.
 */
class ChildPanelUserSync
{
    public static function isChildPanelSite(): bool
    {
        return defined('SMM_CHILD_PANEL') && SMM_CHILD_PANEL
            && defined('PROVIDER_API_URL') && trim((string) PROVIDER_API_URL) !== ''
            && defined('PROVIDER_API_KEY') && trim((string) PROVIDER_API_KEY) !== '';
    }

    /** @param array{user_id: int, username: string, email: string, status?: string, registered_at?: string} $user */
    public static function reportRegistration(array $user): void
    {
        if (!self::isChildPanelSite()) {
            return;
        }
        $domain = defined('SITE_URL') ? (string) parse_url(SITE_URL, PHP_URL_HOST) : '';
        if ($domain === '') {
            return;
        }
        try {
            self::postToParent([
                'action' => 'child_user_register',
                'key' => (string) PROVIDER_API_KEY,
                'panel_domain' => $domain,
                'local_user_id' => (int) ($user['user_id'] ?? 0),
                'username' => trim((string) ($user['username'] ?? '')),
                'email' => strtolower(trim((string) ($user['email'] ?? ''))),
                'status' => trim((string) ($user['status'] ?? 'active')),
                'registered_at' => (string) ($user['registered_at'] ?? date('Y-m-d H:i:s')),
            ]);
        } catch (Throwable $e) {
            Logger::log('Child user sync failed: ' . $e->getMessage(), 'child_panel');
        }
    }

    /** @param array<string, scalar> $payload */
    private static function postToParent(array $payload): void
    {
        $url = rtrim((string) PROVIDER_API_URL, '/');
        if ($url === '') {
            return;
        }
        $body = http_build_query($payload);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_USERAGENT => 'SMM-ChildPanel/1.0',
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            $msg = is_string($response) ? substr($response, 0, 200) : 'empty';
            Logger::log("Child user sync HTTP $code: $msg", 'child_panel');
        }
    }
}
