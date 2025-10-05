<?php
class Sync_Basalam_Confirm_Order_Service
{
    public function confirm_order_on_basalam()
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

        $sync_basalam_order_id = Sync_Basalam_Order_Manager_Utilities::get_invoice_id($wpdb, $order_id);
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

        $response = $this->send_confirm_order_request($token, $sync_basalam_order_id);

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

        $order->update_status('bslm-preparation', 'سفارش توسط ادمین تایید شد.');
        $order->add_order_note('درخواست تایید سفارش با موفقیت به سرور باسلام ارسال شد.');

        return [
            'success' => true,
            'message' =>  'سفارش با موفقیت در باسلام تایید شد.',
            'status_code' => 200
        ];
    }

    private function send_confirm_order_request($token, $sync_basalam_order_id)
    {
        $api_url = 'https://order-processing.basalam.com/v1/vendor/set-preparation-order';
        $body = json_encode([
            'order_id' => $sync_basalam_order_id
        ], JSON_UNESCAPED_UNICODE);

        $headers = [
            'Authorization' => 'Bearer ' . $token
        ];

        $api_service = new Sync_basalam_External_API_Service();
        return $api_service->send_post_request($api_url, $body, $headers);
    }
}
