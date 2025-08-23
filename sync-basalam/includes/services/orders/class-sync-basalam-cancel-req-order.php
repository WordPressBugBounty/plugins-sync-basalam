<?php
class Sync_Basalam_Cancel_Req_Order_Service
{
    public function req_cancel_order_on_basalam()
    {
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $description = isset($_POST['description']) ? sanitize_text_field(wp_unslash($_POST['description'])) : '';

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
        $sync_basalam_order_id = Sync_Basalam_Order_Manager_Utilities::get_invoice_id($wpdb, $order_id);

        if (!$sync_basalam_order_id) {
            return [
                'success' => false,
                'message' =>  'شناسه فاکتور سفارش یافت نشد',
                'status_code' => 400
            ];
        }
        $response = $this->send_cancel_request_to_basalam($token, $item_ids, $description, $sync_basalam_order_id);

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
            'message' =>  'درخواست لغو سفارش با موفقیت ارسال شد.',
            'status_code' => 200
        ];
    }

    private function send_cancel_request_to_basalam($token, $item_ids, $description, $sync_basalam_order_id)
    {
        $api_url = "https://order-processing.basalam.com/v1/vendor/order/$sync_basalam_order_id/cancel-request";

        $body = json_encode([
            'item_ids' => $item_ids,
            'description' => $description
        ], JSON_UNESCAPED_UNICODE);

        $headers = [
            'Authorization' => 'Bearer ' . $token,
        ];
        $api_service = new Sync_basalam_External_API_Service();
        return $api_service->send_post_request($api_url, $body, $headers);
    }
}
