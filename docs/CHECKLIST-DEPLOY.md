# چک‌لیست دیپلوی — همه در یک جا

👉 **دیپلوی خودکار (توصیه‌شده):** [AUTO-DEPLOY.md](AUTO-DEPLOY.md)

**سرور:** `92.205.182.143` (اشتراکی — WHM/cPanel)  
**cPanel:** https://92.205.182.143:2087  
**یوزر cPanel:** `smmturk` (اگر فرق کرد عوضش کن)

---

## ⚠️ سرور اشتراکی (WHM/cPanel)

روی هاست اشتراکی معمولاً **SSH** محدود یا بسته است و در PHP توابعی مثل **exec** غیرفعال‌اند. پس:

- **همه کارها را از داخل cPanel (مرورگر) انجام بده** — قسمت اول زیر کافی است.
- **قسمت دوم (SSH)** را فقط اگر SSH برای اکانت تو فعال است انجام بده؛ وگرنه بعد از هر `git push` از لوکال، در cPanel برو به **Git Version Control** → رپو را انتخاب کن → **Pull** یا **Update** بزن تا سایت بروز شود.
- **Webhook خودکار** روی بسیاری از هاست‌های اشتراکی کار نمی‌کند (خطای ۵۰۰ به‌خاطر exec). در آن صورت بروزرسانی فقط با **Pull دستی** در cPanel یا با **Cron** (اگر SSH/cron در دسترس باشد) انجام می‌شود.

---

## قسمت اول: در cPanel (مرورگر) — همین کافی است برای اشتراکی

| # | کار | مقدار / کار |
|---|-----|-------------|
| 1 | ورود به cPanel | آدرس: https://92.205.182.143:2087 — یوزر و پسورد را بزن |
| 2 | SSH Access | Security → SSH Access — کلید بدون پسورد بساز (Key Password خالی) و Authorize کن |
| 3 | Git Version Control | Tools → Git™ Version Control → Create → Clone a Repository |
| 4 | Clone URL | `https://github.com/ersanjt/smm-turk-panel.git` |
| 5 | Repository Path | `smm-turk-panel` (یا `/home/smmturk/smm-turk-panel`) |
| 6 | Repository Name | `smm-turk-panel` — بعد Create بزن |

بعد از ساخته شدن رپو:
- اگر **Deploy** یا **Checkout to a directory** دیدی، مسیر را **public_html** بگذار تا فایل‌ها مستقیم همان‌جا بروند؛ بعد با **Pull** یا **Update** بروز می‌کنی.
- اگر فقط **Pull** / **Update** داری، هر بار که از لوکال `git push` زدی، اینجا همان رپو را انتخاب کن و **Pull** بزن تا سایت بروز شود.

**نکته:** فایل **config.php** را دست نزن — اگر cPanel فایل‌ها را روی public_html رونویسی کرد، یک بار **config.php** را در public_html درست کن و بعد از هر Pull آن را **exclude** کن یا دوباره از backup برنگردان (راهنما: [SETUP-SERVER-92.205.182.143.md](SETUP-SERVER-92.205.182.143.md)).

---

## قسمت دوم: روی سرور (فقط اگر SSH فعال است)

**اختیاری — روی هاست اشتراکی اغلب SSH بسته است.** اگر باز است، با SSH وصل شو: `ssh smmturk@92.205.182.143`

| # | دستور | توضیح |
|---|--------|--------|
| 7 | `cd ~` | برو home |
| 8 | `cp ~/smm-turk-panel/scripts/deploy-server.sh ~/deploy-smm.sh` | کپی اسکریپت دیپلوی |
| 9 | `chmod +x ~/deploy-smm.sh` | قابل اجرا کردن |
| 10 | `nano ~/deploy-smm.sh` | ویرایش — فقط این دو خط را درست کن: |
| | `REPO_DIR="$HOME/smm-turk-panel"` | همان پوشهٔ کلون |
| | `WEB_DIR="$HOME/public_html"` | پوشهٔ وب سایت (اگر دامنه جدا داری: `$HOME/domains/دامنه/public_html`) |
| 11 | `nano ~/deploy-secret.txt` | ساخت فایل سکرت وب‌هوک — داخلش فقط این دو خط: |
| | `WEBHOOK_SECRET=یک_رمز_۳۲_حرفی_تصادفی` | همین را بعداً در GitHub هم می‌گذاری |
| | `DEPLOY_SCRIPT=/home/smmturk/deploy-smm.sh` | مسیر اسکریپت (یوزر را درست کن) |
| 12 | `cp ~/smm-turk-panel/deploy-webhook.php ~/public_html/` | کپی وب‌هوک به وب (اگر با Git Deploy از cPanel نریختی) |
| 13 | `cp ~/deploy-secret.txt ~/public_html/` | کپی سکرت تا PHP بتواند بخواند (یا سکرت را در `~/public_html/deploy-secret.txt` بساز) |
| 14 | `~/deploy-smm.sh` | یک بار دستی اجرا — اگر خطا داد مسیرها را در مرحله ۱۰ درست کن |

---

## قسمت سوم: در GitHub (فقط اگر قسمت دوم را انجام دادی و Webhook می‌خواهی)

| # | کار | مقدار |
|---|-----|--------|
| 15 | برو به رپو | https://github.com/ersanjt/smm-turk-panel |
| 16 | Settings → Webhooks → Add webhook | |
| 17 | Payload URL | `https://92.205.182.143/deploy-webhook.php` یا اگر دامنه داری: `https://دامنه-تو/deploy-webhook.php` |
| 18 | Content type | `application/json` |
| 19 | Secret | همان **WEBHOOK_SECRET** که در مرحله ۱۱ گذاشتی |
| 20 | Which events | فقط **Just the push event** |
| 21 | Add webhook | ذخیره |

---

## قسمت چهارم: تست

| # | کار |
|---|-----|
| 22 | از کامپیوتر خودت: `git push origin main` |
| 23 | در GitHub → Settings → Webhooks → روی webhook کلیک کن → Recent Deliveries باید سبز (۲۰۰) باشد |
| 24 | سایت را رفرش کن — باید با آخرین کد بروز باشد |

---

## اگر Webhook خطا داد (۴۰۳ / ۵۰۰)

- **۴۰۳:** Secret در `deploy-secret.txt` و در GitHub یکی نیست.
- **۵۰۰ (روی سرور اشتراکی خیلی معمول است):** روی هاست اشتراکی معمولاً **exec** در PHP غیرفعال است، پس Webhook نمی‌تواند اسکریپت را اجرا کند. در این حالت:
  - **به‌جای Webhook:** بعد از هر `git push` در cPanel برو به **Git Version Control** → همان رپو → **Pull** بزن.
  - اگر **SSH و Cron** در دسترس است: در cPanel → **Cron Jobs** یک Cron اضافه کن (زمان: `*/5 * * * *`، دستور: `/home/smmturk/deploy-smm.sh >> /home/smmturk/deploy.log 2>&1`).

---

## خلاصه مسیرها (برای این سرور)

| مورد | مقدار |
|------|--------|
| رپو روی سرور | `/home/smmturk/repositories/smm-turk-panel` |
| پوشهٔ وب | `/home/smmturk/public_html` |
| اسکریپت دیپلوی | `/home/smmturk/deploy-smm.sh` |
| سکرت وب‌هوک | `~/deploy-secret.txt` یا `~/public_html/deploy-secret.txt` |
| آدرس وب‌هوک | `https://smm-turk.com/deploy-webhook.php` |

راهنمای کامل‌تر: [SETUP-SERVER-92.205.182.143.md](SETUP-SERVER-92.205.182.143.md)
