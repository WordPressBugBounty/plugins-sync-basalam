<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Product_Queue_Manager
{

    public static function create_all_products_in_basalam($include_out_of_stock = false)
    {

        $job_manager = new SyncBasalamJobManager();

        $existing_job = $job_manager->get_job([
            'job_type' => 'sync_basalam_create_all_products',
            'status' => 'pending'
        ]);

        if ($existing_job) {
            return [
                'success' => false,
                'message' => 'در حال حاضر یک عملیات در صف انتظار است.',
                'status_code' => 409
            ];
        }


        delete_option('last_offset_create_products');


        $initial_data = json_encode([
            'offset' => 0,
            'include_out_of_stock' => $include_out_of_stock
        ]);

        $job_manager->create_job(
            'sync_basalam_create_all_products',
            'pending',
            $initial_data
        );

        return [
            'success' => true,
            'message' => 'محصولات با موفقیت به صف ایجاد افزوده شدند.',
            'status_code' => 200
        ];
    }

    public static function create_specific_products_in_basalam($product_ids)
    {
        if (is_array($product_ids)) {

            $operation_type = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::PRODUCT_OPERATION_TYPE);

            foreach ($product_ids as $product_id) {
                $basalam_product_id = get_post_meta($product_id, 'sync_basalam_product_id', true);
                if (empty($basalam_product_id)) {
                    update_post_meta($product_id, 'sync_basalam_product_sync_status', 'pending');
                    if ($operation_type === 'immediate') {
                        try {
                            $class = new sync_basalam_Create_Product_wp_bg_proccess_Task();
                            $class->push(['type' => 'create_product', 'id' => $product_id]);
                            $class->save();
                        } catch (\Throwable $th) {
                            update_post_meta($product_id, 'sync_basalam_product_sync_status', 'no');
                            sync_basalam_Logger::error("خطا در ایجاد محصول فوری: " . $th->getMessage(), [
                                'product_id' => $product_id,
                                'عملیات' => 'ایجاد فوری محصولات انتخابی',
                            ]);
                        }
                    } else {
                        $job_manager = new SyncBasalamJobManager();
                        $job_manager->create_job(
                            'sync_basalam_create_single_product',
                            'pending',
                            $product_id,
                        );
                    }
                }
            }
            if ($operation_type === 'immediate') {
                $class->dispatch();
            }
        }
    }

    public static function get_products_for_creation($args)
    {
        $posts_per_page = $args['posts_per_page'] ?? 100;
        $offset = $args['offset'] ?? 0;
        $include_out_of_stock = $args['include_out_of_stock'] ?? false;

        $meta_query = [
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key'     => 'sync_basalam_product_id',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_price',
                    'compare' => 'EXISTS',
                ],
                [
                    'key'     => '_price',
                    'value'   => 1000,
                    'type'    => 'NUMERIC',
                    'compare' => '>=',
                ],
                [
                    'key'     => '_downloadable',
                    'value'   => 'yes',
                    'compare' => '!=',
                ],
                [
                    'key'     => '_virtual',
                    'value'   => 'yes',
                    'compare' => '!=',
                ],
                [
                    'relation' => 'OR',
                    [
                        'key'     => '_thumbnail_id',
                        'compare' => 'EXISTS',
                    ],
                    [
                        'key'     => '_product_image_gallery',
                        'value'   => '',
                        'compare' => '!=',
                    ],
                ],
            ],
        ];

        if (!$include_out_of_stock) {
            $meta_query[] = [
                'key'     => '_stock_status',
                'value'   => 'instock',
                'compare' => '=',
            ];
        }

        $product_ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $posts_per_page,
            'offset'         => $offset,
            'fields'         => 'ids',
            'meta_query'     => $meta_query,
        ]);

        $product_ids = array_filter($product_ids, function ($id) {
            return get_post_type($id) === 'product';
        });

        return array_values($product_ids);
    }

    public static function update_all_products_in_basalam()
    {
        $class = new Sync_basalam_Update_Products_wp_bg_proccess_Task();
        $class->push(null);
        $class->save();
        $class->dispatch();

        return [
            'success' => true,
            'message' => 'محصولات با موفقیت به صف بروزرسانی افزوده شدند.',
            'status_code' => 200
        ];
    }

    public static function update_specific_products_in_basalam($product_ids)
    {
        if (is_array($product_ids)) {
            $operation_type = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::PRODUCT_OPERATION_TYPE);

            foreach ($product_ids as $product_id) {
                $basalam_product_id = get_post_meta($product_id, 'sync_basalam_product_id', true);

                if (!empty($basalam_product_id)) {
                    update_post_meta($product_id, 'sync_basalam_product_sync_status', 'pending');
                    if ($operation_type === 'immediate') {
                        try {
                            $class = new sync_basalam_Update_Product_wp_bg_proccess_Task();
                            $class->push(['type' => 'update_product', 'id' => $product_id]);
                            $class->save();
                        } catch (\Throwable $th) {
                            update_post_meta($product_id, 'sync_basalam_product_sync_status', 'no');
                            sync_basalam_Logger::error("خطا در بروزرسانی محصول فوری: " . $th->getMessage(), [
                                'product_id' => $product_id,
                                'عملیات' => 'بروزرسانی فوری محصولات انتخابی',
                            ]);
                        }
                    } else {
                        $job_manager = new SyncBasalamJobManager();
                        $job_manager->create_job(
                            'sync_basalam_update_single_product',
                            'pending',
                            $product_id,
                        );
                    }
                }
            }
            if ($operation_type === 'immediate') {
                $class->dispatch();
            }
        }
    }

    public static function disconnect_specific_products_in_basalam($product_ids)
    {
        if (is_array($product_ids)) {
            foreach ($product_ids as $product_id) {
                $operator = new sync_basalam_Admin_Product_Operations;
                $operator->disconnect_product($product_id);
            }
        }
    }

    public static function get_products_for_update($args)
    {
        $posts_per_page = $args['posts_per_page'] ?? 100;
        $offset = $args['offset'] ?? 0;

        $product_ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $posts_per_page,
            'offset'         => $offset,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => 'sync_basalam_product_id',
                    'compare' => 'EXISTS',
                ]
            ],
        ]);
        $product_ids = array_filter($product_ids, function ($id) {
            return get_post_type($id) === 'product';
        });

        return array_values($product_ids);
    }

    public static function sync_basalam_auto_connect_all_products($page = 1)
    {
        $class = new Sync_basalam_Auto_Connect_Product_Task();

        $class->push($page);

        $class->save()->dispatch();
    }

    public static function add_to_schedule($create_task_class, $args, $delay = null)
    {
        ($create_task_class)->schedule($args, $delay);
    }
}
