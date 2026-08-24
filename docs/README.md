# راهنماهای دیپلوی و Git

**سرور 92.205.182.143 اشتراکی (WHM/cPanel) است.** روی اشتراکی معمولاً SSH و exec محدودند؛ کار با Git را از **cPanel → Git Version Control** (کلون + Pull) انجام بده.

| فایل | کاربرد |
|------|--------|
| **[CHECKLIST-DEPLOY.md](CHECKLIST-DEPLOY.md)** | چک‌لیست کوتاه — برای اشتراکی فقط «قسمت اول» کافی است؛ بقیه اختیاری |
| **[SETUP-SERVER-92.205.182.143.md](SETUP-SERVER-92.205.182.143.md)** | راهنمای گام‌به‌گام با توضیح (شامل نکات سرور اشتراکی) |
| **[GIT-DEPLOY-SETUP.md](GIT-DEPLOY-SETUP.md)** | راهنمای کلی: لوکال + GitHub + cPanel + Webhook |

**شروع:** [CHECKLIST-DEPLOY.md](CHECKLIST-DEPLOY.md) را باز کن؛ روی اشتراکی فقط **قسمت اول (در cPanel)** را انجام بده و بعد از هر `git push` در cPanel همان رپو را **Pull** بزن.
