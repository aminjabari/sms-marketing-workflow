<?php
// includes/class-sms-workflow-runner.php
namespace SmsWorkflow;


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SMS_Workflow_Runner {

    // 🚨 استفاده از پراپرتی استاتیک برای ذخیره لاگ‌ها به جای Constant سراسری (رفع خطای define)
    private static $debug_log = [];
    private static $instance = null;

    public static function get_instance(){
        if(!self::$instance)
        {
            self::$instance = new self();
        }

        return self::$instance;
    }


    /**
     * لیست تگ‌های مجاز (public static برای استفاده در صفحه تنظیمات ادمین)
     */
    public static function get_available_tags() {
        $default_tags = array(
            '[user_name]' => 'نام کاربر',
            '[user_phone]' => 'شماره تلفن کاربر',
            '[site_name]' => 'نام سایت',
        );

        /**
         * فیلتر برای اضافه کردن تگ‌های قالب سفارشی به سیستم WorkFlow
         *
         * @param array $default_tags آرایه‌ای از تگ‌های پیش‌فرض WorkFlow Runner.
         */
        return apply_filters('sms_workflow_available_tags', $default_tags);
    }

    /**
     * تابع کمکی برای ثبت مراحل عیب‌یابی (استفاده از پراپرتی استاتیک)
     */
    private function log_debug_step($message, $data = null) {

        $data_output = 'N/A';
        if ($data !== null) {
            $data_output = is_array($data) || is_object($data) ? print_r($data, true) : $data;
        }

        self::$debug_log[] = [ // 👈 استفاده از پراپرتی استاتیک
                               'time' => current_time('mysql'),
                               'step' => $message,
                               'data' => $data_output
        ];
    }

    /**
     * جایگزینی تگ‌ها با مقادیر واقعی
     * ⚠️ این متد اکنون public است تا با $this-> در run_test_workflow فراخوانی شود و Fatal Error برطرف شود.
     */
    public function process_template_tags($template_text, $data) {
        $replacements = array();

        if ( isset( $data['name'] ) ) {
            $replacements['[user_name]'] = $data['name'];
        }
        if ( isset( $data['phone'] ) ) {
            $replacements['[user_phone]'] = $data['phone'];
        }

        $replacements['[site_name]'] = get_bloginfo( 'name' );

        /**
         * 🚨 فیلتر جدید: تغییر آرایه جایگزینی‌ها ($replacements) قبل از اجرا 🚨
         *
         * این فیلتر به توسعه‌دهندگان اجازه می‌دهد تگ‌های جدیدی اضافه کنند یا مقادیر موجود را بازنویسی کنند.
         *
         * @param array $replacements آرایه‌ی تگ‌ها و مقادیر آن‌ها (مانند ['[tag]' => 'value']).
         * @param array $data آرایه‌ی داده‌های ورودی (شامل name و phone).
         * @param string $template_text متن قالب اصلی پیش از پردازش.
         */
        $replacements = apply_filters('sms_workflow_tag_replacements', $replacements, $data, $template_text);

        return strtr( $template_text, $replacements );
    }


    /**
     * ارسال پیامک از طریق API با قابلیت زمان‌بندی و مدیریت کامل خطا (شامل لاگ عیب‌یابی مستقیم).
     */
    private function send_sms($recipient, $message, $schedule_timestamp = null) {

//        error_log('SMS_SEND_DEBUG: 1. Starting send_sms function.');

        if (!class_exists('SmsWorkflow\Workflow_Sms')) {
            return array('success' => false, 'error' => 'خطا: کلاس Workflow_Sms برای ارسال یافت نشد.', 'response' => null);
        }

        try {
            $sms_api = Workflow_Sms::get_instance();
//            error_log('SMS_SEND_DEBUG: 2. Workflow_Sms instance obtained.');

            if ($sms_api->sms === null) {
                error_log('SMS_SEND_DEBUG: ERROR 2.1: API Key is NULL or invalid in Workflow_Sms constructor.');
                return array(
                    'success' => false,
                    'error' => 'تنظیمات API ناقص است. لطفاً کلید API و شماره ارسال‌کننده را بررسی کنید.',
                    'message' => 'کلید API یا شماره ارسال‌کننده در تنظیمات پلاگین وارد نشده است.',
                    'response' => null
                );
            }

            // 3. فراخوانی اصلی API
//            error_log("SMS_SEND_DEBUG: 3. Calling Kavenegar API. Recipient: {$recipient}");
            $response = $sms_api->send( $recipient, $message, $schedule_timestamp );

            // 4. لاگ کردن پاسخ خام API بلافاصله پس از دریافت
//            error_log('SMS_SEND_DEBUG: 4. Raw response received: ' . print_r($response, true));

            // 5. بررسی پاسخ موفقیت‌آمیز
            if ($response === false) {
                return array('success' => false, 'error' => 'خطای داخلی: فراخوانی send ناموفق بود.', 'response' => null);
            }

            // 6. منطق بررسی ساختار (همانند قبل)
            if (!is_array($response) || empty($response)) {
                goto structure_error;
            }

            $msg = $response[0];

            if (is_object($msg) && property_exists($msg, 'status') && property_exists($msg, 'messageid')) {

                if ($msg->status == 1 || $msg->status == 2 || $msg->status == 5) {
//                    error_log("SMS_SEND_DEBUG: SUCCESS! Message ID: {$msg->messageid}, Status: {$msg->status}");
                    return array(
                        'success' => true,
                        'message_id' => $msg->messageid,
                        'status_text' => $msg->statustext,
                        'response' => $response
                    );
                }

                // 🛑 لاگ کردن پاسخ خام برای وضعیت‌های غیرموفق (Status 5)
//                error_log("SMS_SEND_DEBUG: FAILURE 4.1: API accepted but final status is not success (Status: {$msg->status}). Raw Response: " . print_r($response, true));
                return array(
                    'success' => false,
                    'error' => 'درخواست پذیرفته شد اما وضعیت نهایی 1 یا 2 یا 5 نیست.',
                    'message' => 'وضعیت دریافتی: ' . $msg->status . ' - ' . (property_exists($msg, 'statustext') ? $msg->statustext : ''),
                    'response' => $response
                );
            }

            structure_error:
            error_log('SMS_SEND_DEBUG: FATAL 4.2: Invalid API response structure.');
            return array(
                'success' => false,
                'error' => 'پاسخ API نامعتبر (ساختار غیرمنتظره).',
                'response' => var_export($response, true)
            );

        } catch (\Kavenegar\Exceptions\ApiException $e) {
            $error_msg = 'خطای API: ' . $e->getMessage();
            error_log("SEND_SMS API EXCEPTION: " . $error_msg);
            return array('success' => false, 'error' => $error_msg, 'response' => null);

        } catch (\Kavenegar\Exceptions\HttpException $e) {
            $error_msg = 'خطای ارتباطی: ' . $e->getMessage();
            error_log("SEND_SMS HTTP EXCEPTION: " . $error_msg);
            return array('success' => false, 'error' => $error_msg, 'response' => null);
        }
    }


    /**
     * ایجاد تایم‌استمپ برای ارسال برنامه‌ریزی شده
     * * این نسخه، افست Timezone (3:30 ساعت) را برای جبران تفسیر API کاوه‌نگار اضافه می‌کند.
     * @param int $days_after تعداد روزهای بعد از الان (0 برای ارسال آنی)
     * @param string $time_24h ساعت ارسال به فرمت H:i (یا 'now' برای ارسال آنی)
     * @return int Unix Timestamp
     */
    private function get_future_timestamp($days_after = 0, $time_24h = null) {

        $timezone = new \DateTimeZone('Asia/Tehran');
        $this->log_debug_step('TIMEZONE: Set to Asia/Tehran.');

        $date_object = null;

        // 1. تنظیم ساعت هدف در روز پایه
        // 🚨 اصلاح برای مدیریت 'now' و جلوگیری از خطای Undefined Array Key 1
        if ( $time_24h === 'now' || empty($time_24h) ) {
            // حالت ارسال آنی
            $date_object = new \DateTime('now', $timezone);
            $this->log_debug_step('DEBUG 1: Time set to NOW (instant send).', $date_object->format('Y-m-d H:i:s T'));

        } elseif ( strpos($time_24h, ':') !== false ) {
            // حالت زمان‌بندی دقیق (مانند '10:00')
            $date_object = new \DateTime('today', $timezone);

            $parts = explode(':', $time_24h);

            // بررسی صحت تجزیه (وجود ساعت و دقیقه)
            if (count($parts) < 2) {
                // اگر فرمت H:i نباشد، از زمان فعلی استفاده می‌کنیم
                $date_object = new \DateTime('now', $timezone);
                $this->log_debug_step('ERROR: Invalid time format. Using NOW.', $time_24h);
            } else {
                list($hour, $minute) = $parts;
                $date_object->setTime((int)$hour, (int)$minute);
                $this->log_debug_step('DEBUG 2: Time set to target ('. $time_24h .').', $date_object->format('Y-m-d H:i:s T'));
            }

        } else {
            // حالت پیش‌فرض یا نامعتبر دیگر (بهتر است به Now برگردد)
            $date_object = new \DateTime('now', $timezone);
            $this->log_debug_step('ERROR: Unknown time string. Using NOW.', $time_24h);
        }

        // 2. اضافه کردن روزهای آینده
        if ($days_after > 0) {
            $date_object->modify("+$days_after days");
            $this->log_debug_step('DEBUG 4: Date after adding days.', $date_object->format('Y-m-d H:i:s T'));
        }

        // 3. بررسی حالت ارسال آنی
        if ($days_after === 0 && $date_object->getTimestamp() < time()) {
            // اگر زمان هدف امروز در گذشته باشد، به فردا موکول می‌شود (مگر اینکه time_24h صراحتا NOW باشد)
            $date_object->modify('+1 day');
            $this->log_debug_step('DEBUG 5: Target time was in the past, moved to tomorrow.', $date_object->format('Y-m-d H:i:s T'));
        }

        // 4. 🚨 اعمال افست جبرانی برای API کاوه‌نگار (+3:30 ساعت)
        // این جبران، زمان مورد نیاز API را برای درست نشان دادن ساعت تهران فراهم می‌کند.
        $date_object->modify('+3 hours +30 minutes');
        $this->log_debug_step('FINAL ADJUSTMENT: Added 3h 30m to compensate API Timezone.', $date_object->format('Y-m-d H:i:s T'));

        // تبدیل نهایی به Unix Timestamp
        $final_timestamp = $date_object->getTimestamp();

        $this->log_debug_step('FINAL TIMESTAMP (Sent to API)', $final_timestamp);

        return $final_timestamp;
    }

    /**
     * تابع تست برای اجرای دستی ورکفلو و ثبت آن در صف (با نمایش گام به گام عیب‌یابی)
     */
// includes/class-sms-workflow-runner.php - متد run_test_workflow

    public function run_test_workflow() {
        echo "<h3>شروع تست زمان‌بندی (Dispatch) ورکفلو:</h3>";
        global $wpdb;

        $this->log_debug_step('START: Initializing Test WorkFlow Execution.');

        // 1. 🛑 تست سلامت دیتابیس (Log اولیه)
        $db_check_result = \SmsWorkflow\SMS_DB_Manager::insert_log( array(
            'workflow_name' => 'تست اولیه لاگ',
            'workflow_category' => 'Debug',
            'recipient' => '0000',
            'status' => 'Debugged',
            'message_id' => null,
            'log_data' => ['Initial check'],
        ) );

        if ($db_check_result === false || $db_check_result === 0) {
            $this->log_debug_step('FATAL ERROR: Failed to insert initial DB log. Check WPDB connection.', $wpdb->last_error);
            echo '<div class="notice notice-error"><p>❌ **خطای بحرانی اولیه در WPDB!** لاگ اولیه ذخیره نشد.</p></div>';
            if ($wpdb->last_error) {
                echo '<p>خطای WPDB (Initial Check): ' . esc_html($wpdb->last_error) . '</p>';
            }
            $this->print_debug_log_table(); // 🚨 چاپ لاگ قبل از خروج
            return;
        }
        $this->log_debug_step('SUCCESS: Initial DB log inserted. Proceeding to fetch workflow data.', "Log ID: {$db_check_result}");


        // 2. آماده‌سازی داده‌های پایه
        $test_phone = '09129176722';
        $user_data = array(
            'name' => 'کاربر تست صف',
            'phone' => $test_phone,
        );
        $this->log_debug_step('INFO: Test User Data Defined.', $user_data);


        // 3. 🚨 منطق واکشی اولین WorkFlow فعال (ادغام شده در اینجا) 🚨
        $table_name = $wpdb->prefix . 'sms_workflows';

        $workflow = $wpdb->get_row(
            $wpdb->prepare("SELECT name, category, workflow_data FROM {$table_name} WHERE is_active = %d ORDER BY id ASC LIMIT 1", 1),
            ARRAY_A
        );

        $workflow_data = false;

        if (!empty($workflow) && isset($workflow['workflow_data'])) {
            $data = maybe_unserialize($workflow['workflow_data']);

            if (is_array($data) && isset($data['steps']) && is_array($data['steps'])) {
                $workflow_data = [
                    'name' => $workflow['name'],
                    'category' => $workflow['category'],
                    'steps' => $data['steps'],
                ];
            }
        }

        // 4. بررسی نتیجه واکشی
        if ($workflow_data === false) {
            $this->log_debug_step('FAILURE: No active and valid WorkFlow found in DB.', $workflow);
            echo '<div class="notice notice-error"><p>❌ خطا: هیچ WorkFlow فعال یا معتبری در دیتابیس یافت نشد. لطفاً ابتدا یک WorkFlow فعال بسازید.</p></div>';
            $this->print_debug_log_table(); // 🚨 چاپ لاگ قبل از خروج
            return;
        }

        $this->log_debug_step('SUCCESS: WorkFlow data fetched successfully.', [
            'name' => $workflow_data['name'],
            'steps_count' => count($workflow_data['steps'] ?? [])
        ]);


        // 💡 استخراج مراحل، نام و دسته‌بندی از ساختار برگشتی
        $workflow_steps = $workflow_data['steps'] ?? [];
        $final_name = $workflow_data['name'];
        $final_category = $workflow_data['category'] ?? 'General';

        if (empty($workflow_steps)) {
            $this->log_debug_step('ERROR: WorkFlow steps array is empty.', $workflow_data);
            echo '<div class="notice notice-error"><p>❌ خطا: WorkFlow یافت شده (' . esc_html($final_name) . ') حاوی هیچ مرحله‌ای نیست.</p></div>';
            $this->print_debug_log_table(); // 🚨 چاپ لاگ قبل از خروج
            return;
        }

        echo "<p>تلاش برای ثبت WorkFlow با نام <strong>" . esc_html($final_name) . "</strong> (" . count($workflow_steps) . " مرحله) در صف...</p>";

        // 5. 🚀 فراخوانی متد اصلی WorkFlow برای ثبت در صف (Dispatch)
        $this->log_debug_step('DISPATCH: Calling execute_full_workflow to register steps.', $final_name);

        $sms_result = $this->execute_full_workflow(
            $workflow_steps,
            $final_name,
            $final_category,
            $user_data,
            $test_phone
        );

        // 6. نمایش نتیجه ثبت صف
        if ($sms_result['success']) {
            $log_id = $sms_result['log_id'] ?? 'N/A';
            $total_steps = count($workflow_steps);

            $this->log_debug_step('FINAL SUCCESS: WorkFlow registration complete.', "Log ID: {$log_id}");

            echo '<div class="notice notice-success"><p>✅ **ثبت WorkFlow موفقیت‌آمیز!** ورکفلو با <strong>' . esc_html($total_steps) . ' مرحله</strong> در دیتابیس (لاگ ID: ' . esc_html($log_id) . ') ثبت شد و وضعیت آن **SCHEDULED** است.</p></div>';
            echo '<p>⚠️ **نکته:** پیامک‌های واقعی در هر ۱۰ دقیقه توسط WP-Cron ارسال خواهند شد.</p>';
        } else {
            $error = $sms_result['error'] ?? 'خطای ناشناخته در زمان ثبت صف.';
            $this->log_debug_step('FINAL FAILURE: Dispatch failed.', $error);

            echo '<div class="notice notice-error"><p>❌ **خطا در ثبت WorkFlow!** خطا: ' . esc_html($error) . '</p></div>';
        }

        // 7. نمایش گزارش گام به گام عیب‌یابی (پایان موفق/ناموفق)
        $this->print_debug_log_table();
    }

//    public function run_test_workflow() {
//        external_sms_test_workflow();
////        $this->example_user_registration_workflow(1);
//
//    }


    /**
     * مثال اصلاح شده از ورکفلو ثبت‌نام کاربر با استفاده از لایه جدید مدیریت ورکفلوها
     */
    public function example_user_registration_workflow($user_id) {
        // 1. دریافت اطلاعات کاربر و شماره موبایل
        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            return;
        }

        $user_phone = get_user_meta( $user_id, 'billing_phone', true );
        if ( empty( $user_phone ) ) {
            error_log("Workflow Error: User $user_id does not have a billing phone.");
            return;
        }

        // 2. آماده‌سازی داده‌های جایگزینی برای تگ‌ها (مطابق مستندات متد جدید)
        $user_data = array(
            'name'  => $user->display_name,
            'phone' => $user_phone,
        );

        // 3. فراخوانی متد لایه بالاتر برای افزودن کاربر به ورکفلو
        // این متد به صورت خودکار فعال بودن ورکفلو و عدم تکراری بودن کاربر (در 30 روز اخیر) را چک می‌کند.
        $result = $this->add_user_to_workflow_by_name(
            'ورکفلو ثبت نام جدید', // نام دقیق ورکفلو در Builder
            $user_data,            // اطلاعات برای تگ‌ها
            $user_phone,           // شماره موبایل مقصد
            [
                'days_back' => 30, // بازه زمانی اختیاری برای چک کردن سابقه
                'skip_check' => false // اطمینان از اینکه چک کردن سابقه انجام شود
            ]
        );

        // 4. مدیریت نتیجه (اختیاری)
        if ( ! $result['success'] ) {
            error_log("Workflow Dispatch Failed: " . $result['error']);
        }
    }

    public function execute_full_workflow(array $workflow_steps, string $workflow_name, string $workflow_category, array $user_data, string $recipient_phone = null) {

        $full_workflow_log = [];
        $message_ids_list = []; // 💡 اینجا خالی باقی می‌ماند
        $overall_success = true;
        $all_templates = get_option( 'sms_templates', [] );

        // ... (کدهای واکشی شماره تلفن) ...

        if (empty($recipient_phone)) {
            return ['success' => false, 'error' => 'شماره تلفن گیرنده یافت نشد.'];
        }

        // 2. اجرای حلقه برای هر مرحله از ورکفلو
        foreach ($workflow_steps as $step) {

            $template = null;

            // 🚨 اصلاح: پیدا کردن ایندکس تمپلیت بر اساس نام
            $template_index = $this->get_template_index_by_name($all_templates, $step['template_name']);

            if ($template_index === null) {
                // اگر تمپلیت با نام مشخص شده یافت نشد، این مرحله Skipped می‌شود.
                $step_status = 'Skipped';
                $step_message = 'Template not found by name: ' . $step['template_name'];
                $overall_success = false;
            } else {
                // 💡 استفاده از ایندکس صحیح
                $template = $all_templates[$template_index];

                // 🚨 1. محاسبه زمان اجرا (که زمان واقعی ارسال نیست، بلکه زمان هدف است)
                $schedule_timestamp = $this->get_future_timestamp( $step['days_after'], $step['send_time'] );
                $final_message = $this->process_template_tags( $template['text'], $user_data );

                // 🛑 حذف فراخوانی $this->send_sms()

                // 2. جمع‌آوری نتیجه برنامه ریزی
                $step_status = 'Scheduled'; // 💡 وضعیت اولیه را 'Scheduled' می‌گذاریم
                $step_message = $final_message;
            }

            // 3. جمع‌آوری نتیجه مرحله در آرایه اصلی (log_data)
            $full_workflow_log[] = [
                'step_description' => $step['description'] ?? 'Step ' . $template_index,
                'template_name' => $template['name'] ?? 'N/A',
                'message_sent' => $step_message,
                'status' => $step_status,
                'scheduled_time' => $schedule_timestamp, // 💡 ذخیره زمان هدف
                // ❌ message_id_step و sms_result_raw حذف می‌شوند
            ];
        }

        // 4. ذخیره‌سازی نهایی در یک خط واحد
        $final_log_status = 'Scheduled'; // 💡 وضعیت کلی ورکفلو را 'Scheduled' قرار می‌دهیم

        SMS_DB_Manager::insert_log( array(
            'workflow_name' => $workflow_name,
            'workflow_category' => $workflow_category,
            'recipient' => $recipient_phone,
            'status' => $final_log_status,
            'message_id' => null, // 💡 Message ID هنوز موجود نیست
            'log_data' => $full_workflow_log,
            // 💡 تنظیم created_at برای زمان اجرای WorkFlow فعلی (اختیاری)
            // 'created_at' => current_time('mysql'),
        ) );

        return ['success' => true, 'log_entries' => $full_workflow_log];
    }


    /**
     * بررسی می‌کند که آیا کاربر اخیراً در یک ورکفلو/دسته‌بندی خاص شرکت داشته است.
     * 💡 فقط وضعیت‌های موفق ('Completed' یا 'Sent' یا 'ثبت شد') را در نظر می‌گیرد.
     *
     * @param string $recipient شماره موبایل کاربر.
     * @param string|null $workflow_name نام دقیق ورکفلو (اختیاری).
     * @param string|null $workflow_category دسته‌بندی ورکفلو (اختیاری).
     * @param int $days_back تعداد روزهایی که باید به عقب برگردد (پیش‌فرض ۳۰ روز).
     * @return bool اگر لاگی موفق در بازه زمانی یافت شود، true برمی‌گرداند.
     */
    public function has_user_recent_workflow(string $recipient, string $workflow_name = null, string $workflow_category = null, int $days_back = 30): bool {
        global $wpdb;
        $table_name = SMS_DB_Manager::get_log_table_name();

        $where = $wpdb->prepare("recipient = %s", $recipient);

        // شرط زمان (مثلاً ۳۰ روز گذشته)
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_back} days"));
        $where .= $wpdb->prepare(" AND created_at >= %s", $cutoff_date);

        // 🚨 شرط وضعیت‌های موفقیت‌آمیز 🚨
        // ما باید مطمئن شویم که فقط لاگ‌های موفق (ورکفلو کامل شده یا پیامک ارسال شده) را بررسی کنیم.
        $success_statuses = ['Completed', 'Sent','Scheduled', 'ثبت شد', 'زمانبندی شده', 'در صف ارسال'];
        $status_list = "'" . implode("','", array_map('esc_sql', $success_statuses)) . "'";
        $where .= " AND status IN ({$status_list})";

        // شرط نام ورکفلو
        if ($workflow_name) {
            $where .= $wpdb->prepare(" AND workflow_name = %s", $workflow_name);
        }

        // شرط دسته‌بندی
        if ($workflow_category) {
            $where .= $wpdb->prepare(" AND workflow_category = %s", $workflow_category);
        }

        // بررسی وجود حداقل یک ردیف
        $query = "SELECT COUNT(id) FROM $table_name WHERE {$where}";
        $count = $wpdb->get_var($query);

        return (int)$count > 0;
    }


    /**
     * 💡 اجرای فرآیند لغو WorkFlow برای یک شماره موبایل خاص.
     * این متد عمل واکشی (Fetch) و لغو/حذف (Cancel/Delete) را ادغام می‌کند.
     *
     * @param string $recipient_phone شماره موبایل کاربر مورد نظر.
     * @return array آرایه‌ای شامل وضعیت عملیات.
     */
    public static function execute_cancellation_by_recipient(string $recipient_phone): array {
        global $wpdb;
        $table_name = SMS_DB_Manager::get_log_table_name();

        // 1. اعتبارسنجی اولیه
        if (empty($recipient_phone)) {
            return ['success' => false, 'message' => 'شماره موبایل نامعتبر است.'];
        }

        // 2. واکشی آیدی تمام لاگ‌های مرتبط با این شماره
        // (استفاده از متد DB Manager برای واکشی IDs)
        $log_ids = SMS_DB_Manager::get_log_ids_by_recipient($recipient_phone);

        if (empty($log_ids)) {
            return ['success' => true, 'message' => "هیچ WorkFlowی برای شماره {$recipient_phone} یافت نشد.", 'deleted_logs_count' => 0];
        }

        // 3. اجرای فرآیند لغو API و حذف نهایی لاگ‌ها
        // ما باید یک نمونه از Runner داشته باشیم تا متد غیر استاتیک (یا منطق لغو) را فراخوانی کنیم
        $runner = new self();

        // 💡 اجرای منطق لغو و حذف برای آیدی‌های واکشی شده
        $cancellation_results = $runner->process_cancellation_and_deletion($log_ids);

        // 4. فرمت‌بندی نتیجه برای نمایش
        if (isset($cancellation_results['errors']) && !empty($cancellation_results['errors'])) {
            return [
                'success' => false,
                'message' => "عملیات لغو کامل نبود. تعداد حذف DB: {$cancellation_results['deleted']}. خطا: " . implode('; ', $cancellation_results['errors']),
                'deleted_logs_count' => $cancellation_results['deleted']
            ];
        }

        return [
            'success' => true,
            'deleted_logs_count' => $cancellation_results['deleted'],
            'message' => "حذف و لغو کامل WorkFlow برای کاربر {$recipient_phone} با موفقیت انجام شد."
        ];
    }

    /**
     * 💡 منطق هسته لغو و حذف (انتقال یافته از execute_cancellation_by_log_ids)
     */
    private function process_cancellation_and_deletion(array $log_ids): array {
        global $wpdb;
        $table_name = SMS_DB_Manager::get_log_table_name();

        // 1. بررسی کلاس API
        if (!class_exists('SmsWorkflow\Workflow_Sms')) {
            return ['count' => 0, 'errors' => ['کلاس Sms برای عملیات لغو یافت نشد.']];
        }

        $sms_api = Workflow_Sms::get_instance();
        $cancelled_count = 0;
        $errors = [];
        $total_deleted = 0;

        // 2. واکشی message_id و Status از لاگ‌ها
        $id_list = implode( ',', array_map( 'absint', $log_ids ) );
        $logs = $wpdb->get_results(
            "SELECT id, message_id, status FROM $table_name WHERE id IN ({$id_list})",
            ARRAY_A
        );

        if (empty($logs)) {
            return ['count' => 0, 'errors' => ['هیچ WorkFlowی برای پردازش یافت نشد.']];
        }

        foreach ($logs as $log) {
            $log_id = $log['id'];
            $message_ids_string = $log['message_id'];
            $current_status = $log['status'];

            $delete_success = false;

            // 🛑 A. منطق حذف فوری (برای جلوگیری از اجرای کران جاب) 🛑
            if ( $current_status === 'Scheduled' || $current_status === 'زمانبندی شده' || $current_status === 'در صف ارسال' ) {

                // 1. حذف مستقیم از DB
                $delete_success = $wpdb->delete($table_name, array('id' => $log_id), array('%d'));

                if ($delete_success === false) {
                    $errors[] = "ID {$log_id}: خطای WPDB حین حذف. Error: {$wpdb->last_error}";
                } else {
                    $total_deleted++;
                    error_log("CLEANUP: Log ID {$log_id} deleted instantly to prevent cron job.");
                }
                continue; // برو به لاگ بعدی
            }

            // 🛑 B. منطق لغو API و حذف (برای WorkFlowهای Completed) 🛑
            if ( $current_status === 'Completed' || $current_status === 'Sent' || $current_status === 'ثبت شد' ) {

                if (empty($message_ids_string)) {
                    $errors[] = "ID {$log_id}: وضعیت ارسال شده اما Message ID وجود ندارد.";
                    goto final_delete; // اگر آیدی ندارد، فقط لاگ را حذف کن
                }

                try {
                    // 1. اجرای لغو API
                    $cancel_response = $sms_api->cancel($message_ids_string);

                    if (is_array($cancel_response) && !empty($cancel_response)) {
                        $cancelled_count += count($cancel_response);
                        // 💡 فقط برای ردیابی تغییر وضعیت را ثبت می‌کنیم، اما حذف DB در ادامه است.
                        $wpdb->update($table_name, array( 'status' => 'Cancelled' ), array( 'id' => $log_id ));
                    } else {
                        $errors[] = "ID {$log_id}: لغو API شکست خورد یا پاسخ نامعتبر بود.";
                    }

                } catch (\Kavenegar\Exceptions\ApiException $e) {
                    $errors[] = "ID {$log_id}: خطای API حین لغو: " . $e->getMessage();
                } catch (\Kavenegar\Exceptions\HttpException $e) {
                    $errors[] = "ID {$log_id}: خطای ارتباطی حین لغو: " . $e->getMessage();
                }
            }

            // 4. 🛑 حذف نهایی لاگ از دیتابیس (پس از تلاش برای لغو)
            final_delete:
            $delete_success = $wpdb->delete($table_name, array('id' => $log_id), array('%d'));

            if ($delete_success === false) {
                $db_error = $wpdb->last_error ? $wpdb->last_error : 'Unknown DB Error (Operation failed).';
                $errors[] = "ID {$log_id}: خطای WPDB حین حذف. Error: {$db_error}";
            } else {
                $total_deleted++;
            }
        }

        return [
            'count' => $cancelled_count,
            'deleted' => $total_deleted,
            'errors' => $errors
        ];
    }

    /**
     * 💡 متد اصلی که توسط WP-Cron فراخوانی می‌شود.
     * جدول لاگ‌ها را بررسی و ردیف‌های 'scheduled' را پردازش می‌کند.
     */
    public function process_pending_jobs() {

//        error_log( 'CRON START: process_pending_jobs triggered for dispatch.' );

        global $wpdb;
        $table_name = SMS_DB_Manager::get_log_table_name();

        // 1. واکشی WorkFlowهایی که آماده ارسال نهایی هستند (حذف چک زمان)

        // 🚨 Status فعال WorkFlow را 'Scheduled' در نظر می‌گیریم.
        $target_status = 'Scheduled';

        $sql = $wpdb->prepare(
            "SELECT id, recipient, log_data FROM {$table_name} 
         WHERE status = %s
         ORDER BY created_at ASC LIMIT 50",
            $target_status
        );

        $pending_logs_parent = $wpdb->get_results( $sql, ARRAY_A );

//        error_log( 'CRON DEBUG: Found ' . count($pending_logs_parent) . ' full WorkFlows ready for dispatch.' );

        // 2. اجرای ارسال WorkFlow برای هر ردیف
        if (!empty($pending_logs_parent)) {

            $sms_runner = SMS_Workflow_Runner::get_instance();

            foreach ($pending_logs_parent as $log) {

                $log_id = $log['id'];
                $full_workflow_log = unserialize($log['log_data']);
                $new_message_ids = [];
                $overall_step_success = true;

                if ( !is_array($full_workflow_log) || empty($full_workflow_log) ) {
                    $wpdb->update($table_name, ['status' => 'Data Corrupt'], ['id' => $log_id]);
                    continue;
                }

                // 3. 🚨 اجرای ارسال پیامک برای تک تک مراحل (بدون چک زمان)
                foreach ($full_workflow_log as $step_index => $step) {

                    // 💡 استفاده از زمان هدف ذخیره شده (حتی اگر در آینده باشد)
                    $target_timestamp = $step['scheduled_time'];
                    $recipient = $log['recipient'];
                    $final_message = $step['message_sent'];

                    // 🛑 ارسال واقعی پیامک با تایم‌استمپ آینده
                    $sms_result = $sms_runner->send_sms( $recipient, $final_message, $target_timestamp );

                    if ($sms_result['success']) {
                        $message_id = $sms_result['message_id'];
                        $new_message_ids[] = $message_id;

                        // 💡 به‌روزرسانی وضعیت مرحله در ساختار log_data به وضعیت API
                        $full_workflow_log[$step_index]['status'] = $sms_result['status_text'] ?? 'ثبت شد';
                        $full_workflow_log[$step_index]['message_id_step'] = $message_id;
                    } else {
                        $full_workflow_log[$step_index]['status'] = 'Failed';
                        $overall_step_success = false;

                        $error_msg = $sms_result['error'] ?? 'API call failed without explicit error.';
//                        error_log("CRON API FAILURE for Log ID {$log_id}: " . $error_msg);
                    }
                }

                // 4. به‌روزرسانی لاگ اصلی: Status به Completed تغییر می‌کند
                $all_message_ids_string = implode(',', $new_message_ids);

                $new_status = $overall_step_success ? 'Completed' : 'Partial Failure';

                $wpdb->update(
                    $table_name,
                    [
                        'status' => $new_status,
                        'message_id' => $all_message_ids_string, // 👈 ذخیره آیدی‌های جدید
                        'log_data' => serialize($full_workflow_log), // 👈 ذخیره مجدد ساختار کامل
                    ],
                    ['id' => $log_id]
                );
//                error_log("CRON END: Log ID {$log_id} dispatched. Final Status: {$new_status}.");
            }
        }

//        error_log( 'CRON END: process_pending_jobs finished successfully.' );
    }

    /**
     * وظیفه کران جاب: اجرای پاکسازی دوره‌ای لاگ‌های دیتابیس.
     * این تابع به هوک sms_cleanup_logs_daily متصل است.
     */
    public function process_cleanup_task() {

        $days_to_keep = 60; // 💡 نگهداری لاگ‌های ۶۰ روز اخیر

        // فراخوانی تابع پاکسازی از DB Manager
        $deleted_count = SMS_DB_Manager::delete_old_logs($days_to_keep);

        // ثبت نهایی در لاگ PHP (این خط در تابع DB Manager هم وجود دارد اما اینجا برای تأیید می‌ماند)
//        error_log("SMS CLEANUP: Successfully deleted {$deleted_count} log entries older than {$days_to_keep} days.");

        // 💡 نکته: این تابع نیازی به بازگرداندن مقدار خاصی ندارد.
    }


    /**
     * پیدا کردن ایندکس تمپلیت در آرایه تنظیمات بر اساس نام آن.
     * * @param array $templates آرایه‌ی تمپلیت‌های ذخیره‌شده (از wp_options).
     * @param string $name نام تمپلیت مورد نظر.
     * @return int|null ایندکس تمپلیت یا null در صورت عدم وجود.
     */
    private function get_template_index_by_name(array $templates, string $name): ?int {
        foreach ($templates as $index => $template) {
            if (isset($template['name']) && $template['name'] === $name) {
                // 💡 در صورت وجود نام‌های تکراری، همیشه اولین مورد را برمی‌گرداند (First Match)
                return $index;
            }
        }
        return null;
    }



    /**
     * چاپ جدول HTML گزارش‌های عیب‌یابی (Debug Log) بر اساس self::$debug_log
     */
    private function print_debug_log_table() {
        if (empty(self::$debug_log)) {
            return;
        }

        echo '<h3>گزارش گام به گام عیب‌یابی (زمان‌بندی):</h3>';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr><th>زمان</th><th>گام</th><th>داده</th></tr></thead><tbody>';

        foreach (self::$debug_log as $log) {
            echo '<tr>';
            echo '<td>' . esc_html($log['time']) . '</td>';
            echo '<td>' . esc_html($log['step']) . '</td>';
            echo '<td><pre>' . esc_html($log['data']) . '</pre></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // 💡 پاکسازی آرایه لاگ پس از نمایش
        self::$debug_log = [];
    }

    /**
     * افزودن کاربر به یک ورکفلو بر اساس نام (تعریف شده در Builder)
     * * @param string $workflow_name نام دقیق ورکفلو در WorkFlow Builder.
     * @param array  $user_data      اطلاعات کاربر برای تگ‌ها (مانند ['name' => '..', 'phone' => '..']).
     * @param string $recipient_phone شماره موبایل مقصد.
     * @param array  $options {
     * تنظیمات اختیاری برای کنترل رفتار متد:
     * @type bool $skip_check   اگر true باشد، بررسی تکراری بودن کاربر انجام نمی‌شود (پیش‌فرض: false).
     * @type int  $days_back    تعداد روزهای بازگشت به عقب برای چک کردن سابقه (پیش‌فرض: 30).
     * }
     * * @return array وضعیت اجرای عملیات.
     */
    public function add_user_to_workflow_by_name(string $workflow_name, array $user_data, string $recipient_phone, array $options = []) {
        global $wpdb;
        $table_workflows = $wpdb->prefix . 'sms_workflows';

        // 1. استخراج تنظیمات اختیاری با مقادیر پیش‌فرض
        $skip_check = isset($options['skip_check']) ? (bool) $options['skip_check'] : false;
        $days_back  = isset($options['days_back']) ? (int) $options['days_back'] : 30;

        // 2. واکشی اطلاعات ورکفلو و بررسی وضعیت فعال بودن
        $workflow = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT name, category, workflow_data, is_active FROM {$table_workflows} WHERE name = %s",
                $workflow_name
            ),
            ARRAY_A
        );

        if ( ! $workflow ) {
            error_log("SMS Workflow Error: Workflow '$workflow_name' not found.");
            return ['success' => false, 'error' => 'ورکفلو یافت نشد.'];
        }

        if ( (int) $workflow['is_active'] !== 1 ) {
            return ['success' => false, 'error' => 'ورکفلو در حال حاضر غیرفعال است.'];
        }

        // 3. بررسی سابقه کاربر (مگر اینکه skip_check فعال باشد)
        if ( ! $skip_check ) {
            $already_exists = $this->has_user_recent_workflow(
                $recipient_phone,
                $workflow['name'],
                $workflow['category'],
                $days_back
            );

            if ( $already_exists ) {
                return [
                    'success' => false,
                    'error'   => "کاربر قبلاً در بازه $days_back روزه در این ورکفلو عضو شده است."
                ];
            }
        }

        // 4. استخراج مراحل (Steps)
        $data = maybe_unserialize( $workflow['workflow_data'] );
        $steps = ( is_array( $data ) && isset( $data['steps'] ) ) ? $data['steps'] : [];

        if ( empty( $steps ) ) {
            return ['success' => false, 'error' => 'این ورکفلو هیچ مرحله‌ای برای اجرا ندارد.'];
        }

        // 5. ثبت در دیتابیس لاگ‌ها
        return $this->execute_full_workflow(
            $steps,
            $workflow['name'],
            $workflow['category'] ?? 'General',
            $user_data,
            $recipient_phone
        );
    }


}