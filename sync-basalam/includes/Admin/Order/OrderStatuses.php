<?php

namespace SyncBasalam\Admin\Order;

class OrderStatuses
{
    public function getBasalamOrderStatuses()
    {
        return [
            "wc-bslm-rejected"     => "(باسلام) لغو شده",
            "wc-bslm-preparation" => "(باسلام) آماده‌سازی سفارش",
            "wc-bslm-wait-vendor" => "(باسلام) در انتظار تایید غرفه دار",
            "wc-bslm-shipping"    => "(باسلام) ارسال سفارش برای مشتری",
            "wc-bslm-completed"   => "(باسلام) تکمیل شده",
        ];
    }

    public function registerorderStatuses(array $orderStatuses): array
    {
        foreach ($this->getBasalamOrderStatuses() as $key => $label) {
            \register_post_status($key, [
                "label"                     => $label,
                "public"                    => true,
                "show_in_admin_all_list"    => true,
                "show_in_admin_status_list" => true,
                "wc_status"                 => true,
            ]);
        }
        return $orderStatuses + $this->getBasalamOrderStatuses();
    }
}
