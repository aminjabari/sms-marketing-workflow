<?php
// includes/class-sms-list-table.php
namespace SmsWorkflow;

if ( ! defined( 'ABSPATH' ) ) {
    exit; 
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class SMS_Logs_List_Table extends \WP_List_Table {



    private static $instance = null;
    public static function get_instance(){
        if(!self::$instance)
        {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __construct() {
        parent::__construct( array(
            'singular' => 'log',
            'plural'   => 'logs',
            'ajax'     => false,
        ) );
    }

    public function get_columns() {
        $columns = array(
            'cb'            => '<input type="checkbox" />',
            'created_at'    => 'تاریخ و زمان',
            'status'        => 'وضعیت',
            'workflow_name' => 'ورکفلو',
            'workflow_category' => 'دسته‌بندی',
            'recipient'     => 'دریافت‌کننده',
            'log_data_summary'  => 'جزئیات پیام',
            'message_id'    => 'آیدی پیامک',
        );
        return $columns;
    }

    public function get_sortable_columns() {
        $sortable_columns = array(
            'created_at' => array( 'created_at', false ),
            'status'     => array( 'status', false ),
            'workflow_category' => array( 'workflow_category', false ) /* 👈 اضافه شدن */
        );
        return $sortable_columns;
    }

    public function column_cb( $item ) {
        return sprintf(
            '<input type="checkbox" name="%1$s[]" value="%2$s" />',
            $this->_args['singular'],
            $item['id']
        );
    }

    public function column_message_sent( $item ) {
        return '<span title="' . esc_attr( $item['message_sent'] ) . '">' . wp_trim_words( $item['message_sent'], 8 ) . '</span>';
    }

    public function column_status( $item ) {
        $color = 'gray';
        $status_text = esc_html( $item['status'] );

        if ( $status_text === 'Sent' || $status_text === 'Completed' || $status_text === 'رسیده به مخابرات') {
            $color = 'green';
        } elseif ( $status_text === 'Failed' || $status_text === 'Partial Failure' || $status_text === 'Cancel Failed' || $status_text === 'خطا') {
            $color = 'red';
        } elseif ( $status_text === 'Skipped' || $status_text === 'در صف ارسال' || $status_text === 'زمانبندی شده') {
            $color = 'orange';
        } elseif ( $status_text === 'Cancelled') {
            $color = 'blue';
        }
        return sprintf( '<span style="color: %s; font-weight: bold;">%s</span>', $color, $status_text );
    }

    public function get_bulk_actions() {
        $actions = array(
            'delete'     => 'حذف لاگ',
            'cancel_sms' => 'لغو پیامک برنامه‌ریزی شده' // <--- اکشن جدید
        );
        return $actions;
    }

    public function prepare_items() {
        global $wpdb;
        $table_name = SMS_DB_Manager::get_log_table_name();
        
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ( $current_page - 1 ) * $per_page;
        
        $this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
        
        // مدیریت مرتب‌سازی
        $orderby = isset( $_GET['orderby'] ) ? sanitize_sql_orderby( $_GET['orderby'] ) : 'created_at';
        $order = isset( $_GET['order'] ) ? sanitize_sql_orderby( $_GET['order'] ) : 'desc';

        // واکشی داده‌ها
        $query = $wpdb->prepare( 
            "SELECT * FROM $table_name ORDER BY %s %s LIMIT %d OFFSET %d",
            $orderby,
            $order,
            $per_page,
            $offset
        );
        
        $this->items = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY $orderby $order LIMIT $per_page OFFSET $offset", ARRAY_A );
        
        // محاسبه تعداد کل آیتم‌ها
        $total_items = $wpdb->get_var( "SELECT COUNT(id) FROM $table_name" );

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ) );
    }

    /**
     * نمایش ستون workflow_name به همراه لینک‌های عملیاتی (Row Actions)
     */
    public function column_workflow_name($item) {

        // 1. URL اصلی برای حذف این لاگ (Action: delete)
        $delete_url = add_query_arg(
            array(
                'page'   => sanitize_text_field($_REQUEST['page']),
                'action' => 'delete',
                'log'    => $item['id'],
                '_wpnonce' => wp_create_nonce('sms_delete_log_' . $item['id']), // Nonce اختصاصی
            ),
            admin_url('admin.php')
        );

        // 2. URL اصلی برای لغو ورکفلو (Action: cancel_sms)
        $cancel_url = add_query_arg(
            array(
                'page'   => sanitize_text_field($_REQUEST['page']),
                'action' => 'cancel_sms',
                'log'    => $item['id'], // ارسال آیدی این لاگ به عنوان هدف لغو
                '_wpnonce' => wp_create_nonce('sms_cancel_log_' . $item['id']), // Nonce اختصاصی
            ),
            admin_url('admin.php')
        );

        // لینک‌های عملیاتی زیر ردیف
        $actions = array(
            'cancel' => sprintf(
                '<a href="%s" onclick="return confirm(\'آیا مطمئن هستید که می‌خواهید تمام پیامک‌های برنامه‌ریزی شده این ورکفلو (%s) را لغو کنید؟\');" style="color: orange;">لغو ورکفلو</a>',
                esc_url($cancel_url),
                esc_attr($item['workflow_name'])
            ),
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm(\'آیا مطمئن هستید که می‌خواهید این لاگ را حذف کنید؟ این عمل برگشت‌ناپذیر است.\');" style="color: red;">حذف لاگ</a>',
                esc_url($delete_url)
            )
        );

        return sprintf('%s %s', esc_html($item['workflow_name']), $this->row_actions($actions));
    }

    /**
     * متد column_default را اصلاح می‌کنیم تا column_workflow_name تکرار نشود
     */
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'created_at':
            case 'workflow_category':
            case 'recipient':
            case 'message_id':
            case 'status': // 👈 اگر status در ستون‌های اصلی نباشد
                return esc_html($item[$column_name]);
            // 💡 دیگر نیازی به workflow_name نیست
            default:
                return print_r($item, true);
        }
    }

    public function column_log_data_summary($item) {

        $log_data_raw = $item['log_data'] ?? null;

        if (empty($log_data_raw)) {
            return '—';
        }

        // 1. Unserialize کردن داده‌ها
        $log_data = maybe_unserialize($log_data_raw);

        // 2. 🚨 مدیریت خطای Object/Null: تبدیل شیء stdClass به آرایه 🚨
        if (is_object($log_data)) {
            $log_data = (array) $log_data;
        }

        if (!is_array($log_data) || empty($log_data)) {
            return '—';
        }

        // 3. تشخیص WorkFlow چند مرحله‌ای (Steps)
        if (isset($log_data['steps']) && is_array($log_data['steps'])) {
            $step_count = count($log_data['steps']);
            return "WorkFlow ({$step_count} مرحله)";
        }

        // 4. تشخیص لاگ ساده (مانند لاگ Debug یا خطای عمومی)
        // 💡 اگر یک لاگ ساده باشد، کلیدها در سطح ریشه آرایه خواهند بود.

        // اگر لاگ ساده باشد و کلید 0 وجود داشته باشد (برای لاگ‌های آرایه‌ای ساده مانند تست)
        if (isset($log_data[0]) && (is_array($log_data[0]) || is_object($log_data[0]))) {

            // تبدیل عنصر اول به آرایه برای اطمینان
            $first_step = (array) $log_data[0];

            // نمایش خلاصه پیام (اگر ستون message_sent در مراحل وجود داشته باشد)
            if (isset($first_step['message_sent'])) {
                return esc_html(substr($first_step['message_sent'], 0, 50)) . '...';
            }

            // اگر لاگ تست ساده باشد
            if (isset($first_step[0]) && is_string($first_step[0])) {
                return esc_html(substr($first_step[0], 0, 50)) . '...';
            }

        }

        return 'جزئیات لاگ موجود است.';
    }

}