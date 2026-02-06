<?php

namespace SyncBasalam\Admin\Order;

defined("ABSPATH") || exit;

class OrderColumn
{
    public function addColumn($columns)
    {
        $reorderedColumns = [];

        foreach ($columns as $key => $column) {
            $reorderedColumns[$key] = $column;
            if ($key === "order_status") $reorderedColumns["basalam_order"] = "سفارش از باسلام";
        }

        return $reorderedColumns;
    }

    public function renderColumn($column, $order_or_id)
    {
        if ($column !== "basalam_order") {
            return;
        }

        // HPOS passes order object, traditional CPT passes post ID
        if (is_object($order_or_id) && method_exists($order_or_id, 'get_id')) {
            $order_id = $order_or_id->get_id();
        } else {
            $order_id = $order_or_id;
        }

        if (get_post_meta($order_id, "_is_sync_basalam_order", true)) {
            echo "بله";
        } else {
            echo "خیر";
        }
    }
}
