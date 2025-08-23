<?php
class Sync_Basalam_Tracking_Code_Order_Service
{
    public function tracking_code_on_basalam()
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

        $response = $this->send_tracking_code_to_basalam($order_id, $tracking_code, $phone_number, $shipping_method);

        $status_code = $response['status_code'];
        if ($status_code !== 200 && $status_code !== 201) {

            $body = $response['body'];
            $error_message = '';

            if (is_array($body)) {
                if (!empty($body['errors']) && is_array($body['errors'])) {
                    $error_message = $body['errors'][0]['message'] ?? '';
                }
            } else {
                $error_message = $body;
            }

            return [
                'success' => false,
                'message' => $error_message,
                'status_code' => $status_code
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

    private function send_tracking_code_to_basalam($order_id, $tracking_code, $phone_number, $shipping_method)
    {
        global $wpdb;

        $token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        if (!$token) {
            return new WP_Error('no_token', 'توکن معتبری در پایگاه داده یافت نشد.');
        }

        $sync_basalam_order_id = Sync_Basalam_Order_Manager_Utilities::get_invoice_id($wpdb, $order_id);
        if (!$sync_basalam_order_id) {
            return new WP_Error('no_invoice_id', 'شناسه فاکتور سفارش یافت نشد.');
        }

        $api_url = 'https://order-processing.basalam.com/v2/vendor/set-posted-order';
        $body = json_encode([
            'order_id' => $sync_basalam_order_id,
            'shipping_method' => $shipping_method,
            'tracking_code' => $tracking_code,
            'phone_number' => $phone_number
        ], JSON_UNESCAPED_UNICODE);

        $headers = [
            'Authorization' => 'Bearer ' . $token
        ];

        $api_service = new Sync_basalam_External_API_Service();
        return $api_service->send_post_request($api_url, $body, $headers);
    }
}
