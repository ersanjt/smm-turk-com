# SMM Turk Panel — Setup Guide

## Requirements
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- cURL extension enabled
- Apache / Nginx web server

---

## Installation Steps

### 1. Upload Files
Upload all files to your web server's public directory (e.g., `/var/www/html/` or `public_html/`).

### 2. Create Database
1. Go to **phpMyAdmin** or run MySQL CLI
2. Create a new database named `smm_turk`
3. Import the file `install.sql`:
   ```sql
   mysql -u root -p smm_turk < install.sql
   ```

### 3. Configure the App
Edit `config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'smm_turk');
define('DB_USER', 'your_mysql_user');
define('DB_PASS', 'your_mysql_password');
define('SITE_URL', 'https://smm-turk.com');
define('PROVIDER_API_KEY', 'YOUR_SMMFOLLOWS_API_KEY');
```

### 4. Get Your SmmFollows API Key
1. Login to https://smmfollows.com
2. Go to **Account → API**
3. Copy your API key
4. Paste it in `config.php` as `PROVIDER_API_KEY`

### 4b. Payments (Crypto only)
Customer deposits are **crypto only**. You can set wallet addresses in **Admin → Settings → Crypto Wallets** (BTC, ETH, USDT TRC20/ERC20, BNB, SOL). If none are set, the panel uses `CRYPTO_WALLET_ADDRESS` from `config.php`. Deposits are approved manually in **Admin → Pending Deposits**.

### 4c. Google Sign-In (optional)
To let customers log in with Google:
1. Create OAuth credentials at [Google Cloud Console](https://console.cloud.google.com/apis/credentials)
2. Set redirect URI to `https://yourdomain.com/login-google-callback` (no .php; site uses clean URLs)
3. In `config.php` set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`
4. Run migration: `migrations/002_google_oauth.sql` (adds `google_id` to `users`)

### 5. First admin and login
- If your install created a default admin (e.g. via a seed or install script), use those credentials once to log in.
- **Immediately** change the admin password in **Account Settings** (or create a new admin user and disable/remove the default one).
- For production, set a **fixed** `SECRET_KEY` in `config.php` (e.g. 32-char hex from `openssl rand -hex 16` or env `SMM_SECRET_KEY`). Do not use a random value generated on each request, or sessions will break on restart.

### 5b. Optional: Forgot password
To enable "Forgot password" and email reset links, run the migration `migrations/003_password_reset.sql` once (adds `password_reset_token` and `password_reset_expires` to `users`). Ensure mail (e.g. Mail From in Admin → Settings) is configured.

### 6. Sync Services
1. Login as admin
2. Go to **Admin → Sync Services**
3. Click **Sync Now**
4. All services from SmmFollows will be imported

### 7. Apache Configuration (.htaccess)
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^api/v2$ /api/v2.php [L]
```

### 8. Nginx Configuration
```nginx
location /api/v2 {
    try_files $uri /api/v2.php$is_args$args;
}
```

---

## File Structure
```
smm-turk/
├── config.php              # Main configuration
├── config.example.php      # Config template
├── install.sql             # Database schema
├── install-cpanel.sql      # cPanel database (no CREATE DB)
├── index.php               # New Order / Dashboard
├── home.php                # Landing page
├── login.php               # Login/Register
├── logout.php              # Logout
├── add-funds.php           # Add funds
├── orders.php              # Order history
├── services.php            # Services list
├── mass-order.php          # Mass ordering
├── tickets.php             # Support tickets
├── affiliates.php          # Referral system
├── api-page.php            # API documentation
├── cron-sync.php           # Cron: sync order statuses
├── install-db.php          # DB setup helper (delete after use)
│
├── app/                    # Core application
│   ├── init.php            # Bootstrap loader
│   ├── Database.php        # Database (PDO)
│   ├── Auth.php            # Authentication
│   ├── SmmApi.php          # SmmFollows API
│   └── OrderManager.php    # Order logic
│
├── layouts/                # Layout templates
│   ├── header.php
│   └── footer.php
│
├── admin/                  # Admin panel
│   ├── index.php
│   ├── admin-users.php
│   ├── admin-sync.php
│   └── admin-settings.php
│
├── api/
│   └── v2.php              # Public API
│
└── assets/                 # Static assets
    ├── css/
    └── js/
```

---

## API Usage (for resellers)
Your customers can integrate via API:

**Endpoint:** `POST https://smm-turk.com/api/v2`

```php
$api_key = 'USER_API_KEY';

// Get services
$ch = curl_init('https://smm-turk.com/api/v2');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, "key=$api_key&action=services");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$response = json_decode(curl_exec($ch));
```

---

## Cron Jobs (recommended)
Add to crontab to auto-sync order statuses:
```bash
# Sync order statuses every 5 minutes
*/5 * * * * php /var/www/html/cron-sync.php
```

---

## Security Notes
- Change the default admin password (or create your first admin and remove defaults) right after install.
- Use a **fixed** `SECRET_KEY` in production (env `SMM_SECRET_KEY` or constant in `config.php`); avoid generating it at runtime.
- In production, keep errors hidden: use default (no `SMM_DEBUG`) or set `define('SMM_DEBUG', false);` in `config.php`.
- Use HTTPS (SSL certificate required).
- Keep `config.php` permissions restrictive (e.g. 640).

## Production deployment checklist
- [ ] Copy `config.example.php` to `config.php` and set DB, `SITE_URL`, `PROVIDER_API_KEY`, `CRYPTO_WALLET_ADDRESS`.
- [ ] Set a fixed `SECRET_KEY` (or `SMM_SECRET_KEY` env); do not rely on random generation in production.
- [ ] Create or secure the first admin user and change default password.
- [ ] Ensure HTTPS; session cookies will use Secure when the site is served over HTTPS.
- [ ] Leave `SMM_DEBUG` unset or set to `false` so DB errors and paths are not shown to users.
- [ ] Disable or remove `install-db.php` (or restrict by IP) after initial setup.
- [ ] Configure cron for `cron-sync.php` if you use order sync.
- [ ] Optional: set up database backups.
- [ ] Optional: use `/health` or `/health.php` for uptime monitoring (returns 200 + `{"status":"ok","db":"ok"}` when DB is reachable; 503 on failure).

---

## GitHub Setup

### First-time setup

1. Copy config template and never commit real credentials:
   ```bash
   cp config.example.php config.php
   # Edit config.php with your values (config.php is in .gitignore)
   ```

2. Create a new repository on GitHub (do not initialize with README).

3. Initialize and push from your project folder:
   ```bash
   git init
   git add .
   git commit -m "Initial commit: SMM Turk Panel"
   git branch -M main
   git remote add origin https://github.com/YOUR_USERNAME/smm-turk-panel.git
   git push -u origin main
   ```
