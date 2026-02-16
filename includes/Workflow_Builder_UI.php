<?php
// includes/Workflow_Builder_UI.php
namespace SmsWorkflow;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Workflow_Builder_UI {

    // 1. پراپرتی نمونه واحد
    private static $instance = null;

    // 2. سازنده خصوصی برای جلوگیری از نمونه‌سازی مستقیم
    private function __construct() {
        // 🚨 اتصال متد بارگذاری منابع به هوک وردپرس
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // 🚨 اتصال متد ذخیره WorkFlow به هوک admin_init
        add_action( 'admin_init', array( $this, 'handle_save_workflow' ) );

        // 🚨 اتصال متدهای پردازش درخواست URL
        add_action('admin_init', array($this, 'handle_toggle_status'));
        add_action('admin_init', array($this, 'handle_delete_workflow'));
    }

    // 3. متد استاتیک برای دریافت نمونه واحد (Singleton)
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * واکشی WorkFlowهای موجود از جدول DB.
     */
    private function fetch_all_workflows() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sms_workflows';

        // 💡 واکشی تمام ستون‌های اصلی ورکفلو
        $results = $wpdb->get_results(
            "SELECT id, name, category, is_active, created_at FROM {$table_name} ORDER BY id DESC",
            ARRAY_A
        );

        // اگر جدول وجود نداشته باشد، آرایه خالی برمی‌گردد
        return $results ?: [];
    }

    private function fetch_workflow_by_id($id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sms_workflows';

        $workflow = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $id),
            ARRAY_A
        );

        if ($workflow && isset($workflow['workflow_data'])) {
            // 💡 تبدیل داده‌های مراحل از Serialized به آرایه
            $workflow['workflow_data'] = maybe_unserialize($workflow['workflow_data']);
        }

        return $workflow;
    }


    public function render_builder_page() {

        // 1. واکشی WorkFlowهای موجود و تشخیص حالت ویرایش
        $workflows = $this->fetch_all_workflows(); // واکشی لیست WorkFlowهای ذخیره‌شده

        $edit_id = sanitize_key($_GET['edit_id'] ?? null);
        $current_workflow = null;

        // 2. واکشی WorkFlow انتخابی برای ویرایش
        if (is_numeric($edit_id) && $edit_id > 0) {
            $current_workflow = $this->fetch_workflow_by_id($edit_id);
            if (!$current_workflow) {
                // اگر WorkFlow با این ID پیدا نشد، به لیست برمی‌گردد
                $edit_id = null;
            }
        }

        // 3. 🚨 واکشی تمپلیت‌ها برای Dropdown (حل مشکل Undefined Variable)
        $templates = get_option( 'sms_templates', array() );
        $template_options = '';
        foreach ($templates as $t) {
            $name = esc_attr($t['name']);
            $template_options .= '<option value="' . $name . '">' . $name . '</option>';
        }

        ?>
        <div class="wrap">
            <h1>مدیریت WorkFlowهای پیامکی 🎯</h1>

            <?php settings_errors('workflow_save'); // نمایش پیام‌های ذخیره‌سازی ?>

            <a href="<?php echo esc_url(add_query_arg('edit_id', 'new', admin_url('admin.php?page=sms-workflow-builder'))); ?>" class="page-title-action">
                افزودن WorkFlow جدید
            </a>

            <?php if ($edit_id === 'new' || $current_workflow): ?>
                <div style="margin-top: 30px;">
                    <?php
                    // 💡 فراخوانی فرم کامل WorkFlow Builder با داده‌های موجود
                    $this->render_full_builder_form($current_workflow, $template_options);
                    ?>
                </div>
            <?php else: ?>
                <h2 class="screen-reader-text">لیست WorkFlowها</h2>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                    <thead>
                    <tr>
                        <th>نام WorkFlow</th>
                        <th>دسته‌بندی</th>
                        <th>وضعیت</th>
                        <th>تاریخ ثبت</th>
                        <th>عملیات</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($workflows)): ?>
                        <tr><td colspan="5">هیچ WorkFlowی تاکنون ذخیره نشده است.</td></tr>
                    <?php else: ?>
                        <?php foreach ($workflows as $wf):
                            $status_text = ($wf['is_active'] == 1) ? 'فعال' : 'غیرفعال';
                            $status_color = ($wf['is_active'] == 1) ? 'green' : 'red';
                            $edit_url = esc_url(add_query_arg('edit_id', $wf['id'], admin_url('admin.php?page=sms-workflow-builder')));

                            // URL برای تغییر وضعیت (روشن/خاموش)
                            $toggle_url = esc_url(add_query_arg([
                                'action' => 'toggle_status',
                                'wf_id' => $wf['id'],
                                '_wpnonce' => wp_create_nonce('wf_toggle_' . $wf['id'])
                            ], admin_url('admin.php?page=sms-workflow-builder')));

                            // URL برای حذف
                            $delete_url = esc_url(add_query_arg([
                                'action' => 'delete_workflow',
                                'wf_id' => $wf['id'],
                                '_wpnonce' => wp_create_nonce('wf_delete_' . $wf['id'])
                            ], admin_url('admin.php?page=sms-workflow-builder')));
                            ?>
                            <tr>
                                <td><?php echo esc_html($wf['name']); ?></td>
                                <td><?php echo esc_html($wf['category']); ?></td>
                                <td><strong style="color: <?php echo $status_color; ?>"><?php echo $status_text; ?></strong></td>
                                <td><?php echo date_i18n(get_option('date_format'), strtotime($wf['created_at'])); ?></td>
                                <td>
                                    <a href="<?php echo $edit_url; ?>" class="button button-small">ویرایش</a>
                                    | <a href="<?php echo $toggle_url; ?>"><?php echo ($wf['is_active'] == 1) ? 'غیرفعال کن' : 'فعال کن'; ?></a>
                                    | <a href="<?php echo $delete_url; ?>" onclick="return confirm('آیا از حذف WorkFlow مطمئن هستید؟')" style="color: red;">حذف</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * تابع نمایش فرم کامل WorkFlow Builder
     */
    public function render_full_builder_form($workflow_data = null, $template_options = '') {

        // 💡 تزریق داده‌ها یا تنظیم مقادیر پیش‌فرض
        $is_edit = $workflow_data !== null;
        $wf_id = $is_edit ? $workflow_data['id'] : '';
        $workflow_name = $is_edit ? $workflow_data['name'] : '';
        $workflow_category = $is_edit ? $workflow_data['category'] : '';
        $is_active = $is_edit ? $workflow_data['is_active'] : true;
        $steps_data = $is_edit ? ($workflow_data['workflow_data']['steps'] ?? []) : [
            array('days_after' => 0, 'send_time' => '10:00', 'template_name' => 'انتخاب کنید'),
        ];

        // 💡 نمایش HTML فرم کامل
        ?>
        <form method="post" action="" id="sms-workflow-builder-form">
            <?php wp_nonce_field( 'sms_workflow_save_action', 'sms_workflow_nonce' ); ?>

            <?php if ($is_edit): ?>
                <input type="hidden" name="workflow_id" value="<?php echo esc_attr($wf_id); ?>">
            <?php endif; ?>

            <div class="postbox">
                <h2 class="hndle"><span><?php echo $is_edit ? 'ویرایش WorkFlow: ' . esc_html($workflow_name) : 'افزودن WorkFlow جدید'; ?></span></h2>
                <div class="inside">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="workflow_name">نام WorkFlow</label></th>
                            <td>
                                <input type="text" name="workflow_name" id="workflow_name" value="<?php echo esc_attr($workflow_name); ?>" class="regular-text" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="workflow_category">دسته‌بندی</label></th>
                            <td><input type="text" name="workflow_category" id="workflow_category" value="<?php echo esc_attr($workflow_category); ?>" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="is_active">وضعیت</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="is_active" id="is_active" value="1" <?php checked($is_active, 1); ?>>
                                    فعال/اجرا (روشن)
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="postbox">
                <h2 class="hndle"><span>مراحل زمان‌بندی (Steps)</span></h2>
                <div class="inside">
                    <table class="wp-list-table widefat fixed striped steps-table" id="workflow-steps-container">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>نام تمپلیت</th>
                            <th>زمان ارسال (HH:MM)</th>
                            <th>تأخیر (روز بعد)</th>
                            <th>عملیات</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($steps_data as $index => $step) : ?>
                            <?php
                            // 💡 فراخوانی تابع رندر سطر با داده‌های واکشی شده
                            echo $this->render_step_row($index, $step, $template_options);
                            ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <button type="button" id="add-workflow-step" class="button button-secondary" style="margin-top: 15px;">افزودن مرحله جدید</button>
                </div>
            </div>

            <p class="submit">
                <input type="submit" name="save_workflow_settings" class="button button-primary" value="ذخیره WorkFlow">
            </p>
        </form>

        <?php
        // 💡 کد JS برای تکرار سطرها (نیاز به بارگذاری در assets/js/workflow-ui.js)
        echo '<script>';
        echo 'var smsTemplateOptions = ' . json_encode(str_replace("\n", "", $template_options)) . ';';
        echo '</script>';
    }

    /**
     * تابع کمکی برای رندر کردن یک سطر از مراحل WorkFlow
     */
    public function render_step_row($index, $step, $template_options) {
        ob_start();
        ?>
        <tr class="workflow-step-row" data-index="<?php echo $index; ?>">
            <td><?php echo $index + 1; ?></td>
            <td>
                <select name="steps[<?php echo $index; ?>][template_name]" required>
                    <option value="">انتخاب کنید</option>
                    <?php
                    // 💡 انتخاب مقدار فعلی در Dropdown
                    $selected_option = esc_attr($step['template_name']);
                    // جایگزینی ساده برای تنظیم selected
                    echo str_replace('value="' . $selected_option . '"', 'value="' . $selected_option . '" selected', $template_options);
                    ?>
                </select>
            </td>
            <td>
                <input type="text" name="steps[<?php echo $index; ?>][send_time]" value="<?php echo esc_attr($step['send_time']); ?>" placeholder="10:00 یا now" required>
            </td>
            <td>
                <input type="number" name="steps[<?php echo $index; ?>][days_after]" value="<?php echo esc_attr($step['days_after']); ?>" min="0" required>
            </td>
            <td>
                <button type="button" class="button button-small remove-step">حذف</button>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * متد بارگذاری منابع (CSS/JS)
     */
    public function enqueue_assets() {
        $screen = get_current_screen();

        // 💡 فقط در صورتی بارگذاری شود که در صفحه WorkFlow Builder هستیم
        if ($screen && strpos($screen->id, 'sms-workflow-builder') !== false) {

            // 1. CSS و JS عمومی (مورد نیاز برای ظاهر تمپلیت‌ها)
            wp_enqueue_script(
                'sms-workflow-admin-js',
                plugins_url( 'assets/js/admin.js', SMS_WORKFLOW_PLUGIN_DIR . 'sms-marketing-workflow.php' ),
                array( 'jquery' ),
                '1.0',
                true
            );
            wp_enqueue_style(
                'sms-workflow-admin-css',
                plugins_url( 'assets/css/admin.css', SMS_WORKFLOW_PLUGIN_DIR . 'sms-marketing-workflow.php' ),
                array(),
                '1.0'
            );

            // 2. اسکریپت اختصاصی WorkFlow Builder (برای تکرار سطرها)
            wp_enqueue_script(
                'sms-workflow-builder-js',
                plugins_url( 'assets/js/workflow-ui.js', SMS_WORKFLOW_PLUGIN_DIR . 'sms-marketing-workflow.php' ),
                array('jquery'),
                '1.0',
                true
            );
        }
    }

    /**
     * مدیریت درخواست‌های ذخیره WorkFlow از صفحه Builder
     */
    public function handle_save_workflow() {
        if ( ! isset( $_POST['save_workflow_settings'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'مجوز کافی برای اجرای این عملیات را ندارید.' );
        }

        if ( ! isset( $_POST['sms_workflow_nonce'] ) || ! wp_verify_nonce( $_POST['sms_workflow_nonce'], 'sms_workflow_save_action' ) ) {
            wp_die( 'خطای امنیتی: Nonce نامعتبر.' );
        }

        global $wpdb;
        $workflow_table = $wpdb->prefix . 'sms_workflows';

        // 💡 1. تشخیص حالت: جدید (null) یا ویرایش (ID)
        $workflow_id = (int) sanitize_key($_POST['workflow_id'] ?? 0);
        $is_editing = $workflow_id > 0;

        // 2. واکشی و اعتبارسنجی داده‌های اصلی
        $workflow_name = sanitize_text_field( $_POST['workflow_name'] ?? '' );
        $workflow_category = sanitize_text_field( $_POST['workflow_category'] ?? '' );
        $is_active = isset( $_POST['is_active'] ) ? 1 : 0;
        $steps_data = $_POST['steps'] ?? [];

        if ( empty( $workflow_name ) ) {
            add_settings_error( 'workflow_save', 'empty_name', 'نام WorkFlow نمی‌تواند خالی باشد.', 'error' );
            goto redirect;
        }

        // 3. پاکسازی و ساختاردهی مراحل (Steps)
        $sanitized_steps = [];
        $has_step_error = false;
        foreach ($steps_data as $step) {
            if (empty($step['template_name']) || empty($step['send_time'])) {
                add_settings_error( 'workflow_save', 'incomplete_step', 'مراحل WorkFlow باید نام تمپلیت و زمان ارسال داشته باشند.', 'error' );
                $has_step_error = true;
                break;
            }
            $sanitized_steps[] = [
                'template_name' => sanitize_text_field( $step['template_name'] ),
                'send_time'     => sanitize_text_field( $step['send_time'] ),
                'days_after'    => (int) $step['days_after'],
            ];
        }

        if ($has_step_error) {
            goto redirect;
        }

        // 4. ساخت آرایه داده‌های نهایی
        $data_to_save = [
            'name'          => $workflow_name,
            'category'      => $workflow_category,
            'is_active'     => $is_active,
            'workflow_data' => serialize(['steps' => $sanitized_steps]),
            // created_at فقط در زمان INSERT نیاز است
        ];

        // 5. 🛑 اجرای UPSERT (درج یا به‌روزرسانی)
        if ($is_editing) {
            // حالت UPDATE
            $result = $wpdb->update(
                $workflow_table,
                $data_to_save,
                ['id' => $workflow_id], // شرط WHERE
                ['%s', '%s', '%d', '%s'], // فرمت‌ها برای داده‌های نهایی
                ['%d'] // فرمت برای WHERE (id)
            );
            $message = 'WorkFlow با موفقیت به‌روزرسانی شد.';
        } else {
            // حالت INSERT
            $data_to_save['created_at'] = current_time('mysql');
            $result = $wpdb->insert($workflow_table, $data_to_save);
            $message = 'WorkFlow با موفقیت ذخیره شد.';
        }

        // 6. بررسی خطا
        if ($result === false) {
            add_settings_error( 'workflow_save', 'db_error', 'خطای دیتابیس در هنگام ذخیره‌سازی WorkFlow: ' . $wpdb->last_error, 'error' );
        } else {
            add_settings_error( 'workflow_save', 'saved', $message, 'success' );
        }

        // 7. ریدایرکت نهایی (بازگشت به لیست یا فرم ویرایش)
        redirect:
        // 💡 اگر ذخیره موفق بود، WorkFlow ID جدید را به URL اضافه کنید
        if (!$is_editing && $result !== false) {
            $workflow_id = $wpdb->insert_id;
            $redirect_url = admin_url( 'admin.php?page=sms-workflow-builder' );
            $redirect_url = add_query_arg('edit_id', $workflow_id, $redirect_url);
        } else {
            $redirect_url = admin_url( 'admin.php?page=sms-workflow-builder' );
        }

        wp_safe_redirect( add_query_arg('settings-updated', 'true', $redirect_url) );
        exit;
    }
    /**
     * 💡 متد استاتیک برای ایجاد جدول WorkFlow Builder.
     * این متد باید در زمان فعال‌سازی افزونه فراخوانی شود.
     * * @return void
     */
    public static function create_workflow_table() {
        global $wpdb;

        // 🚨 نیاز به استفاده از نام کامل کلاس SMS_DB_Manager
        $table_name = $wpdb->prefix . 'sms_workflows';

        // مطمئن می‌شویم که این تابع dbDelta در دسترس باشد
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        }

        $charset_collate = $wpdb->get_charset_collate();

        // 2. تعریف جدول WorkFlow Builder
        $sql_workflows = "CREATE TABLE " . $table_name . " (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            category VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            workflow_data LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY name_idx (name)
        ) " . $charset_collate . ";";

        dbDelta( $sql_workflows );
    }

    /**
     * مدیریت درخواست‌های تغییر وضعیت (Toggle Status) WorkFlow.
     */
    public function handle_toggle_status() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'toggle_status') {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('مجوز کافی ندارید.');
        }

        $wf_id = (int) sanitize_key($_GET['wf_id'] ?? 0);
        $nonce = sanitize_text_field($_GET['_wpnonce'] ?? '');

        // 1. اعتبارسنجی Nonce
        if (!$wf_id || !wp_verify_nonce($nonce, 'wf_toggle_' . $wf_id)) {
            wp_die('خطای امنیتی: Nonce نامعتبر یا WorkFlow ID نامشخص.');
        }

        global $wpdb;
        $workflow_table = $wpdb->prefix . 'sms_workflows';

        // 2. واکشی وضعیت فعلی
        $current_status = $wpdb->get_var($wpdb->prepare("SELECT is_active FROM {$workflow_table} WHERE id = %d", $wf_id));

        if ($current_status === null) {
            wp_die('WorkFlow مورد نظر یافت نشد.');
        }

        // 3. اجرای Toggle: تغییر وضعیت
        $new_status = ($current_status == 1) ? 0 : 1;

        $updated = $wpdb->update(
            $workflow_table,
            ['is_active' => $new_status],
            ['id' => $wf_id],
            ['%d'],
            ['%d']
        );

        // 4. نمایش پیام و ریدایرکت
        $message = ($new_status == 1) ? 'فعال' : 'غیرفعال';
        if ($updated !== false) {
            add_settings_error('workflow_save', 'status_changed', "WorkFlow با موفقیت $message شد.", 'success');
        } else {
            add_settings_error('workflow_save', 'status_error', 'خطای دیتابیس در هنگام به‌روزرسانی وضعیت.', 'error');
        }

        $redirect_url = remove_query_arg(['action', 'wf_id', '_wpnonce'], wp_get_referer() ?: admin_url('admin.php?page=sms-workflow-builder'));
        wp_safe_redirect(add_query_arg('settings-updated', 'true', $redirect_url));
        exit;
    }

    /**
     * مدیریت درخواست‌های حذف WorkFlow از صفحه Builder.
     */
    public function handle_delete_workflow() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'delete_workflow') {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('مجوز کافی ندارید.');
        }

        $wf_id = (int) sanitize_key($_GET['wf_id'] ?? 0);
        $nonce = sanitize_text_field($_GET['_wpnonce'] ?? '');

        // 1. اعتبارسنجی Nonce
        if (!$wf_id || !wp_verify_nonce($nonce, 'wf_delete_' . $wf_id)) {
            wp_die('خطای امنیتی: Nonce نامعتبر یا WorkFlow ID نامشخص.');
        }

        global $wpdb;
        $workflow_table = $wpdb->prefix . 'sms_workflows';

        // 2. اجرای حذف از دیتابیس
        $deleted = $wpdb->delete($workflow_table, ['id' => $wf_id], ['%d']);

        // 3. نمایش پیام و ریدایرکت
        if ($deleted === 1) {
            add_settings_error('workflow_save', 'deleted', 'WorkFlow با موفقیت حذف شد.', 'success');
        } else {
            add_settings_error('workflow_save', 'delete_error', 'WorkFlow یافت نشد یا خطای دیتابیس.', 'error');
        }

        $redirect_url = remove_query_arg(['action', 'wf_id', '_wpnonce'], wp_get_referer() ?: admin_url('admin.php?page=sms-workflow-builder'));
        wp_safe_redirect(add_query_arg('settings-updated', 'true', $redirect_url));
        exit;
    }
}