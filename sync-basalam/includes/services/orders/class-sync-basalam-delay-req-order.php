<?php
class Sync_Basalam_Delay_Req_Order_Service
{
    public function delay_req_on_basalam()
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

        $item_ids = Sync_Basalam_Order_Manager_Utilities::get_all_item_ids_from_meta($wpdb, $order_id);

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

        foreach ($item_ids as $item_id) {
            $response = $this->send_delay_request_to_basalam($token, $item_id, $description, $postpone_days);
        }

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
        return [
            'success' => true,
            'message' =>  'درخواست تاخیر برای سفارش با موفقیت ارسال شد.',
            'status_code' => 200
        ];
    }

    private function send_delay_request_to_basalam($token, $item_id, $description, $postpone_days)
    {
        $api_url = 'https://order-processing.basalam.com/v1/vendor/set-overdue-agreement-request';

        $body = json_encode([
            'item_id' => $item_id,
            'description' => $description,
            'postpone_days' => $postpone_days
        ], JSON_UNESCAPED_UNICODE);

        $headers = [
            'Authorization' => 'Bearer ' . $token,
        ];

        $api_service = new Sync_basalam_External_API_Service();
        return $api_service->send_post_request($api_url, $body, $headers);
    }
}
