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
- **خودکار (پیشنهادی — cPanel):** اسکریپت `cron-sync.php` — در پنل ادمین «همگام‌سازی با cron سرور» را فعال کنید
- **خودکار (جایگزین):** WP Cron (`hourly` / `twicedaily` / `daily`) — فقط وقتی cron سرور خاموش است

**term رنگ/سایز:** در همگام‌سازی، مقادیر parse‌شده (مثل `سرمه‌ای`, `صورتی بنفش`) با `get_or_create_attribute_term()` در `pa_color` / `pa_size` ساخته یا از term موجود reuse می‌شوند (بدون تکرار؛ سایز با تطبیق case-insensitive مثل `xl` → `XL`).

**رد شده (Skipped):** تعداد job یا variationهایی که وارد نشدند — در گزارش پنل ادمین نمایش داده می‌شود.

**لاگ متنی رد شده:** هر بار «شروع درون‌ریزی» (`offset=0`) فایل `uploads/sai-cache/sync-skip-log.json` پاک و از نو پر می‌شود. در باکس **گزارش همگام‌سازی** زیر آمار، لیست فارسی هر مورد رد شده (شناسه `GoodCode` + دلیل + کد/متن انگلیسی خطا) نمایش داده می‌شود. کلاس: `includes/class-sync-skip-log.php`.
### Cron سرور (cPanel) — روش پیشنهادی

1. در **همگام‌سازی سبز** → تنظیمات، گزینه **همگام‌سازی با cron سرور (cPanel)** را فعال کنید و ذخیره کنید.
2. **همگام‌سازی اتوماتیک (WP Cron)** را خاموش نگه دارید (با فعال بودن cron سرور، WP Cron محصول اجرا نمی‌شود).
3. در cPanel → **Cron Jobs** دستور زیر را ثبت کنید (مسیر PHP را با `which php` بررسی کنید):

```bash
/usr/local/bin/php /home/YOUR_USER/public_html/wp-content/plugins/sabz-afzar-integration/cron-sync.php >> /home/YOUR_USER/logs/sai-cron.log 2>&1
```

4. زمان‌بندی پیشنهادی: هر ساعت — دقیقه `0`، ساعت `*`.
5. قبل از cron، یک بار دستی تست کنید: `php /path/to/cron-sync.php`
6. (اختیاری) در `wp-config.php`: `define('DISABLE_WP_CRON', true);` — فقط cron محصول را با `cron-sync.php` اجرا کنید؛ سایر رویدادهای وردپرس همچنان به بازدید سایت وابسته می‌مانند مگر wp-cron جداگانه تنظیم شود.

**خروجی موفق:** خطوط `[SAI_CRON]` در `sai-cron.log` با `Status : OK` و exit code `0`. خطای batch → exit code `1`.

### انواع محصول

| نوع | شرط | مثال |
|-----|------|------|
| **Variable + variation** | `GoodGroupCode` دارد + رنگ/سایز در نام parse می‌شود | `تیشرت پرنده شدن سفید` → parent: تیشرت پرنده شدن، variation: رنگ سفید؛ `تابلو جام قاب طلایی سایز 70×70` → parent: تابلو جام، رنگ: قاب طلایی، سایز: 70X70 |
| **Simple** | `GoodGroupCode` خالی یا نام parse نمی‌شود | محصول بدون پسوند رنگ/سایز |

- حتی **یک** variation (تک‌رنگ یا تک‌سایز) هم به‌صورت محصول متغیر ساخته می‌شود.
- SKU والد (جدید): `sai-parent-{اولینGoodCode}` — اولین کد محصول گروه (مرتب‌سازی الفبایی)
- SKU والد (legacy، فقط lookup): `sai-parent-{کدگروه}-{hash}` — برای محصولات قبلاً ایمپورت‌شده
- SKU variation: `GoodCode` از سبز افزار

**جلوگیری از تکرار:** قبل از ساخت والد، `find_variable_parent_id()` به ترتیب SKU جدید، variation موجود، SKU legacy و meta `_sai_parent_anchor_goodcode` را جستجو می‌کند. SKU والد موجود تغییر نمی‌کند.

**دسته‌بندی:** فقط هنگام **ایجاد** محصول جدید از `GoodGroupName` ساخته/تخصیص می‌شود؛ در آپدیت دسته WooCommerce تغییر نمی‌کند.

**وضعیت انتشار:** محصولات draft در آپدیت draft می‌مانند؛ فقط والد variable **جدید** به‌صورت publish ایجاد می‌شود.

### parse نام محصول

- **رنگ:** پسوند رنگ در انتهای نام (مشکی، سفید، سدری، …) یا توصیفگر قاب (`قاب طلایی`، `قاب نقره‌ای`) وقتی سایز ابعادی در نام باشد
- **سایز پوشاک:** `سایز M`، `سایز3`، یا سایز تنها در انتها (`M`، `3`، `XL`)
- **سایز ابعادی:** `سایز 70×70` / `سایز 50x50` (نرمال به `70X70`)

مثال تابلو: `تابلو جام قاب طلایی سایز 70×70` → والد `تابلو جام`، رنگ `قاب طلایی`، سایز `70X70`

لیست رنگ‌ها در `get_color_suffixes()` داخل `includes/class-woo-integration.php` قابل گسترش است.

### remediation

اگر محصولی به‌اشتباه simple مانده:

1. sync کامل
2. **تبدیل variationهای جاافتاده** در پنل ادمین

---

## همگام‌سازی سفارش

وقتی سفارش به وضعیت **processing** می‌رود:

1. **مشتری** — `POST api/linkJSONEShopAddCustomer` (مطابق Postman AddPerson): `firstName`, `lastName`, `mobileNo` (۱۰ رقم), `email=null`, `introducerMobileNo=''`, `isMale=true` — بدون body و بدون `Content-Type`. پاسخ: `PersonId`؛ `NationalCode` و `Gender` فقط در JSON پاسخ.
2. **فاکتور تاییدشده** — `POST api/LinkJSONEShopAddHistFactor` با JSON body (`PersonId`, `histFactorDocDetails`, `LocationCode`, …).

هر دو در `includes/class-order-sync.php` و `includes/class-api-service.php`. لاگ: `[SAI] AddCustomer query:` و `[SabzAfzar Integration]`.

### یادداشت‌های سفارش (Order Notes)

پس از همگام‌سازی، دو یادداشت خصوصی در پنل سفارش ثبت می‌شود:

1. **مشتری** — موفق از API: «نام کاربری ساخته شد» + شناسه؛ از کش وردپرس: «نام کاربری از قبل ساخته شده است» + شناسه؛ خطا: «نام کاربری ایجاد و یا یافت نشد» + متن ارور.
2. **فاکتور** — موفق: «فاکتور ایجاد شد» + شماره فاکتور؛ خطا: «فاکتور ایجاد نشد» + متن ارور.

یادداشت‌ها فقط یک‌بار ثبت می‌شوند (سفارش‌های قبلاً sync‌شده بدون یادداشت تکراری).

### Deploy با zip

اگر افزونه را با `sabz-afzar-integration.zip` روی سرور نصب می‌کنید، **بعد از هر تغییر کد** zip را دوباره بسازید (zip قدیمی ممکن است هنوز `Content-Type: application/json` روی AddCustomer بفرستد و HTTP 400 بدهد).

از PowerShell در پوشهٔ افزونه:

```powershell
$root = "A:\local sites\taghechian\html\wp-content\plugins\sabz-afzar-integration"
$zip  = Join-Path $root "sabz-afzar-integration.zip"
if (Test-Path $zip) { Remove-Item $zip -Force }
$staging = Join-Path $env:TEMP "sai-zip-build"
if (Test-Path $staging) { Remove-Item $staging -Recurse -Force }
New-Item -ItemType Directory -Path $staging | Out-Null
@("sabz-afzar-integration.php", "cron-sync.php", "README.md", "assets", "includes", "templates") | ForEach-Object {
    Copy-Item -Path (Join-Path $root $_) -Destination $staging -Recurse -Force
}
Compress-Archive -Path (Join-Path $staging "*") -DestinationPath $zip -Force
Remove-Item $staging -Recurse -Force
Write-Host "Created: $zip"
```

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
| `[SAI] AddCustomer query:` | QueryString ساخت مشتری — باید `email=null` و `isMale=true` داشته باشد؛ در `[SAI] Headers` نباید `Content-Type` باشد |
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
│   ├── class-sync-lock.php      # قفل همزمانی sync
│   └── class-admin-handler.php  # پنل ادمین
├── templates/admin-dashboard.php
├── assets/js/admin-script.js
└── tests/simulate-variation-grouping.php
```

## نسخه

**1.0.0** — Amirali Dizabadi