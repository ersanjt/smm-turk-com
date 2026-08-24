# 🚀 راهنمای راه‌اندازی SMM Turk Panel روی cPanel

---

## چک‌لیست: چه چیزهایی باید داشته باشی

| مورد | توضیح |
|------|--------|
| **هاست cPanel** | هاست با PHP 8+ و MySQL |
| **دامنه یا ساب‌دامین** | مثلاً `smm-turk.com` یا `panel.yoursite.com` |
| **API Key از SmmFollows** | از [smmfollows.com](https://smmfollows.com) → Account → API |
| **حساب SmmFollows** | با موجودی کافی برای سفارش‌ها |

---

## مرحله ۱: آپلود فایل‌ها

1. وارد **File Manager** در cPanel شو.
2. به پوشه `public_html` (یا `domains/yourdomain.com/public_html`) برو.
3. فایل‌ها را به این پوشه آپلود کن. می‌توانی:
   - از طریق File Manager فایل ZIP آپلود کنی و Extract بزنی، یا
   - با FTP (مثلاً FileZilla) وصل شوی و فایل‌ها را بکشی.

---

## مرحله ۲: ایجاد دیتابیس MySQL

1. در cPanel به **MySQL® Databases** برو.
2. یک **دیتابیس جدید** بساز (مثلاً `cpanel_user_smmturk`).
3. یک **کاربر MySQL** بساز و پسورد قوی بده.
4. کاربر را به دیتابیس **Add** کن و **All Privileges** بده.
5. نام دیتابیس، کاربر و پسورد را یادداشت کن.

---

## مرحله ۳: ایمپورت دیتابیس

### ۳.۱ باز کردن phpMyAdmin و انتخاب دیتابیس

1. در cPanel وارد **phpMyAdmin** شو (معمولاً در بخش «Databases» یا «MySQL® Databases» لینک «phpMyAdmin» هست).
2. در سمت چپ لیست دیتابیس‌ها را می‌بینی. روی **نام دیتابیسی که ساختی** (مثلاً `cpanel_user_smmturk` یا `smmturk_tork`) کلیک کن تا انتخاب شود.
3. بعد از انتخاب، بالای صفحه نام همان دیتابیس نمایش داده می‌شود. مطمئن شو همان دیتابیسی است که در `config.php` گذاشته‌ای.

### ۳.۲ نصب اولیه (اولین بار)

اگر پنل را **تازه** نصب می‌کنی و هنوز جدول‌ها را نساخته‌ای:

1. بالای صفحه تب **Import** را بزن.
2. روی **Choose File** / **انتخاب فایل** کلیک کن و فایل **`install-cpanel.sql`** را از داخل پوشهٔ پروژه انتخاب کن.
3. پایین صفحه **Go** (یا **برو**) را بزن.
4. اگر موفق باشد، پیامی مثل «Import has been successfully finished» می‌بینی.
5. بعد از این، **فقط** فایل **`migrations/003_password_reset.sql`** را هم یک بار Import کن (ستون‌های فراموشی رمز در `install-cpanel.sql` نیستند، پس باید جدا اضافه شوند):
   - دوباره تب **Import** → انتخاب فایل `migrations/003_password_reset.sql` → **Go**.

بعد از این دو مرحله نصب اولیهٔ دیتابیس تمام است.

### ۳.۳ آپدیت دیتابیس (قبلاً نصب کرده بودی)

اگر پنل را **قبلاً** نصب کرده بودی و الان بعد از یک دیپلوی جدید می‌خواهی قابلیت‌های جدید (ورود با گوگل، فراموشی رمز) را فعال کنی:

1. در phpMyAdmin همان دیتابیس پنل را انتخاب کن.
2. تب **Import** را بزن.
3. هر کدام از فایل‌های زیر را **فقط یک بار** Import کن. اگر ستون از قبل وجود داشته باشد، ممکن است خطای «Duplicate column» بگیری؛ در آن صورت آن فایل را نادیده بگیر و سراغ بعدی برو.

| فایل | کاربرد |
|------|--------|
| `migrations/001_email_verification.sql` | ستون‌های تایید ایمیل (`email_verification_token`, `email_verification_expires`) |
| `migrations/002_google_oauth.sql` | ستون ورود با گوگل (`google_id`) |
| `migrations/003_password_reset.sql` | ستون‌های فراموشی رمز (`password_reset_token`, `password_reset_expires`) |

**ترتیب پیشنهادی:** اول ۰۰۱، بعد ۰۰۲، بعد ۰۰۳.

### ۳.۴ اگر خطا گرفتی

- **Duplicate column name**  
  یعنی آن ستون از قبل در جدول `users` هست. نیازی به کار نیست؛ آن فایل مایگریشن را اجرا نکن یا نادیده بگیر.

- **Table 'users' doesn't exist**  
  یعنی اول باید `install-cpanel.sql` را Import کنی (بخش ۳.۲).

- **Cannot add foreign key**  
  نادر است؛ اگر دیدی، بگو تا برات دقیق‌تر راهنمایی کنم.

### ۳.۵ خلاصهٔ سریع

| وضعیت | کارهایی که باید انجام بدی |
|--------|----------------------------|
| نصب اولیه | Import کردن `install-cpanel.sql` سپس `migrations/003_password_reset.sql` |
| آپدیت (قبلاً نصب داشتی) | یک بار Import کردن `001`، `002`، `003` (در صورت نیاز؛ اگر خطای Duplicate column دیدی، همان یکی را skip کن) |

---

## مرحله ۴: تنظیم config.php

1. فایل `config.example.php` را **کپی** کن و اسمش را به `config.php` بگذار.
2. `config.php` را ویرایش کن و مقدارها را پر کن:

```php
define('DB_HOST', 'localhost');              // معمولاً localhost
define('DB_NAME', 'نام_دیتابیس');            // مثلاً cpanel_user_smmturk
define('DB_USER', 'نام_کاربر');              // کاربر MySQL
define('DB_PASS', 'رمز_دیتابیس');            // پسورد MySQL
define('SITE_URL', 'https://smm-turk.com');   // آدرس اصلی سایت، بدون / در آخر
define('GEO_REGION', 'TR');                    // منطقهٔ جغرافیایی برای سئو (TR = ترکیه)
define('PROVIDER_API_KEY', 'API_KEY_SMMFOLLOWS');
```

**سئو و Geo (برای دامنهٔ اصلی):** برای سایت smm-turk.com حتماً `SITE_URL` را روی `https://smm-turk.com` و در صورت تمایل `GEO_REGION` را روی `TR` بگذار. این مقادیر برای لینک‌های canonical، Open Graph، hreflang و متاهای جغرافیایی استفاده می‌شوند. فایل `robots.txt` در روت پروژه قرار دارد و ایندکس شدن توسط موتورهای جستجو را مجاز می‌کند.

### اصلاح لینک‌ها (اگر لینک‌ها درست کار نمی‌کنند)

**همهٔ لینک‌های سایت** (ورود، ثبت‌نام، داشبورد، API، پشتیبانی و …) از **یک جا** ساخته می‌شوند: مقدار **`SITE_URL`** در فایل **`config.php`**.

| کاری که می‌خواهی | مقدار SITE_URL |
|-------------------|----------------|
| سایت در **روت دامنه** (مثلاً `https://smm-turk.com/home.php`) | `https://smm-turk.com` |
| سایت در **زیرپوشه** (مثلاً `https://smm-turk.com/panel/home.php`) | `https://smm-turk.com/panel` |

**قوانین:**
1. همیشه با **https** (در production) و **بدون** اسلش در آخر.
2. دقیقاً همان آدرسی را بگذار که کاربر در مرورگر می‌زند تا به صفحهٔ اصلی/لاگین برسد (اگر زیرپوشه داری، حتماً در SITE_URL باشد).
3. بعد از تغییر `config.php` یک بار صفحه را رفرش کن؛ نیازی به تغییر هیچ فایل PHP دیگری نیست.

اگر لینک‌ها هنوز اشتباه هستند، چک کن که واقعاً فایل **`config.php`** (نه فقط `config.example.php`) را ویرایش کرده‌ای و مقدار `SITE_URL` را ذخیره کرده‌ای.

---

## مرحله ۵: تنظیم .htaccess (Apache)

اگر از Apache استفاده می‌کنی، این قوانین را در `.htaccess` پوشه اصلی اضافه کن:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^api/v2$ /api/v2.php [L]
```

اگر فایل `.htaccess` نداشتی، یک فایل جدید با همین نام بساز.

---

## مرحله ۶: دسترسی‌ها (Permissions)

پوشه‌ها را معمولاً روی `755` و فایل‌ها روی `644` بگذار.  
فایل `config.php` را `640` کن تا فقط سرور آن را بخواند.  
پوشه `tmp/` (و در صورت وجود `tmp/rate_limit/`) باید قابل نوشتن توسط وب‌سرور باشد (برای محدودیت تعداد تلاش ورود و لاگ). برای لاگ خطاها، `tmp/logs/` را هم قابل نوشتن کن (لاگ ایمیل: `mail.log`، لاگ تأیید واریز: `deposits.log`).

---

## مرحله ۷: تنظیم ایمیل (تایید ثبت‌نام)

- **روش ۱ (ساده):** در **Admin → Settings** فقط **Mail From** را پر کن (مثلاً `noreply@yourdomain.com`). ایمیل با PHP `mail()` ارسال می‌شود (روی cPanel معمولاً بدون تنظیم اضافه کار می‌کند).
- **روش ۲ (SMTP):** برای تحویل بهتر و سرویس‌های خارجی (مثل Gmail، SendGrid)، در همان صفحه بخش **SMTP** را پر کن: **SMTP Host** (مثلاً `smtp.gmail.com`)، **Port** (۵۸۷ یا ۴۶۵)، **User** و **Password**. وقتی `smtp_host` پر باشد، ارسال از طریق SMTP انجام می‌شود؛ در غیر این صورت از `mail()` استفاده می‌شود.
- در صورت خطا در ارسال، یک خط در `tmp/logs/mail.log` ثبت می‌شود (پوشه `tmp/logs/` را قابل نوشتن کن).

---

## مرحله ۸: ورود به ادمین

1. به آدرس `https://yourdomain.com/login.php` برو.
2. ورود ادمین:
   - **Username:** `admin`
   - **Email:** `admin@smm-turk.com`
   - **Password:** `password`
3. حتماً بلافاصله پسورد را عوض کن.

---

## مرحله ۹: همگام‌سازی سرویس‌ها

1. وارد پنل ادمین شو → **Admin** یا `/admin/`
2. **Sync Services** را بزن.
3. دکمه **Sync Now** را بزن تا سرویس‌های SmmFollows وارد شوند.

---

## Cron Job (اختیاری اما توصیه‌شده)

برای به‌روزرسانی خودکار وضعیت سفارش‌ها، یک Cron Job اضافه کن:

- **محدوده اجرا:** هر ۵ دقیقه
- **دستور:**

```bash
*/5 * * * * php /home/YOUR_CPANEL_USER/public_html/cron-sync.php
```

---

## دیپلوی با Git و rsync

اگر از گیت و rsync برای آپدیت استفاده می‌کنی:

- **`config.php`** همیشه از حذف مصون است (با `--exclude="config.php"`).
- بعد از هر rsync دسترسی فایل‌ها خودکار **755** (پوشه‌ها) و **644** (فایل‌ها) می‌شود تا خطای 403 برای `.htaccess` پیش نیاید.
- روی سرور فقط `~/deploy-smm.sh` را اجرا کن (بعد از `git pull` در رپو از لپ‌تاپ). push را فقط از سیستم خودت بزن.

**اتصال کامل لوکال + Git + cPanel و دیپلوی خودکار:**
- **شروع از اینجا (چک‌لیست یک‌صفحه‌ای):** [docs/CHECKLIST-DEPLOY.md](docs/CHECKLIST-DEPLOY.md) — برای سرور 92.205.182.143 با مقادیر آماده.
- **راهنمای گام‌به‌گام با توضیح:** [docs/SETUP-SERVER-92.205.182.143.md](docs/SETUP-SERVER-92.205.182.143.md).
- **راهنمای کلی Git + دیپلوی:** [docs/GIT-DEPLOY-SETUP.md](docs/GIT-DEPLOY-SETUP.md).

---

## مشکل‌های رایج

| مشکل | راه‌حل |
|------|--------|
| صفحه سفید | `display_errors = 1` در config را موقتاً فعال کن و لاگ خطا را چک کن |
| 403 Forbidden / unable to read htaccess | دسترسی فایل‌ها: پوشه‌ها 755، فایل‌ها 644 (`chmod` یا دیپلوی با rsync و `--chmod=D755,F644`) |
| خطای دیتابیس | نام دیتابیس، کاربر و رمز در cPanel را دقیق وارد کن؛ بعضی هاست‌ها به‌جای localhost از 127.0.0.1 استفاده می‌کنند |
| API کار نمی‌کند | API Key را از SmmFollows چک کن و موجودی حساب را ببین |
| 404 برای api/v2 | قوانین `.htaccess` را اضافه کن و mod_rewrite را فعال کن |
| ایمیل تایید نمی‌رسد | در Admin → Settings آدرس Mail From را با یک ایمیل دامنه خودت پر کن و در cPanel آن ایمیل را بساز |

---

## امنیت در Production

- [ ] پسورد ادمین را عوض کن
- [ ] در `config.php` قرار بده: `error_reporting(0); ini_set('display_errors', 0);` و برای پروداکشن `define('SMM_DEBUG', false);`
- [ ] **SECRET_KEY:** در پروداکشن یک مقدار ثابت ۳۲ کاراکتر (مثلاً `bin2hex(random_bytes(16))` یک بار تولید کن) در `config.php` یا env قرار بده تا session پایدار بماند
- [ ] حتماً از HTTPS استفاده کن
- [ ] دسترسی `config.php` را روی 640 بگذار
- [ ] **API:** روی هر کلید API حداکثر ۱۲۰ درخواست در دقیقه اعمال می‌شود؛ پوشه `tmp/rate_limit/` باید قابل نوشتن باشد
- [ ] **واریز:** آدرس کیف‌پول‌ها از Admin → Settings خوانده می‌شود؛ در صورت خالی بودن از `CRYPTO_WALLET_ADDRESS` در config استفاده می‌شود
