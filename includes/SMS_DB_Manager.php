<?php
// includes/class-sms-db-manager.php
namespace SmsWorkflow;

if ( ! defined( 'ABSPATH' ) ) {
    exit; 
}

class SMS_DB_Manager {

    private static $instance = null;
    public static function get_instance(){
        if(!self::$instance)
        {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * نام جدول لاگ‌ها
     */
    public static function get_log_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'sms_logs';
    }

    /**
     * ایجاد جدول هنگام فعال‌سازی پلاگین
     */
    public static function activate() {
        global $wpdb;
        $table_name = self::get_log_table_name();

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        }

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE " . $table_name . " (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        workflow_name VARCHAR(255) NOT NULL,
        workflow_category VARCHAR(255) NULL, 
        recipient VARCHAR(20) NOT NULL,
        status VARCHAR(50) NOT NULL,
        message_id TEXT NULL, 
        log_data LONGTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, 
        PRIMARY KEY (id)
    ) " . $charset_collate . ";";

        dbDelta( $sql );
    }
    /**
     * ذخیره لاگ جدید
     */
    public static function insert_log($data) {
        global $wpdb;
        $table_name = self::get_log_table_name();

        $defaults = array(
            'workflow_name' => '',
            'workflow_category' => null,
            'recipient' => '',
            'status' => 'scheduled',
            'message_id' => null,
            'log_data' => '',
            'created_at' => current_time( 'mysql' ), // 💡 تضمین ارسال زمان فعلی
        );

        $data = wp_parse_args( $data, $defaults );

        // 🚨 حذف کلیدهای اضافی که در جدول نیستند (مانند template_name یا message_sent)
        // 💡 اگر کلیدهای اضافی را در کدهای قبلی خود حذف نکرده‌اید، اینجا انجام دهید:
        unset($data['template_name'], $data['message_sent'], $data['attempts']);

        // سریالایز کردن داده‌های پیچیده
        if ( is_array( $data['log_data'] ) ) {
            $data['log_data'] = serialize( $data['log_data'] );
        }

        // 💡 تعریف فرمت‌ها با توجه به ۸ ستون موجود (به جز ID)
        $format = [
            '%s', // workflow_name
            '%s', // workflow_category
            '%s', // recipient
            '%s', // status
            '%s', // message_id
            '%s', // log_data
            '%s'  // created_at (استفاده از %s برای DATETIME)
        ];

        return $wpdb->insert( $table_name, $data, $format );
    }

//    public function update_log_status($log_id, $new_status, $message_id = null, $raw_data = null, $increment_attempt = false) {
//        global $wpdb;
//        $table_name = self::get_log_table_name();
//
//        $set = array(
//            'status' => $new_status,
//        );
//        $formats = array('%s');
//
//        // افزایش تلاش در صورت نیاز
//        if ($increment_attempt) {
//            $wpdb->query( $wpdb->prepare(
//                "UPDATE $table_name SET attempts = attempts + 1 WHERE id = %d",
//                $log_id
//            ) );
//        }
//
//        // به‌روزرسانی سایر ستون‌ها
//        if ($message_id !== null) {
//            $set['message_id'] = $message_id;
//            $formats[] = '%s';
//        }
//        if ($raw_data !== null) {
//            $set['log_data'] = is_array($raw_data) ? serialize($raw_data) : $raw_data;
//            $formats[] = '%s';
//        }
//
//        return $wpdb->update(
//            $table_name,
//            $set,
//            array('id' => $log_id),
//            $formats,
//            array('%d')
//        );
//    }

    /**
     * حذف لاگ‌ها بر اساس ID یا شماره تلفن
     */
    public static function delete_logs($ids = [], $recipient = null) {
        global $wpdb;
        $table_name = self::get_log_table_name();
        
        $where = array();
        
        if ( ! empty( $ids ) && is_array( $ids ) ) {
            $id_list = implode( ',', array_map( 'absint', $ids ) );
            $where[] = "id IN ({$id_list})";
        }
        
        if ( ! empty( $recipient ) ) {
            $where[] = $wpdb->prepare( "recipient = %s", $recipient );
        }

        if ( empty( $where ) ) {
            return false;
        }

        $where_sql = implode( ' OR ', $where );
        
        $sql = "DELETE FROM $table_name WHERE $where_sql";
        return $wpdb->query( $sql );
    }

    /**
     * حذف تمام لاگ‌های قدیمی‌تر از تعداد روز مشخص.
     *
     * @param int $days_old تعداد روزهایی که باید به عقب برگردد (مثلاً 60 روز).
     * @return int|bool تعداد ردیف‌های حذف شده یا false در صورت خطا.
     */
    public static function delete_old_logs(int $days_old) {
        global $wpdb;
        $table_name = self::get_log_table_name();

        // محاسبه تاریخ برش (Cutoff Date)
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));

        // 💡 کوئری حذف: ردیف‌هایی که created_at آن‌ها قدیمی‌تر از cutoff_date است.
        $sql = $wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_at < %s",
            $cutoff_date
        );

        $deleted_rows = $wpdb->query($sql);

        // ثبت در لاگ PHP برای ردیابی (اختیاری)
//        error_log("SMS Cleanup Cron: Deleted $deleted_rows logs older than $days_old days.");

        return $deleted_rows;
    }

    /**
     * واکشی آیدی لاگ‌های مرتبط با یک شماره موبایل خاص
     *
     * @param string $recipient_phone شماره موبایل دریافت‌کننده.
     * @return array آرایه‌ای از آیدی‌های لاگ.
     */
    public static function get_log_ids_by_recipient(string $recipient_phone): array {
        global $wpdb;
        $table_name = self::get_log_table_name();

        // 💡 کوئری ساده برای واکشی تمامی آیدی‌های مربوط به شماره موبایل (بدون چک وضعیت)
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM $table_name WHERE recipient = %s",
            sanitize_text_field($recipient_phone)
        ) );

        return array_map('absint', $ids);
    }
}