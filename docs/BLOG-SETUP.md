# راه‌اندازی بلاگ (Blog Setup)

## نصب

1. **اجرای مایگریشن دیتابیس** (یک بار):
   ```bash
   php migrate-blog.php
   ```

2. **پر کردن اولیه مقالات و دسته‌ها** (اختیاری):
   ```bash
   php seed-blog.php
   ```
   این اسکریپت ۶ دسته، ۱۴ برچسب و **۱۲ مقاله** آماده سئو با کلیدواژه‌های مناسب اضافه می‌کند.

## آدرس‌ها

| آدرس | توضیح |
|------|--------|
| `/blog` | لیست مقالات (با فیلتر دسته و برچسب) |
| `/blog/slug-مقاله` | نمایش یک مقاله (مثلاً `/blog/what-is-smm-panel-cheapest-guide`) |
| پنل ادمین → **Blog** | مدیریت مقالات، دسته‌ها و برچسب‌ها |

## سئو و جستجوی هوشمند

- **Meta:** هر مقاله و صفحه لیست دارای `title`, `description`, `keywords` و canonical است.
- **Schema.org:** برای هر مقاله **JSON-LD نوع Article** و برای صفحه لیست **Blog + BlogPosting** برای موتورهای جستجو و خزنده‌های هوش مصنوعی تولید می‌شود.
- **ساختار محتوا:** عنوان (H1)، زیرعنوان (H2, H3)، پاراگراف و لیست برای خوانایی و ایندکس بهتر.
- **کلیدواژه‌ها:** مقالات با کلمات کلیدی مثل SMM panel, cheapest SMM, Instagram followers, YouTube views, TikTok, reseller, API و غیره پر شده‌اند.

## فایل‌های اضافه‌شده

- `migrate-blog.php` — ایجاد جداول بلاگ
- `seed-blog.php` — درج دسته‌ها، برچسب‌ها و ۱۲ مقاله نمونه
- `blog.php` — صفحه لیست مقالات
- `blog-post.php` — صفحه تک‌مقاله
- `layouts/blog-header.php`, `layouts/blog-footer.php` — قالب عمومی بلاگ
- `admin/admin-blog.php` — لیست مقالات و مدیریت دسته/برچسب
- `admin/admin-blog-edit.php` — افزودن/ویرایش مقاله
- کلیدهای زبان بلاگ در `lang/en.php`, `tr.php`, `de.php`, `fr.php`

## لینک‌های ناوبری

- **صفحه اصلی (home):** لینک «Blog» در ناو و فوتر
- **داشبورد (sidebar):** لینک «Blog» در بخش Support & Info
- **ادمین:** لینک «Blog» در منوی Admin برای مدیریت بلاگ
