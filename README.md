# همگام‌سازی سبز (Sabz Afzar Integration)

افزونهٔ وردپرس برای اتصال **WooCommerce** به نرم‌افزار حسابداری **سبز افزار**.

- همگام‌سازی محصولات (قیمت، موجودی، variation)
- ثبت مشتری در سبز افزار
- صدور فاکتور هنگام تغییر وضعیت سفارش به «در حال پردازش»

---

## پیش‌نیازها

- WordPress + WooCommerce
- دسترسی به API سبز افزار (آدرس، توکن، کد شعبه)
- WooCommerce فعال باشد؛ افزونه در فعال‌سازی `pa_color` (رنگ) و `pa_size` (سایز) را در صورت نبود می‌سازد

---

## نصب و راه‌اندازی

1. پوشهٔ افزونه را در `wp-content/plugins/sabz-afzar-integration` قرار دهید.
2. افزونه را از **افزونه‌ها** فعال کنید.
3. به **همگام‌سازی سبز** در منوی ادمین بروید.
4. تنظیمات اتصال را پر کنید:

| تنظیم | توضیح |
|--------|--------|
| آدرس API | مثلاً `localhost:4217` یا `http://192.168.1.5:4217` — بدون `http://` هم قابل ذخیره است |
| توکن | `POST {base}/TOKEN` — دکمه **ساخت توکن جدید** (OAuth؛ پیش‌فرض user: greenware) |
| کد شعبه | BranchCode — برای API محصولات و فاکتور |
| کد انبار | LocationCode (برای فاکتور؛ اگر خالی باشد از کد شعبه استفاده می‌شود) |
| واحد قیمت | ریال یا تومان (مطابق تنظیمات سایت) |

5. آدرس API را وارد کنید و **ساخت توکن جدید** را بزنید (`POST /TOKEN` → `access_token` در فیلد توکن ذخیره می‌شود).
6. **بررسی ارتباط** را بزنید (باید بدون 401 باشد).
7. **شروع درون‌ریزی و آپدیت محصولات** را برای اولین sync اجرا کنید.

### فیلترهای توسعه‌دهنده (توکن OAuth)

- `sai_token_path` — مسیر توکن (پیش‌فرض: `TOKEN`)
- `sai_token_username` / `sai_token_password` — اعتبار OAuth (پیش‌فرض: `greenware` / `1`)
- `sai_token_request_bodies` — آرایه bodyهای form-urlencoded برای `/TOKEN`

---

## همگام‌سازی محصولات

### جریان کار

```
API سبز افزار → کش (uploads/sai-cache/products.json) → import دسته‌ای
```

- **دستی:** دکمهٔ sync در پنل ادمین — باکس **وضعیت** (پیشرفت) و **گزارش همگام‌سازی** (ساخته‌شده / آپدیت‌شده / رد شده) در ستون راست
- **خودکار:** WP Cron (`hourly` / `twicedaily` / `daily`)

**term رنگ/سایز:** در همگام‌سازی، مقادیر parse‌شده (مثل `سرمه‌ای`, `صورتی بنفش`) با `get_or_create_attribute_term()` در `pa_color` / `pa_size` ساخته یا از term موجود reuse می‌شوند (بدون تکرار؛ سایز با تطبیق case-insensitive مثل `xl` → `XL`).

**رد شده (Skipped):** تعداد job یا variationهایی که وارد نشدند — در گزارش پنل ادمین نمایش داده می‌شود.

**لاگ متنی رد شده:** هر بار «شروع درون‌ریزی» (`offset=0`) فایل `uploads/sai-cache/sync-skip-log.json` پاک و از نو پر می‌شود. در باکس **گزارش همگام‌سازی** زیر آمار، لیست فارسی هر مورد رد شده (شناسه `GoodCode` + دلیل + کد/متن انگلیسی خطا) نمایش داده می‌شود. کلاس: `includes/class-sync-skip-log.php`.
- **سرور:** اسکریپت `cron-sync.php` (برای cPanel)

### انواع محصول

| نوع | شرط | مثال |
|-----|------|------|
| **Variable + variation** | `GoodGroupCode` دارد + رنگ/سایز در نام parse می‌شود | `تیشرت پرنده شدن سفید` → parent: تیشرت پرنده شدن، variation: رنگ سفید |
| **Simple** | `GoodGroupCode` خالی یا نام parse نمی‌شود | محصول بدون پسوند رنگ/سایز |

- حتی **یک** variation (تک‌رنگ یا تک‌سایز) هم به‌صورت محصول متغیر ساخته می‌شود.
- SKU والد: `sai-parent-{کدگروه}-{hash}`
- SKU variation: `GoodCode` از سبز افزار

### parse نام محصول

- **رنگ:** پسوند رنگ در انتهای نام (مشکی، سفید، سدری، …)
- **سایز:** `سایز M`، `سایز3`، یا سایز تنها در انتها (`M`، `3`، `XL`)

لیست رنگ‌ها در `get_color_suffixes()` داخل `includes/class-woo-integration.php` قابل گسترش است.

### remediation

اگر محصولی به‌اشتباه simple مانده:

1. sync کامل
2. **تبدیل variationهای جاافتاده** در پنل ادمین

---

## همگام‌سازی سفارش

وقتی سفارش به وضعیت **processing** می‌رود:

1. مشتری در سبز افزار ثبت/به‌روز می‌شود (در صورت فعال بودن)
2. فاکتور ساخته می‌شود (در صورت فعال بودن)

---

## تنظیمات قابل خاموش/روشن

- همگام‌سازی مشتریان
- ساخت فاکتور
- همگام‌سازی قیمت
- همگام‌سازی موجودی
- همگام‌سازی خودکار (با انتخاب بازهٔ زمانی)

---

## لاگ و عیب‌یابی

در error log وردپرس دنبال این prefixها بگردید:

| Prefix | محتوا |
|--------|--------|
| `[SAI_SYNC]` | import محصول، variation، remediation |
| `[SAI_CRON]` | اجرای cron |
| `[SabzAfzar Integration]` | sync سفارش |

---

## ساختار فایل‌ها

```
sabz-afzar-integration/
├── sabz-afzar-integration.php   # bootstrap، cron
├── cron-sync.php                # cron مستقل سرور
├── includes/
│   ├── class-api-service.php    # ارتباط با API
│   ├── class-woo-integration.php # import و variation
│   ├── class-order-sync.php     # سفارش و فاکتور
│   └── class-admin-handler.php  # پنل ادمین
├── templates/admin-dashboard.php
├── assets/js/admin-script.js
└── tests/simulate-variation-grouping.php
```

## نسخه

**1.0.0** — Amirali Dizabadi