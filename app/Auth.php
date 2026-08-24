<?php
class Auth {

    private Database $db;
    private const DEFAULT_PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function __construct() {
        $this->db = Database::getInstance();
        if (session_status() === PHP_SESSION_NONE) {
            $lifetime = defined('SESSION_LIFETIME') ? (int) SESSION_LIFETIME : 0;
            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (defined('SITE_URL') && str_starts_with((string) SITE_URL, 'https://'));
            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public function register(string $username, string $email, string $password, string $referral_code = ''): array {
        if (strlen($username) < 3 || strlen($username) > 30) {
            return ['success' => false, 'error' => 'Username must be 3-30 characters'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address'];
        }
        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'Password must be at least 6 characters'];
        }

        $email = strtolower(trim($email));
        $username = trim($username);

        $existing = $this->db->fetch("SELECT id FROM users WHERE username = ? OR email = ?", [$username, $email]);
        if ($existing) {
            return ['success' => false, 'error' => 'Username or email already exists'];
        }

        $referred_by = null;
        if ($referral_code) {
            $referrer = $this->db->fetch("SELECT id FROM users WHERE referral_code = ?", [$referral_code]);
            if ($referrer) $referred_by = $referrer['id'];
        }

        // Email verification is required by default; admin can disable in Settings.
        $verifyRequired = ($this->db->getSetting('email_verification_required') ?? '1') !== '0';
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $ref_code = strtolower(substr(md5(uniqid()), 0, 8));
        $api_key = bin2hex(random_bytes(20));

        if ($verifyRequired) {
            $token = bin2hex(random_bytes(24));
            $expires = date('Y-m-d H:i:s', time() + 86400);
            $id = $this->db->insert(
                "INSERT INTO users (username, email, password, referral_code, referred_by, api_key, status, email_verification_token, email_verification_expires) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)",
                [$username, $email, $hashed, $ref_code, $referred_by, $api_key, $token, $expires]
            );
            $mail = new Mail();
            $emailSent = $mail->sendVerification($email, $username, $token);
            if (!$emailSent) {
                Logger::log('Verification email failed for ' . $email . ': ' . ($mail->getLastError() ?? 'unknown'), 'mail');
            }
            Notify::signup($username, $email, (int) $id);
            $this->reportChildEndUser((int) $id, $username, $email, 'pending');
            $this->applyGrowthOnSignup((int) $id, false);
            return [
                'success' => true,
                'user_id' => $id,
                'verify_required' => true,
                'email_sent' => $emailSent,
            ];
        }

        $id = $this->db->insert(
            "INSERT INTO users (username, email, password, referral_code, referred_by, api_key, status) VALUES (?, ?, ?, ?, ?, ?, 'active')",
            [$username, $email, $hashed, $ref_code, $referred_by, $api_key]
        );

        Notify::signup($username, $email, (int) $id);
        Notify::welcome($username, $email);
        $this->reportChildEndUser((int) $id, $username, $email, 'active');
        $this->applyGrowthOnSignup((int) $id, true);

        return ['success' => true, 'user_id' => $id, 'verify_required' => false, 'email_sent' => false];
    }

    /** Ensure user has a unique referral code (legacy accounts may lack one). */
    public function ensureReferralCode(int $userId): string {
        $user = $this->db->fetch("SELECT referral_code FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            return '';
        }
        $code = trim((string)($user['referral_code'] ?? ''));
        if ($code !== '') {
            return $code;
        }
        for ($i = 0; $i < 8; $i++) {
            $code = strtolower(substr(bin2hex(random_bytes(4)), 0, 8));
            $exists = $this->db->fetch("SELECT id FROM users WHERE referral_code = ? AND id != ?", [$code, $userId]);
            if (!$exists) {
                $this->db->execute("UPDATE users SET referral_code = ? WHERE id = ?", [$code, $userId]);
                return $code;
            }
        }
        return '';
    }

    /**
     * Resend verification email for pending accounts. Returns success even if email not found (avoid enumeration).
     */
    public function resendVerificationEmail(string $email): array {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address.'];
        }

        $user = $this->db->fetch(
            "SELECT id, username, status FROM users WHERE email = ? AND status != 'banned'",
            [$email]
        );
        if (!$user || ($user['status'] ?? '') === 'active') {
            return ['success' => true, 'email_sent' => false];
        }

        $token = bin2hex(random_bytes(24));
        $expires = date('Y-m-d H:i:s', time() + 86400);
        $this->db->execute(
            "UPDATE users SET email_verification_token = ?, email_verification_expires = ?, status = 'pending' WHERE id = ?",
            [$token, $expires, $user['id']]
        );

        $mail = new Mail();
        $emailSent = $mail->sendVerification($email, $user['username'], $token);
        if (!$emailSent) {
            Logger::log('Resend verification failed for ' . $email . ': ' . ($mail->getLastError() ?? 'unknown'), 'mail');
        }

        return ['success' => true, 'email_sent' => $emailSent];
    }

    public function loginById(int $userId): array {
        $user = $this->db->fetch("SELECT * FROM users WHERE id = ? AND status != 'banned'", [$userId]);
        if (!$user) {
            return ['success' => false, 'error' => 'Account not found.'];
        }
        return $this->finalizeLogin($user);
    }

    public function login(string $email_or_username, string $password): array {
        $email_or_username = trim($email_or_username);
        $loginEmail = str_contains($email_or_username, '@') ? strtolower($email_or_username) : $email_or_username;
        $user = $this->db->fetch(
            "SELECT * FROM users WHERE (LOWER(email) = ? OR username = ?) AND status != 'banned'",
            [$loginEmail, $email_or_username]
        );

        if (!$user || !password_verify($password, $user['password'])) {
            return ['success' => false, 'error' => 'Invalid credentials'];
        }
        if (($user['status'] ?? 'active') === 'pending') {
            return ['success' => false, 'error' => 'Please verify your email first. Check your inbox and click the activation link.'];
        }

        return $this->finalizeLogin($user);
    }

    public function hasPendingTwoFactor(): bool {
        if (empty($_SESSION['pending_2fa_user_id'])) {
            return false;
        }
        if (!empty($_SESSION['pending_2fa_expires']) && time() > (int)$_SESSION['pending_2fa_expires']) {
            $this->clearPendingTwoFactor();
            return false;
        }
        return true;
    }

    public function completeTwoFactorLogin(string $code): array {
        if (!$this->hasPendingTwoFactor()) {
            return ['success' => false, 'error' => 'Two-factor session expired. Please sign in again.'];
        }
        $userId = (int)$_SESSION['pending_2fa_user_id'];
        $user = $this->db->fetch("SELECT * FROM users WHERE id = ? AND status != 'banned'", [$userId]);
        if (!$user || empty($user['two_factor_secret']) || empty($user['two_factor_enabled'])) {
            $this->clearPendingTwoFactor();
            return ['success' => false, 'error' => 'Invalid two-factor session. Please sign in again.'];
        }
        if (!Totp::verify($user['two_factor_secret'], $code)) {
            return ['success' => false, 'error' => 'Invalid authentication code. Try again.'];
        }
        $this->clearPendingTwoFactor();
        return $this->finalizeLogin($user, false);
    }

    public function startTwoFactorSetup(int $userId): array {
        $user = $this->db->fetch("SELECT id, username, email FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found.'];
        }
        $secret = Totp::generateSecret();
        $_SESSION['2fa_setup_secret'] = $secret;
        $_SESSION['2fa_setup_user_id'] = $userId;
        $_SESSION['2fa_setup_expires'] = time() + 600;
        $siteName = defined('SITE_NAME') ? SITE_NAME : 'SMM Turk';
        $label = $user['email'] ?: $user['username'];
        $uri = Totp::getProvisioningUri($secret, $label, $siteName);
        return ['success' => true, 'secret' => $secret, 'uri' => $uri];
    }

    public function confirmTwoFactorSetup(int $userId, string $code): array {
        if (empty($_SESSION['2fa_setup_secret']) || (int)($_SESSION['2fa_setup_user_id'] ?? 0) !== $userId) {
            return ['success' => false, 'error' => 'Setup session expired. Start again.'];
        }
        if (!empty($_SESSION['2fa_setup_expires']) && time() > (int)$_SESSION['2fa_setup_expires']) {
            unset($_SESSION['2fa_setup_secret'], $_SESSION['2fa_setup_user_id'], $_SESSION['2fa_setup_expires']);
            return ['success' => false, 'error' => 'Setup session expired. Start again.'];
        }
        $secret = (string)$_SESSION['2fa_setup_secret'];
        if (!Totp::verify($secret, $code)) {
            return ['success' => false, 'error' => 'Invalid code. Check your authenticator app and try again.'];
        }
        try {
            $this->db->execute(
                "UPDATE users SET two_factor_enabled = 1, two_factor_secret = ? WHERE id = ?",
                [$secret, $userId]
            );
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Could not enable 2FA. Run php migrate-two-factor.php first.'];
        }
        unset($_SESSION['2fa_setup_secret'], $_SESSION['2fa_setup_user_id'], $_SESSION['2fa_setup_expires']);
        return ['success' => true];
    }

    public function disableTwoFactor(int $userId, string $password, string $code): array {
        $user = $this->db->fetch("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user || !password_verify($password, $user['password'])) {
            return ['success' => false, 'error' => 'Current password is incorrect.'];
        }
        if (empty($user['two_factor_secret']) || !Totp::verify($user['two_factor_secret'], $code)) {
            return ['success' => false, 'error' => 'Invalid authentication code.'];
        }
        $this->db->execute(
            "UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL WHERE id = ?",
            [$userId]
        );
        return ['success' => true];
    }

    private function clearPendingTwoFactor(): void {
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_expires']);
    }

    private function userNeedsTwoFactor(array $user): bool {
        return !empty($user['two_factor_enabled']) && !empty($user['two_factor_secret']);
    }

    private function beginPendingTwoFactor(array $user): array {
        $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
        $_SESSION['pending_2fa_expires'] = time() + 300;
        return ['success' => true, 'needs_2fa' => true];
    }

    private function finalizeLogin(array $user, bool $checkTwoFactor = true): array {
        if ($checkTwoFactor && $this->userNeedsTwoFactor($user)) {
            return $this->beginPendingTwoFactor($user);
        }
        $this->setSession($user);
        $this->applyPasswordChangeRequirement($user);
        $this->db->execute("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);
        return [
            'success' => true,
            'role' => $user['role'],
            'must_change_password' => !empty($_SESSION['must_change_password']),
        ];
    }

    public function logout(): void {
        session_destroy();
        header('Location: ' . url('login.php'));
        exit;
    }

    public function isLoggedIn(): bool {
        if (empty($_SESSION['logged_in']) || empty($_SESSION['user_id'])) {
            return false;
        }
        return $this->refreshSessionLifetime();
    }

    public function isAdmin(): bool {
        if (!$this->isLoggedIn()) {
            return false;
        }
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $user = $this->db->fetch("SELECT role, status FROM users WHERE id = ?", [$this->getUserId()]);
        if (!$user || ($user['status'] ?? '') === 'banned') {
            return false;
        }
        if (($user['role'] ?? '') !== ($_SESSION['role'] ?? '')) {
            $_SESSION['role'] = $user['role'];
        }
        $cached = ($user['role'] ?? '') === 'admin';
        return $cached;
    }

    public function requireLogin(): void {
        if (!$this->isLoggedIn()) {
            $returnPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $query = $_SERVER['QUERY_STRING'] ?? '';
            if ($query !== '') {
                $returnPath .= '?' . $query;
            }
            header('Location: ' . url('login.php') . '?next=' . urlencode($returnPath));
            exit;
        }
        $this->enforcePasswordChangeIfNeeded();
    }

    public function requireAdmin(): void {
        if (!$this->isAdmin()) {
            header('Location: ' . url('dashboard.php'));
            exit;
        }
        $this->enforcePasswordChangeIfNeeded();
    }

    public function postLoginRedirectUrl(array $loginResult = []): string {
        if (!empty($_SESSION['must_change_password']) || !empty($loginResult['must_change_password'])) {
            return page_url('account-settings.php', ['change_password' => '1']);
        }
        if (!empty($_SESSION['login_next'])) {
            return consume_login_next();
        }
        if (($loginResult['role'] ?? $_SESSION['role'] ?? '') === 'admin') {
            return url('admin/index.php');
        }
        return url('dashboard.php');
    }

    private function enforcePasswordChangeIfNeeded(): void {
        if (empty($_SESSION['must_change_password'])) {
            return;
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('#(?:/settings|account-settings)(?:[/?]|$)|logout#', $uri)) {
            return;
        }
        flash('error', 'Please change your default password before continuing.');
        redirect(page_url('account-settings.php', ['change_password' => '1']));
    }

    private function applyPasswordChangeRequirement(array $user): void {
        if (!empty($user['must_change_password']) || $this->usesDefaultPasswordHash($user['password'] ?? '')) {
            $_SESSION['must_change_password'] = true;
            return;
        }
        unset($_SESSION['must_change_password']);
    }

    private function usesDefaultPasswordHash(string $hash): bool {
        return hash_equals(self::DEFAULT_PASSWORD_HASH, $hash);
    }

    private function clearPasswordChangeRequirement(int $userId): void {
        unset($_SESSION['must_change_password']);
        try {
            $this->db->execute("UPDATE users SET must_change_password = 0 WHERE id = ?", [$userId]);
        } catch (Throwable $e) {
            // column may not exist on older installs until migration runs
        }
    }

    public function getCurrentUser(): ?array {
        if (!$this->isLoggedIn()) return null;
        return $this->db->fetch("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);
    }

    public function getUserId(): int {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    public function updateEmail(int $userId, string $newEmail, string $currentPassword): array {
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address'];
        }
        $user = $this->db->fetch("SELECT id, password, email FROM users WHERE id = ?", [$userId]);
        if (!$user || !password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'error' => 'Current password is incorrect'];
        }
        $existing = $this->db->fetch("SELECT id FROM users WHERE email = ? AND id != ?", [$newEmail, $userId]);
        if ($existing) {
            return ['success' => false, 'error' => 'Email already in use'];
        }
        $this->db->execute("UPDATE users SET email = ? WHERE id = ?", [$newEmail, $userId]);
        return ['success' => true];
    }

    public function updatePassword(int $userId, string $currentPassword, string $newPassword): array {
        if (strlen($newPassword) < 6) {
            return ['success' => false, 'error' => 'New password must be at least 6 characters'];
        }
        $user = $this->db->fetch("SELECT password FROM users WHERE id = ?", [$userId]);
        if (!$user || !password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'error' => 'Current password is incorrect'];
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->db->execute("UPDATE users SET password = ? WHERE id = ?", [$hash, $userId]);
        $this->clearPasswordChangeRequirement($userId);
        try {
            (new ChildPanelManager())->syncChildPanelsPasswordHashForUser($userId, $hash);
        } catch (Throwable $e) {
            Logger::log('Child panel password sync: ' . $e->getMessage(), 'child_panel');
        }
        return ['success' => true];
    }

    /**
     * Request password reset: create token and send email. Returns success true even if email not found (avoid enumeration).
     */
    public function requestPasswordReset(string $email): array {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address'];
        }
        $user = $this->db->fetch("SELECT id, username FROM users WHERE email = ? AND status != 'banned'", [$email]);
        if (!$user) {
            return ['success' => true];
        }
        $token = bin2hex(random_bytes(24));
        $expires = date('Y-m-d H:i:s', time() + 3600);
        try {
            $this->db->execute(
                "UPDATE users SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?",
                [$token, $expires, $user['id']]
            );
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'Password reset is not available. Please contact support.'];
        }
        $mail = new Mail();
        $emailSent = $mail->sendPasswordReset($email, $user['username'], $token);
        if (!$emailSent) {
            Logger::log('Password reset email failed for ' . $email . ': ' . ($mail->getLastError() ?? 'unknown'), 'mail');
        }
        return ['success' => true, 'email_sent' => $emailSent];
    }

    /**
     * Reset password using token from email. Returns ['success' => bool, 'error' => string|null].
     */
    public function resetPasswordByToken(string $token, string $newPassword): array {
        if (strlen($newPassword) < 6) {
            return ['success' => false, 'error' => 'Password must be at least 6 characters'];
        }
        $token = trim($token);
        if ($token === '') {
            return ['success' => false, 'error' => 'Invalid reset link.'];
        }
        $user = $this->db->fetch(
            "SELECT id FROM users WHERE password_reset_token = ? AND password_reset_expires > NOW()",
            [$token]
        );
        if (!$user) {
            return ['success' => false, 'error' => 'This reset link is invalid or has expired.'];
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->db->execute(
            "UPDATE users SET password = ?, password_reset_token = NULL, password_reset_expires = NULL WHERE id = ?",
            [$hash, $user['id']]
        );
        $this->clearPasswordChangeRequirement((int)$user['id']);
        return ['success' => true];
    }

    /**
     * Login or create user from Google OAuth (email, name, google_id from Google profile).
     * Returns ['success' => bool, 'error' => string|null].
     */
    public function loginOrCreateFromGoogle(string $googleId, string $email, string $name): array {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email from Google'];
        }
        $googleId = trim($googleId);
        if ($googleId === '') {
            return ['success' => false, 'error' => 'Missing Google ID'];
        }

        // Existing user by google_id
        $user = $this->db->fetch("SELECT * FROM users WHERE google_id = ? AND status != 'banned'", [$googleId]);
        if ($user) {
            $this->db->execute("UPDATE users SET last_login = NOW(), email = ? WHERE id = ?", [$email, $user['id']]);
            $user = $this->db->fetch("SELECT * FROM users WHERE id = ?", [$user['id']]) ?: $user;
            return $this->finalizeLogin($user);
        }

        // Existing user by email — only link verified (active) accounts without Google linked yet
        $user = $this->db->fetch("SELECT * FROM users WHERE email = ? AND status != 'banned'", [$email]);
        if ($user) {
            if (($user['status'] ?? '') === 'pending') {
                return ['success' => false, 'error' => 'Please verify your email first, then sign in with Google again.'];
            }
            if (!empty($user['google_id']) && $user['google_id'] !== $googleId) {
                return ['success' => false, 'error' => 'This email is linked to another Google account. Sign in with your password.'];
            }
            try {
                $this->db->execute("UPDATE users SET google_id = ?, last_login = NOW() WHERE id = ?", [$googleId, $user['id']]);
            } catch (Throwable $e) {
                return ['success' => false, 'error' => 'Google sign-in is not available yet. Please contact support.'];
            }
            $user = $this->db->fetch("SELECT * FROM users WHERE id = ?", [$user['id']]) ?: $user;
            return $this->finalizeLogin($user);
        }

        // New user: create (no email verification for Google sign-in)
        $username = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($name));
        if (strlen($username) < 3) $username = 'user' . substr(uniqid(), -6);
        $existing = $this->db->fetch("SELECT id FROM users WHERE username = ?", [$username]);
        if ($existing) $username = $username . substr(uniqid(), -4);
        $api_key = bin2hex(random_bytes(20));
        $ref_code = strtolower(substr(md5(uniqid()), 0, 8));
        $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

        $id = $this->db->insert(
            "INSERT INTO users (username, email, password, role, status, api_key, referral_code, google_id) VALUES (?, ?, ?, 'user', 'active', ?, ?, ?)",
            [$username, $email, $passwordHash, $api_key, $ref_code, $googleId]
        );
        $user = $this->db->fetch("SELECT * FROM users WHERE id = ?", [$id]);
        if ($user) {
            Notify::signup($username, $email, (int) $id, true);
            Notify::welcome($username, $email);
            $this->reportChildEndUser((int) $id, $username, $email, 'active');
            $this->applyGrowthOnSignup((int) $id, true);
            return $this->finalizeLogin($user);
        }
        return ['success' => false, 'error' => 'Could not create account'];
    }

    private function reportChildEndUser(int $userId, string $username, string $email, string $status): void
    {
        if (!class_exists('ChildPanelUserSync', false)) {
            return;
        }
        ChildPanelUserSync::reportRegistration([
            'user_id' => $userId,
            'username' => $username,
            'email' => $email,
            'status' => $status,
            'registered_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function applyGrowthOnSignup(int $userId, bool $grantCredit): void
    {
        if (!class_exists('GrowthEngine', false)) {
            return;
        }
        $growth = new GrowthEngine();
        $growth->applyUtmToUser($userId);
        if ($grantCredit) {
            $growth->grantWelcomeCredit($userId);
        }
    }

    private function setSession(array $user): void {
        session_regenerate_id(true);
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['role']      = $user['role'];
        $_SESSION['logged_in'] = true;
        $_SESSION['last_activity'] = time();
    }

    private function refreshSessionLifetime(): bool {
        $lifetime = defined('SESSION_LIFETIME') ? (int)SESSION_LIFETIME : 0;
        if ($lifetime <= 0) {
            $_SESSION['last_activity'] = time();
            return true;
        }
        $now = time();
        $last = (int)($_SESSION['last_activity'] ?? $now);
        if ($now - $last > $lifetime) {
            $this->destroySessionQuietly();
            return false;
        }
        $_SESSION['last_activity'] = $now;
        return true;
    }

    private function destroySessionQuietly(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
