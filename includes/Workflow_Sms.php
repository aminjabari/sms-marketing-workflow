<?php
// includes/Sms.php
namespace SmsWorkflow;
use Kavenegar\KavenegarApi;

class Workflow_Sms
{
    private $api = '';
    private $sender = 0;
    private static $instance = null;
    public KavenegarApi $sms;

    public function __construct() {

        $api_key_setting = \get_option( 'sms_api_key' );
        $sender_number_setting = \get_option( 'sms_sender_number' );

        $this->api    = ! empty( $api_key_setting ) ? $api_key_setting : '';
        $this->sender = ! empty( $sender_number_setting ) ? $sender_number_setting : 0;

        // 💡 تضمین: اگر کلاس SDK در دسترس نباشد، نمونه‌سازی انجام نشود
        if ( ! empty( $this->api ) && class_exists('\Kavenegar\KavenegarApi')) {
            // 🚨 چک کردن مجدد Nonce: از آنجایی که API Key در متغیر است، مطمئن می‌شویم که آن را به صورت مستقیم استفاده می‌کنیم.
            $this->sms = new \Kavenegar\KavenegarApi( $this->api );
        } else {
            $this->sms = null;
            // 🚨 افزودن لاگ برای عیب‌یابی (API Key یا SDK Missing)
            error_log('FATAL SMS SETUP: Kavenegar SDK not found or API Key is empty.');
        }
    }

    public static function get_instance(){
        if(!self::$instance)
        {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function send($tel,$message,$date=null){
        if ( $this->sms === null || empty( $this->sender ) ) {
            error_log('SMS API Key or Sender Number is not set in plugin settings.');
            return false;
        }

        return $this->sms->Send($this->sender,$tel,$message,$date);
    }

    public function lookup($tel,$template,$token="",$token2="",$token3="")
    {
        if ( $this->sms === null  ) {
            error_log('SMS API Key or Sender Number is not set in plugin settings.');
            return false;
        }
        return $this->sms->VerifyLookup($tel, $template, $token, $token2, $token3);
    }

    public function cancel($messageid){
        if ( $this->sms === null  ) {
            error_log('SMS API Key or Sender Number is not set in plugin settings.');
            return false;
        }

        return $this->sms->Cancel($messageid);
    }
}