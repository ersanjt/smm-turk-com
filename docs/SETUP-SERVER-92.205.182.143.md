# راهنمای گام‌به‌گام — تنظیم Git و دیپلوی روی سرور

**سرور:** `92.205.182.143` (اشتراکی — WHM/cPanel)  
**cPanel:** https://92.205.182.143:2087  
**یوزر cPanel:** `smmturk` (اگر فرق کرد در همهٔ دستورات عوضش کن)

👉 **چک‌لیست کوتاه:** [CHECKLIST-DEPLOY.md](CHECKLIST-DEPLOY.md)

---

## سرور اشتراکی (WHM/cPanel)

روی هاست **اشتراکی** معمولاً این محدودیت‌ها هست:
- **SSH** ممکن است غیرفعال یا محدود باشد.
- در PHP توابعی مثل **exec** / **shell_exec** اغلب **غیرفعال**‌اند (برای امنیت).

بنابراین:
1. **روش اصلی:** همهٔ کار با Git را از داخل **cPanel → Git Version Control** انجام بده (کلون + Pull/Update). بعد از هر `git push` از لوکال، در cPanel همان رپو را انتخاب کن و **Pull** بزن تا سایت بروز شود.
2. **Webhook خودکار** روی بسیاری از هاست‌های اشتراکی کار نمی‌کند (خطای ۵۰۰). در آن صورت بروزرسانی فقط **دستی با Pull در cPanel** یا با **Cron** (در صورت فعال بودن SSH) انجام می‌شود.
3. مراحل SSH و اسکریپت و Webhook در این راهنما **اختیاری** هستند — فقط اگر اکانت تو SSH و اجرای اسکریپت را پشتیبانی کند.

**نکته config.php:** در رپو فقط `config.example.php` هست؛ `config.php` داخل Git نیست. بعد از اولین کلون/Deploy، در **public_html** یک بار از روی `config.example.php` فایل **config.php** بساز و دیتابیس و SITE_URL را پر کن. با هر Pull بعدی، `config.php` رونویسی نمی‌شود (در رپو نیست).

---

## مرحله ۱: ورود به cPanel

1. در مرورگر بزن: **https://92.205.182.143:2087**
2. اگر هشدار امنیتی (SSL) آمد → «Advanced» → «Proceed to …» را بزن.
3. **Username** و **Password** cPanel را وارد کن و لاگین کن.

---

## مرحله ۲: SSH و ترمینال (اختیاری — روی اشتراکی اغلب بسته است)

اگر روی هاست اشتراکی هستی و SSH در دسترس نیست، **مرحله ۲ و ۳ و ۵ تا ۸** را انجام نده؛ فقط **مرحله ۱** (ورود به cPanel) و بعد **cPanel → Git Version Control → Create → Clone** با آدرس رپو، و بعد از ساخته شدن رپو هر بار با **Pull** یا **Update** سایت را بروز کن.

اگر از قبل SSH داری، از مرحله ۲.۲ شروع کن.

### ۲.۱ فعال کردن SSH در cPanel

1. در cPanel به **«Security»** یا **«SSH Access»** برو.
2. اگر **SSH** غیرفعال است، آن را **Enable** کن.
3. در صورت نیاز یک **SSH Key** اضافه کن یا از **Password Authentication** استفاده کن.

### ۲.۲ وصل شدن با SSH از کامپیوتر خودت

روی ویندوز می‌توانی از **PowerShell** یا **Windows Terminal** استفاده کنی، یا نرم‌افزار **PuTTY**.

```bash
ssh smmturk@92.205.182.143
```

(به‌جای `smmturk` یوزرنیم cPanel خودت را بگذار. اگر پورت SSH عوض شده، مثلاً: `ssh -p 22 smmturk@92.205.182.143`)

پسورد cPanel را بزن و وارد سرور شو.

---

## مرحله ۳: نصب/بررسی Git روی سرور (از طریق SSH)

بعد از ورود با SSH این را بزن:

```bash
git --version
```

اگر خطا داد، در cPanel به **«Setup Python App»** یا **«Select PHP Version»** سر بزن؛ بعضی هاست‌ها از **Terminal** در cPanel یا از تیکت از پشتیبانی می‌توانند Git را فعال کنند. اگر دسترسی **root** داری، با پشتیبانی یا خودت Git را نصب کن.

---

## مرحله ۴: کلون رپو روی سرور (یک بار)

همان‌جا در SSH:

```bash
cd ~
git clone https://github.com/ersanjt/smm-turk-panel.git
```

اگر رپو پرایوت است و با پسورد/توکن کار می‌کنی:

```bash
git clone https://USERNAME:TOKEN@github.com/ersanjt/smm-turk-panel.git
```

(یک بار **Personal Access Token** از GitHub می‌سازی و به‌جای USERNAME و TOKEN می‌گذاری.)

بعد از clone، پوشه‌ای مثل `~/smm-turk-panel` یا `~/smm-turk` داری. مسیر دقیق را یادداشت کن؛ در مرحله بعد لازم است.

---

## مرحله ۵: اسکریپت دیپلوی روی سرور (یک بار)

1. فایل نمونه داخل رپو است: **`scripts/deploy-server.sh`**
2. یک کپی در home خودت بگذار:

```bash
cp ~/smm-turk-panel/scripts/deploy-server.sh ~/deploy-smm.sh
chmod +x ~/deploy-smm.sh
```

3. ویرایش کن و مسیرها را با وضعیت سرور خودت یکی کن:

```bash
nano ~/deploy-smm.sh
```

- **REPO_DIR:** مسیر همان پوشه‌ای که `git clone` زدی.  
  مثلاً اگر کلون کردی در `~/smm-turk-panel` پس:  
  `REPO_DIR="$HOME/smm-turk-panel"`  
  (اگر داخلش پوشهٔ دیگری مثل `smm-turk` است و فایل‌های سایت آنجاست، می‌توانی بگذاری مثلاً `REPO_DIR="$HOME/smm-turk-panel/smm-turk"` و در **WEB_DIR** مسیر public_html را بگذاری.)
- **WEB_DIR:** مسیر پوشهٔ وب سایت. معمولاً یکی از این‌هاست:  
  `WEB_DIR="$HOME/public_html"`  
  یا  
  `WEB_DIR="$HOME/domains/yourdomain.com/public_html"`

ذخیره: در `nano` با **Ctrl+O** و Enter، بعد **Ctrl+X**.

4. یک بار دستی اجرا کن تا مطمئن شوی کار می‌کند:

```bash
~/deploy-smm.sh
```

اگر خطا داد، مسیرهای **REPO_DIR** و **WEB_DIR** را دوباره در همان فایل درست کن.

---

## مرحله ۶: فایل سکرت وب‌هوک (برای دیپلوی خودکار)

یک فایل روی سرور بساز که **هیچ وقت** داخل Git نباشد و فقط روی سرور بماند:

```bash
nano ~/deploy-secret.txt
```

داخلش این خطوط را بگذار (مقادیر را با وضعیت خودت عوض کن):

```text
WEBHOOK_SECRET=یک_رمز_طولانی_تصادفی_مثلاً_۳۲_کاراکتر
DEPLOY_SCRIPT=/home/smmturk/deploy-smm.sh
```

- به‌جای `smmturk` یوزر cPanel خودت را بگذار.
- **WEBHOOK_SECRET** را خودت انتخاب کن (مثلاً یک رشته ۳۲ حرفی تصادفی). بعداً همین را در GitHub هم وارد می‌کنی.

ذخیره و بستن: **Ctrl+O**, Enter, **Ctrl+X**.

اگر فایل **deploy-webhook.php** را داخل **public_html** می‌گذاری، باید این سکرت را جایی بگذاری که آن اسکریپت بتواند بخواند. در **deploy-webhook.php** دو محل چک می‌شود:
- همان پوشهٔ **deploy-webhook.php** (مثلاً `~/public_html/deploy-secret.txt`)
- یا یک پوشه بالاتر (مثلاً `~/deploy-secret.txt`)

پس یا `~/deploy-secret.txt` بساز و **deploy-webhook.php** را جایی بگذار که بتواند `../deploy-secret.txt` بخواند، یا یک کپی در کنار **deploy-webhook.php** بگذار:  
`~/public_html/deploy-secret.txt` با همان محتوا.

---

## مرحله ۷: قرار دادن deploy-webhook.php روی سرور

فایل **deploy-webhook.php** داخل رپو (در روت پروژه) هست. با همان اسکریپت **deploy-smm.sh** که اجرا کردی، اگر **WEB_DIR** را درست گذاشته باشی، این فایل هم با بقیهٔ فایل‌ها داخل **public_html** کپی شده.

اگر هنوز دیپلوی نزدی، می‌توانی دستی یک بار از رپو کپی کنی:

```bash
cp ~/smm-turk-panel/deploy-webhook.php ~/public_html/
```

(مسیرها را با **REPO_DIR** و **WEB_DIR** خودت تطبیق بده.)

آدرس نهایی وب‌هوک می‌شود چیزی شبیه:

**https://دامنه-تو/deploy-webhook.php**

اگر سایت با IP باز می‌شود: **https://92.205.182.143/deploy-webhook.php** (در صورت تنظیم دامنه روی همین IP و پورت ۴۴۳).

این آدرس را برای مرحله ۸ لازم داری.

---

## مرحله ۸: تنظیم Webhook در GitHub

1. برو به رپو در GitHub: **https://github.com/ersanjt/smm-turk-panel**
2. **Settings** → **Webhooks** → **Add webhook**
3. مقادیر را پر کن:
   - **Payload URL:**  
     اگر دامنه داری: `https://yourdomain.com/deploy-webhook.php`  
     اگر فقط IP داری و سایت روی همان سرور است: `https://92.205.182.143/deploy-webhook.php`
   - **Content type:** `application/json`
   - **Secret:** همان **WEBHOOK_SECRET** که در **deploy-secret.txt** گذاشتی.
   - **Which events:** فقط **Just the push event**.
4. **Add webhook** را بزن.

با هر **push به برنچ main**، GitHub به این آدرس درخواست می‌فرستد و اگر سکرت درست باشد، اسکریپت **deploy-smm.sh** روی سرور اجرا می‌شود و سایت بروز می‌شود.

---

## مرحله ۹: تست

1. از **لوکال** یک تغییر کوچک بزن و push کن:

```bash
git add .
git commit -m "test deploy"
git push origin main
```

2. در GitHub برو به **Settings** → **Webhooks** → روی همان webhook کلیک کن. پایین صفحه **Recent Deliveries** را ببین؛ باید یک درخواست سبز (۲۰۰) ببینی.
3. اگر ۲۰۰ بود، روی سرور چک کن که فایل‌های **public_html** با آخرین کد بروز شده‌اند.

اگر به‌جای ۲۰۰ خطا دیدی (مثلاً ۴۰۳ یا ۵۰۰)، در همان صفحه **Recent Deliveries** روی آن درخواست کلیک کن و **Response** را ببین. معمولاً یکی از این‌هاست:
- **۴۰۳:** سکرت اشتباه است → **WEBHOOK_SECRET** در **deploy-secret.txt** و در GitHub یکی باشند.
- **۵۰۰:** مسیر **DEPLOY_SCRIPT** یا **REPO_PATH** اشتباه است، یا توابعی مثل `exec` روی هاست غیرفعال است. در آن صورت از **Cron** (مرحله ۱۰) استفاده کن.

---

## مرحله ۹.۱: مایگریشن Child Panel (یک بار)

اگر صفحه **Child Panel** را باز می‌کنی و با «Order failed» یا «Child panel feature is not set up» روبه‌رو می‌شوی، یعنی جدول `child_panels` در دیتابیس ساخته نشده. با **SSH** در مسیر پروژه (همان **REPO_DIR** یا جایی که فایل‌های PHP هستند) یک بار این را اجرا کن:

```bash
cd ~/smm-turk-panel/smm-turk
php migrate-child-panel.php
```

(مسیر را با **REPO_DIR** خودت تطبیق بده. اگر پروژه مستقیم در `~/smm-turk-panel` است، همانجا `php migrate-child-panel.php` بزن.)

بعد از اجرا باید پیام **Child panels table OK.** را ببینی و سفارش Child Panel از داخل سایت کار کند.

---

## مرحله ۹.۲: مایگریشن بلاگ — کجا و چطور اجرا کنم؟

**کجا بزنی؟** همیشه داخل **همان پوشه‌ای که سایت از آن اجرا می‌شود** — یعنی جایی که فایل‌های **config.php** و **index.php** و **blog.php** هستند.

| نحوهٔ دیپلوی تو | مسیر همان پوشه | دستورات |
|------------------|-----------------|----------|
| اسکریپت دیپلوی (کپی به public_html) | **public_html** | `cd ~/public_html` سپس `php migrate-blog.php` و در صورت تمایل `php seed-blog.php` |
| cPanel → Git و Checkout به public_html | **public_html** | `cd ~/public_html` سپس همان دو دستور بالا |
| فقط Git روی سرور (بدون کپی؛ سایت از خود رپو سرو می‌شود) | همان پوشهٔ رپو | `cd ~/smm-turk-panel/smm-turk` (یا جایی که رپو را کلون کردی) سپس همان دو دستور |

**گام‌به‌گام (روی سرور):**

1. **ترمینال را باز کن**
   - یا با **SSH:** وصل شو (`ssh smmturk@92.205.182.143`) و بعد دستورات زیر را بزن.
   - یا از **cPanel:** برو **Advanced → Terminal** و همان دستورات را بزن.

2. **برو داخل پوشهٔ سایت (معمولاً public_html):**
   ```bash
   cd ~/public_html
   ```
   اگر دامنهٔ جدا داری و سایت در مسیر دیگری است:
   ```bash
   cd ~/domains/smm-turk.com/public_html
   ```
   (نام دامنه را با دامنهٔ خودت عوض کن.)

3. **مطمئن شو فایل‌های بلاگ آنجاست:**
   ```bash
   ls migrate-blog.php blog.php config.php
   ```
   اگر خطای «No such file» دیدی، یعنی یا هنوز دیپلوی نکردی (اول Pull/Deploy بزن) یا مسیر اشتباه است.

4. **مایگریشن بلاگ را یک بار اجرا کن:**
   ```bash
   php migrate-blog.php
   ```
   باید پیام **Blog tables created.** را ببینی.

5. **(اختیاری) پر کردن اولیهٔ ۱۲ مقاله و دسته/برچسب:**
   ```bash
   php seed-blog.php
   ```

**نکته:** اگر با **cPanel** فقط Git Version Control داری و Terminal/SSH نداری، بعد از Pull در cPanel فایل‌ها داخل **public_html** بروز می‌شوند؛ ولی برای اجرای `php migrate-blog.php` باید یا **SSH** را فعال کنی یا از **پشتیبانی هاست** بخواهی یک بار این اسکریپت را برایت اجرا کنند (مسیر: همان **public_html**).

---

## مرحله ۱۰ (اختیاری): اگر Webhook کار نکرد — استفاده از Cron

در cPanel به **Cron Jobs** برو و یک Cron جدید اضافه کن:

- **Common Settings:** مثلاً هر ۵ دقیقه: `*/5 * * * *`
- **Command:**

```bash
/home/smmturk/deploy-smm.sh >> /home/smmturk/deploy.log 2>&1
```

(یوزرنیم را با یوزر cPanel خودت عوض کن.)

با این کار هر ۵ دقیقه یک بار اسکریپت دیپلوی اجرا می‌شود و سایت حداکثر ۵ دقیقه با تأخیر بروز می‌شود.

---

## خلاصه

| مرحله | کار |
|--------|-----|
| ۱ | ورود به cPanel با آدرس و یوزر/پسورد |
| ۲ | فعال کردن SSH و وصل شدن با `ssh user@92.205.182.143` |
| ۳ | نصب/بررسی Git روی سرور |
| ۴ | `git clone` رپو در home |
| ۵ | کپی و ویرایش **deploy-server.sh** به **~/deploy-smm.sh** و یک بار اجرا |
| ۶ | ساختن **deploy-secret.txt** و قرار دادن **WEBHOOK_SECRET** و **DEPLOY_SCRIPT** |
| ۷ | اطمینان از وجود **deploy-webhook.php** در **public_html** |
| ۸ | ساخت Webhook در GitHub با آدرس و سکرت |
| ۹ | تست با یک push از لوکال |
| ۱۰ | در صورت نیاز، تنظیم Cron برای دیپلوی دوره‌ای |

اگر در هر مرحله به خطا خوردی، همان خطا (متن دقیق یا اسکرین‌شات) را بفرست تا همان قسمت را دقیق برایت درست کنیم.
