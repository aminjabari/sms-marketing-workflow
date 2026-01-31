<?php
/**
 * Plugin Name: SMS Marketing Workflow
 * Plugin URI: https://example.com/
 * Description: A simple WordPress plugin for creating SMS templates and logging workflow executions.
 * Version: 1.0.0
 * Author: Gemini
 * License: GPL2
 */

// 💡 استفاده از USE برای دسترسی به کلاس‌های دارای فضای نام
use SmsWorkflow\SMS_DB_Manager;
use SmsWorkflow\SMS_Admin_Page;
use SmsWorkflow\SMS_Cron_Manager;
use SmsWorkflow\SMS_Workflow_Runner;
use SmsWorkflow\Workflow_Builder_UI;

// جلوگیری از دسترسی مستقیم به فایل
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// تعریف ثابت مسیر اصلی
if ( ! defined( 'SMS_WORKFLOW_PLUGIN_DIR' ) ) {
    define( 'SMS_WORKFLOW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

// 🚨 فراخوانی فایل Autoload (Composer)
// این خط تمام کلاس‌های داخل پوشه includes را بارگذاری می‌کند.
require_once SMS_WORKFLOW_PLUGIN_DIR . 'vendor/autoload.php';


/**
 * کلاس اصلی پلاگین
 */
class SMS_Marketing_Workflow {
    private static $instance = null;

    // 💡 متد نمونه‌سازی استاتیک برای تضمین نمونه واحد
    public static function get_instance(){
        if(!self::$instance)
        {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __construct() {
        // هوک فعال‌سازی (ایجاد جدول)
        register_activation_hook( __FILE__, array( SMS_DB_Manager::class, 'activate' ) );

        // 🚨 هوک فعال‌سازی (ایجاد جدول WorkFlow Builder) 🚨
        register_activation_hook( __FILE__, array( Workflow_Builder_UI::class, 'create_workflow_table' ) );

        // هوک زمانبندی کران (Schedule Cron)
        register_activation_hook( __FILE__, array( SMS_Cron_Manager::class, 'schedule_cron_jobs' ) );

        // حذف کرون در زمان غیرفعال‌سازی
        register_deactivation_hook( __FILE__, array( SMS_Cron_Manager::class, 'clear_cron_jobs' ) );

        // راه‌اندازی بخش ادمین
        SMS_Admin_Page::get_instance();



        // نمونه‌سازی ورکفلو و اتصال متدهای اصلی
        SMS_Workflow_Runner::get_instance();
        SMS_Cron_Manager::get_instance(); // نمونه‌سازی برای تضمین اتصال هوک‌ها (در متد __construct آن کلاس)

        // 💡 WorkFlow Builder UI (نیاز به نمونه‌سازی برای اتصال هوک‌های enqueue)
        Workflow_Builder_UI::get_instance();

        // اضافه کردن استایل‌ها و اسکریپت‌های مورد نیاز
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }


    /**
     * بارگذاری CSS و JS
     */
    public function enqueue_admin_assets() {
        $screen = get_current_screen();
        if ( $screen && strpos( $screen->id, 'sms-marketing-settings' ) !== false ) {
            wp_enqueue_script(
                'sms-workflow-admin-js',
                plugins_url( 'assets/js/admin.js', __FILE__ ),
                array( 'jquery' ),
                '1.0',
                true
            );
            wp_enqueue_style(
                'sms-workflow-admin-css',
                plugins_url( 'assets/css/admin.css', __FILE__ ),
                array(),
                '1.0'
            );
        }
    }
}

// 🚀 اجرای پلاگین در Global Namespace
SMS_Marketing_Workflow::get_instance();