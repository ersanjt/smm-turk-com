<?php
/**
 * SMM Turk — Mail (cPanel mail() + SMTP) · TR / EN templates
 */
class Mail
{
    private Database $db;
    private ?string $lastError = null;
    private ?string $lastTransport = null;

    public function getLastTransport(): ?string
    {
        return $this->lastTransport;
    }

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    private function getFrom(): string
    {
        $from = trim((string) ($this->db->getSetting('smtp_from') ?? ''));
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return $from;
        }
        if (defined('MAIL_FROM') && MAIL_FROM !== '' && filter_var(MAIL_FROM, FILTER_VALIDATE_EMAIL)) {
            return MAIL_FROM;
        }
        $host = parse_url(defined('SITE_URL') ? SITE_URL : 'https://localhost', PHP_URL_HOST);
        return 'noreply@' . ($host ?: 'localhost');
    }

    private function getReplyTo(): ?string
    {
        $contact = trim((string) ($this->db->getSetting('contact_email') ?? ''));
        return filter_var($contact, FILTER_VALIDATE_EMAIL) ? $contact : null;
    }

    private function getSiteName(): string
    {
        return $this->db->getSetting('site_name') ?: (defined('SITE_NAME') ? SITE_NAME : 'SMM Turk');
    }

    private function siteUrl(): string
    {
        return defined('SITE_URL') && SITE_URL !== '' ? rtrim(SITE_URL, '/') : '';
    }

    private function btn(string $href, string $label): string
    {
        return '<p style="margin:20px 0;">'
            . '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" '
            . 'style="display:inline-block;background:#E30A17;color:#fff;padding:12px 28px;text-decoration:none;border-radius:8px;font-weight:bold;font-size:15px;">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></p>';
    }

    private function copyLink(string $href, ?string $lang): string
    {
        return '<p style="font-size:12px;color:#666;margin-top:8px;">'
            . htmlspecialchars(MailLocale::t('copy_link', $lang), ENT_QUOTES, 'UTF-8')
            . '<br><a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" style="color:#E30A17;word-break:break-all;">'
            . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '</a></p>';
    }

    /** Branded HTML wrapper */
    public function wrapHtml(string $title, string $innerHtml, ?string $lang = null): string
    {
        $lang = MailLocale::resolveLang($lang);
        $siteName = htmlspecialchars($this->getSiteName(), ENT_QUOTES, 'UTF-8');
        $home = htmlspecialchars($this->siteUrl() ?: page_url('home.php'), ENT_QUOTES, 'UTF-8');
        $year = date('Y');
        $footer = htmlspecialchars(MailLocale::t('footer_auto', $lang), ENT_QUOTES, 'UTF-8');
        $enNote = MailLocale::t('footer_en_note', $lang);
        $enLine = $enNote !== '' ? '<br><span style="color:#aaa;">' . htmlspecialchars($enNote, ENT_QUOTES, 'UTF-8') . '</span>' : '';

        return '<!DOCTYPE html><html lang="' . $lang . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f0f2f5;font-family:Arial,Helvetica,sans-serif;">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f2f5;padding:28px 12px;"><tr><td align="center">'
            . '<table width="100%" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;box-shadow:0 2px 8px rgba(0,0,0,.06);">'
            . '<tr><td style="background:#E30A17;padding:20px 24px;">'
            . '<a href="' . $home . '" style="color:#fff;font-size:20px;font-weight:bold;text-decoration:none;">' . $siteName . '</a></td></tr>'
            . '<tr><td style="padding:28px 24px;color:#1f2937;font-size:15px;line-height:1.65;">' . $innerHtml . '</td></tr>'
            . '<tr><td style="padding:16px 24px;background:#f9fafb;color:#9ca3af;font-size:11px;border-top:1px solid #e5e7eb;line-height:1.5;">'
            . '© ' . $year . ' ' . $siteName . '. ' . $footer . $enLine
            . '</td></tr></table></td></tr></table></body></html>';
    }

    private function encodeHeader(string $text): string
    {
        return preg_match('/[^\x20-\x7E]/', $text)
            ? '=?UTF-8?B?' . base64_encode($text) . '?='
            : $text;
    }

    /**
     * @return true on success
     */
    public function send(string $to, string $subject, string $bodyPlain, string $bodyHtml = '', ?string $lang = null): bool
    {
        $this->lastError = null;
        $this->lastTransport = null;
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->lastError = 'Invalid recipient email';
            return false;
        }

        $from = $this->getFrom();
        $siteName = $this->getSiteName();
        $html = $bodyHtml !== '' ? $bodyHtml : '<pre style="font-family:monospace;white-space:pre-wrap;">' . htmlspecialchars($bodyPlain) . '</pre>';
        if (stripos($html, '<html') === false) {
            $html = $this->wrapHtml($subject, $html, $lang);
        }

        $mode = strtolower(trim((string) ($this->db->getSetting('mail_mode') ?? 'auto')));
        $smtpHost = trim((string) ($this->db->getSetting('smtp_host') ?? ''));

        if ($mode === 'mail' || ($mode === 'auto' && $smtpHost === '')) {
            $ok = $this->sendPhpMail($from, $to, $subject, $html, $siteName);
            if ($ok) {
                $this->lastTransport = 'mail';
            }
            return $ok;
        }

        if ($smtpHost !== '') {
            $ok = $this->sendSmtp($smtpHost, $from, $to, $subject, $html, $siteName);
            if ($ok) {
                $this->lastTransport = 'smtp';
                return true;
            }
            if ($mode === 'smtp') {
                Logger::log("SMTP only mode failed to {$to}: " . ($this->lastError ?? 'unknown'), 'mail');
                return false;
            }
            Logger::log("SMTP failed ({$this->lastError}), falling back to mail() for {$to}", 'mail');
        }

        $ok = $this->sendPhpMail($from, $to, $subject, $html, $siteName);
        if ($ok) {
            $this->lastTransport = 'mail';
        }
        return $ok;
    }

    private function sendPhpMail(string $from, string $to, string $subject, string $html, string $siteName): bool
    {
        $encodedSubject = $this->encodeHeader($subject);
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->encodeHeader($siteName) . ' <' . $from . '>',
            'Reply-To: ' . ($this->getReplyTo() ?? $from),
            'X-Mailer: SMM-Turk-Mail/3.0',
        ];

        $params = '-f' . $from;
        $ok = @mail($to, $encodedSubject, $html, implode("\r\n", $headers), $params);
        if (!$ok) {
            $this->lastError = 'PHP mail() failed. Configure SMTP in Admin → Settings.';
            Logger::log("mail() failed to {$to} from {$from}", 'mail');
        }
        return $ok;
    }

    /** @return string[] */
    private function readSmtpResponse($fp): array
    {
        $lines = [];
        while (!feof($fp)) {
            $line = fgets($fp, 8192);
            if ($line === false) {
                break;
            }
            $lines[] = rtrim($line, "\r\n");
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        return $lines;
    }

    private function smtpCode(array $lines): int
    {
        return $lines === [] ? 500 : (int) substr($lines[0], 0, 3);
    }

    private function smtpCmd($fp, string $cmd, array $expectCodes = [250], bool $logCmd = true): bool
    {
        if (@fwrite($fp, $cmd . "\r\n") === false) {
            $this->lastError = 'Write failed: ' . ($logCmd ? $cmd : '(auth)');
            return false;
        }
        $lines = $this->readSmtpResponse($fp);
        $code = $this->smtpCode($lines);
        if (!in_array($code, $expectCodes, true) && !($code >= 200 && $code < 300)) {
            $shown = $logCmd ? $cmd : '(auth command hidden)';
            $this->lastError = 'SMTP ' . $shown . ' → ' . implode(' | ', $lines);
            return false;
        }
        return true;
    }

    /** @param resource $fp */
    private function smtpAuthenticate($fp, string $user, string $pass): bool
    {
        if ($user === '') {
            return true;
        }
        if ($pass === '') {
            $this->lastError = 'SMTP password is empty — enter mailbox password in Admin → Settings → Email';
            return false;
        }

        $plain = base64_encode("\0" . $user . "\0" . $pass);
        if ($this->smtpCmd($fp, 'AUTH PLAIN ' . $plain, [235], false)) {
            return true;
        }

        $plainErr = $this->lastError;
        if ($this->smtpCmd($fp, 'AUTH LOGIN', [334])
            && $this->smtpCmd($fp, base64_encode($user), [334], false)
            && $this->smtpCmd($fp, base64_encode($pass), [235], false)) {
            return true;
        }

        $this->lastError = 'SMTP authentication failed'
            . ($this->lastError ? ' — ' . $this->lastError : '')
            . ($plainErr ? ' (PLAIN: ' . $plainErr . ')' : '');
        return false;
    }

    /** @return string[] */
    private function smtpHostCandidates(string $host): array
    {
        $host = trim($host);
        if ($host === '') {
            return [];
        }
        $siteHost = parse_url(defined('SITE_URL') ? SITE_URL : '', PHP_URL_HOST) ?: '';
        $candidates = [$host];
        if ($siteHost !== '' && $host === $siteHost && !str_starts_with($host, 'mail.')) {
            $candidates[] = 'mail.' . $siteHost;
        } elseif (str_starts_with($host, 'mail.') && $siteHost !== '') {
            $candidates[] = $siteHost;
        }
        return array_values(array_unique($candidates));
    }

    private function sendSmtp(string $host, string $from, string $to, string $subject, string $body, string $siteName): bool
    {
        $this->lastError = null;
        $port = (int) ($this->db->getSetting('smtp_port') ?: 587);
        $user = trim((string) ($this->db->getSetting('smtp_user') ?? ''));
        $pass = (string) ($this->db->getSetting('smtp_pass') ?? '');
        $enc = strtolower(trim((string) ($this->db->getSetting('smtp_encryption') ?? 'auto')));

        if ($enc === 'auto') {
            $enc = $port === 465 ? 'ssl' : ($port === 587 ? 'tls' : 'none');
        }

        $lastErr = '';
        foreach ($this->smtpHostCandidates($host) as $tryHost) {
            if ($this->smtpSession($tryHost, $port, $enc, $user, $pass, $from, $to, $subject, $body, $siteName)) {
                return true;
            }
            $lastErr = $this->lastError ?? 'unknown';
        }

        $this->lastError = $lastErr;
        return false;
    }

    /** @return resource|null */
    private function smtpConnect(string $host, int $port, string $enc)
    {
        $target = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);
        $fp = @stream_socket_client($target, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            $ctxRelaxed = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'SNI_enabled' => true,
                    'peer_name' => $host,
                ],
            ]);
            $fp = @stream_socket_client($target, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctxRelaxed);
        }
        if (!$fp) {
            $this->lastError = "Cannot connect to {$target}: {$errstr} ({$errno})";
            return null;
        }
        stream_set_timeout($fp, 20);
        return $fp;
    }

    private function smtpSession(
        string $host,
        int $port,
        string $enc,
        string $user,
        string $pass,
        string $from,
        string $to,
        string $subject,
        string $body,
        string $siteName
    ): bool {
        $fp = $this->smtpConnect($host, $port, $enc);
        if (!$fp) {
            return false;
        }

        $greeting = $this->readSmtpResponse($fp);
        if ($this->smtpCode($greeting) !== 220) {
            $this->lastError = 'Bad greeting from ' . $host . ': ' . implode(' | ', $greeting);
            fclose($fp);
            return false;
        }

        $ehloHost = parse_url(defined('SITE_URL') ? SITE_URL : '', PHP_URL_HOST) ?: $host;
        if (!$this->smtpCmd($fp, 'EHLO ' . $ehloHost, [250])) {
            fclose($fp);
            return false;
        }

        if ($enc === 'tls') {
            if (!$this->smtpCmd($fp, 'STARTTLS', [220])) {
                fclose($fp);
                return false;
            }
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
                $this->lastError = 'STARTTLS handshake failed on ' . $host;
                fclose($fp);
                return false;
            }
            if (!$this->smtpCmd($fp, 'EHLO ' . $ehloHost, [250])) {
                fclose($fp);
                return false;
            }
        }

        if (!$this->smtpAuthenticate($fp, $user, $pass)) {
            fclose($fp);
            return false;
        }

        if (!$this->smtpCmd($fp, 'MAIL FROM:<' . $from . '>', [250]) || !$this->smtpCmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]) || !$this->smtpCmd($fp, 'DATA', [354])) {
            fclose($fp);
            return false;
        }

        $replyTo = $this->getReplyTo() ?? $from;
        $msg = 'From: ' . $this->encodeHeader($siteName) . ' <' . $from . ">\r\n"
            . 'To: <' . $to . ">\r\n"
            . 'Reply-To: <' . $replyTo . ">\r\n"
            . 'Subject: ' . $this->encodeHeader($subject) . "\r\nMIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
            . $this->smtpDotStuff($body) . "\r\n";

        if (@fwrite($fp, $msg) === false || !$this->smtpCmd($fp, '.', [250])) {
            fclose($fp);
            return false;
        }
        $this->smtpCmd($fp, 'QUIT', [221]);
        fclose($fp);
        return true;
    }

    private function smtpDotStuff(string $body): string
    {
        return preg_replace('/^\./m', '..', $body) ?? $body;
    }

    private function subjectPrefix(string $text, ?string $lang): string
    {
        return '[' . $this->getSiteName() . '] ' . $text;
    }

    /** Admin notification inbox (falls back to contact_email). */
    public function adminNotifyEmail(): ?string
    {
        $notify = trim((string) ($this->db->getSetting('admin_notify_email') ?? ''));
        if ($notify !== '' && filter_var($notify, FILTER_VALIDATE_EMAIL)) {
            return $notify;
        }
        return $this->getReplyTo();
    }

    /** @param 'signup'|'orders'|'deposits' $event */
    public function adminNotifyOn(string $event): bool
    {
        $key = 'admin_notify_' . $event;
        $val = $this->db->getSetting($key);
        return ($val ?? '1') !== '0';
    }

    public function sendWelcome(string $to, string $username, ?string $lang = null): bool
    {
        $lang = MailLocale::resolveLang($lang);
        $subject = $this->subjectPrefix(MailLocale::t('welcome_subject', $lang), $lang);
        $inner = '<p>' . MailLocale::t('welcome_hi', $lang, ['name' => $username]) . '</p>'
            . '<p>' . MailLocale::t('welcome_body', $lang) . '</p>'
            . $this->btn(page_url('add-funds.php'), MailLocale::t('btn_funds', $lang));
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml(MailLocale::t('welcome_subject', $lang), $inner, $lang), $lang);
    }

    public function sendSignupToAdmin(string $to, string $username, string $userEmail, int $userId, bool $viaGoogle = false, ?string $lang = null): bool
    {
        $lang = MailLocale::resolveLang($lang ?? 'en');
        $method = MailLocale::t($viaGoogle ? 'method_google' : 'method_register', $lang);
        $subject = $this->subjectPrefix(MailLocale::t('admin_signup_subject', $lang, ['user' => $username]), $lang);
        $inner = '<p>' . MailLocale::t('admin_signup_body', $lang, ['user' => $username, 'email' => $userEmail]) . '</p>'
            . '<p>' . MailLocale::t('admin_signup_via', $lang, ['method' => $method]) . '</p>'
            . '<p><strong>ID:</strong> #' . (int) $userId . '</p>'
            . $this->btn(page_url('admin/admin-users.php'), MailLocale::t('btn_users', $lang));
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml('Signup #' . $userId, $inner, $lang), $lang);
    }

    public function sendOrderToAdmin(
        string $to,
        string $username,
        string $userEmail,
        int $orderId,
        string $serviceName,
        int $quantity,
        float $charge,
        string $link,
        ?string $lang = null
    ): bool {
        $lang = MailLocale::resolveLang($lang ?? 'en');
        $chargeFmt = number_format($charge, 4);
        $subject = $this->subjectPrefix(MailLocale::t('admin_order_subject', $lang, ['id' => $orderId, 'user' => $username]), $lang);
        $inner = '<p>' . MailLocale::t('admin_order_body', $lang, ['user' => $username, 'email' => $userEmail]) . '</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;">'
            . '<tr><td style="padding:8px;border-bottom:1px solid #eee;color:#666;">#' . (int) $orderId . '</td><td style="padding:8px;border-bottom:1px solid #eee;font-weight:bold;">' . htmlspecialchars($serviceName) . '</td></tr>'
            . '<tr><td style="padding:8px;border-bottom:1px solid #eee;color:#666;">' . MailLocale::t('order_qty', $lang) . '</td><td style="padding:8px;border-bottom:1px solid #eee;">' . number_format($quantity) . '</td></tr>'
            . '<tr><td style="padding:8px;border-bottom:1px solid #eee;color:#666;">' . MailLocale::t('order_charge', $lang) . '</td><td style="padding:8px;border-bottom:1px solid #eee;">$' . htmlspecialchars($chargeFmt) . '</td></tr>'
            . '<tr><td style="padding:8px;color:#666;">' . MailLocale::t('order_link_label', $lang) . '</td><td style="padding:8px;word-break:break-all;font-size:12px;">' . htmlspecialchars($link) . '</td></tr>'
            . '</table>'
            . $this->btn(page_url('admin/admin-orders.php'), MailLocale::t('btn_admin', $lang));
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml('Order #' . $orderId, $inner, $lang), $lang);
    }

    public function sendDepositPendingToUser(
        string $to,
        string $username,
        int $depositId,
        float $amount,
        string $methodLabel,
        string $txHash = '',
        ?string $lang = null
    ): bool {
        $lang = MailLocale::resolveLang($lang);
        $amountFmt = number_format($amount, 2);
        $ref = $txHash !== '' ? MailLocale::t('deposit_pending_tx', $lang) : '';
        $subject = $this->subjectPrefix(MailLocale::t('deposit_pending_subject', $lang, ['amount' => $amountFmt]), $lang);
        $inner = '<p>' . MailLocale::t('deposit_pending_hi', $lang, ['name' => $username]) . '</p>'
            . '<p>' . MailLocale::t('deposit_pending_body', $lang, ['amount' => $amountFmt, 'ref' => $ref]) . '</p>'
            . '<p><strong>' . htmlspecialchars($methodLabel) . '</strong> · #' . (int) $depositId . '</p>'
            . $this->btn(page_url('add-funds.php'), MailLocale::t('btn_funds', $lang));
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml(MailLocale::t('deposit_pending_subject', $lang, ['amount' => $amountFmt]), $inner, $lang), $lang);
    }

    public function sendDepositPendingToAdmin(
        string $to,
        string $username,
        string $userEmail,
        int $depositId,
        float $amount,
        string $methodLabel,
        string $txHash = '',
        ?string $lang = null
    ): bool {
        $lang = MailLocale::resolveLang($lang ?? 'en');
        $amountFmt = number_format($amount, 2);
        $subject = $this->subjectPrefix(MailLocale::t('admin_deposit_pending_subject', $lang, ['id' => $depositId, 'amount' => $amountFmt]), $lang);
        $inner = '<p>' . MailLocale::t('admin_deposit_pending_body', $lang, ['user' => $username, 'email' => $userEmail, 'amount' => $amountFmt]) . '</p>'
            . '<p><strong>' . htmlspecialchars($methodLabel) . '</strong></p>';
        if ($txHash !== '') {
            $inner .= '<p><strong>' . MailLocale::t('admin_deposit_tx', $lang) . '</strong><br><code style="word-break:break-all;">' . htmlspecialchars($txHash) . '</code></p>';
        }
        $inner .= $this->btn(page_url('admin/admin-deposits.php'), MailLocale::t('btn_admin', $lang));
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml('Deposit #' . $depositId, $inner, $lang), $lang);
    }

    public function sendDepositCreditedToAdmin(
        string $to,
        string $username,
        string $userEmail,
        int $depositId,
        float $amount,
        float $balanceAfter,
        ?string $lang = null
    ): bool {
        $lang = MailLocale::resolveLang($lang ?? 'en');
        $amountFmt = number_format($amount, 2);
        $balanceFmt = number_format($balanceAfter, 2);
        $subject = $this->subjectPrefix(MailLocale::t('admin_deposit_credited_subject', $lang, ['id' => $depositId, 'amount' => $amountFmt]), $lang);
        $inner = '<p>' . MailLocale::t('admin_deposit_credited_body', $lang, ['user' => $username, 'amount' => $amountFmt, 'balance' => $balanceFmt]) . '</p>'
            . '<p style="font-size:13px;color:#666;">' . htmlspecialchars($userEmail) . '</p>'
            . $this->btn(page_url('admin/admin-deposits.php'), MailLocale::t('btn_admin', $lang));
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml('Deposit #' . $depositId, $inner, $lang), $lang);
    }

    public function sendTest(string $to, ?string $lang = null): bool
    {
        $lang = MailLocale::resolveLang($lang);
        $subject = $this->subjectPrefix(MailLocale::t('test_subject', $lang) . ' — ' . date('Y-m-d H:i'), $lang);
        $inner = '<p>' . MailLocale::t('test_body', $lang) . '</p>'
            . '<p><strong>' . MailLocale::t('test_time', $lang) . ':</strong> ' . htmlspecialchars(date('c')) . '</p>'
            . '<p><strong>' . MailLocale::t('test_from', $lang) . ':</strong> ' . htmlspecialchars($this->getFrom()) . '</p>';
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml(MailLocale::t('test_subject', $lang), $inner, $lang), $lang);
    }

    public function sendVerification(string $to, string $username, string $token, ?string $lang = null): bool
    {
        $lang = MailLocale::resolveLang($lang);
        $link = page_url('verify-email.php', ['token' => $token]);
        $subject = $this->subjectPrefix(MailLocale::t('verify_subject', $lang), $lang);
        $inner = '<p>' . MailLocale::t('verify_hi', $lang, ['name' => $username]) . '</p>'
            . '<p>' . MailLocale::t('verify_body', $lang) . '</p>'
            . $this->btn($link, MailLocale::t('btn_verify', $lang))
            . $this->copyLink($link, $lang)
            . '<p style="font-size:13px;color:#666;">' . MailLocale::t('verify_expires', $lang) . '</p>';
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml(MailLocale::t('verify_subject', $lang), $inner, $lang), $lang);
    }

    public function sendPasswordReset(string $to, string $username, string $token, ?string $lang = null): bool
    {
        $lang = MailLocale::resolveLang($lang);
        $link = page_url('reset-password.php', ['token' => $token]);
        $subject = $this->subjectPrefix(MailLocale::t('reset_subject', $lang), $lang);
        $inner = '<p>' . MailLocale::t('reset_hi', $lang, ['name' => $username]) . '</p>'
            . '<p>' . MailLocale::t('reset_body', $lang) . '</p>'
            . $this->btn($link, MailLocale::t('btn_reset', $lang))
            . $this->copyLink($link, $lang)
            . '<p style="font-size:13px;color:#666;">' . MailLocale::t('reset_expires', $lang) . '<br>' . MailLocale::t('reset_ignore', $lang) . '</p>';
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml(MailLocale::t('reset_subject', $lang), $inner, $lang), $lang);
    }

    public function sendDepositConfirmed(string $to, string $username, float $amount, float $balanceAfter, ?int $transactionId = null, ?string $lang = null): bool
    {
        $lang = MailLocale::resolveLang($lang);
        $amountFmt = number_format($amount, 2);
        $balanceFmt = number_format($balanceAfter, 2);
        $ref = $transactionId ? ' (#' . $transactionId . ')' : '';
        $subject = $this->subjectPrefix(MailLocale::t('deposit_subject', $lang, ['amount' => $amountFmt]), $lang);
        $inner = '<p>' . MailLocale::t('deposit_hi', $lang, ['name' => $username]) . '</p>'
            . '<p>' . MailLocale::t('deposit_body', $lang, ['amount' => $amountFmt, 'ref' => $ref]) . '</p>'
            . '<p><strong>' . MailLocale::t('deposit_balance', $lang) . '</strong> $' . htmlspecialchars($balanceFmt) . '</p>'
            . $this->btn(page_url('add-funds.php'), MailLocale::t('btn_deposit', $lang));
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml(MailLocale::t('deposit_subject', $lang, ['amount' => $amountFmt]), $inner, $lang), $lang);
    }

    public function sendOrderPlaced(string $to, string $username, int $orderId, string $serviceName, int $quantity, float $charge, string $link, ?string $lang = null): bool
    {
        $lang = MailLocale::resolveLang($lang);
        $chargeFmt = number_format($charge, 4);
        $subject = $this->subjectPrefix(MailLocale::t('order_placed_subject', $lang, ['id' => $orderId]), $lang);
        $inner = '<p>' . MailLocale::t('order_placed_hi', $lang, ['name' => $username]) . '</p>'
            . '<p>' . MailLocale::t('order_placed_body', $lang) . '</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;">'
            . '<tr><td style="padding:8px;border-bottom:1px solid #eee;color:#666;">#' . (int) $orderId . '</td><td style="padding:8px;border-bottom:1px solid #eee;font-weight:bold;">' . htmlspecialchars($serviceName) . '</td></tr>'
            . '<tr><td style="padding:8px;border-bottom:1px solid #eee;color:#666;">' . MailLocale::t('order_qty', $lang) . '</td><td style="padding:8px;border-bottom:1px solid #eee;">' . number_format($quantity) . '</td></tr>'
            . '<tr><td style="padding:8px;border-bottom:1px solid #eee;color:#666;">' . MailLocale::t('order_charge', $lang) . '</td><td style="padding:8px;border-bottom:1px solid #eee;">$' . htmlspecialchars($chargeFmt) . '</td></tr>'
            . '<tr><td style="padding:8px;color:#666;">' . MailLocale::t('order_link_label', $lang) . '</td><td style="padding:8px;word-break:break-all;font-size:12px;">' . htmlspecialchars($link) . '</td></tr>'
            . '</table>'
            . $this->btn(page_url('orders.php'), MailLocale::t('btn_orders', $lang));
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml('Order #' . $orderId, $inner, $lang), $lang);
    }

    public function sendOrderStatusUpdate(string $to, string $username, int $orderId, string $serviceName, string $status, ?string $lang = null): bool
    {
        $lang = MailLocale::resolveLang($lang);
        $statusLabel = MailLocale::orderStatusLabel($status, $lang);
        $subject = $this->subjectPrefix(MailLocale::t('order_status_subject', $lang, ['id' => $orderId, 'status' => $statusLabel]), $lang);
        $inner = '<p>' . MailLocale::t('order_status_hi', $lang, ['name' => $username]) . '</p>'
            . '<p>' . MailLocale::t('order_status_body', $lang) . '</p>'
            . '<p><strong>#' . (int) $orderId . '</strong> — ' . htmlspecialchars($serviceName) . '</p>'
            . '<p><strong>' . MailLocale::t('order_status_label', $lang) . ':</strong> ' . htmlspecialchars($statusLabel) . '</p>'
            . $this->btn(page_url('orders.php'), MailLocale::t('btn_orders', $lang));
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml('Order #' . $orderId, $inner, $lang), $lang);
    }

    public function sendTicketStaffReply(string $to, string $username, int $ticketId, string $subject, string $message, ?string $lang = null): bool
    {
        $lang = MailLocale::resolveLang($lang);
        $link = page_url('ticket.php', ['id' => $ticketId]);
        $emailSubject = $this->subjectPrefix(MailLocale::t('ticket_reply_subject', $lang, ['id' => $ticketId]), $lang);
        $inner = '<p>' . MailLocale::t('ticket_reply_hi', $lang, ['name' => $username]) . '</p>'
            . '<p>' . MailLocale::t('ticket_reply_body', $lang, ['subject' => $subject]) . '</p>'
            . '<blockquote style="margin:12px 0;padding:12px 16px;background:#f5f5f5;border-left:4px solid #E30A17;">'
            . nl2br(htmlspecialchars($message)) . '</blockquote>'
            . $this->btn($link, MailLocale::t('btn_ticket', $lang));
        return $this->send($to, $emailSubject, strip_tags($inner), $this->wrapHtml('Ticket #' . $ticketId, $inner, $lang), $lang);
    }

    public function sendTicketNewToAdmin(string $to, string $username, string $userEmail, int $ticketId, string $subject, string $message, ?string $lang = null): bool
    {
        $lang = MailLocale::resolveLang($lang ?? 'en');
        $link = page_url('admin/admin-ticket.php', ['id' => $ticketId]);
        $emailSubject = $this->subjectPrefix(MailLocale::t('ticket_new_subject', $lang, ['id' => $ticketId]) . ' — ' . $subject, $lang);
        $inner = '<p>' . MailLocale::t('ticket_new_body', $lang, ['user' => $username, 'email' => $userEmail]) . '</p>'
            . '<p><strong>' . MailLocale::t('ticket_subject_label', $lang) . ':</strong> ' . htmlspecialchars($subject) . '</p>'
            . '<blockquote style="margin:12px 0;padding:12px 16px;background:#f5f5f5;border-left:4px solid #E30A17;">'
            . nl2br(htmlspecialchars($message)) . '</blockquote>'
            . $this->btn($link, MailLocale::t('btn_admin', $lang));
        return $this->send($to, $emailSubject, strip_tags($inner), $this->wrapHtml('Ticket #' . $ticketId, $inner, $lang), $lang);
    }

    public function sendChildPanelReady(
        string $to,
        string $username,
        string $domain,
        string $panelUrl,
        string $parentApi,
        string $apiKey,
        string $adminUsername,
        string $adminPassword,
        ?string $lang = null
    ): bool {
        $lang = MailLocale::resolveLang($lang);
        $loginUrl = rtrim($panelUrl, '/') . '/login';
        $adminUrl = rtrim($panelUrl, '/') . '/admin';
        $subject = $this->subjectPrefix(MailLocale::t('child_ready_subject', $lang, ['domain' => $domain]), $lang);
        $inner = '<p>' . MailLocale::t('child_ready_hi', $lang, ['name' => $username]) . '</p>'
            . '<p>' . MailLocale::t('child_ready_body', $lang, ['domain' => $domain]) . '</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;">'
            . '<tr><td style="padding:8px;border-bottom:1px solid #eee;color:#666;">' . MailLocale::t('child_panel_url', $lang) . '</td><td style="padding:8px;border-bottom:1px solid #eee;word-break:break-all;"><a href="' . htmlspecialchars($panelUrl) . '">' . htmlspecialchars($panelUrl) . '</a></td></tr>'
            . '<tr><td style="padding:8px;border-bottom:1px solid #eee;color:#666;">' . MailLocale::t('child_admin_login', $lang) . '</td><td style="padding:8px;border-bottom:1px solid #eee;word-break:break-all;"><a href="' . htmlspecialchars($loginUrl) . '">' . htmlspecialchars($loginUrl) . '</a></td></tr>'
            . '<tr><td style="padding:8px;border-bottom:1px solid #eee;color:#666;">' . MailLocale::t('child_admin_dashboard', $lang) . '</td><td style="padding:8px;border-bottom:1px solid #eee;word-break:break-all;"><a href="' . htmlspecialchars($adminUrl) . '">' . htmlspecialchars($adminUrl) . '</a></td></tr>'
            . '<tr><td style="padding:8px;border-bottom:1px solid #eee;color:#666;">' . MailLocale::t('child_admin_user', $lang) . '</td><td style="padding:8px;border-bottom:1px solid #eee;font-family:monospace;">' . htmlspecialchars($adminUsername) . '</td></tr>'
            . '<tr><td style="padding:8px;border-bottom:1px solid #eee;color:#666;">' . MailLocale::t('child_admin_pass', $lang) . '</td><td style="padding:8px;border-bottom:1px solid #eee;font-family:monospace;">'
            . ($adminPassword !== '' ? htmlspecialchars($adminPassword) : htmlspecialchars(MailLocale::t('child_admin_pass_parent', $lang)))
            . '</td></tr>'
            . '<tr><td style="padding:8px;border-bottom:1px solid #eee;color:#666;">' . MailLocale::t('child_parent_api', $lang) . '</td><td style="padding:8px;border-bottom:1px solid #eee;word-break:break-all;font-size:12px;">' . htmlspecialchars($parentApi) . '</td></tr>'
            . '<tr><td style="padding:8px;color:#666;">' . MailLocale::t('child_api_key', $lang) . '</td><td style="padding:8px;font-family:monospace;font-size:12px;word-break:break-all;">' . htmlspecialchars($apiKey) . '</td></tr>'
            . '</table>'
            . '<p style="font-size:13px;color:#666;">' . MailLocale::t('child_connect_hint', $lang) . '</p>'
            . '<p style="font-size:13px;color:#666;">' . MailLocale::t('child_brand_hint', $lang) . '</p>'
            . $this->btn($loginUrl, MailLocale::t('btn_login', $lang))
            . $this->btn(page_url('child-panel.php'), MailLocale::t('btn_child_panel', $lang));
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml($domain, $inner, $lang), $lang);
    }

    public function sendResellerLowBalance(string $to, string $username, float $balance, float $minBalance, ?string $lang = null): bool
    {
        $lang = MailLocale::resolveLang($lang);
        $addFunds = page_url('add-funds.php');
        $childPanel = page_url('child-panel.php');
        $subject = $this->subjectPrefix('Low balance — child panel orders may stop', $lang);
        $inner = '<p>Hi ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Your SMM Turk balance is <strong>$' . number_format($balance, 2) . '</strong>. '
            . 'Child panel orders use your parent balance; we recommend at least <strong>$' . number_format($minBalance, 2) . '</strong>.</p>'
            . '<p>Top up now so your customers can keep placing orders 24/7.</p>'
            . $this->btn($addFunds, 'Add funds')
            . $this->btn($childPanel, 'Child panel');
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml('Low balance', $inner, $lang), $lang);
    }

    public function sendWelcomeCredit(string $to, string $username, float $amount, ?string $lang = null): bool
    {
        $dash = page_url('dashboard.php');
        $funds = page_url('add-funds.php');
        $subject = $this->subjectPrefix('Welcome — $' . number_format($amount, 2) . ' free balance', null);
        $inner = '<p>Hi ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Your account is ready with <strong>$' . number_format($amount, 2) . '</strong> free balance. Place your first order now!</p>'
            . '<p>Add crypto later for bigger campaigns — first deposit bonus may apply.</p>'
            . $this->btn($dash, 'Place first order')
            . $this->btn($funds, 'Add funds');
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml('Welcome', $inner, null), null);
    }

    public function sendDepositNudge(string $to, string $username, float $balance, ?string $lang = null): bool
    {
        $addFunds = page_url('add-funds.php');
        $subject = $this->subjectPrefix('Your balance is low — add funds to keep ordering', null);
        $inner = '<p>Hi ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Your balance is <strong>$' . number_format($balance, 2) . '</strong>. Top up to avoid interrupted orders.</p>'
            . $this->btn($addFunds, 'Add Funds');
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml('Low balance', $inner, null), null);
    }

    public function sendWinBack(string $to, string $username, float $spent, ?string $lang = null): bool
    {
        $dash = page_url('dashboard.php');
        $subject = $this->subjectPrefix('We miss you — exclusive deals inside', null);
        $inner = '<p>Hi ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>You have spent <strong>$' . number_format($spent, 2) . '</strong> with us. Come back and place a new order — check our featured services.</p>'
            . $this->btn($dash, 'Place order');
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml('Welcome back', $inner, null), null);
    }

    public function sendChildPanelRenewalReminder(string $to, string $username, string $domain, string $expiresAt, float $price, float $balance, ?string $lang = null): bool
    {
        $child = page_url('child-panel.php');
        $funds = page_url('add-funds.php');
        $subject = $this->subjectPrefix('Child panel renewal — ' . $domain, null);
        $inner = '<p>Hi ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Your child panel <strong>' . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') . '</strong> renews on '
            . htmlspecialchars($expiresAt, ENT_QUOTES, 'UTF-8') . ' ($' . number_format($price, 2) . '/month).</p>'
            . '<p>Current balance: <strong>$' . number_format($balance, 2) . '</strong>.</p>'
            . $this->btn($funds, 'Add funds')
            . $this->btn($child, 'Child panel');
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml('Renewal', $inner, null), null);
    }

    public function sendChildPanelSuspended(string $to, string $username, string $domain, float $price, ?string $lang = null): bool
    {
        $child = page_url('child-panel.php');
        $funds = page_url('add-funds.php');
        $subject = $this->subjectPrefix('Child panel suspended — ' . $domain, null);
        $inner = '<p>Hi ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Panel <strong>' . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') . '</strong> was suspended (insufficient balance for $' . number_format($price, 2) . ' renewal).</p>'
            . '<p>Add funds and contact support or renew from your dashboard.</p>'
            . $this->btn($funds, 'Add funds')
            . $this->btn($child, 'Child panel');
        return $this->send($to, $subject, strip_tags($inner), $this->wrapHtml('Suspended', $inner, null), null);
    }

    /** @return array{domain: string, ok: bool, mx: list<array{priority: int, host: string, resolves: bool, ip: string}>, hint: string, recommended_mx: string, mail_a_ip: string} */
    public function getIncomingMailDiagnostics(): array
    {
        $domain = parse_url(defined('SITE_URL') ? SITE_URL : '', PHP_URL_HOST) ?: '';
        $recommendedMx = $domain !== '' ? 'mail.' . $domain : '';
        $mailA = $recommendedMx !== '' ? $this->resolveMailHostIp($recommendedMx) : '';
        $serverIp = trim((string) ($this->db->getSetting('child_panel_server_ip') ?? ''));
        if ($serverIp === '' && $mailA !== '') {
            $serverIp = $mailA;
        }

        $mxRows = $this->fetchMxRecords($domain);
        $mx = [];
        $ok = $domain !== '' && $mxRows !== [];
        foreach ($mxRows as $row) {
            $mxHost = strtolower(rtrim((string) ($row['host'] ?? ''), '.'));
            $ip = $mxHost !== '' ? $this->resolveMailHostIp($mxHost) : '';
            $resolves = $ip !== '' && $ip !== $mxHost;
            $mx[] = [
                'priority' => (int) ($row['priority'] ?? 0),
                'host' => $mxHost,
                'resolves' => $resolves,
                'ip' => $ip,
            ];
            if (!$resolves) {
                $ok = false;
            }
        }
        if ($mxRows === []) {
            $ok = false;
        }

        $hint = '';
        if ($domain === '') {
            $hint = 'SITE_URL is not set.';
        } elseif ($mxRows === []) {
            $hint = 'No MX record found for ' . $domain . '. Add MX → ' . $recommendedMx . ' in Cloudflare / cPanel Zone Editor.';
        } elseif (!$ok) {
            $broken = implode(', ', array_map(
                static fn(array $r): string => $r['host'] . ($r['resolves'] ? '' : ' (no A record)'),
                $mx
            ));
            $hint = 'MX host does not resolve: ' . $broken . '. Change MX to ' . $recommendedMx
                . ($serverIp !== '' ? ' (A → ' . $serverIp . ')' : '') . ' or add an A record for the broken MX hostname.';
        } else {
            $hint = 'Incoming mail DNS looks OK.';
        }

        return [
            'domain' => $domain,
            'ok' => $ok,
            'mx' => $mx,
            'hint' => $hint,
            'recommended_mx' => $recommendedMx,
            'mail_a_ip' => $mailA,
            'server_ip' => $serverIp,
        ];
    }

    /** @return list<array{priority: int, host: string}> */
    private function fetchMxRecords(string $domain): array
    {
        if ($domain === '') {
            return [];
        }
        $rows = [];
        $raw = @dns_get_record($domain, DNS_MX);
        if (is_array($raw)) {
            foreach ($raw as $rec) {
                $host = strtolower(rtrim((string) ($rec['target'] ?? ''), '.'));
                if ($host !== '') {
                    $rows[] = ['priority' => (int) ($rec['pri'] ?? 0), 'host' => $host];
                }
            }
        }
        if ($rows !== []) {
            usort($rows, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
            return $rows;
        }

        $url = 'https://dns.google/resolve?name=' . rawurlencode($domain) . '&type=MX';
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'SMM-Turk-Mail/1.0']]);
        $json = @file_get_contents($url, false, $ctx);
        if (!is_string($json) || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        foreach ($data['Answer'] ?? [] as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $value = (string) ($answer['data'] ?? '');
            if ($value === '' || !str_contains($value, ' ')) {
                continue;
            }
            [$priority, $host] = explode(' ', $value, 2);
            $host = strtolower(rtrim($host, '.'));
            if ($host !== '') {
                $rows[] = ['priority' => (int) $priority, 'host' => $host];
            }
        }
        usort($rows, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
        return $rows;
    }

    private function resolveMailHostIp(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '') {
            return '';
        }
        $records = @dns_get_record($host, DNS_A);
        if (is_array($records)) {
            foreach ($records as $rec) {
                $ip = (string) ($rec['ip'] ?? '');
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        $resolved = @gethostbyname($host);
        if (is_string($resolved) && $resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
            return $resolved;
        }
        return '';
    }

    public function getDiagnostics(): array
    {
        $host = parse_url(defined('SITE_URL') ? SITE_URL : '', PHP_URL_HOST) ?: 'yourdomain.com';
        $incoming = $this->getIncomingMailDiagnostics();
        return [
            'from' => $this->getFrom(),
            'reply_to' => $this->getReplyTo(),
            'mail_mode' => $this->db->getSetting('mail_mode') ?: 'auto',
            'mail_lang' => $this->db->getSetting('mail_lang') ?: 'tr',
            'smtp_host' => $this->db->getSetting('smtp_host') ?: '(empty)',
            'smtp_port' => $this->db->getSetting('smtp_port') ?: '587',
            'smtp_user' => $this->db->getSetting('smtp_user') ?: '(empty)',
            'smtp_encryption' => $this->db->getSetting('smtp_encryption') ?: 'auto',
            'smtp_pass_set' => trim((string) ($this->db->getSetting('smtp_pass') ?? '')) !== '',
            'smtp_configured' => trim((string) ($this->db->getSetting('smtp_host') ?? '')) !== '',
            'cpanel_hint_host' => $host,
            'cpanel_hint_alt' => 'mail.' . $host,
            'last_error' => $this->lastError,
            'incoming' => $incoming,
        ];
    }
}
