<?php

namespace SyncBasalam\Services\Orders;

defined('ABSPATH') || exit;

class FetchWeeklyUnsyncOrders
{
    private $getOrdersService;
    private $createOrderService;

    public function __construct()
    {
        $this->getOrdersService = new FetchOrders();
        $this->createOrderService = new OrderManager();
    }

    public function addUnsyncBasalamOrderToWoo()
    {
        global $wpdb;
        $table_name_payments = $wpdb->prefix . 'sync_basalam_payments';

        $orders = $this->getOrdersService->getWeeklyOrders();

        if (!$orders) {
            return [
                'success'     => true,
                'message'     => 'در هفته اخیر هیچ سفارشی ثبت نشده است.',
                'status_code' => 200,
            ];
        }
        $new_order = false;
        foreach ($orders as $order) {
            $invoice_id = $order['order']['id'];

            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT invoice_id  FROM  $table_name_payments WHERE invoice_id = %d",
                    $invoice_id
                )
            );

            if (!$exists) {
                $request = new \WP_REST_Request('POST');
                $request->set_param('invoice_id', $order['order']['id']);
                $request->set_param('user_id', $order['order']['customer']['user']['id']);
                $request->set_param('city_id', $order['order']['customer']['city']['id']);
                $request->set_param('province_id', $order['order']['customer']['city']['parent']['id']);
                $this->createOrderService->orderManger($request, false);
                $new_order = true;
            }
        }
        if ($new_order) {
            return [
                'success'     => true,
                'message'     => 'سفارشات با موفقیت همگام سازی شدند.',
                'status_code' => 200,
            ];
        } else {
            return [
                'success'     => true,
                'message'     => 'تمامی سفارشات باسلام همگام هستند.',
                'status_code' => 200,
            ];
        }
    }
}
