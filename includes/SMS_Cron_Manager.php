<?php
// includes/class-sms-cron-manager.php
namespace SmsWorkflow;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * کلاس مدیریت زمان‌بندی WP-Cron و ثبت هوک‌های پردازش صف.
 */
class SMS_Cron_Manager {

    private static $instance = null;
    const CRON_HOOK = 'sms_queue_checker_hook';
    const CRON_INTERVAL = 'ten_minutes';
    const CLEANUP_HOOK = 'sms_cleanup_logs_daily'; // 👈 هوک جدید
    const CLEANUP_INTERVAL = 'monthly'; // 👈 فاصله زمانی (مثلاً هر روز)

    // پیاده‌سازی الگوی Singleton
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // 1. ثبت فواصل زمانی سفارشی
        add_filter( 'cron_schedules', array( $this, 'add_custom_cron_schedules' ) );




        // 2. اتصال تابع پردازشگر به هوک کرون
        // از نمونه سینگل‌تون Runner استفاده می‌کنیم تا متد نان‌استاتیک به‌درستی فراخوانی شود.
        $runner = SMS_Workflow_Runner::get_instance();
        if ( $runner ) {
            add_action( self::CRON_HOOK, array( $runner, 'process_pending_jobs' ) );

            // 💡 اتصال تابع پاکسازی جدید
            add_action( self::CLEANUP_HOOK, array( $runner, 'process_cleanup_task' ) );
        } else {
            error_log( 'SMS Cron Manager: runner instance not available.' );
        }
    }

    /**
     * ثبت فاصله زمانی 'ten_minutes'
     */
    public function add_custom_cron_schedules( $schedules ) {
        if ( ! isset( $schedules[self::CRON_INTERVAL] ) ) {
            $schedules[self::CRON_INTERVAL] = array(
                'interval' => 600, // 10 * 60 seconds
                'display'  => __( 'Every 10 Minutes', 'sms-workflow' )
            );
        }

        if ( ! isset( $schedules[self::CLEANUP_INTERVAL] ) ) {
            $monthly_seconds = 30 * DAY_IN_SECONDS;
            $schedules[self::CLEANUP_INTERVAL] = array(
                'interval' => $monthly_seconds, // 10 * 60 seconds
                'display'  => __( 'Monthly', 'sms-workflow' )
            );
        }
        return $schedules;
    }

    /**
     * 💡 متد استاتیک: ثبت رویداد کرون در زمان فعال‌سازی افزونه.
     */
    public static function schedule_cron_jobs() {
        $interval = self::CRON_INTERVAL;
        $hook_name = self::CRON_HOOK;

        if ( ! wp_next_scheduled( $hook_name ) ) {
            // ثبت رویداد کرون برای اجرای اولین بار بلافاصله، و سپس تکرار در هر 'ten_minutes'
            wp_schedule_event( time(), $interval, $hook_name );
        }

        // 🚨 زمان‌بندی کران جاب پاکسازی
        if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
            wp_schedule_event( time(), self::CLEANUP_INTERVAL, self::CLEANUP_HOOK );
        }
    }



    /**
     * 💡 متد استاتیک: حذف رویداد کران در زمان غیرفعال‌سازی افزونه.
     */
    public static function clear_cron_jobs() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        wp_clear_scheduled_hook( self::CLEANUP_HOOK ); // 👈 حذف هوک جدید
    }
}