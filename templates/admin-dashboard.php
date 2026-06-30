<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap sai-admin-wrap">
    <div class="sai-header">
        <p>همگام سازی سبز افزار</p>
        <span>مدیریت همگام‌سازی API سبز افزار، قیمت‌گذاری، موجودی، مشتریان و فاکتورها.</span>
    </div>

    <?php if (!empty($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible">
            <p>تنظیمات با موفقیت ذخیره شد.</p>
        </div>
    <?php endif; ?>

    <div class="sai-grid">
        <div class="sai-card">
            <p class="title">تنظیمات اتصال</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sai_save_settings">
                <?php wp_nonce_field('sai_save_settings_nonce'); ?>

                <div class="sai-field">
                    <label for="sai_api_base_url">آدرس برای ارسال درخواست پایه</label>
                    <input
                        type="text"
                        id="sai_api_base_url"
                        name="sai_api_base_url"
                        placeholder="localhost:4217"
                        value="<?php echo esc_attr(SAI_API_Service::format_base_url_for_display((string) get_option('sai_api_base_url', 'http://localhost:4217'))); ?>">
                    <p class="description">می‌توانید بدون http:// وارد کنید؛ هنگام ذخیره یا دریافت توکن، http:// به‌صورت خودکار اضافه می‌شود.</p>
                </div>

                <div class="sai-field">
                    <label for="sai_fixed_token">توکن</label>
                    <div class="sai-token-row">
                        <input type="text" id="sai_fixed_token" name="sai_fixed_token" value="<?php echo esc_attr(get_option('sai_fixed_token', '')); ?>">
                        <button type="button" class="button" id="sai-refresh-token">ساخت توکن جدید</button>
                    </div>
                    <p class="description">توکن از <code>POST /TOKEN</code> (OAuth) با کاربر پیش‌فرض greenware گرفته می‌شود؛ فقط با کلیک «ساخت توکن جدید» به‌روز می‌شود. بعد از آن «بررسی ارتباط» را بزنید.</p>
                </div>

                <div class="sai-field">
                    <label for="sai_branch_code">کد شعبه</label>
                    <input type="text" id="sai_branch_code" name="sai_branch_code" value="<?php echo esc_attr(get_option('sai_branch_code', '')); ?>">
                </div>

                <div class="sai-field">
                    <label for="sai_location_code">کد انبار (LocationCode)</label>
                    <input type="text" id="sai_location_code" name="sai_location_code" value="<?php echo esc_attr(get_option('sai_location_code', '')); ?>">
                    <p class="description">برای فاکتور تایید شده. اگر خالی باشد از کد شعبه استفاده می‌شود.</p>
                </div>

                <p class="title">تغییر ویژگی</p>

                <div class="sai-toggle-row">
                    <span>همگام سازی مشتریان</span>
                    <label class="sai-switch">
                        <input type="checkbox" name="sai_enable_customer_sync" <?php checked(get_option('sai_enable_customer_sync', 'yes'), 'yes'); ?>>
                        <span class="sai-slider"></span>
                    </label>
                </div>

                <div class="sai-toggle-row">
                    <span>ساخت فاکتور</span>
                    <label class="sai-switch">
                        <input type="checkbox" name="sai_enable_factor_creation" <?php checked(get_option('sai_enable_factor_creation', 'yes'), 'yes'); ?>>
                        <span class="sai-slider"></span>
                    </label>
                </div>

                <div class="sai-toggle-row">
                    <span>همگام سازی قیمت</span>
                    <label class="sai-switch">
                        <input type="checkbox" name="sai_enable_price_sync" <?php checked(get_option('sai_enable_price_sync', 'yes'), 'yes'); ?>>
                        <span class="sai-slider"></span>
                    </label>
                </div>

                <div class="sai-toggle-row">
                    <span>همگام سازی موجودی</span>
                    <label class="sai-switch">
                        <input type="checkbox" name="sai_enable_stock_sync" <?php checked(get_option('sai_enable_stock_sync', 'yes'), 'yes'); ?>>
                        <span class="sai-slider"></span>
                    </label>
                </div>

                <div class="sai-toggle-row" style="border-bottom: 0px solid #f0f0f1;">
                    <span>همگام‌سازی با cron سرور (cPanel)</span>
                    <label class="sai-switch">
                        <input type="checkbox" name="sai_use_server_cron" id="sai_use_server_cron" <?php checked(get_option('sai_use_server_cron', 'yes'), 'yes'); ?>>
                        <span class="sai-slider"></span>
                    </label>
                </div>
                <p class="description" style="margin-top:-8px; padding-bottom:16px; border-bottom: 1px solid #f0f0f1;">
                    وقتی فعال است، WP Cron همگام‌سازی محصول اجرا نمی‌شود؛ فقط <code>cron-sync.php</code> در cPanel باید زمان‌بندی شود.
                </p>

                <div class="sai-field sai-server-cron-box" id="sai-server-cron-box">
                    <label>دستور Cron در cPanel</label>
                    <textarea readonly rows="3" class="large-text code" onclick="this.select();">
                        <?php $cron_script = trailingslashit(WP_PLUGIN_DIR) . 'sabz-afzar-integration/cron-sync.php';
                        echo esc_textarea('/usr/local/bin/php ' . $cron_script . ' >> ~/logs/sai-cron.log 2>&1');
                        ?>
                    </textarea>
                    <p class="description">مسیر PHP را با <code>which php</code> در SSH بررسی کنید. زمان‌بندی پیشنهادی: هر ساعت (دقیقه ۰).</p>
                </div>

                <div class="sai-toggle-row sai-wp-cron-row" id="sai-wp-cron-row" style="border-bottom: 0px solid #f0f0f1;">
                    <span>همگام سازی اتوماتیک (WP Cron)</span>
                    <label class="sai-switch">
                        <input type="checkbox" name="sai_enable_auto_sync" id="sai_enable_auto_sync" <?php checked(get_option('sai_enable_auto_sync', 'no'), 'yes'); ?>>
                        <span class="sai-slider"></span>
                    </label>
                </div>
                <p class="description sai-wp-cron-desc" id="sai-wp-cron-desc" style="margin-top:-8px; padding-bottom:16px; border-bottom: 1px solid #f0f0f1;">
                    فقط وقتی «cron سرور» خاموش است استفاده کنید.
                </p>

                <div class="sai-toggle-row" style="display: none;">
                    <span>از نقطه پایانی فشرده استفاده کنید (لطفا این مورد فعال نشود)</span>
                    <label class="sai-switch">
                        <input type="checkbox" name="sai_use_compressed_endpoint" <?php checked(get_option('sai_use_compressed_endpoint', 'no'), 'yes'); ?>>
                        <span class="sai-slider"></span>
                    </label>
                </div>

                <div class="sai-field">
                    <label for="sai_sync_batch_size">تعداد دسته در هر batch</label>
                    <input
                        type="number"
                        id="sai_sync_batch_size"
                        name="sai_sync_batch_size"
                        min="1"
                        max="500"
                        step="1"
                        value="<?php echo esc_attr((string) Sabz_Afzar_Integration::get_sync_batch_size()); ?>">
                    <p class="description">
                        برای cron سرور، WP Cron و sync دستی اعمال می‌شود. هر batch = یک دستهٔ گروه‌بندی‌شده (مثلاً یک محصول متغیر با چند نسخه = یک دسته).
                        پیشنهاد هاست: ۵۰ تا ۱۵۰؛ برای ۲۰۰۰+ محصول می‌توانید ۱۰۰–۲۰۰ امتحان کنید.
                    </p>
                </div>

                <div class="sai-field" id="sai-wp-cron-interval">
                    <label for="sai_auto_sync_interval">فاصله همگام سازی خودکار</label>
                    <select id="sai_auto_sync_interval" name="sai_auto_sync_interval">
                        <option value="hourly" <?php selected(get_option('sai_auto_sync_interval', 'hourly'), 'hourly'); ?>>هر یک ساعت</option>
                        <option value="twicedaily" <?php selected(get_option('sai_auto_sync_interval', 'hourly'), 'twicedaily'); ?>>دو بار در روز</option>
                        <option value="daily" <?php selected(get_option('sai_auto_sync_interval', 'hourly'), 'daily'); ?>>روزی یک بار</option>
                    </select>
                    <p class="description">
                        اگر همگام‌سازی با cron سرور فعال است، این فیلد عملاً بی‌اثر است
                    </p>
                </div>

                <div class="sai-field">
                    <label for="sai_price_unit">واحد قیمت</label>
                    <select id="sai_price_unit" name="sai_price_unit">
                        <option value="rial" <?php selected(get_option('sai_price_unit', 'rial'), 'rial'); ?>>ریال</option>
                        <option value="toman" <?php selected(get_option('sai_price_unit', 'rial'), 'toman'); ?>>تومان</option>
                    </select>
                    <p class="description">اگر در سایت شما قیمت ها به ریال و یا تومان تنظیم شده است لطفا این قسمت هم گزینه متناسب با تنظیمات را انتخاب کنید.</p>
                </div>

                <div class="sai-actions">
                    <button type="submit" class="button button-primary">ذخیره تنظیمات</button>
                    <button type="button" class="button" id="sai-test-connection">بررسی ارتباط</button>
                    <button type="button" class="button" id="sai-manual-sync">شروع درون ریزی و آپدیت محصولات</button>
                    <button type="button" class="button" id="sai-remediate-variations">تبدیل نسخه‌های متغیر جاافتاده</button>
                </div>
            </form>
        </div>

        <div class="sai-sidebar">
            <div class="sai-card">
                <p class="title">وضعیت</p>
                <div id="sai-ajax-result">
                    <p>از دکمه‌ها برای آزمایش دسترسی به API یا شروع همگام‌سازی محصول استفاده کنید.</p>
                </div>
            </div>

            <div class="sai-card">
                <p class="title">گزارش همگام‌سازی</p>
                <div id="sai-report-result">
                    <p class="sai-report-empty">پس از شروع همگام‌سازی، آمار ساخته‌شده، آپدیت‌شده و رد شده اینجا نمایش داده می‌شود.</p>
                </div>
            </div>

            <div class="sai-card">
                <p class="title">گزارش همگام‌سازی خودکار</p>
                <div id="sai-auto-sync-report-result">
                    <p class="sai-auto-sync-report-empty">پس از اجرای cron، آمار آخرین همگام‌سازی خودکار (ساخته‌شده، آپدیت‌شده و رد شده) اینجا نمایش داده می‌شود.</p>
                </div>
            </div>
        </div>

    </div>
</div>