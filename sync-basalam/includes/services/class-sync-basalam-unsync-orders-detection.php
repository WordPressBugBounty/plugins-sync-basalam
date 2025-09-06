<?php
if (! defined('ABSPATH')) exit;
class Sync_basalam_Unsync_Orders_Detection
{
    private $get_sync_basalam_orders_service;
    private $create_order_service;
    public function __construct()
    {
        $this->get_sync_basalam_orders_service = new sync_basalam_Get_sync_basalam_Orders();
        $this->create_order_service = new SyncBasalamOrderManger();
    }
    public function add_unsync_basalam_order_to_woo()
    {
        $orders = $this->get_sync_basalam_orders_service->get_weekly_sync_basalam_orders();
        $orders = $orders['data'];

        global $wpdb;
        $table_name_payments = $wpdb->prefix . 'sync_basalam_payments';
        if (!$orders['data'] && $orders['status_code'] = 200) {
            return [
                'success' => true,
                'message' =>  'در هفته اخیر هیچ سفارشی ثبت نشده است.',
                'status_code' => 200
            ];
        }
        $new_order = false;
        foreach ($orders['data'] as $order) {
            $invoice_id = $order['order']['id'];

            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT invoice_id  FROM  $table_name_payments WHERE invoice_id = %d",
                    $invoice_id
                )
            );

            if (!$exists) {
                $request = new WP_REST_Request('POST');
                $request->set_param('invoice_id', $order['order']['id']);
                $request->set_param('user_id', $order['order']['customer']['user']['id']);
                $request->set_param('city_id', $order['order']['customer']['city']['id']);
                $request->set_param('province_id', $order['order']['customer']['city']['parent']['id']);
                $this->create_order_service->orderManger($request, false);
                $new_order = true;
            }
        }
        if ($new_order) {
            return [
                'success' => true,
                'message' =>  'سفارشات با موفقیت همگام سازی شدند.',
                'status_code' => 200
            ];
        } else {
            return [
                'success' => true,
                'message' =>  'تمامی سفارشات باسلام همگام هستند.',
                'status_code' => 200
            ];
        }
    }
}
