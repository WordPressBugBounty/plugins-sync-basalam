<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Order_Manager
{
    public function add_custom_order_tracking_box()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $screen = get_current_screen();
        if ('shop_order' !== $screen->post_type) {
            return;
        }

        add_meta_box(
            'wc_order_tracking_box',
            'تنظیمات باسلام',
            array($this, 'display_order_tracking_box'),
            ['woocommerce_page_wc-orders', 'shop_order'],
            'side',
            'high'
        );
    }

    function display_order_tracking_box($post)
    {
        global $theorder;
        if (!is_object($theorder)) {
            $nonce = isset($_POST['sync_basalam_order_nonce']) ? sanitize_text_field(wp_unslash($_POST['sync_basalam_order_nonce'])) : '';
            if (!wp_verify_nonce($nonce, 'sync_basalam_manage_order_actions')) {
                wp_die('درخواست نامعتبر است.');
            }
            $order_id = sanitize_text_field(isset($_GET['id'])) ? sanitize_text_field(absint($_GET['id'])) : 0;
            $theorder = wc_get_order($order_id);
        }

        if (!$theorder) {
            return;
        }

        $order_id = $theorder->get_id();
        $order_status = $theorder->get_status();

        include_once plugin_dir_path(__FILE__) . 'views/order-tracking-box.php';
    }
    public function handle_admin_confirm_order()
    {
        global $wpdb;

        $order_id = isset($_POST['order_id']) ? sanitize_text_field(intval($_POST['order_id'])) : 0;
        if (!$order_id) {
            return [
                'success' => false,
                'message' =>  'شناسه سفارش نامعتبر است.',
                'status_code' => 400
            ];
        }

        $token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        if (!$token) {
            return [
                'success' => false,
                'message' =>  'توکن یافت نشد.',
                'status_code' => 400
            ];
        }

        $sync_basalam_order_id = $this->get_invoice_id($wpdb, $order_id);
        if (!$sync_basalam_order_id) {
            return [
                'success' => false,
                'message' =>  'شناسه فاکتور سفارش یافت نشد.',
                'status_code' => 400
            ];
        }

        if (!current_user_can('manage_woocommerce')) {
            return [
                'success' => false,
                'message' =>  'تنها مدیر کل امکان تغییر وضعیت سفارش را دارد.',
                'status_code' => 400
            ];
        }


        $order = wc_get_order($order_id);
        if (!$order) {
            return [
                'success' => false,
                'message' =>  'سفارش یافت نشد.',
                'status_code' => 400
            ];
        }

        $order->update_status('bslm-preparation', 'سفارش توسط ادمین تایید شد.');

        $response = $this->send_request_to_basalam($token, $sync_basalam_order_id);

        if (is_wp_error($response)) {
            $error_message = 'خطا در ارتباط با سرور باسلام: ' . $response->get_error_message();
            $order->add_order_note($error_message);
            return [
                'success' => false,
                'message' =>  $error_message,
                'status_code' => 400
            ];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code != 200) {
            $error_message = 'خطا در ارسال درخواست به سرور باسلام. کد وضعیت: ' . $response_code;
            $order->add_order_note($error_message);
            return [
                'success' => false,
                'message' =>  $error_message,
                'status_code' => 400
            ];
        }

        return [
            'success' => true,
            'message' =>  'سفارش با موفقیت در باسلام تایید شد.',
            'status_code' => 200
        ];
        $order->add_order_note('درخواست تایید سفارش با موفقیت به سرور باسلام ارسال شد.');
    }
    public function confirm_order_automatically($order_id)
    {
        global $wpdb;

        $token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        if (!$token) {
            return new WP_Error('no_token', 'توکن یافت نشد');
        }

        $sync_basalam_order_id = $this->get_invoice_id($wpdb, $order_id);
        if (!$sync_basalam_order_id) {
            return new WP_Error('no_invoice', 'شناسه فاکتور سفارش یافت نشد');
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error('no_order', 'سفارش یافت نشد');
        }

        $order_status_type = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::ORDER_STATUES_TYPE);
        if ($order_status_type == 'woosalam_statuses') {
            $order->update_status('bslm-preparation');
        }

        $response = $this->send_request_to_basalam($token, $sync_basalam_order_id);

        if (is_wp_error($response)) {
            $error_message = 'خطا در ارتباط با سرور باسلام: ' . $response->get_error_message();
            $order->add_order_note($error_message);
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code != 200) {
            $error_message = 'خطا در ارسال درخواست به سرور باسلام. کد وضعیت: ' . $response_code;
            $order->add_order_note($error_message);
            return new WP_Error('sync_basalam_error', $error_message);
        }

        $order->add_order_note('سفارش به صورت خودکار و موفق تایید و به باسلام ارسال شد.');
        return true;
    }

    public function handle_admin_cancel_order()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die();
        }


        $order_id = isset($_POST['order_id']) ? sanitize_text_field(intval($_POST['order_id'])) : 0;
        $order = wc_get_order($order_id);

        if ($order) {
            $order->update_status('bslm-rejected', 'سفارش توسط ادمین لغو شد.');
            wp_send_json_success('سفارش با موفقیت لغو شد.');
        }

        wp_send_json_error('خطا در لغو سفارش.');
    }

    public function handle_save_tracking_code()
    {
        if (!current_user_can('manage_woocommerce')) {
            return [
                'success' => false,
                'message' =>  'تنها مدیر کل امکان تغییر وضعیت سفارش را دارد.',
                'status_code' => 400
            ];
        }


        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $tracking_code = isset($_POST['tracking_code']) ? sanitize_text_field(wp_unslash($_POST['tracking_code'])) : '';
        $phone_number = isset($_POST['phone_number']) ? sanitize_text_field(wp_unslash($_POST['phone_number'])) : '';
        $shipping_method = isset($_POST['shipping_method']) ? intval($_POST['shipping_method']) : 0;

        if (empty($order_id)) {
            return [
                'success' => false,
                'message' =>  'شناسه سفارش نامعتبر است.',
                'status_code' => 400
            ];
        }

        if (empty($tracking_code)) {
            return [
                'success' => false,
                'message' =>  'لطفاً کد رهگیری را وارد کنید.',
                'status_code' => 400
            ];
        }

        if (empty($phone_number)) {
            return [
                'success' => false,
                'message' =>  'لطفاً شماره تلفن را وارد کنید.',
                'status_code' => 400
            ];
        }

        if (empty($shipping_method)) {
            return [
                'success' => false,
                'message' =>  'لطفاً روش ارسال را انتخاب کنید.',
                'status_code' => 400
            ];
        }

        update_post_meta($order_id, '_basalam_order_tracking_code', $tracking_code);

        $response = $this->send_tracking_data_to_basalam($order_id, $tracking_code, $phone_number, $shipping_method);
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' =>  'خطا در ارسال داده‌ها به سرور باسلام: ' . $response['errors']['message'],
                'status_code' => 400
            ];
        }


        if (isset($response['response']['code']) && (int)$response['response']['code'] === 422) {
            $body = json_decode($response['body'], true);

            $error_message = 'خطا در پردازش سفارش.';
            if (is_array($body) && isset($body['errors'][0]['message'])) {
                $error_message = $body['errors'][0]['message'];
            }
            return [
                'success' => false,
                'message' => $error_message,
                'status_code' => 422
            ];
        }


        if (!isset($response['response']['code']) || $response['response']['code'] !== 200) {
            return [
                'success' => false,
                'message' =>  'خطا در ارتباط با سرور باسلام. کد وضعیت: ' . ($response['response']['code'] ?? 'نامشخص'),
                'status_code' => 400
            ];
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return [
                'success' => false,
                'message' =>  'سفارش یافت نشد.',
                'status_code' => 400
            ];
        }

        $order->update_status('bslm-shipping', 'سفارش توسط ادمین ارسال شد.');
        return [
            'success' =>  true,
            'message' =>  'کد رهگیری و شماره تلفن با موفقیت ثبت و ارسال شد.',
            'status_code' => 200
        ];
    }

    public function handle_submit_delay_request()
    {
        if (!current_user_can('manage_woocommerce')) {
            return [
                'success' => false,
                'message' =>  'تنها مدیر کل امکان تغییر وضعیت سفارش را دارد.',
                'status_code' => 400
            ];
        }


        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $description = isset($_POST['description']) ? sanitize_text_field(wp_unslash($_POST['description'])) : '';
        $postpone_days = isset($_POST['postpone_days']) ? intval($_POST['postpone_days']) : 0;

        if (empty($order_id)) {
            return [
                'success' => false,
                'message' =>  'شناسه سفارش نامعتبر است.',
                'status_code' => 400
            ];
        }

        if (empty($description)) {
            return [
                'success' => false,
                'message' =>  'لطفاً توضیحات را وارد کنید.',
                'status_code' => 400
            ];
        }

        if (empty($postpone_days)) {
            return [
                'success' => false,
                'message' =>  'لطفاً تعداد روزهای تاخیر را وارد کنید.',
                'status_code' => 400
            ];
        }

        global $wpdb;

        $item_ids = $this->get_all_item_ids_from_meta($wpdb, $order_id);

        if (empty($item_ids)) {
            return [
                'success' => false,
                'message' =>  'هیچ شناسه آیتمی یافت نشد.',
                'status_code' => 400
            ];
        }

        $token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        if (!$token) {
            return [
                'success' => false,
                'message' =>  'توکن یافت نشد.',
                'status_code' => 400
            ];
        }

        $errors = [];
        foreach ($item_ids as $item_id) {
            $response = $this->send_delay_request_to_basalam($token, $item_id, $description, $postpone_days);

            if (is_wp_error($response)) {
                $errors[] = 'خطا برای آیتم ' . $item_id . ': ' . $response->get_error_message();
            } elseif (wp_remote_retrieve_response_code($response) != 200) {
                $errors[] = 'خطا برای آیتم ' . $item_id . '. کد وضعیت: ' . wp_remote_retrieve_response_code($response);
            }
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'message' =>  'خطا در ارسال درخواست تاخیر: ' . implode(', ', $errors),
                'status_code' => 400
            ];
        }

        return [
            'success' => true,
            'message' =>  'درخواست تاخیر برای سفارش با موفقیت ارسال شد.',
            'status_code' => 200
        ];
    }

    private function get_all_item_ids_from_meta($wpdb, $order_id)
    {
        $meta_key_pattern = '_sync_basalam_item_id_%';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}wc_orders_meta 
                 WHERE order_id = %d AND meta_key LIKE %s",
                $order_id,
                $meta_key_pattern
            )
        );

        $item_ids = [];
        if ($results) {
            foreach ($results as $row) {
                $item_ids[] = $row->meta_value;
            }
        }

        return $item_ids;
    }


    private function send_delay_request_to_basalam($token, $item_id, $description, $postpone_days)
    {
        $api_url = 'https://order-processing.basalam.com/v1/vendor/set-overdue-agreement-request';
        $body = [
            'item_id' => $item_id,
            'description' => $description,
            'postpone_days' => $postpone_days
        ];
        $args = [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'user-agent' => 'Wp-Basalam',

            ],
            'body' => json_encode($body)
        ];
        return wp_remote_post($api_url, $args);
    }

    private function send_tracking_data_to_basalam($order_id, $tracking_code, $phone_number, $shipping_method)
    {
        global $wpdb;

        $token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        if (!$token) {
            return new WP_Error('no_token', 'توکن معتبری در پایگاه داده یافت نشد.');
        }

        $sync_basalam_order_id = $this->get_invoice_id($wpdb, $order_id);
        if (!$sync_basalam_order_id) {
            return new WP_Error('no_invoice_id', 'شناسه فاکتور سفارش یافت نشد.');
        }

        $api_url = 'https://order-processing.basalam.com/v2/vendor/set-posted-order';
        $body = [
            'order_id' => $sync_basalam_order_id,
            'shipping_method' => $shipping_method,
            'tracking_code' => $tracking_code,
            'phone_number' => $phone_number
        ];

        $args = [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'user-agent' => 'Wp-Basalam',

                'Authorization' => 'Bearer ' . $token
            ],
            'body' => json_encode($body)
        ];

        return wp_remote_post($api_url, $args);
    }

    private function get_invoice_id($wpdb, $order_id)
    {
        $table_name = $wpdb->prefix . 'sync_basalam_payments';

        $order_data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT invoice_id FROM {$table_name} WHERE order_id = %d LIMIT 1",
                $order_id
            )
        );

        return $order_data ? $order_data->invoice_id : null;
    }

    private function send_request_to_basalam($token, $sync_basalam_order_id)
    {
        $api_url = 'https://order-processing.basalam.com/v1/vendor/set-preparation-order';
        $body = [
            'order_id' => $sync_basalam_order_id
        ];
        $args = [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'user-agent' => 'Wp-Basalam',
                'Authorization' => 'Bearer ' . $token
            ],
            'body' => json_encode($body)
        ];

        return wp_remote_post($api_url, $args);
    }
}
