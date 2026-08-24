# دیپلوی خودکار — smm-turk.com

**جریان کار:**

```
[لوکال]  git push  →  [GitHub]  webhook  →  [سرور]  deploy-smm.sh  →  public_html  →  smm-turk.com
```

---

## گام ۱: راه‌اندازی یک‌بار (لوکال)

در PowerShell از روت پروژه:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/setup-auto-deploy.ps1
```

این اسکریپت:
- فایل `deploy-secret.txt` را لوکال می‌سازد (commit نمی‌شود)
- Webhook را در GitHub به `https://smm-turk.com/deploy-webhook.php` وصل می‌کند

---

## گام ۲: تنظیم سرور (cPanel — یک بار)

### ۲.۱ آپلود `deploy-secret.txt`

1. ورود به cPanel: https://92.205.182.143:2087
2. **File Manager** → برو به `/home/smmturk/` (نه داخل public_html)
3. فایل `deploy-secret.txt` را از روت پروژه لوکال آپلود کن
4. Permission: **600** یا **640**

### ۲.۲ اسکریپت دیپلوی

**اگر Terminal/SSH داری:**

```bash
cp ~/repositories/smm-turk-panel/scripts/deploy-cpanel.sh ~/deploy-smm.sh
chmod +x ~/deploy-smm.sh
~/deploy-smm.sh
```

**اگر SSH نداری (File Manager):**

1. فایل `scripts/deploy-cpanel.sh` را به `/home/smmturk/deploy-smm.sh` کپی کن
2. Permission را **755** بگذار

### ۲.۳ Git در cPanel (اگر هنوز نیست)

1. cPanel → **Git™ Version Control** → **Create**
2. Clone URL: `https://github.com/ersanjt/smm-turk-panel.git`
3. Repository Path: `smm-turk-panel` (مسیر نهایی: `/home/smmturk/repositories/smm-turk-panel`)

**مهم:** `config.php` فقط در `public_html` باشد و در Git نباشد — با هر دیپلوی دست نخورده می‌ماند.

---

## گام ۳: کار روزانه (بعد از هر تغییر)

```powershell
powershell -File scripts/push.ps1 "توضیح تغییرات"
```

یا دستی:

```powershell
git add .
git commit -m "توضیح تغییرات"
git push origin main
```

بعد از push، ظرف چند ثانیه سایت بروز می‌شود.

---

## تست و عیب‌یابی

| بررسی | آدرس / کار |
|--------|------------|
| Webhook زنده | https://smm-turk.com/deploy-webhook.php?diag=1&key=WEBHOOK_SECRET |
| تحویل webhook | GitHub → Settings → Webhooks → Recent Deliveries → **200** |
| سایت | https://smm-turk.com/ |
| Health | https://smm-turk.com/health → `{"status":"ok","db":"ok"}` |

### خطاهای رایج

در GitHub: webhook → **Recent Deliveries** → delivery قرمز → تب **Response**

| پیام Response | راه‌حل |
|---------------|--------|
| `deploy-secret.txt not found` | آپلود `F:\smm-turk\deploy-secret.txt` به `/home/smmturk/` |
| `DEPLOY_SCRIPT not found` | کپی `deploy-cpanel.sh` به `/home/smmturk/deploy-smm.sh` (755) |
| `Deploy queued` | OK — Cron برای `deploy-cron.sh` بگذار |
| `Invalid signature` (403) | secret را دوباره sync کن |
| `/root/repositories/... not found` | اسکریپت قدیمی است — از WHM به‌عنوان root با مسیر ثابت اجرا کن (پایین) |
| `Permission denied (publickey)` | remote گیت SSH است — اسکریپت خودکار به HTTPS عوض می‌کند؛ یا `git remote set-url origin https://github.com/ersanjt/smm-turk-panel.git` |
| `dubious ownership` | یک‌بار: `git config --global --add safe.directory /home/smmturk/repositories/smm-turk-panel` |
| `Shell access is not enabled` | طبیعی است — cron همچنان کار می‌کند؛ دستی از WHM root اجرا کن |

### WHM Terminal (root) — دیپلوی دستی

`su - smmturk` روی اکثر هاست‌ها غیرفعال است. از **root** با مسیرهای ثابت استفاده کن:

```bash
# ۱) رپو وجود دارد؟
ls -la /home/smmturk/repositories/smm-turk-panel/.git

# ۲) اسکریپت جدید (مسیر ثابت — دیگر به $HOME وابسته نیست)
cp /home/smmturk/repositories/smm-turk-panel/scripts/deploy-cpanel.sh /home/smmturk/deploy-smm.sh
cp /home/smmturk/repositories/smm-turk-panel/scripts/deploy-cron.sh /home/smmturk/deploy-cron.sh
chmod 755 /home/smmturk/deploy-smm.sh /home/smmturk/deploy-cron.sh
chown smmturk:smmturk /home/smmturk/deploy-smm.sh /home/smmturk/deploy-cron.sh

# ۳) اگر git fetch خطای SSH می‌دهد — cPanel → Git Version Control → Pull/Deploy
#    یا remote را HTTPS کن:
cd /home/smmturk/repositories/smm-turk-panel
git remote set-url origin https://github.com/ersanjt/smm-turk-panel.git

# ۴) دیپلوی (به‌عنوان root هم کار می‌کند)
#    اگر git fetch پسورد خواست: کل بلوک را یکجا paste نکن — هر خط جدا اجرا کن.
#    اسکریپت جدید بدون login از GitHub archive هم deploy می‌کند (رپو public).
bash /home/smmturk/deploy-smm.sh

# ۵) بعد از دیپلوی موفق — migration ایندکس‌ها
php /home/smmturk/public_html/migrate-performance-indexes.php
```

اگر `git fetch` باز هم خطا داد: cPanel → **Git™ Version Control** → **Pull or Deploy**. رپو اکنون **public** است — با HTTPS و `safe.directory` معمولاً از WHM root هم fetch موفق می‌شود.

```bash
cd /home/smmturk/repositories/smm-turk-panel && git log -1 --oneline
rsync -av --delete \
  --exclude='.git' --exclude='config.php' --exclude='deploy-secret.txt' \
  --exclude='tmp/' --exclude='uploads/' \
  /home/smmturk/repositories/smm-turk-panel/ /home/smmturk/public_html/
```

### Cron (هاست اشتراکی — توصیه می‌شود)

در `/home/smmturk/` این فایل‌ها باشند:
- `deploy-smm.sh` (از `scripts/deploy-cpanel.sh`)
- `deploy-cron.sh` (از `scripts/deploy-cron.sh`)

cPanel → Cron Jobs:

```
* * * * * /home/smmturk/deploy-cron.sh >> /home/smmturk/deploy.log 2>&1
```

یا هر ۵ دقیقه مستقیم:

```
*/5 * * * * /home/smmturk/deploy-smm.sh >> /home/smmturk/deploy.log 2>&1
```

**روش B — FTP از GitHub Actions:**

در GitHub → Settings → Secrets → Actions این‌ها را اضافه کن:

- `FTP_SERVER` — مثلاً `ftp.smm-turk.com`
- `FTP_USERNAME` — `smmturk`
- `FTP_PASSWORD` — از cPanel → FTP Accounts
- `FTP_SERVER_DIR` — `/public_html/`

Workflow: `.github/workflows/deploy-ftp.yml`

---

## مسیرهای سرور

| مورد | مسیر |
|------|------|
| رپو Git (cPanel) | `/home/smmturk/repositories/smm-turk-panel` |
| سایت | `/home/smmturk/public_html` → https://smm-turk.com |
| اسکریپت دیپلوی | `/home/smmturk/deploy-smm.sh` |
| سکرت webhook | `/home/smmturk/deploy-secret.txt` |
