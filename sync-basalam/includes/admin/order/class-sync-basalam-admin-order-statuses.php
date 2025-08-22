<?php
class Sync_basalam_Admin_Order_Statuses
{
    public function add_custom_order_statuses($order_statuses)
    {
        $order_statuses['wc-bslm-rejected'] = '(باسلام) لغو شده';
        $order_statuses['wc-bslm-preparation'] = '(باسلام) آماده‌سازی سفارش';
        $order_statuses['wc-bslm-wait-vendor'] = '(باسلام) در انتظار تایید غرفه دار';
        $order_statuses['wc-bslm-shipping'] = '(باسلام) ارسال سفارش برای مشتری';
        $order_statuses['wc-bslm-completed'] = '(باسلام) تکمیل شده';
        return $order_statuses;
    }

    public function register_custom_order_statuses()
    {
        $statuses = [
            'wc-bslm-rejected' => 'لغو شده',
            'wc-bslm-wait-vendor' => 'در انتظار تایید غرفه دار',
            'wc-bslm-preparation' => 'حال آماده‌سازی سفارش',
            'wc-bslm-shipping' => 'ارسال سفارش برای مشتری',
            'wc-bslm-completed' => 'تکمیل شده'
        ];

        foreach ($statuses as $key => $label) {
            register_post_status($key, [
                'label' => $label,
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'post_type' => ['shop_order', 'wc_order'],
                'wc_status' => true
            ]);
        }
    }

    public function add_custom_status_to_bulk_actions($bulk_actions)
    {
        $bulk_actions['mark_order-rejected'] = 'تغییر به لغو شده';
        $bulk_actions['mark_order-preparation'] = 'تغییر به حال آماده‌سازی سفارش';
        $bulk_actions['mark_order-vendor'] = 'تغییر به در انتظار تایید غرفه دار';
        $bulk_actions['mark_shipping-product'] = 'تغییر به ارسال سفارش برای مشتری';
        $bulk_actions['mark_order-completed'] = 'تغییر به تکمیل شده';
        return $bulk_actions;
    }
}
