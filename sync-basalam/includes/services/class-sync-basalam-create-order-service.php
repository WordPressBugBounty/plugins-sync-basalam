<?php
defined('ABSPATH') || exit;

class Sync_basalam_Create_Order_Service
{

    private $apiservice;
    public function __construct()
    {
        $this->apiservice = new sync_basalam_External_API_Service;
    }

    public static function create_order_in_woo(WP_REST_Request $request, $check_sync_status = true)
    {
        if ($check_sync_status) {
            $sync_status_order = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::SYNC_STATUS_ORDER);
            if (!$sync_status_order) {
                return;
            }
        }
        $parsed_params = $request->get_params();
        $payment_id = $parsed_params['payment_id'] ?? null;
        $invoice_id = $parsed_params['invoice_id'] ?? null;
        $user_id = $parsed_params['user_id'] ?? null;
        $city_id = $parsed_params['city_id'] ?? null;
        $province_id = $parsed_params['province_id'] ?? null;

        global $wpdb;
        $table_name = $wpdb->prefix . 'sync_basalam_payments';

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE invoice_id = %d",
                $invoice_id
            )
        );
        if ($existing) {
            sync_basalam_Logger::error("سفارش با شناسه فاکتور $province_id قبلا ایجاد شده");
            return false;
        }

        $result = $wpdb->insert(
            $table_name,
            array(
                'payment_id' => $payment_id,
                'invoice_id' => $invoice_id,
                'user_id' => $user_id,
                'city_id' => $city_id,
                'province_id' => $province_id,
            ),
            array('%d', '%d', '%d', '%d', '%d')
        );

        if (!$result) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to insert payment data.',
                'error' => $wpdb->last_error,
            ), 500);
        }

        $token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        $vendor_id = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::VENDOR_ID);

        sleep(5);

        $api_url = "https://order-processing.basalam.com/v2/vendors/$vendor_id/orders/$invoice_id";

        $response = wp_remote_get(
            $api_url,
            array(
                'headers' => array(
                    'Authorization' => "Bearer {$token}",
                    'user-agent' => 'Wp-Basalam',
                ),
            )
        );

        if (is_wp_error($response)) {
            ("API Error: " . $response->get_error_message());
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to fetch invoice details.',
                'error' => $response->get_error_message(),
            ), 500);
        }

        $api_response = wp_remote_retrieve_body($response);
        $data = json_decode($api_response, true);

        if (!class_exists('WooCommerce')) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'WooCommerce is not active.',
            ), 500);
        }

        try {
            $order = wc_create_order();

            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    $sync_basalam_product_id = $item['product']['id'] ?? null;
                    $quantity = $item['quantity'] ?? 1;
                    $price = $item['price'] ?? 0;
                    $item_id = $item['id'] ?? null;

                    if ($sync_basalam_product_id) {
                        try {
                            if (!empty($item['variation']) && !empty($item['variation']['id'])) {
                                $variation_id = self::get_woo_product_variable_id($item['variation']['id']);
                                $woo_product_id = $variation_id;
                            } else {
                                $woo_product_id = self::get_woo_product_simple_id($sync_basalam_product_id);
                            }

                            if ($woo_product_id) {
                                $product = wc_get_product($woo_product_id);
                                if ($product) {
                                    $order_item_id = $order->add_product($product, $quantity);

                                    $stock_quantity = $product->get_stock_quantity();
                                    if ($stock_quantity !== null) {
                                        $new_stock = max(0, $stock_quantity - $quantity);
                                        $product->set_stock_quantity($new_stock);
                                        $product->save();
                                    }

                                    if ($item_id && $order_item_id) {
                                        $order->update_meta_data('_sync_basalam_item_id_' . $order_item_id, $item_id);
                                    }
                                }
                            } else {
                                $placeholder_product_id = self::get_placeholder_product_id();
                                if ($placeholder_product_id) {
                                    $placeholder_product = wc_get_product($placeholder_product_id);
                                    if ($placeholder_product) {
                                        $order_item_id = $order->add_product($placeholder_product, $quantity);

                                        if ($item_id && $order_item_id) {
                                            $order->update_meta_data('_sync_basalam_item_id_' . $order_item_id, $item_id);
                                        }
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            sync_basalam_Logger::error('خطا در ایجاد سفارش: ' . $e->getMessage());
                        }
                    }
                }
            }

            // Set address from new response structure
            if (isset($data['customer_data']['recipient']) && is_array($data['customer_data']['recipient'])) {
                $recipient = $data['customer_data']['recipient'];
                $province = $data['customer_data']['city']['parent']['title'] ?? '';
                $city = $data['customer_data']['city']['title'] ?? '';

                $stateCode = Sync_Basalam_Iran_Provinces_Code::getCodeByName($province);

                if ($stateCode) {
                    $order->set_billing_state($stateCode);
                    $order->set_shipping_state($stateCode);
                }
                $full_name = $recipient['name'] ?? '';
                if (!empty($full_name)) {
                    $patrs_of_full_name = explode(' ', $full_name);
                    $first_name = array_shift($patrs_of_full_name);
                    $last_name = implode(' ', $patrs_of_full_name);
                }
                $order->set_billing_first_name($first_name);
                $order->set_billing_last_name($last_name);
                $order->set_billing_address_1($recipient['postal_address'] ?? '');
                $order->set_billing_city($city);
                $order->set_billing_postcode($recipient['postal_code'] ?? '');
                $order->set_billing_country('IR');
                $order->set_billing_phone($recipient['mobile'] ?? '');

                $order->set_shipping_first_name($first_name);
                $order->set_shipping_last_name($last_name);
                $order->set_shipping_address_1($recipient['postal_address'] ?? '');
                $order->set_shipping_city($city);
                $order->set_shipping_postcode($recipient['postal_code'] ?? '');
                $order->set_shipping_phone($recipient['mobile'] ?? '');
                $order->set_shipping_country('IR');

                $default_method = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::ORDER_SHIPPING_METHOD);
                $default_method = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::ORDER_SHIPPING_METHOD);

                if ($default_method && $default_method !== 'false') {
                    $methods = (new sync_basalam_Get_Shipping_Methods)->get_woo_shipping_methods();

                    $shipping_cost = isset($data['parcel_detail']['shipping_cost']) ? $data['parcel_detail']['shipping_cost'] : 0;

                    if (get_woocommerce_currency() === 'IRT') {
                        $shipping_cost = $shipping_cost / 10;
                    }

                    foreach ($methods as $method) {
                        if ($method['method_id'] === $default_method) {
                            $shipping_item = new WC_Order_Item_Shipping();
                            $shipping_item->set_method_title($method['method_title']);
                            $shipping_item->set_method_id($method['method_id']);
                            $shipping_item->set_total(floatval($shipping_cost));
                            $shipping_item->set_taxes(array());

                            $order->add_item($shipping_item);
                            break;
                        }
                    }

                    $order->save();
                }
            }

            if (isset($data['parcel_detail']['shipping_method']['title'])) {
                $order->add_order_note("روش ارسال این سفارش : " . $data['parcel_detail']['shipping_method']['title']);
            }

            $total_price = 0;

            if (isset($data['financial_report']['product_cost']['report_items'][0]['amount'])) {
                $total_price += $data['financial_report']['product_cost']['report_items'][0]['amount'];
            }

            if (isset($data['financial_report']['shipping_cost']['total']['amount'])) {
                $total_price += $data['financial_report']['shipping_cost']['total']['amount'];
            }

            if ($total_price > 0) {
                if (get_woocommerce_currency() === 'IRT') {
                    $total_price = $total_price / 10;
                }

                $order->set_total($total_price);
            }

            $order->set_payment_method('basalam payment method');
            $order->set_payment_method_title('Basalam Payment');
            $order_status_type = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::ORDER_STATUES_TYPE);

            $status_map = [
                3067 => 'bslm-rejected',
                3739 => 'bslm-wait-vendor',
                3237 => 'bslm-preparation',
                3238 => 'bslm-shipping',
                3195 => 'bslm-completed',
                3233 => 'bslm-rejected'
            ];

            $status_id = $data['status']['id'] ?? null;

            if ($order_status_type == 'woocommerce_statuses') {
                $order->set_status('processing');
            } else {

                $order_status = $status_map[$status_id] ?? 'bslm-wait-vendor';

                $order->set_status($order_status);
            }

            $order->update_meta_data('_is_sync_basalam_order', true);
            $order->save();

            $order_id = $order->get_id();
            $wpdb->update(
                $table_name,
                array('order_id' => $order_id),
                array('invoice_id' => $invoice_id),
                array('%d'),
                array('%d')
            );
            update_post_meta($order_id, '_is_sync_basalam_order', true);

            $auto_confirm = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::AUTO_CONFIRM_ORDER);
            if ($auto_confirm && $status_id == 3739) {
                self::auto_confirm_order($order_id);
            }

            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Order created successfully',
                'order_id' => $order_id
            ), 200);
        } catch (Exception $e) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to create order.',
                'error' => $e->getMessage(),
            ), 500);
        }
    }
    static function get_placeholder_product_id()
    {
        $placeholder_name = 'این محصول در سایت شما تعریف نشده است ، برای مشاهده جزییات به باسلام مراجعه کنید';
        $product_id = self::product_exists_by_title($placeholder_name);
        if (!$product_id) {
            $product = new WC_Product_Simple();
            $product->set_name($placeholder_name);
            $product->set_status('draft');
            $product->set_sku('placeholder-basalam-product');
            $product->save();
            $product_id = $product->get_id();
        }
        return $product_id;
    }

    static function get_woo_product_simple_id($sync_basalam_product_id)
    {
        $product = get_posts(array(
            'post_type' => 'product',
            'meta_key' => 'sync_basalam_product_id',
            'meta_value' => $sync_basalam_product_id,
            'posts_per_page' => 1
        ));
        return !empty($product) ? $product[0]->ID : null;
    }
    public static function auto_confirm_order($order_id)
    {
        $orderManager = new Sync_Basalam_Confirm_Order_Service();
        $result = $orderManager->confirm_order_automatically($order_id);

        if (is_wp_error($result)) {
            sync_basalam_Logger::error('فرایند تایید اتوماتیک سفارش ناموفق بود: ' . $result->get_error_message());
        }
    }

    static function get_woo_product_variable_id($sync_basalam_product_variant_id)
    {
        $args = array(
            'post_type'      => 'product_variation',
            'posts_per_page' => 1,
            'meta_key'       => 'sync_basalam_variation_id',
            'meta_value'     => $sync_basalam_product_variant_id,
            'fields'         => 'ids',
        );

        $variation = get_posts($args);

        return !empty($variation) ? $variation[0] : null;
    }


    static function product_exists_by_title($title)
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
}
