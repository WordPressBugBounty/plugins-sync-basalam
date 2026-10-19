<?php

namespace SyncBasalam\SalamPay;

defined('ABSPATH') || exit;

final class Gateway extends \WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'salampay';
        $this->method_title = __('سلام‌پی | ووسلام', 'sync-basalam');
        $this->method_description = __('پرداخت سفارش با سلام‌پی و پیگیری بازگشت وجه، از طریق ووسلام.', 'sync-basalam');
        $this->has_fields = false;
        $this->supports = ['products', 'refunds'];
        $this->icon = Module::assetUrl('logo.svg');
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title', __('پرداخت با سلام‌پی', 'sync-basalam'));
        $this->description = $this->get_option('description', __('پرداخت امن با سلام‌پی', 'sync-basalam'));
        $this->enabled = $this->get_option('enabled', 'no');
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    public function is_available()
    {
        return Settings::configured() && Money::supports(get_woocommerce_currency()) && parent::is_available();
    }

    public function get_icon()
    {
        return apply_filters('woocommerce_gateway_icon', '<img src="' . esc_url($this->icon)
            . '" alt="' . esc_attr($this->get_title()) . '" style="max-height:24px;width:auto" />', $this->id);
    }

    public function admin_options()
    {
        parent::admin_options();
        if (!Money::supports(get_woocommerce_currency())) {
            echo '<p class="notice notice-error">' . esc_html__('سلام‌پی فقط از ریال، تومان، هزار ریال و هزار تومان پشتیبانی می‌کند.', 'sync-basalam') . '</p>';
        }
        echo '<p>' . esc_html__('بازگشت وجه ابتدا در سلام‌پی ثبت و پیگیری می‌شود؛ تا تأیید نهایی، مبلغ سفارش در ووکامرس مستردشده محسوب نمی‌شود. برای بررسی مجدد از عملیات سفارش استفاده کنید.', 'sync-basalam') . '</p>';
    }

    public function process_payment($order_id)
    {
        $result = PaymentService::start((int) $order_id);
        if (is_wp_error($result)) {
            wc_add_notice($result->get_error_message(), 'error');
            return ['result' => 'failure'];
        }
        return $result;
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $result = RefundService::request((int) $order_id, $amount, (string) $reason);
        if (is_wp_error($result)) {
            return $result;
        }
        // WC would immediately finalize the refund on true. The worker will create
        // the actual WC refund only after the provider confirms settlement.
        return new \WP_Error('salampay_refund_pending', __('درخواست بازگشت وجه ثبت شد و در انتظار تأیید سلام‌پی است. نتیجه و بازگشت وجه ووکامرس خودکار ثبت می‌شوند؛ درخواست را دوباره ارسال نکنید.', 'sync-basalam'));
    }

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'              => array(
				'title'   => __( 'فعال‌سازی', 'sync-basalam' ),
				'type'    => 'checkbox',
				'label'   => __( 'فعال کردن درگاه پرداخت سلام پی', 'sync-basalam' ),
				'default' => 'no',
			),
			'title'                => array(
				'title'       => __( 'عنوان درگاه', 'sync-basalam' ),
				'type'        => 'text',
				'description' => __( 'این عنوان در صفحه پرداخت به مشتری نمایش داده می‌شود.', 'sync-basalam' ),
				'default'     => __( 'پرداخت با سلام پی', 'sync-basalam' ),
				'desc_tip'    => true,
			),
			'description'          => array(
				'title'       => __( 'توضیحات درگاه', 'sync-basalam' ),
				'type'        => 'textarea',
				'description' => __( 'این متن زیر عنوان درگاه در صفحه پرداخت نمایش داده می‌شود.', 'sync-basalam' ),
				'default'     => __( 'پرداخت امن از طریق درگاه سلام پی', 'sync-basalam' ),
				'desc_tip'    => true,
			),
			'client_secret'        => array(
				'title'       => __( 'سکرت کلاینت', 'sync-basalam' ),
				'type'        => 'password',
				'description' => __( 'کلید اتصال دریافتی از سلام‌پی را وارد کنید.', 'sync-basalam' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'success_order_status' => array(
				'title'       => __( 'وضعیت سفارش بعد از پرداخت موفق', 'sync-basalam' ),
				'type'        => 'select',
				'description' => __( 'بعد از تایید موفق پرداخت توسط سلام پی، سفارش به این وضعیت تغییر می‌کند.', 'sync-basalam' ),
				'default'     => 'auto',
				'options'     => ['auto' => __('خودکار ووکامرس', 'sync-basalam'), 'processing' => __('در حال انجام', 'sync-basalam'), 'completed' => __('تکمیل‌شده', 'sync-basalam')],
			),
			'failed_order_status'  => array(
				'title'       => __( 'وضعیت سفارش بعد از پرداخت ناموفق', 'sync-basalam' ),
				'type'        => 'select',
				'description' => __( 'پس از اعلام قطعی پرداخت ناموفق از سمت سلام‌پی، سفارش به این وضعیت تغییر می‌کند.', 'sync-basalam' ),
				'default'     => 'failed',
				'options'     => ['failed' => __('ناموفق', 'sync-basalam'), 'cancelled' => __('لغوشده', 'sync-basalam')],
			),
			'debug_log'            => array(
				'title'       => __( 'لاگ عیب‌یابی', 'sync-basalam' ),
				'type'        => 'checkbox',
				'label'       => __( 'فعال کردن لاگ امن سلام پی', 'sync-basalam' ),
				'description' => __( 'فقط خلاصه فنی غیرحساس در لاگ ووکامرس ذخیره می‌شود؛ سکرت، هدرها و بدنه خام پاسخ‌ها ذخیره نمی‌شوند.', 'sync-basalam' ),
				'default'     => 'no',
			),
			'auto_refund_cancelled' => [
                'title' => __('بازگشت وجه سفارش لغوشده', 'sync-basalam'),
                'type' => 'checkbox',
                'label' => __('درخواست خودکار بازگشت مانده وجه هنگام لغو سفارش پرداخت‌شده', 'sync-basalam'),
                'description' => __('نتیجه تا تأیید سلام‌پی در انتظار بررسی می‌ماند.', 'sync-basalam'),
                'default' => 'no',
            ],
			'promotion_bar_enabled' => array(
				'title'       => __( 'نوار معرفی سلام پی', 'sync-basalam' ),
				'type'        => 'checkbox',
				'label'       => __( 'نمایش نوار سلام پی در بالای فروشگاه', 'sync-basalam' ),
				'description' => __( 'یک نوار جمع‌وجور و واکنش‌گرا بالای هدر فروشگاه نمایش داده می‌شود.', 'sync-basalam' ),
				'default'     => 'yes',
			),
			'promotion_bar_text'    => array(
				'title'             => __( 'متن نوار معرفی', 'sync-basalam' ),
				'type'              => 'text',
				'description'       => __( 'متن کوتاهی که کنار لوگوی سلام پی نمایش داده می‌شود.', 'sync-basalam' ),
				'default'           => __( 'برای پرداخت سفارش می‌توانید سلام پی را انتخاب کنید.', 'sync-basalam' ),
				'custom_attributes' => array(
					'maxlength' => '90',
				),
				'desc_tip'          => true,
			),
			'product_badge_enabled' => array(
				'title'       => __( 'نشان محصولات', 'sync-basalam' ),
				'type'        => 'checkbox',
				'label'       => __( 'نمایش نشان سلام پی روی محصولات', 'sync-basalam' ),
				'description' => __( 'نشان لوگودار سلام پی روی کارت محصولات و کنار عنوان صفحه محصول نمایش داده می‌شود.', 'sync-basalam' ),
				'default'     => 'yes',
			),
		);
	}

}
