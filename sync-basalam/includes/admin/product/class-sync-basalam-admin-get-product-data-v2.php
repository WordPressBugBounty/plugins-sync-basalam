<?php
if (! defined('ABSPATH')) exit;

class SyncBasalamAdminGetProductDataV2
{
    private $currency;

    public function __construct()
    {
        $this->currency = get_woocommerce_currency();
    }

    public function build_product_data($product_id)
    {
        $product_data = [];
        $basalam_product_id = get_post_meta($product_id, 'sync_basalam_product_id', true);

        if (!$basalam_product_id)  return false;

        $product = wc_get_product($product_id);

        $product_name = $this->get_product_name($product);
        $stock_quantity = $this->get_stock_quantity($product);

        $mapped_category_id = $this->get_mapped_category($product_id);

        if ($mapped_category_id) {
            $category_ids = [$mapped_category_id];
        } else {
            $product_title = mb_substr($product->get_name(), 0, 120);
            $category_ids = sync_basalam_Get_Category_id::get_category_id_from_basalam(urlencode($product_title), 'multi');
        }

        $product_data['id'] = $basalam_product_id;
        $product_data['status'] = $this->get_stock_status($product);
        $product_data['preparation_days'] = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::DEFAULT_PREPARATION);

        if (!$product->is_type('variable')) {
            $price = $this->get_final_price($this->get_price($product), $category_ids);
            if (!$price) {
                throw new \Exception(' قیمت محصول ' . esc_html($product->get_name()) . ' ' . 'کمتر از 1000 تومان است.');
            }
            $product_data['primary_price'] = intval($price);
        }

        if ($product->is_type('variable')) {
            $variants = $this->get_variants($product, $category_ids);
            $product_data['variants'] = $variants;
        } else {
            $product_data['stock'] = $stock_quantity;
        }

        if ($this->is_mobile_product($product)) {
            $product_data['product_attribute'] = $this->get_mobile_attributes($product);
        }

        return $product_data;
    }

    private function get_product_name($product)
    {
        $product_name = $product->get_name();

        $prefix_title = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::PRODUCT_PREFIX_TITLE);
        $suffix_title = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::PRODUCT_SUFFIX_TITLE);

        if ($prefix_title) {
            $product_name = "{$prefix_title} {$product_name}";
        }

        if ($suffix_title) {
            $product_name = "{$product_name} {$suffix_title}";
        }
        $word_count = count(explode(' ', trim($product_name)));

        if (mb_strlen($product_name) < 6 || $word_count < 2) {
            $product_name .= " ویژه";
        }

        $product_name = mb_substr($product_name, 0, 120);
        return $product_name;
    }

    private function get_price($product)
    {
        $price_field = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::PRODUCT_PRICE_FIELD);

        $regular_price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();


        $regular_price = !empty($regular_price) && is_numeric($regular_price) ? $regular_price : null;
        $sale_price = !empty($sale_price) && is_numeric($sale_price) ? $sale_price : null;

        if ($price_field == 'original_price' || $price_field == 'sale_strikethrough_price') {
            if ($regular_price !== null && $this->get_final_price($regular_price)) {
                return $regular_price;
            }
        } elseif ($price_field == 'sale_price') {
            if ($sale_price) {
                if ($this->get_final_price($sale_price)) {
                    return $sale_price;
                }
            }
            if ($this->get_final_price($regular_price)) {
                return $regular_price;
            }
            return null;
        }
    }

    private function get_final_price($price, $category_ids = null)
    {
        $increase_value = intval(sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::INCREASE_PRICE_VALUE));
        $round_mode = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::ROUND_PRICE);
        $currency = $this->currency;
        $converted_price = $this->convert_price_to_rial($price, $currency);

        if (empty($converted_price) || !is_numeric($converted_price)) {
            return false;
        }


        $converted_price = floatval($converted_price);

        if ($increase_value == -1) {
            $category_data = sync_basalam_Get_Commission::get_commission_basalam($category_ids);

            if (is_array($category_data)) {
                list($category_percent, $max_amount) = $category_data;
            } else {
                $category_percent = $category_data;
                $max_amount = null;
            }

            $category_percent = floatval($category_percent);
            $calculated_increase = $converted_price * ($category_percent / 100);

            if ($max_amount !== null && $calculated_increase > $max_amount) {
                $finalPrice = $converted_price + floatval($max_amount);
            } else {
                $finalPrice = $converted_price + $calculated_increase;
            }
        } elseif ($increase_value <= 100) {
            $finalPrice = $converted_price + ($converted_price * ($increase_value / 100));
        } else {
            $finalPrice = $converted_price + ($increase_value * 10);
        }

        if ($round_mode === 'up') {
            return ceil($finalPrice / 10000) * 10000;
        } elseif ($round_mode === 'down') {
            return floor($finalPrice / 10000) * 10000;
        } else {
            return $finalPrice;
        }
    }

    private function convert_price_to_rial($price, $currency)
    {

        if (empty($price) || !is_numeric($price)) {
            return 0;
        }

        $price = floatval($price);

        if ($currency == 'IRT') {
            return $price * 10;
        } elseif ($currency == 'IRHT') {
            return $price * 10000;
        } elseif ($currency == 'IRHR') {
            return $price * 1000;
        }
        return $price;
    }

    function get_woo_attributes($product)
    {
        $attributes = [];
        $product_attrs = $product->get_attributes();

        if (is_array($product_attrs) & !empty($product_attrs)) {
            foreach ($product_attrs as $attribute) {
                if ($attribute->is_taxonomy()) {
                    $taxonomy = $attribute->get_name();
                    $label = wc_attribute_label($taxonomy);

                    $terms = wc_get_product_terms($product->get_id(), $taxonomy, ['fields' => 'names']);
                    $value = implode(', ', $terms);

                    $attributes[] = [
                        'title' => $label,
                        'value' => $value,
                    ];
                } else {
                    $label = wc_attribute_label($attribute->get_name());
                    $value = $attribute->get_options();
                    $value = implode(', ', $value);

                    $attributes[] = [
                        'title' => $label,
                        'value' => $value,
                    ];
                }
            }
        }

        return $attributes;
    }

    function compare_attributes_with_Basalam($product, $category_id)
    {
        $woo_attrs = $this->get_woo_attributes($product);
        $maped_attrs = $this->get_mapped_category_option();
        if (!$woo_attrs) {
            return null;
        }
        if ($maped_attrs) {
            foreach ($woo_attrs as &$woo_attr) {
                foreach ($maped_attrs as $maped_attr) {
                    if (trim($maped_attr['woo_name']) == trim($woo_attr['title'])) {
                        $woo_attr['title'] = $maped_attr['sync_basalam_name'];
                        break;
                    }
                }
            }
            unset($woo_attr);
        }
        $response = sync_basalam_Get_Category_Attr::get_attr($category_id);
        $sync_basalam_attrs = [];
        foreach ($response['data'] as $group) {
            foreach ($group['attributes'] as $attr) {
                $sync_basalam_attrs[] = [
                    'id' => $attr['id'],
                    'title' => $attr['title']
                ];
            }
        }
        $matched_attrs = [];

        foreach ($woo_attrs as $woo_attr) {
            foreach ($sync_basalam_attrs as $sync_basalam_attr) {
                if (trim($woo_attr['title']) === trim($sync_basalam_attr['title'])) {
                    $matched_attrs[] = [
                        'attribute_id' => $sync_basalam_attr['id'],
                        'value' => $woo_attr['value'],
                    ];
                    break;
                }
            }
        }

        return ($matched_attrs);
    }

    function get_mapped_category_option()
    {
        global $wpdb;
        $categoryOptionsManager = new sync_basalam_Manage_Category_Options($wpdb);
        $category_map_options = $categoryOptionsManager->get_all();
        return $category_map_options;
    }

    private function get_stock_quantity($product)
    {
        $defualt_stock_quantity = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::DEFAULT_STOCK_QUANTITY);
        $stock_quantity = $product->get_stock_quantity();
        $stock_status = $product->get_stock_status();
        if ($stock_status == 'instock' && $product->get_status() === 'publish') {
            return $stock_quantity === null ? $defualt_stock_quantity : $stock_quantity;
        } else {
            return 0;
        }
    }

    private function get_stock_status($product)
    {
        return 2976;
    }

    private function get_variants($product, $category_ids)
    {
        $variants = [];
        $variation_ids = $product->get_children();

        $price_field = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::PRODUCT_PRICE_FIELD);

        foreach ($variation_ids as $variation_id) {
            $variant_product = wc_get_product($variation_id);
            $basalam_variant_id = get_post_meta($variation_id, 'sync_basalam_variation_id', true);
            if (!$variant_product || !$basalam_variant_id) {
                continue;
            }

            $regular_price = $variant_product->get_regular_price();
            $sale_price    = $variant_product->get_sale_price();

            $regular_price = !empty($regular_price) && is_numeric($regular_price) ? $regular_price : null;
            $sale_price = !empty($sale_price) && is_numeric($sale_price) ? $sale_price : null;

            if ($price_field === 'original_price' || $price_field == 'sale_strikethrough_price') {
                $variant_price = $regular_price !== null ? $this->get_final_price($regular_price, $category_ids) : false;
            } elseif ($price_field == 'sale_price') {
                if ($sale_price && $this->get_final_price($sale_price, $category_ids)) {
                    $variant_price = $this->get_final_price($sale_price, $category_ids);
                } else {
                    $variant_price = $this->get_final_price($regular_price, $category_ids);
                }
            }

            if (!$variant_price) {

                continue;
            }

            $attributes = [];

            $variation_data = $variant_product->get_variation_attributes();
            foreach ($variation_data as $attribute_name => $attribute_value) {
                $taxonomy_name   = str_replace('attribute_', '', $attribute_name);
                $attribute_label = str_replace(['pa_', '-'], ' ', wc_attribute_label($taxonomy_name, $product));

                $value_name = rawurldecode($attribute_value);
                if (taxonomy_exists($taxonomy_name)) {
                    $term = get_term_by('slug', $attribute_value, $taxonomy_name);
                    if ($term && !is_wp_error($term)) {
                        $value_name = $term->name;
                    }
                }

                $attributes[] = [
                    'property' => $attribute_label,
                    'value'    => str_replace('-', ' ', mb_convert_encoding($value_name, 'UTF-8', 'auto')),
                ];
            }

            $variants[] = [
                'id' => $basalam_variant_id,
                'primary_price' => $variant_price,
                'stock'         => $this->get_stock_quantity($variant_product),
            ];
        }

        return $variants;
    }

    private function is_mobile_product($product)
    {
        return get_post_meta($product->get_id(), '_sync_basalam_is_mobile_product_checkbox', true) === 'yes';
    }

    private function get_mobile_attributes($product)
    {
        return [
            [
                "attribute_id" => 1707,
                "value" => get_post_meta($product->get_id(), '_sync_basalam_mobile_storage', true),
            ],
            [
                "attribute_id" => 1708,
                "value" => get_post_meta($product->get_id(), '_sync_basalam_cpu_type', true),
            ],
            [
                "attribute_id" => 1709,
                "value" => get_post_meta($product->get_id(), '_sync_basalam_mobile_ram', true),
            ],
            [
                "attribute_id" => 1710,
                "value" => get_post_meta($product->get_id(), '_sync_basalam_screen_size', true),
            ],
            [
                "attribute_id" => 1711,
                "value" => get_post_meta($product->get_id(), '_sync_basalam_rear_camera', true),
            ],
            [
                "attribute_id" => 1712,
                "value" => get_post_meta($product->get_id(), '_sync_basalam_battery_capacity', true),
            ],
        ];
    }

    private function get_mapped_category($product_id)
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }

        $woo_categories = $product->get_category_ids();

        if (empty($woo_categories)) {
            return false;
        }

        foreach ($woo_categories as $woo_category_id) {
            $mapped_category = Sync_Basalam_Category_Mapping::get_basalam_category_for_woo_category($woo_category_id);

            if ($mapped_category && isset($mapped_category->basalam_category_id)) {
                sync_basalam_Logger::info(
                    'استفاده از اتصال دسته‌بندی',
                    [
                        'product_id' => $product_id,
                        'woo_category_id' => $woo_category_id,
                        'basalam_category_id' => $mapped_category->basalam_category_id,
                        'basalam_category_name' => $mapped_category->basalam_category_name
                    ]
                );

                return $mapped_category->basalam_category_id;
            }
        }

        return false;
    }
}
