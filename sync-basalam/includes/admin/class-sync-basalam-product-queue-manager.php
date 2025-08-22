<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Product_Queue_Manager
{

    public static function create_all_products_in_basalam($include_out_of_stock = false)
    {
        if (sync_basalam_QueueManager::has_pending_tasks('sync_basalam_plugin_chunk_create_products')) {
            return [
                'success' => false,
                'message' => 'در حال حاضر یک عملیات در صف انتظار است.',
                'status_code' => 409
            ];
        }

        $chunk_size = 100;
        $max_chunks_per_task = 10;

        $data = [
            'posts_per_page'        => $chunk_size,
            'offset'                => 0,
            'max_chunks'            => $max_chunks_per_task,
            'include_out_of_stock'  => $include_out_of_stock,
        ];

        (new sync_basalam_Chunk_Create_Products_Task())->schedule($data);

        return [
            'success' => true,
            'message' => 'محصولات با موفقیت به صف ایجاد افزوده شدند.',
            'status_code' => 200
        ];
    }

    public static function create_specific_products_in_basalam($product_ids)
    {
        if (is_array($product_ids)) {
            foreach ($product_ids as $product_id) {
                $basalam_product_id = get_post_meta($product_id, 'sync_basalam_product_id', true);
                if (empty($basalam_product_id)) {
                    sync_basalam_Product_Queue_Manager::add_to_schedule(new sync_basalam_Create_Product_Task(), ['type' => 'create_product', 'id' => $product_id]);
                }
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
                    'value'   => 100,
                    'type'    => 'NUMERIC',
                    'compare' => '>',
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
        if (sync_basalam_QueueManager::has_pending_tasks('sync_basalam_plugin_chunk_update_products')) {
            return [
                'success' => false,
                'message' => 'در حال حاضر یک عملیات در صف انتظار است.',
                'status_code' => 409
            ];
        }

        $chunk_size = 100;
        $max_chunks_per_task = 10;

        $data = [
            'posts_per_page' => $chunk_size,
            'offset'         => 0,
            'max_chunks'     => $max_chunks_per_task,
        ];

        (new sync_basalam_Chunk_Update_Products_Task())->schedule($data);

        return [
            'success' => true,
            'message' => 'محصولات با موفقیت به صف بروزرسانی افزوده شدند.',
            'status_code' => 200
        ];
    }

    public static function update_specific_products_in_basalam($product_ids)
    {
        if (is_array($product_ids)) {
            foreach ($product_ids as $product_id) {
                $basalam_product_id = get_post_meta($product_id, 'sync_basalam_product_id', true);

                if (!empty($basalam_product_id)) {
                    self::add_to_schedule(new sync_basalam_Update_Product_Task(), ['type' => 'update_product', 'id' => $product_id]);
                }
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

    public static function  sync_basalam_auto_connect_all_products($page = 1)
    {
        $class = new Sync_basalam_Auto_Connect_Product_Task();

        $class->push_to_queue($page);

        $class->save()->dispatch();
    }

    public static function add_to_schedule($create_task_class, $args)
    {
        ($create_task_class)->schedule($args);
    }
}
