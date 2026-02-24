<?php

namespace SyncBasalam\Services\Orders;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Utilities\GetProvincesData;
use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;

class OrderManager
{
    private $apiService;

    public function __construct()
    {
        $this->apiService = new ApiServiceManager();
    }

    public static function orderManger(\WP_REST_Request $request, $checkSyncStatus = true)
    {
        $parsedParams = $request->get_params();

        if ($checkSyncStatus && !syncBasalamSettings()->getSettings(SettingsConfig::SYNC_STATUS_ORDER)) {
            return;
        }

        Logger::debug("دریافت رویداد سفارش: " . json_encode($parsedParams));

        if (isset($parsedParams['event_id']) && $parsedParams['event_id'] == 7) {
            if ($parsedParams['type'] == 'shipped') {
                self::shippedOrderWoo($parsedParams['invoice_id']);
            } elseif ($parsedParams['type'] == 'cancelled') {
                self::cancelOrderWoo($parsedParams['invoice_id']);
            } elseif ($parsedParams['type'] == 'preparation') {
                self::confirmOrderWoo($parsedParams['invoice_id']);
            }
        } elseif (isset($parsedParams['event_id']) && $parsedParams['event_id'] == 3) {
            if ($parsedParams['status'] == '3195') {
                self::completeOrderWoo($parsedParams['more_data']['invoice_id']);
            } elseif ($parsedParams['status'] == '3067' || $parsedParams['status'] == '3233') {
                self::cancelOrderWoo($parsedParams['more_data']['invoice_id']);
            }
        } else {
            self::createOrderWoo($parsedParams);
        }
    }

    public static function createOrderWoo($params)
    {
        $payment_id = $params['payment_id'] ?? null;
        $invoice_id = $params['invoice_id'] ?? null;
        $user_id = $params['user_id'] ?? null;
        $city_id = $params['city_id'] ?? null;
        $province_id = $params['province_id'] ?? null;

        global $wpdb;
        $table_name = $wpdb->prefix . 'sync_basalam_payments';

        $wpdb->query('START TRANSACTION');

        try {
            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE invoice_id = %d FOR UPDATE",
                    $invoice_id
                )
            );

            if ($existing) {
                $wpdb->query('ROLLBACK');
                Logger::error("سفارش با شناسه فاکتور $invoice_id قبلا ایجاد شده");

                return false;
            }

            $vendor_id = syncBasalamSettings()->getSettings(SettingsConfig::VENDOR_ID);

            $api_url = "https://order-processing.basalam.com/v2/vendors/$vendor_id/orders/$invoice_id";

            $apiServiceManager = new ApiServiceManager();

            $response = $apiServiceManager->sendGetRequest($api_url);

            if (isset($response['success']) && !$response['success']) {
                $wpdb->query('ROLLBACK');
                Logger::error("درخواست API ناموفق بود: " . ($response['error'] ?? 'خطای نامشخص'));

                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'Failed to fetch invoice details.',
                    'error'   => $response['error'] ?? 'Unknown error',
                ], 500);
            }

            $api_response = $response['body'] ?? '';
            $data = json_decode($api_response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $wpdb->query('ROLLBACK');

                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'Failed to parse API response.',
                    'error'   => 'Invalid JSON response',
                ], 500);
            }

            if (empty($data)) {
                $wpdb->query('ROLLBACK');
                Logger::error("پاسخ خالی از API برای فاکتور دریافت شد: $invoice_id");

                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'Empty API response.',
                    'error'   => 'No data received from API',
                ], 500);
            }

            $order = wc_create_order();
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    $sync_basalam_product_id = $item['product']['id'] ?? null;
                    $quantity = $item['quantity'] ?? 1;
                    $item_id = $item['id'] ?? null;

                    if ($sync_basalam_product_id) {
                        try {
                            if (!empty($item['variation']['id'])) {
                                $woo_product_id = self::getWooProductVariableId($item['variation']['id']);
                            } else {
                                $woo_product_id = self::getWooProductSimpleId($sync_basalam_product_id);
                            }

                            if ($woo_product_id) {
                                $product = wc_get_product($woo_product_id);
                                if ($product) {
                                    $order_item_id = $order->add_product($product, $quantity);
                                    if ($item_id && $order_item_id) {
                                        $order->update_meta_data('_sync_basalam_item_id_' . $order_item_id, $item_id);
                                    }

                                    self::set_item_price_from_financial_report($order, $order_item_id, $item, $quantity);
                                }
                            } else {
                                $placeholder_product_id = self::getPlaceholderProductId();
                                if ($placeholder_product_id) {
                                    $placeholder_product = wc_get_product($placeholder_product_id);
                                    if ($placeholder_product) {
                                        $order_item_id = $order->add_product($placeholder_product, $quantity);

                                        if ($item_id && $order_item_id) {
                                            $order->update_meta_data('_sync_basalam_item_id_' . $order_item_id, $item_id);
                                        }

                                        self::set_item_price_from_financial_report($order, $order_item_id, $item, $quantity);
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            Logger::error('خطا در ایجاد سفارش: ' . $e->getMessage());
                        }
                    }
                }
            }

            if (isset($data['customer_data']['recipient']) && is_array($data['customer_data']['recipient'])) {
                $recipient = $data['customer_data']['recipient'];
                $province = $data['customer_data']['city']['parent']['title'] ?? '';
                $city = $data['customer_data']['city']['title'] ?? '';

                $full_name = $recipient['name'] ?? '';
                $first_name = '';
                $last_name = '';
                if (!empty($full_name)) {
                    $parts = explode(' ', trim($full_name));
                    $parts = array_filter($parts);

                    if (count($parts) === 1) {
                        $first_name = $parts[0];
                        $last_name = $parts[0];
                    } else {
                        $first_name = array_shift($parts);
                        $last_name = implode(' ', $parts);
                    }
                }

                $prefix = syncBasalamSettings()->getSettings(SettingsConfig::CUSTOMER_PREFIX_NAME);
                $suffix = syncBasalamSettings()->getSettings(SettingsConfig::CUSTOMER_SUFFIX_NAME);

                if (!empty($prefix)) $first_name = $prefix . ' ' . $first_name;
                if (!empty($suffix)) $last_name = $last_name . ' ' . $suffix;

                // Set basic billing info
                $order->set_billing_first_name($first_name);
                $order->set_billing_last_name($last_name);
                $order->set_billing_address_1($recipient['postal_address'] ?? '');
                $order->set_billing_postcode($recipient['postal_code'] ?? '');
                $order->set_billing_country('IR');
                $order->set_billing_phone($recipient['mobile'] ?? '');

                // Set basic shipping info
                $order->set_shipping_first_name($first_name);
                $order->set_shipping_last_name($last_name);
                $order->set_shipping_address_1($recipient['postal_address'] ?? '');
                $order->set_shipping_postcode($recipient['postal_code'] ?? '');
                $order->set_shipping_phone($recipient['mobile'] ?? '');
                $order->set_shipping_country('IR');

                // Set state and city with PWS compatibility
                $addressData = [
                    'province' => $province,
                    'city'     => $city,
                ];
                GetProvincesData::setOrderAddress($order, $addressData, 'billing');
                GetProvincesData::setOrderAddress($order, $addressData, 'shipping');

                // Add shipping method based on settings
                $shipping_method_setting = syncBasalamSettings()->getSettings(SettingsConfig::ORDER_SHIPPING_METHOD);

                if (isset($data['parcel_detail']['shipping_cost'])) {
                    $shipping_cost = $data['parcel_detail']['shipping_cost'];

                    $currency = get_woocommerce_currency();
                    if ($currency === 'IRT') {
                        $shipping_cost = $shipping_cost / 10;
                    } elseif ($currency === 'IRHT') {
                        $shipping_cost = $shipping_cost / 10000;
                    } elseif ($currency === 'IRHR') {
                        $shipping_cost = $shipping_cost / 1000;
                    }

                    $shipping_item = new \WC_Order_Item_Shipping();

                    if ($shipping_method_setting === 'basalam') {
                        // Use Basalam shipping method title from API
                        if (isset($data['parcel_detail']['shipping_method']['title'])) {
                            $shipping_method_title = $data['parcel_detail']['shipping_method']['title'];
                            $shipping_item->set_method_title($shipping_method_title);
                        }
                        $shipping_item->set_method_id('basalam_shipping');
                    } elseif (strpos($shipping_method_setting, 'wc_') === 0) {
                        // Use WooCommerce shipping method
                        $wc_method_id = substr($shipping_method_setting, 3); // Remove 'wc_' prefix

                        // Find the shipping method instance
                        $method_instance_id = self::findShippingMethodInstanceId($wc_method_id);
                        if ($method_instance_id) {
                            $shipping_item->set_method_id($wc_method_id . ':' . $method_instance_id);

                            // Get the method title from WooCommerce
                            $method_title = self::getShippingMethodTitle($wc_method_id, $method_instance_id);
                            if ($method_title) {
                                $shipping_item->set_method_title($method_title);
                            }
                        } else {
                            // Fallback to method id without instance
                            $shipping_item->set_method_id($wc_method_id);
                            $shipping_item->set_method_title($wc_method_id);
                        }
                    }

                    $shipping_item->set_total(floatval($shipping_cost));
                    $shipping_item->set_taxes([]);
                    $order->add_item($shipping_item);
                }
            }

            $order->calculate_totals();

            $total_price = 0;
            $products_total = 0;

            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    if (isset($item['financial_report']['report_items']) && is_array($item['financial_report']['report_items'])) {
                        foreach ($item['financial_report']['report_items'] as $report_item) {
                            if (isset($report_item['title']) && $report_item['title'] === 'قیمت محصول' && isset($report_item['amount'])) {
                                $products_total += (int) $report_item['amount'];
                                break;
                            }
                        }
                    }
                }
            }

            if ($products_total > 0) {
                $total_price = $products_total;
            } else {
                if (isset($data['financial_report']['product_cost']['report_items'][0]['amount'])) {
                    $total_price += $data['financial_report']['product_cost']['report_items'][0]['amount'];
                }
            }

            if (isset($data['financial_report']['shipping_cost']['total']['amount'])) {
                $total_price += $data['financial_report']['shipping_cost']['total']['amount'];
            }

            if ($total_price > 0) {
                $currency = get_woocommerce_currency();
                if ($currency === 'IRT') {
                    $total_price = $total_price / 10;
                } elseif ($currency === 'IRHT') {
                    $total_price = $total_price / 10000;
                } elseif ($currency === 'IRHR') {
                    $total_price = $total_price / 1000;
                }
                $order->set_total($total_price);
            }

            $order->set_payment_method('basalam payment method');
            $order->set_payment_method_title('Basalam Payment');

            $orderStatusType = syncBasalamSettings()->getSettings(SettingsConfig::ORDER_STATUES_TYPE);

            $status_map = [
                3067 => 'bslm-rejected',
                3739 => 'bslm-wait-vendor',
                3237 => 'bslm-preparation',
                3238 => 'bslm-shipping',
                3195 => 'bslm-completed',
                3233 => 'bslm-rejected',
            ];
            $status_id = $data['status']['id'] ?? null;

            if ($orderStatusType == 'woocommerce_statuses') {
                $order->set_status('processing');
            } else {
                $order_status = $status_map[$status_id] ?? 'bslm-wait-vendor';
                $order->set_status($order_status);
            }

            $purchase_count = $data['customer_data']['purchase_count'];
            $fee_amount = $data['financial_report']['product_cost']['report_items'][2]['amount'] ?? 0;
            $balance_amount = $data['financial_report']['product_cost']['total']['amount'] ?? 0;


            $order->update_meta_data('_basalam_fee_amount', intval($fee_amount / 10));
            $order->update_meta_data('_basalam_balance_amount', intval($balance_amount / 10));
            $order->update_meta_data('_basalam_purchase_count', $purchase_count);

            if (isset($data['hash_id'])) {
                $order->update_meta_data('_sync_basalam_hash_id', $data['hash_id']);
            }

            $order->save();

            $order_id = $order->get_id();
            if ($order_id) {

                $insert_result = $wpdb->insert(
                    $table_name,
                    [
                        'payment_id'  => $payment_id,
                        'invoice_id'  => $invoice_id,
                        'user_id'     => $user_id,
                        'city_id'     => $city_id,
                        'province_id' => $province_id,
                        'order_id'    => $order_id,
                    ],
                    ['%d', '%d', '%d', '%d', '%d', '%d']
                );

                if ($insert_result === false) {
                    throw new \Exception("خطا در ذخیره اطلاعات سفارش در جدول sync_basalam_payments");
                }

                update_post_meta($order_id, '_is_sync_basalam_order', true);

                $wpdb->query('COMMIT');

                return new \WP_REST_Response([
                    'success'  => true,
                    'message'  => 'Order created successfully',
                    'order_id' => $order_id,
                ], 200);
            } else {
                throw new \Exception("خطا در ایجاد سفارش با شناسه $invoice_id ، از گزینه بررسی سفارشات استفاده نمایید.");
            }
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');

            Logger::error($e->getMessage());

            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Failed to create order.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public static function cancelOrderWoo($invoice_id)
    {
        return self::updateOrderStatus($invoice_id, 'bslm-rejected', 'bslm-rejected');
    }

    public static function completeOrderWoo($invoice_id)
    {
        return self::updateOrderStatus($invoice_id, 'bslm-completed', 'bslm-completed');
    }

    public static function confirmOrderWoo($invoice_id)
    {
        return self::updateOrderStatus($invoice_id, 'bslm-preparation', 'bslm-preparation');
    }

    public static function shippedOrderWoo($invoice_id)
    {
        return self::updateOrderStatus($invoice_id, 'bslm-shipping', 'bslm-shipping');
    }

    public static function updateOrderStatus($invoice_id, $status, $job = null)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sync_basalam_payments';

        $order_id = $wpdb->get_var($wpdb->prepare("SELECT order_id FROM {$table_name} WHERE invoice_id = $invoice_id"));

        if (!$order_id) {
            Logger::error("سفارشی با این شناسه فاکتور پیدا نشد: $invoice_id . عملیات '$job' انجام نشد.");
            return false;
        }

        $order = wc_get_order($order_id);

        if ($order && $order instanceof \WC_Order) {
            $order->update_status($status);
            return $order_id;
        }

        return false;
    }

    public static function getPlaceholderProductId()
    {
        $placeholder_name = 'این محصول در سایت شما تعریف نشده است ، برای مشاهده جزییات به باسلام مراجعه کنید';
        $product_id = self::productExistsByTitle($placeholder_name);
        if (!$product_id) {
            $product = new \WC_Product_Simple();
            $product->set_name($placeholder_name);
            $product->set_status('draft');
            $product->set_sku('placeholder-basalam-product');
            $product->save();
            $product_id = $product->get_id();
        }

        return $product_id;
    }

    public static function getWooProductSimpleId($sync_basalam_product_id)
    {
        $product = get_posts([
            'post_type'      => 'product',
            'meta_key'       => 'sync_basalam_product_id',
            'meta_value'     => $sync_basalam_product_id,
            'posts_per_page' => 1,
        ]);

        return !empty($product) ? $product[0]->ID : null;
    }

    public static function getWooProductVariableId($sync_basalam_product_variant_id)
    {
        $args = [
            'post_type'      => 'product_variation',
            'posts_per_page' => 1,
            'meta_key'       => 'sync_basalam_variation_id',
            'meta_value'     => $sync_basalam_product_variant_id,
            'fields'         => 'ids',
        ];

        $variation = get_posts($args);

        return !empty($variation) ? $variation[0] : null;
    }

    public static function productExistsByTitle($title)
    {
        global $wpdb;
        $product_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status != 'private' AND post_title = %s LIMIT 1",
                $title
            )
        );

        return $product_id ? $product_id : false;
    }

    private static function set_item_price_from_financial_report($order, $order_item_id, $item, $quantity)
    {
        $product_price = 0;
        if (isset($item['financial_report']['report_items']) && is_array($item['financial_report']['report_items'])) {
            foreach ($item['financial_report']['report_items'] as $report_item) {
                if (isset($report_item['title']) && $report_item['title'] === 'قیمت محصول' && isset($report_item['amount'])) {
                    $product_price = (int) $report_item['amount'];
                    break;
                }
            }
        }

        if ($product_price > 0) {
            $currency = get_woocommerce_currency();
            if ($currency === 'IRT') {
                $product_price = $product_price / 10;
            } elseif ($currency === 'IRHT') {
                $product_price = $product_price / 10000;
            } elseif ($currency === 'IRHR') {
                $product_price = $product_price / 1000;
            }

            $order_item = $order->get_item($order_item_id);
            if ($order_item) {
                $order_item->set_subtotal($product_price);
                $order_item->set_total($product_price);
                $order_item->save();
            }
        }
    }

    private static function findShippingMethodInstanceId($method_id)
    {
        if (!class_exists('WC_Shipping_Zones')) {
            return null;
        }

        $shipping_zones = \WC_Shipping_Zones::get_zones();

        foreach ($shipping_zones as $zone) {
            $zone_id = $zone['id'] ?? 0;
            $shipping_zone = new \WC_Shipping_Zone($zone_id);
            $methods = $shipping_zone->get_shipping_methods(true);

            foreach ($methods as $method) {
                if ($method->id === $method_id) {
                    return $method->instance_id;
                }
            }
        }

        return null;
    }

    private static function getShippingMethodTitle($method_id, $instance_id)
    {
        if (!class_exists('WC_Shipping_Zones')) {
            return null;
        }

        $shipping_zones = \WC_Shipping_Zones::get_zones();

        foreach ($shipping_zones as $zone) {
            $zone_id = $zone['id'] ?? 0;
            $shipping_zone = new \WC_Shipping_Zone($zone_id);
            $methods = $shipping_zone->get_shipping_methods(true);

            foreach ($methods as $method) {
                if ($method->id === $method_id && $method->instance_id == $instance_id) {
                    return $method->get_title() ?: $method->get_method_title();
                }
            }
        }

        return null;
    }
}
