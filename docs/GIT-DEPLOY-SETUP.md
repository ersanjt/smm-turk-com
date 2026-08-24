# اتصال کامل Git + لوکال + cPanel (با دیپلوی خودکار)

👉 **دیپلوی خودکار smm-turk.com:** [AUTO-DEPLOY.md](AUTO-DEPLOY.md) — لوکال → GitHub → سرور با یک `git push`

👉 **سرور 92.205.182.143؟** مستقیم برو به **[CHECKLIST-DEPLOY.md](CHECKLIST-DEPLOY.md)** (همه در یک صفحه) یا **[SETUP-SERVER-92.205.182.143.md](SETUP-SERVER-92.205.182.143.md)** (با توضیح بیشتر).

این راهنما به‌طور کلی توضیح می‌دهد:
- **لوکال (ویندوز/مک)** مستقیماً با GitHub کار کند (push/pull).
- **سرور (cPanel/Linux)** همان رپو را داشته باشد و با یک دستور یا **خودکار با هر push** بروز شود.

---

## نمای کلی

```
[لوکال]  --git push-->  [GitHub]  --webhook یا cron-->  [سرور: git pull + rsync]
```

---

## بخش ۱: لوکال به GitHub

### ۱.۱ یک بار تنظیم اولیه (اگر از قبل clone نکردی)

```bash
git clone https://github.com/ersanjt/smm-turk-panel.git
cd smm-turk-panel
git branch -M main
```

اگر پروژه را از قبل داری و فقط می‌خواهی به GitHub وصلش کنی:

```bash
cd smm-turk-panel
git remote add origin https://github.com/ersanjt/smm-turk-panel.git
git branch -M main
git push -u origin main
```

### ۱.۲ کار روزانه

- بعد از هر تغییر:
  ```bash
  git add .
  git commit -m "توضیح تغییرات"
  git push origin main
  ```
- اگر فقط می‌خواهی از GitHub بروز بگیری:
  ```bash
  git pull origin main
  ```

---

## بخش ۲: سرور (cPanel / لینوکس) به Git

### ۲.۱ یک بار: کلون رپو روی سرور

با SSH به سرور وصل شو و یک پوشه برای رپو بساز (مثلاً در home):

```bash
cd ~
git clone https://github.com/ersanjt/smm-turk-panel.git
# اگر رپو پرایوت است:
# git clone git@github.com:ersanjt/smm-turk-panel.git
```

مسیر رپو را یادداشت کن، مثلاً: `/home/smmturk/smm-turk-panel`

### ۲.۲ اسکریپت دیپلوی روی سرور

از داخل همین رپو فایل نمونه اسکریپت هست: `scripts/deploy-server.sh`.

یک کپی در home خودت بگذار و مقادیر را با مسیرهای واقعی سرورت عوض کن:

```bash
cp ~/smm-turk-panel/scripts/deploy-server.sh ~/deploy-smm.sh
chmod +x ~/deploy-smm.sh
nano ~/deploy-smm.sh
```

داخلش این دو متغیر را درست کن:

- `REPO_DIR` = مسیر همان پوشه‌ای که `git clone` زدی (مثلاً `$HOME/smm-turk-panel`)
- `WEB_DIR` = مسیر وب (مثلاً `$HOME/public_html` یا `$HOME/domains/yourdomain.com/public_html`)

بعد از هر بار که از لوکال `git push` زدی، روی سرور فقط اجرا کن:

```bash
~/deploy-smm.sh
```

این اسکریپت داخل رپو `git fetch` + `git reset --hard origin/main` می‌زند و بعد با `rsync` فایل‌ها را به `WEB_DIR` می‌ریزد و `config.php` را دست نمی‌زند.

---

## بخش ۳: دیپلوی خودکار با GitHub Webhook

با این روش بعد از هر `git push` از لوکال، سرور خودکار بروز می‌شود (بدون اینکه دستی `~/deploy-smm.sh` بزنی).

### ۳.۱ فایل وب‌هوک روی سرور

- فایل **`deploy-webhook.php`** را از رپو در جایی قرار بده که از وب در دسترس باشد، مثلاً داخل **`public_html`** (همان جایی که سایت است).
- یک فایل **`deploy-secret.txt`** بساز که **هیچ وقت داخل رپو نباشد** (روی سرور دستی بساز).

محل پیشنهادی برای سکرت (خارج از public_html امن‌تر است):

```bash
nano ~/deploy-secret.txt
```

محتوا (یک خط برای سکرت، بقیه اختیاری):

```text
WEBHOOK_SECRET=یک_رشته_تصادفی_قوی_مثلاً_۳۲_کاراکتر
REPO_PATH=/home/smmturk/smm-turk-panel
DEPLOY_SCRIPT=/home/smmturk/deploy-smm.sh
```

- `WEBHOOK_SECRET` را خودت انتخاب کن (بعداً در GitHub هم همان را می‌گذاری).
- اگر `DEPLOY_SCRIPT` را بگذاری، وب‌هوک بعد از push همان اسکریپت را اجرا می‌کند.
- اگر فقط `REPO_PATH` را بگذاری و `DEPLOY_SCRIPT` خالی باشد، فقط `git pull` داخل آن پوشه اجرا می‌شود (در آن صورت یا همان پوشه باید همان public_html باشد یا خودت با cron/copy کار rsync را انجام بدهی).

اگر `deploy-webhook.php` داخل `public_html` است و می‌خواهی سکرت را همان نزدیکش بگذاری:

```bash
nano ~/public_html/deploy-secret.txt
```

و فقط همان یک خط `WEBHOOK_SECRET=...` و در صورت نیاز `DEPLOY_SCRIPT=...` یا `REPO_PATH=...`.

### ۳.۲ تنظیم Webhook در GitHub

1. برو به رپو در GitHub → **Settings** → **Webhooks** → **Add webhook**.
2. مقادیر را این‌طور بگذار:
   - **Payload URL:**  
     `https://yourdomain.com/deploy-webhook.php`  
     (دامنه واقعی سایتت را بگذار.)
   - **Content type:** `application/json`
   - **Secret:** همان مقدار `WEBHOOK_SECRET` که در `deploy-secret.txt` گذاشتی.
   - **Which events:** فقط **Just the push event**.
3. **Add webhook** را بزن.

با هر **push به برنچ `main`**، GitHub به این آدرس درخواست POST می‌زند و اگر امضا درست باشد، سرور یا اسکریپت دیپلوی را اجرا می‌کند یا فقط `git pull` می‌زند.

### ۳.۳ محدودیت PHP روی برخی هاست‌ها

روی بعضی هاست‌ها توابعی مثل `exec` یا `shell_exec` غیرفعال هستند. در آن صورت:

- یا از **Cron** استفاده کن (بخش ۴).
- یا از **cPanel Git Version Control** (در صورت وجود) استفاده کن و بعد از هر pull دستی یا با یک cron ساده فایل‌ها را به public_html کپی کن.

---

## بخش ۴: دیپلوی نیمه‌خودکار با Cron (بدون Webhook)

اگر Webhook در دسترس نبود یا exec روی PHP بسته بود، هر چند دقیقه یک بار از روی سرور از GitHub بکش و دیپلوی کن:

```bash
crontab -e
```

یک خط اضافه کن (مثلاً هر ۵ دقیقه):

```cron
*/5 * * * * /home/smmturk/deploy-smm.sh >> /home/smmturk/deploy.log 2>&1
```

مسیرها را با کاربر و مسیر واقعی سرورت عوض کن.

---

## خلاصه

| مرحله | کار |
|--------|-----|
| **لوکال** | `powershell -File scripts/push.ps1 "توضیح"` یا `git push origin main` |
| **GitHub** | کد را نگه می‌دارد؛ با Webhook به سرور اطلاع می‌دهد (در صورت تنظیم) |
| **سرور** | یا دستی `~/deploy-smm.sh` یا خودکار با Webhook یا با Cron هر چند دقیقه `deploy-smm.sh` |

با این setup هم لوکال و هم سرور به صورت کامل به Git وصل می‌شوند؛ با Webhook یا Cron می‌توانی بروزرسانی را اتوماتیک کنی.
