<?php
// includes/class-sms-admin-page.php
namespace SmsWorkflow;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SMS_Admin_Page {

    private static $instance = null;
    public static function get_instance(){
        if(!self::$instance)
        {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_plugin_menu' ) );
        add_action( 'admin_init', array( $this, 'save_admin_settings' ) );
        // فعالسازی توابع Export و Import
        add_action( 'admin_init', array( $this, 'handle_export_templates' ) );
        add_action( 'admin_init', array( $this, 'handle_import_templates' ) );

        // 💡 هوک برای پردازش درخواست حذف از URL (اگر قبلا اضافه نشده)
        add_action( 'admin_init', array( $this, 'process_cleanup_url_request' ) );
    }

    /**
     * اضافه کردن آیتم‌های منو
     */
    public function add_plugin_menu() {
        add_menu_page(
            'تنظیمات پیامکی',
            'بازاریابی پیامکی',
            'manage_options',
            'sms-marketing-settings',
            array( $this, 'render_main_settings_page' ),
            'dashicons-email-alt',
            6
        );

        add_submenu_page(
            'sms-marketing-settings',
            'لاگ‌های ارسال',
            'لاگ‌ها',
            'manage_options',
            'sms-workflow-logs',
            array( $this, 'render_logs_page' )
        );

        // اضافه کردن زیرمنوی تست
        add_submenu_page(
            'sms-marketing-settings',
            'اجرای تست',
            'اجرای تست',
            'manage_options',
            'sms-workflow-test',
            array( $this, 'render_test_page' )
        );

        // 💡 اضافه کردن زیرمنوی WorkFlow Builder
        add_submenu_page(
            'sms-marketing-settings',
            'WorkFlow Builder',
            'WorkFlow Builder',
            'manage_options',
            'sms-workflow-builder',
            array( $this, 'render_workflow_builder_page' )
        );
    }

    /**
     * رندر صفحه اجرای تست
     */
    public function render_test_page() {
        if (!class_exists('SmsWorkflow\SMS_Workflow_Runner')) {
            echo '<div class="wrap"><p class="error">خطا: کلاس SMS_Workflow_Runner یافت نشد.</p></div>';
            return;
        }

        ?>
        <div class="wrap">
            <h1>اجرای تست ورکفلو</h1>
            <p>برای اجرای یک نمونه‌ی آزمایشی از ورکفلو و بررسی صحت ارسال و ذخیره‌سازی لاگ، دکمه زیر را بزنید.</p>

            <?php
            if ( isset( $_POST['run_test'] ) && current_user_can( 'manage_options' ) ) {
                $runner = new SMS_Workflow_Runner();
                $runner->run_test_workflow();
            }
            ?>

            <form method="post" action="">
                <?php wp_nonce_field( 'sms_test_workflow_action', 'sms_test_workflow_nonce' ); ?>
                <p class="submit">
                    <input type="submit" name="run_test" id="run-test" class="button button-primary button-large" value="اجرای تست ورکفلو (ارسال پیامک)">
                </p>
            </form>

            <p>⚠️ **هشدار:** اجرای این تست، یک پیامک واقعی با اطلاعات تستی به شماره‌ای که در تابع `run_test_workflow` تعیین شده است، ارسال می‌کند.</p>
        </div>
        <?php
    }

    /**
     * رندر صفحه اصلی تنظیمات (API و تمپلیت‌ها و Export/Import)
     */
    public function render_main_settings_page() {
        if (!class_exists('SmsWorkflow\SMS_Workflow_Runner')) {
            echo '<div class="wrap"><p class="error">خطا: کلاس SMS_Workflow_Runner یافت نشد.</p></div>';
            return;
        }


        $user_phone = '09129176722';
        $cleanup_link = $this->sms_workflow_get_cleanup_url($user_phone);

// 💡 برای نمایش در یک صفحه ادمین:
 echo '<a href="' . esc_url($cleanup_link) . '">حذف تمام ورکفلوهای ' . esc_html($user_phone) . '</a>';
        $api_key = get_option( 'sms_api_key' );
        $sender_number = get_option( 'sms_sender_number' );
        $templates = get_option( 'sms_templates', array( array( 'name' => '', 'text' => '' ) ) );
        $available_tags = SMS_Workflow_Runner::get_available_tags();
        ?>
        <div class="wrap">
            <h1>تنظیمات بازاریابی پیامکی</h1>

            <?php
            // نمایش خطاهای ذخیره شده در سشن (برای Export/Import)
            if ( isset( $_GET['settings-updated'] ) ) {
                settings_errors( 'sms_settings' );
            }
            // 2. 🚨 نمایش پیام‌های موفقیت/خطای Cleanup 🚨
            settings_errors( 'sms_cleanup_status' );
            ?>

            <form method="post" action="">
                <?php wp_nonce_field( 'sms_settings_nonce_action', 'sms_settings_nonce' ); ?>

                <h2>بخش ۱: تنظیمات پنل پیامکی</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="sms_api_key">کلید API</label></th>
                        <td><input name="sms_api_key" type="text" id="sms_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sms_sender_number">شماره ارسال‌کننده</label></th>
                        <td><input name="sms_sender_number" type="text" id="sms_sender_number" value="<?php echo esc_attr( $sender_number ); ?>" class="regular-text" required /></td>
                    </tr>
                </table>

                <hr>

                <h2>بخش ۲: مدیریت تمپلیت‌های پیامکی</h2>
                <p><strong>تگ‌های مجاز:</strong>
                    <?php echo implode( ', ', array_keys( $available_tags ) ); ?>
                </p>
                <div id="sms-templates-container">
                    <?php foreach ( $templates as $index => $template ) : ?>
                        <div class="sms-template-item">
                            <h3>تمپلیت #<?php echo $index + 1; ?></h3>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><label for="template_name_<?php echo $index; ?>">نام تمپلیت</label></th>
                                    <td><input name="templates[<?php echo $index; ?>][name]" type="text" id="template_name_<?php echo $index; ?>" value="<?php echo esc_attr( $template['name'] ); ?>" class="regular-text" required /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="template_text_<?php echo $index; ?>">متن پیامک</label></th>
                                    <td><textarea name="templates[<?php echo $index; ?>][text]" id="template_text_<?php echo $index; ?>" rows="4" cols="50" class="large-text" required><?php echo esc_textarea( $template['text'] ); ?></textarea></td>
                                </tr>
                            </table>
                            <?php if ( $index > 0 ) : ?>
                                <button type="button" class="button button-secondary remove-template">حذف تمپلیت</button>
                            <?php endif; ?>
                            <hr>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" id="add-template-btn" class="button button-primary">افزودن تمپلیت جدید</button>

                <p class="submit">
                    <input type="submit" name="sms_save_settings" id="submit" class="button button-primary" value="ذخیره تغییرات">
                </p>
            </form>

            <hr>
            <h2>بخش ۳: ایمپورت و اکسپورت تمپلیت‌ها 🔄</h2>

            <div style="display: flex; gap: 30px;">
                <form method="post" action="" style="padding: 15px; border: 1px solid #ccc; background: #f9f9f9;">
                    <h3>خروجی گرفتن (Export)</h3>
                    <p>یک فایل JSON شامل تمامی تمپلیت‌های ذخیره‌شده را دانلود کنید.</p>
                    <?php wp_nonce_field( 'sms_export_nonce_action', 'sms_export_nonce' ); ?>
                    <input type="hidden" name="sms_export_action" value="1" />
                    <input type="submit" class="button button-secondary" value="دانلود فایل Export" />
                </form>

                <form method="post" action="" enctype="multipart/form-data" style="padding: 15px; border: 1px solid #ccc; background: #f9f9f9;">
                    <h3>ایمپورت کردن (Import)</h3>
                    <p>لطفاً فایل JSON را انتخاب کرده و نحوه اعمال تمپلیت‌ها را مشخص کنید:</p>

                    <table class="form-table">
                        <tr>
                            <th scope="row">نحوه اعمال</th>
                            <td>
                                <label>
                                    <input type="radio" name="import_mode" value="replace" checked required />
                                    جایگزینی کامل تمپلیت‌ها (حذف همه تمپلیت‌های موجود)
                                </label><br>
                                <label>
                                    <input type="radio" name="import_mode" value="append" required />
                                    اضافه کردن این تمپلیت‌ها (به انتهای لیست موجود اضافه شوند)
                                </label>
                            </td>
                        </tr>
                    </table>

                    <?php wp_nonce_field( 'sms_import_nonce_action', 'sms_import_nonce' ); ?>
                    <input type="file" name="import_file" required />
                    <input type="hidden" name="sms_import_action" value="1" />
                    <p class="submit">
                        <input type="submit" class="button button-primary" value="ایمپورت و جایگزینی تمپلیت‌ها" />
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * رندر صفحه لاگ‌ها
     */
    public function render_logs_page() {
        if (!class_exists('SmsWorkflow\SMS_Logs_List_Table')) {
            wp_die('خطا: کلاس SMS_Logs_List_Table یافت نشد.');
        }

        $list_table =SMS_Logs_List_Table::get_instance();
        $action = $list_table->current_action();

        // مدیریت حذف و لغو
        if ( $action && isset( $_GET['log'] ) ) {
            $ids = array_map( 'absint', (array) $_GET['log'] );
            $processed = false;

            // ------------------------------------
            // 1. اعتبارسنجی Nonce
            // ------------------------------------
            // 🚨 تشخیص: اگر action2 (از دکمه Bulk) در URL باشد، این یک Bulk Action است.
            $is_bulk_form_submission = isset($_GET['action2']) || isset($_GET['bulk_action']) || (count($ids) > 1);

            if ($is_bulk_form_submission) {
                // 🚨 بلوک B: عملیات دسته‌جمعی (Bulk Action)
                check_admin_referer('bulk-' . $list_table->_args['plural']);

            } elseif ( count($ids) === 1 && isset($_GET['_wpnonce']) ) {
                // 🚨 بلوک A: عملیات تک‌ردیفی (Row Action Link Click)
                $log_id = $ids[0];
                $nonce_key = ($action === 'delete') ? 'sms_delete_log_' : 'sms_cancel_log_';

                if (! wp_verify_nonce( sanitize_text_field($_GET['_wpnonce']), $nonce_key . $log_id ) ) {
                    wp_die('خطای امنیتی (عملیات تک‌ردیفی): Nonce نامعتبر.');
                }

            } else {
                // 🛑 در حالت عادی نباید به اینجا برسیم.
                wp_die('خطای امنیتی: درخواست بدون توکن تأیید معتبر.');
            }

            // ------------------------------------
            // 2. بلوک اجرای عملیات
            // ------------------------------------
            if ($action == 'delete') {

                if ( SMS_DB_Manager::delete_logs( $ids ) ) {
                    add_settings_error('sms_cancel_status', 'delete_success', 'لاگ‌های انتخاب شده حذف شدند.', 'success');
                    $processed = true;
                }

            } elseif ($action == 'cancel_sms') {

                $result = $this->handle_cancel_sms( $ids );
                $count = $result['count'];

                if ($count > 0) {
                    add_settings_error('sms_cancel_status', 'cancel_success', $count . ' پیامک برنامه‌ریزی شده لغو شدند.', 'success');
                }

                if (!empty($result['errors'])) {
                    $error_details = implode('<br>', $result['errors']);
                    add_settings_error('sms_cancel_status', 'bulk_errors', "عملیات لغو کامل نشد. جزئیات: {$error_details}", 'error');
                } else if (count($ids) > 0 && $count === 0) {
                    add_settings_error('sms_cancel_status', 'no_cancellable', 'هیچ پیامک برنامه‌ریزی شده و فعالی برای لغو یافت نشد.', 'warning');
                }

                $processed = true;
            }

            // ریدایرکت برای جلوگیری از اجرای مجدد اکشن
            if ($processed) {
                $redirect_url = remove_query_arg( array('action', 'log', '_wpnonce', 'action2', '_wp_http_referer'), $_SERVER['REQUEST_URI'] );
                wp_safe_redirect( add_query_arg('settings-updated', 'true', $redirect_url) );
                exit;
            }
        }

        $list_table->prepare_items();
        ?>
        <div class="wrap">
            <h1>لاگ‌های ارسال پیامک</h1>

            <?php
            settings_errors('sms_cancel_status');
            ?>

            <p>این جدول تمام اجراهای ورکفلو و وضعیت ارسال پیامک را نمایش می‌دهد.</p>
            <form id="sms-logs-filter" method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr( $_REQUEST['page'] ); ?>" />
                <?php wp_nonce_field('bulk-' . $list_table->_args['plural']); ?>
                <?php $list_table->display(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * اتصال به کلاس UI Builder Singleton
     */
    public function render_workflow_builder_page() {
        // 🚨 فراخوانی متد render از کلاس Builder با استفاده از Singleton
        Workflow_Builder_UI::get_instance()->render_builder_page();
    }


    /**
     * ذخیره تنظیمات API و تمپلیت‌ها
     */
    public function save_admin_settings() {
        if ( ! isset( $_POST['sms_save_settings'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // اعتبارسنجی Nonce
        if ( ! isset( $_POST['sms_settings_nonce'] ) || ! wp_verify_nonce( $_POST['sms_settings_nonce'], 'sms_settings_nonce_action' ) ) {
            return;
        }

        // ذخیره تنظیمات API (بدون تغییر)
        if ( isset( $_POST['sms_api_key'] ) ) {
            update_option( 'sms_api_key', sanitize_text_field( $_POST['sms_api_key'] ) );
        }
        if ( isset( $_POST['sms_sender_number'] ) ) {
            update_option( 'sms_sender_number', sanitize_text_field( $_POST['sms_sender_number'] ) );
        }

        // ذخیره تمپلیت‌ها با اعتبارسنجی نام تکراری
        if ( isset( $_POST['templates'] ) && is_array( $_POST['templates'] ) ) {
            $sanitized_templates = array();
            $used_names = array(); // 💡 آرایه برای ردیابی نام‌های استفاده شده
            $has_error = false;

            foreach ( $_POST['templates'] as $template ) {

                // اگر فیلدهای ضروری وجود نداشته باشند، این بخش را رد می‌کنیم.
                if ( ! isset( $template['name'] ) || ! isset( $template['text'] ) ) {
                    continue;
                }

                $name = sanitize_text_field( $template['name'] );
                $text = sanitize_textarea_field( $template['text'] );

                // 1. اعتبارسنجی: نام خالی نباشد (اختیاری)
                if ( empty($name) && !empty($text) ) {
                    add_settings_error( 'sms_settings', 'template_name_empty', 'نام تمپلیت نمی‌تواند خالی باشد.', 'error' );
                    $has_error = true;
                    continue;
                }

                // 2. 🛑 اعتبارسنجی: بررسی تکراری بودن نام
                if ( in_array($name, $used_names) && !empty($name) ) {
                    add_settings_error( 'sms_settings', 'duplicate_template_name', 'نام تمپلیت "' . esc_html($name) . '" تکراری است.', 'error' );
                    $has_error = true;
                    continue; // توقف پردازش این تمپلیت
                }

                // 3. اگر نامی وجود دارد و تکراری نیست، آن را اضافه کنید
                if (!empty($name) || !empty($text)) {
                    $sanitized_templates[] = array('name' => $name, 'text' => $text);
                    $used_names[] = $name;
                }
            }

            // 4. اگر خطا وجود داشت، فرآیند را قبل از ذخیره متوقف کنید
            if ($has_error) {
                // ریدایرکت برای نمایش پیام خطا (settings-updated را برای فعال کردن settings_errors می‌فرستیم)
                $redirect_url = add_query_arg( 'settings-updated', 'true', admin_url( 'admin.php?page=sms-marketing-settings' ) );
                wp_safe_redirect( $redirect_url );
                exit;
            }

            // 5. مدیریت تمپلیت پیش‌فرض (اگر همه حذف شدند)
            if (empty($sanitized_templates)) {
                $sanitized_templates[] = ['name' => 'اولین تمپلیت', 'text' => 'سلام [user_name]'];
            }

            update_option( 'sms_templates', $sanitized_templates );
        } else {
            // حداقل یک تمپلیت پیش‌فرض (در صورت ارسال آرایه خالی)
            update_option( 'sms_templates', array( ['name' => 'اولین تمپلیت', 'text' => 'سلام [user_name]'] ) );
        }

        // ریدایرکت برای نمایش پیام موفقیت (پس از ذخیره‌سازی موفق)
        $redirect_url = add_query_arg( 'settings-updated', 'true', admin_url( 'admin.php?page=sms-marketing-settings' ) );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * مدیریت درخواست خروجی گرفتن (Export) از تمپلیت‌ها
     */
    public function handle_export_templates() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // اطمینان از اینکه اکشن Export فعال شده باشد و nonce معتبر باشد
        if ( ! isset( $_POST['sms_export_action'] ) || ! isset( $_POST['sms_export_nonce'] ) || ! wp_verify_nonce( $_POST['sms_export_nonce'], 'sms_export_nonce_action' ) ) {
            return;
        }

        $templates = get_option( 'sms_templates', array() );

        // اگر تمپلیتی وجود ندارد
        if ( empty( $templates ) ) {
            wp_die( 'هیچ تمپلیتی برای خروجی گرفتن یافت نشد.', 'خطا در خروجی گرفتن', ['response' => 400] );
        }

        $export_data = array(
            'plugin_version' => '1.0.0',
            'export_date'    => current_time( 'mysql' ),
            'templates'      => $templates,
        );

        // تنظیم هدرها برای دانلود فایل JSON
        $filename = 'sms-templates-export-' . date( 'Ymd' ) . '.json';
        header( 'Content-Description: File Transfer' );
        header( 'Content-type: application/json' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Expires: 0' );
        header( 'Cache-Control: must-revalidate' );
        header( 'Pragma: public' );

        echo json_encode( $export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        exit;
    }

    /**
     * مدیریت درخواست ایمپورت کردن (Import) تمپلیت‌ها
     * 💡 تغییرات: پیاده‌سازی منطق 'replace' و 'append'
     */
    public function handle_import_templates() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! isset( $_POST['sms_import_action'] ) || ! isset( $_POST['sms_import_nonce'] ) || ! wp_verify_nonce( $_POST['sms_import_nonce'], 'sms_import_nonce_action' ) ) {
            return;
        }

        if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
            add_settings_error( 'sms_settings', 'import_error', 'لطفاً یک فایل JSON را انتخاب کنید.', 'error' );
            return;
        }

        // 1. دریافت حالت ایمپورت
        $import_mode = sanitize_text_field($_POST['import_mode'] ?? 'replace');

        $file_content = file_get_contents( $_FILES['import_file']['tmp_name'] );
        $data = json_decode( $file_content, true );

        // بررسی صحت داده‌های JSON و ساختار آن
        if ( ! $data || ! isset( $data['templates'] ) || ! is_array( $data['templates'] ) ) {
            add_settings_error( 'sms_settings', 'import_error', 'ساختار فایل JSON وارد شده نامعتبر است.', 'error' );
            return;
        }

        // 2. پاکسازی و اعتبارسنجی تمپلیت‌های وارد شده (بررسی تکراری بودن در فایل وارد شده)
        $new_templates = array();
        $new_template_names = array();
        $has_error = false;

        foreach ( $data['templates'] as $template ) {
            if ( ! isset( $template['name'] ) || ! isset( $template['text'] ) ) {
                continue;
            }

            $name = sanitize_text_field( $template['name'] );
            $text = sanitize_textarea_field( $template['text'] );

            if ( empty($name) && !empty($text) ) {
                add_settings_error( 'sms_settings', 'template_name_empty', 'نام تمپلیت نمی‌تواند خالی باشد.', 'error' );
                $has_error = true;
                continue;
            }

            // 🛑 بررسی تکراری بودن نام در فایل Import شده
            if ( in_array($name, $new_template_names) && !empty($name) ) {
                add_settings_error( 'sms_settings', 'duplicate_import_name', 'نام تمپلیت "' . esc_html($name) . '" در فایل Import تکراری است.', 'error' );
                $has_error = true;
                continue;
            }

            $new_templates[] = array('name' => $name, 'text' => $text);
            $new_template_names[] = $name;
        }

        // اگر در فایل Import شده خطایی بود، متوقف شود
        if ($has_error) {
            return;
        }

        // 3. اعمال منطق بر اساس حالت ایمپورت
        $existing_templates = get_option('sms_templates', array());
        $existing_names = array_column($existing_templates, 'name'); // واکشی نام‌های موجود
        $final_templates = array();
        $message = '';

        if ($import_mode === 'replace') {
            // حالت جایگزینی: جایگزینی کامل، پس نیازی به چک تداخل نیست
            $final_templates = $new_templates;
            $message = 'تمپلیت‌ها با موفقیت جایگزین شدند.';

        } else {
            // حالت اضافه کردن (Append): چک تداخل با تمپلیت‌های موجود
            $tally = 0;
            foreach ($new_templates as $new_t) {
                if (in_array($new_t['name'], $existing_names)) {
                    add_settings_error( 'sms_settings', 'duplicate_existing_name', 'تمپلیت با نام "' . esc_html($new_t['name']) . '" قبلاً وجود دارد و اضافه نشد.', 'error' );
                    $has_error = true;
                } else {
                    $existing_templates[] = $new_t; // اضافه کردن به لیست موجود
                    $tally++;
                }
            }
            $final_templates = $existing_templates;
            $message = $tally . ' تمپلیت با موفقیت اضافه شدند.';
        }

        // 4. مدیریت تمپلیت پیش‌فرض (اگر همه حذف شدند)
        if (empty($final_templates)) {
            $final_templates[] = ['name' => 'اولین تمپلیت', 'text' => 'سلام [user_name]'];
        }

        // 5. ذخیره نهایی تنظیمات
        update_option( 'sms_templates', $final_templates );

        // 6. نمایش پیام موفقیت‌آمیز (فقط در صورت عدم وجود خطای تداخلی)
        if (!$has_error) {
            add_settings_error( 'sms_settings', 'import_success', $message, 'success' );
        }

        // ریدایرکت برای نمایش پیام موفقیت/خطا
        $redirect_url = add_query_arg( 'settings-updated', 'true', admin_url( 'admin.php?page=sms-marketing-settings' ) );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * مدیریت لغو پیامک‌های برنامه‌ریزی شده
     */
    /**
     * مدیریت لغو پیامک‌های برنامه‌ریزی شده
     */
    private function handle_cancel_sms($log_ids) {
        global $wpdb;
        $table_name = SMS_DB_Manager::get_log_table_name();

        if (!class_exists('SmsWorkflow\Workflow_Sms')) {
            return ['count' => 0, 'errors' => ['کلاس Sms برای عملیات لغو یافت نشد.']];
        }

        $sms_api = \SmsWorkflow\Workflow_Sms::get_instance();
        $cancelled_count = 0;
        $errors = [];

        // 1. واکشی تمام لاگ‌ها بر اساس IDها
        $id_list = implode( ',', array_map( 'absint', $log_ids ) );
        $logs = $wpdb->get_results(
            "SELECT id, message_id, status FROM $table_name WHERE id IN ({$id_list})",
            ARRAY_A
        );

        if (empty($logs)) {
            return ['count' => 0, 'errors' => ['هیچ لاگی برای پردازش یافت نشد.']];
        }

        foreach ($logs as $log) {
            $log_id = $log['id'];
            $message_ids_string = $log['message_id'];
            $current_status = $log['status'];

            // 🛑 A. منطق WorkFlow در صف (Scheduled) 🛑
            if ( strtolower($current_status) === 'scheduled' || strtolower($current_status) === 'در صف ارسال' || strtolower($current_status) === 'زمانبندی شده' ) {

                // 1. به‌روزرسانی وضعیت در دیتابیس به "Cancelled" (بدون تماس با API)
                $wpdb->update(
                    $table_name,
                    array( 'status' => 'Cancelled' ),
                    array( 'id' => $log_id )
                );
                $cancelled_count++;
                continue; // 💡 به لاگ بعدی بروید
            }

            // 🛑 B. منطق WorkFlow ارسال شده (نیاز به لغو API) 🛑

            // اگر وضعیت قابل لغو نیست یا Message ID ندارد، رد شود
            if ( $current_status !== 'Sent' && $current_status !== 'Completed' && $current_status !== 'ثبت شد' ) {
                continue;
            }

            if (empty($message_ids_string)) {
                $errors[] = "ID {$log_id}: وضعیت ارسال شده اما Message ID وجود ندارد.";
                $wpdb->update($table_name, array( 'status' => 'Cancel Failed' ), array( 'id' => $log_id ));
                continue;
            }

            // اجرای لغو API
            try {
                $cancel_response = $sms_api->cancel($message_ids_string);

                if (is_array($cancel_response) && !empty($cancel_response)) {
                    $cancelled_count += count($cancel_response);
                    $status = 'Cancelled';
                } else {
                    $status = 'Cancel Failed';
                    $errors[] = "ID {$log_id}: پاسخ API نامعتبر یا خالی ({$message_ids_string}).";
                }

                // به‌روزرسانی وضعیت در دیتابیس
                $wpdb->update(
                    $table_name,
                    array( 'status' => $status, 'log_data' => serialize($cancel_response) ),
                    array( 'id' => $log_id )
                );

            } catch (\Kavenegar\Exceptions\ApiException $e) {
                $errors[] = "ID {$log_id}: خطای API - " . $e->getMessage();
                $wpdb->update($table_name, array( 'status' => 'Cancel Failed', 'log_data' => serialize(['error' => $e->getMessage()]) ), array( 'id' => $log_id ));
            } catch (\Kavenegar\Exceptions\HttpException $e) {
                $errors[] = "ID {$log_id}: خطای ارتباطی - " . $e->getMessage();
                $wpdb->update($table_name, array( 'status' => 'Cancel Failed', 'log_data' => serialize(['error' => $e->getMessage()]) ), array( 'id' => $log_id ));
            }
        }

        return ['count' => $cancelled_count, 'errors' => $errors];
    }

    /**
     * پردازش درخواست URL برای حذف کامل ورکفلوهای یک کاربر خاص.
     * (Example URL: /wp-admin/admin.php?user_cleanup=1&phone=0912...&_wpnonce=...)
     */
    public function process_cleanup_url_request() {

        // 1. بررسی فعال بودن اکشن مورد نظر
        if ( ! isset( $_GET['sms_cleanup_action'] ) ) {
            return;
        }

        // 🛑 شروع عیب‌یابی: این خط باید در debug.log ثبت شود
        error_log('CLEANUP DEBUG: URL action detected.');

        if ( ! current_user_can( 'manage_options' ) ) {
            error_log('CLEANUP ERROR: User lacks manage_options capability.');
            wp_die( 'خطای امنیتی: مجوزهای کافی برای اجرای این عملیات را ندارید.' );
        }

        // 2. اعتبارسنجی Nonce و ورودی‌ها
        $recipient_phone = sanitize_text_field( $_GET['phone'] ?? '' );
        $nonce = sanitize_text_field( $_GET['_wpnonce'] ?? '' );
        $expected_action = 'user_full_cleanup_' . $recipient_phone;

        if ( ! wp_verify_nonce( $nonce, $expected_action ) ) {
            error_log('CLEANUP ERROR: Nonce validation failed. Expected: ' . $expected_action . ', Received: ' . $nonce);
            wp_die( 'خطای امنیتی: Nonce یا مجوزهای شما نامعتبر است.' );
        }

        // 🛑 لاگ‌گیری: Nonce معتبر است
        error_log('CLEANUP DEBUG: Nonce validated successfully. Executing cancellation logic.');


        // 3. اجرای عملیات حذف و لغو
        // 💡 فراخوانی متد استاتیک ادغام‌شده در Runner
        $result = SMS_Workflow_Runner::execute_cancellation_by_recipient($recipient_phone);

        // 4. نمایش نتیجه و ریدایرکت
        $message = $result['message'] ?? 'عملیات ناموفق بود.';
        $type = $result['success'] ? 'success' : 'error';

        // نمایش نتیجه در ادمین نوتیس
        add_settings_error('sms_cleanup_status', 'cleanup_result', $message, $type);
        set_transient('settings_errors', get_settings_errors(), 30);

        // 5. ریدایرکت نهایی
        $redirect_url = admin_url( 'admin.php?page=sms-marketing-settings' );

        // 🚨 اطمینان از وجود پارامتر settings-updated برای نمایش پیام‌ها
        $redirect_url = add_query_arg('settings-updated', 'true', $redirect_url);
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * ساخت URL امن برای اجرای عملیات حذف و لغو تمامی WorkFlow های یک کاربر خاص.
     * این تابع باید در Admin Context اجرا شود.
     *
     * @param string $recipient_phone شماره موبایل کاربر مورد نظر (مانند '09121234567').
     * @return string URL کامل و امن برای اجرای عملیات.
     */
    function sms_workflow_get_cleanup_url(string $recipient_phone): string {

        // 1. تعریف اکشن مورد انتظار برای Nonce
        $nonce_action = 'user_full_cleanup_' . $recipient_phone;

        // 2. تولید توکن امنیتی (Nonce)
        $nonce = wp_create_nonce($nonce_action);

        // 3. پارامترهای اصلی URL
        $args = array(
            // پارامتر اصلی برای تشخیص اینکه این یک درخواست Cleanup است
            'sms_cleanup_action' => 1,

            // شماره موبایل هدف
            'phone'              => $recipient_phone,

            // Nonce امنیتی
            '_wpnonce'           => $nonce,
        );

        // 4. ساخت URL نهایی به سمت wp-admin/admin.php
        $cleanup_url = add_query_arg($args, admin_url('admin.php'));

        return $cleanup_url;
    }


}