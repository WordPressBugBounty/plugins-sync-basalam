<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Admin_Get_Product_Data_Json
{
    private $vendor_id;
    private $token;
    private $currency;

    public function __construct()
    {
        $this->vendor_id = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::VENDOR_ID);
        $this->token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        $this->currency = get_woocommerce_currency();
    }

    public function build_product_data($product_id, $is_update, $category_ids)
    {
        $product = wc_get_product($product_id);
        $product_data = [];
        $description = $this->get_description($product);
        if (!$category_ids) {
            $product_title = mb_substr($product->get_name(), 0, 120);
            $category_ids = sync_basalam_Get_Category_id::get_category_id_from_basalam(urlencode($product_title), 'multi');
        }
        $category_id = isset($category_ids[0]) ? $category_ids[0] : null;

        if (!$category_id) {
            throw new \Exception('دسته بندی محصول یافت نشد.');
        }

        $stock_quantity = $this->get_stock_quantity($product);

        $product_name = $this->get_product_name($product);
        if ($is_update) {
            $sync_fields = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELDS);
            if ($sync_fields == 'price_stock') {
                $product_data['status'] = $this->get_stock_status($product);
                $product_data['stock'] = $stock_quantity;
                if (!$product->is_type('variable')) {
                    $price = $this->get_final_price($this->get_price($product), $category_ids);
                    if (!$price) {
                        throw new \Exception(' قیمت محصول ' . esc_html($product->get_name()) . ' ' . 'کمتر از 1000 تومان است.');
                    }
                    $product_data['primary_price'] = intval($price);
                }
                if ($product->is_type('variable')) {
                    $variants = $this->get_variants($product, $category_ids);
                    if (!$variants) {
                        throw new \Exception('دریافت متغیر ها با مشکل مواجه شد.');
                    }
                    $product_data['variants'] = $variants;
                }
                return $product_data;
            } elseif ($sync_fields == 'custom') {
                $sync_field_name = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELD_NAME);

                if ($sync_field_name == true) {
                    $product_data['name'] = $product_name;
                }
                $sync_field_photos = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELD_PHOTOS);
                if ($sync_field_photos == true) {
                    $photos = $this->get_product_photos($product);
                    $product_data['photo'] = $photos['main'];
                    $product_data['photos'] = $photos['gallery'];
                }

                $sync_field_price = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELD_PRICE);
                if ($sync_field_price == true) {
                    if (!$product->is_type('variable')) {
                        $price = $this->get_final_price($this->get_price($product), $category_ids);
                        if (!$price) {
                            throw new \Exception(' قیمت محصول ' . esc_html($product->get_name()) . ' ' . 'کمتر از 1000 تومان است.');
                        }
                        $product_data['primary_price'] = intval($price);
                    }
                    if ($product->is_type('variable')) {
                        $variants = $this->get_variants($product, $category_ids);
                        if (!$variants) {
                            throw new \Exception('دریافت متغیر ها با مشکل مواجه شد.');
                        }
                        $product_data['variants'] = $variants;
                    }
                }
                $sync_field_stock = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELD_STOCK);
                if ($sync_field_stock == true) {
                    $product_data['stock'] = $stock_quantity;
                }
                $sync_field_weight = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELD_WEIGHT);
                if ($sync_field_weight == true) {
                    $product_data['weight'] = $this->get_weight($product);
                    $product_data['package_weight'] = $this->get_package_weight($product_data['weight']);
                }
                $sync_field_description = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELD_DESCRIPTION);
                if ($sync_field_description  === "1") {
                    $product_data['description'] = $description;
                }
                $sync_field_attr = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELD_ATTR);
                if ($sync_field_attr == true) {
                    $mobile_attrs = [];
                    $woo_attrs = [];
                    $woo_attrs = $this->compare_attributes_with_Basalam($product, $category_id);

                    if ($this->is_mobile_product($product)) {
                        $mobile_attrs = $this->get_mobile_attributes($product);
                    }
                    if (!empty($woo_attrs) || !empty($mobile_attrs)) {
                        if (!empty($woo_attrs) && !empty($mobile_attrs)) {
                            $attrs = array_merge($woo_attrs, $mobile_attrs);
                        } elseif ($woo_attrs && !$mobile_attrs) {
                            $attrs = $woo_attrs;
                        } elseif (!$woo_attrs && $mobile_attrs) {
                            $attrs = $mobile_attrs;
                        }
                        $product_data['product_attribute'] = $attrs;
                    }
                }
                return $product_data;
            }
        }
        $attr = $this->compare_attributes_with_Basalam($product, $category_id);
        $photos = $this->get_product_photos($product);
        if (!$photos) {
            throw new \Exception('دریاف تصاویر محصول با خطا مواجه شد.');
        }

        $photo = $photos['main'];
        $gallery_photos = $photos['gallery'];
        $product_data['name'] = $product_name;
        $product_data['photo'] = $photo;
        $product_data['photos'] = $gallery_photos;
        $product_data['status'] = $this->get_stock_status($product);
        $product_data['stock'] = $stock_quantity;
        $product_data['product_attribute'] = $attr;
        if (!$is_update) {
            $product_data['category_id'] = $category_id;
        }
        $product_data['description'] = $description;
        $product_data['weight'] = $this->get_weight($product);
        $product_data['package_weight'] = $this->get_package_weight($product_data['weight']);
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
            $product_data['variants'] = [];
        }

        if ($this->is_mobile_product($product)) {
            $product_data['product_attribute'] = $this->get_mobile_attributes($product);
        }
        if ($this->is_product_type($product)) {
            $unit_type = get_post_meta($product->get_id(), '_sync_basalam_product_unit', true);

            if ($unit_type != 'none' && is_numeric($unit_type)) {
                $product_data['unit_type'] = $unit_type;
                $product_data['unit_quantity'] = get_post_meta($product->get_id(), '_sync_basalam_product_value', true);
                if (!is_numeric($product_data['unit_quantity'])) {
                    $product_data['unit_quantity'] = 1;
                }
            } else {
                $product_data['unit_type'] = 6304;
                $product_data['unit_quantity'] = 1;
            }
        } else {
            $product_data['unit_type'] = 6304;
            $product_data['unit_quantity'] = 1;
        }
        if (sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::ALL_PRODUCTS_WHOLESALE) == 'all') {
            $product_data['is_wholesale'] = true;
        } else {
            if ($this->is_wholesale($product)) {
                $product_data['is_wholesale'] = true;
            } else {
                $product_data['is_wholesale'] = false;
            }
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

    private function get_product_photos($product)
    {
        $photoFiles = [];
        $photos = [];
        $photoFiles = [];

        $photoFiles[] = [
            'id'   => $product->get_image_id(),
            'file_path' => get_attached_file($product->get_image_id())
        ];

        foreach ($product->get_gallery_image_ids() as $photo_id) {
            $photoFiles[] = [
                'id'   => $photo_id,
                'file_path' => get_attached_file($photo_id)
            ];
        }

        foreach ($photoFiles as $photoFile) {
            if ($photoFile) {
                $check_exist = $this->check_exsit_photo_in_db($photoFile['id']);
                if (!$check_exist) {
                    $uploaded = sync_basalam_Upload_File::upload($photoFile['file_path']);
                    if ($uploaded) {
                        $photos[] = $uploaded;
                        $this->save_photo_in_db($photoFile['id'], $uploaded);
                    }
                } else {
                    $photos[] = $check_exist;
                }
            }
        }
        $image_checker = new sync_basalam_Check_Photos_Ban_status();
        $images = $image_checker->check_ban_status($photos);
        $main_photo_id = null;
        $gallery_photo_ids = [];

        foreach ($images['valid'] as $image) {
            if (is_null($main_photo_id)) {
                $main_photo_id = $image['file_id'];
            } else {
                $gallery_photo_ids[] = $image['file_id'];
            }
        }

        if (is_null($main_photo_id)) {
            throw new \Exception('محصول دارای هیچ عکسی نیست ، تنها محصولات دارای عکس در باسلام ثبت میشوند');
        }
        if (count($images['not_valid']) > 0) {
            sync_basalam_Logger::warning('برخی از تصاویر محصول طبق قوانین باسلام غیرمجاز هستند و از محصول باسلام حذف شدند.', ['product_id' => $product->get_Id()]);
        }

        return [
            'main' => $main_photo_id,
            'gallery' => $gallery_photo_ids
        ];
    }

    private function save_photo_in_db($woo_photo_id, $sync_basalam_photo)
    {
        global $wpdb;
        $table_name_uploaded_photo = $wpdb->prefix . 'sync_basalam_uploaded_photo';

        $wpdb->insert(
            $table_name_uploaded_photo,
            array(
                'woo_photo_id'  => $woo_photo_id,
                'sync_basalam_photo_id' => $sync_basalam_photo['file_id'],
                'sync_basalam_photo_url' => $sync_basalam_photo['url'],
            ),
            array(
                '%d',
                '%d',
                '%s'
            )
        );
    }

    private function check_exsit_photo_in_db($woo_photo_id)
    {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT sync_basalam_photo_id AS file_id, sync_basalam_photo_url AS url FROM wp_sync_basalam_uploaded_photo WHERE woo_photo_id = %d",
                $woo_photo_id
            )
        );

        return !empty($results) ? $results[0] : null;
    }

    private function get_price($product)
    {
        $price_field = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::PRODUCT_PRICE_FIELD);

        $regular_price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();

        if ($price_field == 'original_price') {
            if ($this->get_final_price($regular_price)) {
                return $regular_price;
            }
        } else {
            if ($sale_price) {
                if ($this->get_final_price($sale_price)) {
                    return $sale_price;
                }
            }
            if ($this->get_final_price($regular_price)) {
                return $regular_price;
            }
        }
        return null;
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

        if ($increase_value == -1) {
            $category_data = sync_basalam_Get_Commission::get_commission_basalam($category_ids);

            if (is_array($category_data)) {
                list($category_percent, $max_amount) = $category_data;
            } else {
                $category_percent = $category_data;
                $max_amount = null;
            }

            $calculated_increase = $converted_price * ($category_percent / 100);

            if ($max_amount !== null && $calculated_increase > $max_amount) {
                $finalPrice = $converted_price + $max_amount;
            } else {
                $finalPrice = $converted_price + $calculated_increase;
            }
        } elseif ($increase_value <= 100) {
            $finalPrice = $converted_price + ($converted_price * ($increase_value / 100));
        } else {
            $finalPrice = $converted_price + $increase_value;
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

    private function get_weight($product)
    {
        if (empty($product->get_weight())) {
            return sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::DEFAULT_WEIGHT);
        }
        $weight = $product->get_weight();
        $weight = str_replace(',', '.', $weight);
        $weight_unit = get_option('woocommerce_weight_unit');
        return ($weight_unit === 'kg') ? floatval($weight) * 1000 : floatval($weight);
    }

    private function get_package_weight($weight)
    {
        $package_weight =  sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::DEFAULT_PACKAGE_WEIGHT);
        return intval($weight + $package_weight);
    }

    private function get_variants($product, $category_ids)
    {
        $variants = [];
        $available_variations = $product->get_available_variations();
        $price_field = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::PRODUCT_PRICE_FIELD);

        foreach ($available_variations as $variation) {
            $variant_product = wc_get_product($variation['variation_id']);

            $regular_price = $variant_product->get_regular_price();
            $sale_price = $variant_product->get_sale_price();

            if ($price_field === 'original_price') {
                $variant_price = $this->get_final_price($regular_price, $category_ids);
            } else {
                if ($sale_price && $this->get_final_price($sale_price, $category_ids)) {
                    $variant_price = $this->get_final_price($sale_price, $category_ids);
                } else {
                    $variant_price = $this->get_final_price($regular_price, $category_ids);
                }
            }

            if (!$variant_price) {
                throw new \Exception('قیمت محصول ' . esc_html($product->get_name()) . ' کمتر از ۱۰۰۰ تومان است.');
            }

            $attributes = [];

            foreach ($variation['attributes'] as $attribute_name => $attribute_value) {
                $taxonomy_name = str_replace('attribute_', '', $attribute_name);
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
                    'value' => str_replace('-', ' ', mb_convert_encoding($value_name, 'UTF-8', 'auto')),
                ];
            }

            $variants[] = [
                'primary_price' => $variant_price,
                'stock' => $this->get_stock_quantity($variant_product),
                'properties' => $attributes,
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

    private function is_product_type($product)
    {
        return get_post_meta($product->get_id(), '_sync_basalam_is_product_type_checkbox', true) === 'yes';
    }

    private function is_wholesale($product)
    {
        return get_post_meta($product->get_id(), '_sync_basalam_is_wholesale', true) === 'yes';
    }
    
    private function get_description($product)
    {
        $add_attrs_to_desc = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::ADD_ATTR_TO_DESC_PRODUCT);
        $add_short_desc_to_desc = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::ADD_SHORT_DESC_TO_DESC_PRODUCT);

        $description_parts = [];

        if ($add_short_desc_to_desc == 'yes') {
            $short_desc = $product->get_short_description();
            $short_desc_clean = sync_basalam_Text_CLeaner::convert_html_to_plain_text($short_desc);

            if (!empty($short_desc_clean)) {
                $description_parts[] = trim($short_desc_clean);
            }
        }

        $main_desc = $product->get_description();
        $main_desc_clean = sync_basalam_Text_CLeaner::convert_html_to_plain_text($main_desc);
        if (!empty($main_desc_clean)) {
            $description_parts[] = trim($main_desc_clean);
        }

        if ($add_attrs_to_desc == 'yes') {
            $attrs = $this->get_woo_attributes($product);

            if (!empty($attrs) && is_array($attrs)) {
                $attrs_text = [];

                foreach ($attrs as $attr) {
                    if (!empty($attr['title']) && !empty($attr['value'])) {
                        $attrs_text[] = trim($attr['title']) . ' : ' . trim($attr['value']);
                    }
                }

                if (!empty($attrs_text)) {
                    $description_parts[] = implode("\n", $attrs_text);
                }
            }
        }

        $full_description = implode("\n\n", $description_parts);

        return mb_substr($full_description, 0, 5000);
    }
}
