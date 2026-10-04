<?php
/**
 * ایجاد سفارش تستی ووکامرس با دو آیتم باسلام:
 *   ۱) محصول مپ‌شده (57324227 → محصول ۱۷ سایت)
 *   ۲) محصول غیرمپ (placeholder)
 * از همان مسیر تولید: OrderManager::addBasalamItemToOrder
 */

require '/var/www/html/wp-load.php';

if (!class_exists(\SyncBasalam\Services\Orders\OrderManager::class)) {
    fwrite(STDERR, "OrderManager not found — plugin autoload failed\n");
    exit(1);
}

use SyncBasalam\Services\Orders\OrderManager;

$order = wc_create_order(['status' => 'processing']);

$address = [
    'first_name' => 'علی',
    'last_name'  => 'بهشتی',
    'email'      => 'ali@localhost.test',
    'phone'      => '09120000000',
    'address_1'  => 'خیابان تست',
    'city'       => 'تهران',
    'country'    => 'IR',
];
$order->set_address($address, 'billing');
$order->set_address($address, 'shipping');
$order->update_meta_data('_sync_basalam_hash_id', 'test-order-' . time());

// آیتم ۱: محصول مپ‌شده — تنوع ندارد، مسیر simple mapping
OrderManager::addBasalamItemToOrder($order, [
    'id'       => 900101,
    'quantity' => 2,
    'product'  => ['id' => 57324227, 'title' => 'تی شرت مردانه ایرانی'],
    'financial_report' => [
        'report_items' => [
            ['title' => 'قیمت محصول', 'amount' => 2000000],
        ],
    ],
]);

// آیتم ۲: محصول غیرمپ با تنوع → placeholder + نام + عنوان تنوع
OrderManager::addBasalamItemToOrder($order, [
    'id'        => 900102,
    'quantity'  => 1,
    'product'   => ['id' => 99999999, 'title' => 'ماگ حرارتی استیل'],
    'variation' => ['id' => 88888888, 'title' => 'رنگ مشکی'],
    'financial_report' => [
        'report_items' => [
            ['title' => 'قیمت محصول', 'amount' => 3500000],
        ],
    ],
]);

$order->calculate_totals();
$order->save();

$orderId = $order->get_id();
echo "ORDER_ID={$orderId}\n";

foreach ($order->get_items() as $item) {
    echo "ITEM: [{$item->get_id()}] {$item->get_name()} x{$item->get_quantity()}\n";
    foreach ($item->get_meta_data() as $meta) {
        echo "  META: {$meta->key} = {$meta->value}\n";
    }
}

echo "ADMIN_URL=" . admin_url("admin.php?page=wc-orders&action=edit&id={$orderId}") . "\n";
