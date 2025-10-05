<?php
defined('ABSPATH') || exit;

class Sync_Basalam_Discount_Task_Processor
{
    private $discount_manager;
    private $task_model;
    private $logger;

    public function __construct()
    {
        $this->discount_manager = new Sync_Basalam_Discount_Manager();
        $this->task_model = new Sync_Basalam_Discount_Task_Model();
        $this->logger = Sync_basalam_Logger::getInstance();
    }

    public function process_discount_tasks($product_ids = [], $variation_ids = [], $discount_percent = null, $active_days = null)
    {
        try {
            if (empty($product_ids) && empty($variation_ids)) {
                
                $first_group = $this->task_model->get_first_pending_task_group();

                if (!$first_group) {
                    
                    return;
                }

                
                
                
                
                
                

                $this->process_task_group($first_group);
            } else {
                
                $this->process_direct_discount($product_ids, $variation_ids, $discount_percent, $active_days);
            }

            $this->cleanup_old_tasks();
        } catch (Exception $e) {
            
        }
    }

    private function process_direct_discount($product_ids, $variation_ids, $discount_percent, $active_days)
    {
        try {
            
            $product_ids = array_filter($product_ids);
            $variation_ids = array_filter($variation_ids);

            if (empty($product_ids) && empty($variation_ids)) {
                
                return;
            }

            
            
            
            
            
            
            
            
            
            

            
            $result = $this->discount_manager->apply(
                $discount_percent,
                $product_ids,
                $variation_ids,
                $active_days
            );

            
            
            
            
            
            
            
            
            

            
            if ($result && isset($result['status_code']) && $result['status_code'] === 200) {
                
                
                
                
                
                
                
                
                
            } else {
                
                $error_message = 'Unknown error occurred';

                if ($result) {
                    if (isset($result['body']['message'])) {
                        $error_message = $result['body']['message'];
                    } elseif (isset($result['body']['error'])) {
                        $error_message = $result['body']['error'];
                    } elseif (isset($result['status_code'])) {
                        $error_message = sprintf('API returned status code: %d', $result['status_code']);
                    }
                }

                
                $detailed_error_log = $error_message;
                if ($result && isset($result['body'])) {
                    $detailed_error_log .= ' | Response Body: ' . json_encode($result['body']);
                }
                if ($result && isset($result['status_code'])) {
                    $detailed_error_log .= ' | Status Code: ' . $result['status_code'];
                }

                
                
                
                
                
                
                
                
            }
        } catch (Exception $e) {
            
            
            
            
            
            
            
            
        }
    }

    private function process_task_group($group)
    {
        try {
            $task_ids = !empty($group->task_ids) ? explode(',', $group->task_ids) : [];
            $raw_product_ids = !empty($group->product_ids)
                ? array_filter(explode(',', $group->product_ids))
                : [];

            $raw_variation_ids = !empty($group->variation_ids)
                ? array_filter(explode(',', $group->variation_ids))
                : [];

            
            
            $product_ids = array_filter($raw_product_ids, function ($id) {
                return !empty($id) && $id !== 'NULL' && $id !== null;
            });

            $variation_ids = array_filter($raw_variation_ids, function ($id) {
                return !empty($id) && $id !== 'NULL' && $id !== null;
            });

            
            if (empty($product_ids) && empty($variation_ids)) {
                
                
                
                
                
                

                
                $this->task_model->update_multiple_status(
                    $task_ids,
                    Sync_Basalam_Discount_Task_Model::STATUS_FAILED,
                    'No valid Basalam product or variation IDs found'
                );
                return;
            }

            
            
            
            
            
            
            
            
            
            

            
            $this->task_model->update_multiple_status($task_ids, Sync_Basalam_Discount_Task_Model::STATUS_PROCESSING);

            
            if ($group->action === Sync_Basalam_Discount_Task_Model::ACTION_REMOVE) {
                $result = $this->discount_manager->remove($product_ids, $variation_ids);
            } else {
                $result = $this->discount_manager->apply(
                    $group->discount_percent,
                    $product_ids,
                    $variation_ids,
                    $group->active_days
                );
            }

            
            
            
            
            
            
            
            
            

            
            if ($result && isset($result['status_code']) && $result['status_code'] === 202) {
                
                $this->task_model->update_multiple_status($task_ids, Sync_Basalam_Discount_Task_Model::STATUS_COMPLETED);

                
                $this->track_discount_status($group, $product_ids, $variation_ids);

                $action_text = $group->action === Sync_Basalam_Discount_Task_Model::ACTION_REMOVE ? 'removed' : 'applied';
                
                
                
                
                
                
                
                
                
                
            } else {
                
                $error_message = 'Unknown error occurred';

                if ($result) {
                    if (isset($result['body']['message'])) {
                        $error_message = $result['body']['message'];
                    } elseif (isset($result['body']['error'])) {
                        $error_message = $result['body']['error'];
                    } elseif (isset($result['status_code'])) {
                        $error_message = sprintf('API returned status code: %d', $result['status_code']);
                    }
                }

                
                $detailed_error = $error_message;
                if ($result && isset($result['body'])) {
                    $detailed_error .= ' | Response Body: ' . json_encode($result['body']);
                }
                if ($result && isset($result['status_code'])) {
                    $detailed_error .= ' | Status Code: ' . $result['status_code'];
                }

                
                $this->task_model->update_multiple_status(
                    $task_ids,
                    Sync_Basalam_Discount_Task_Model::STATUS_FAILED,
                    $detailed_error
                );

                
                
                
                
                
                
                
                
                
            }
        } catch (Exception $e) {
            $task_ids = !empty($group->task_ids) ? explode(',', $group->task_ids) : [];
            $this->task_model->update_multiple_status(
                $task_ids,
                Sync_Basalam_Discount_Task_Model::STATUS_FAILED,
                $e->getMessage()
            );

            
            
            
            
            
            
            
            
        }
    }

    private function cleanup_old_tasks()
    {
        $deleted_count = $this->task_model->delete_old_completed_tasks(30);
        if ($deleted_count > 0) {
            
        }
    }

    public static function schedule_recurring_processor()
    {
        $hook = 'sync_basalam_process_discount_tasks';

        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time(), 'every_minute', $hook);
        }
    }

    
    public function process_single_discount_group()
    {
        try {
            
            $first_group = $this->task_model->get_first_pending_task_group();

            if (!$first_group) {
                
                return ['success' => true, 'message' => 'No pending tasks'];
            }

            
            
            
            
            
            

            $this->process_task_group($first_group);

            return [
                'success' => true,
                'message' => sprintf(
                    'اعمال تخفیف گروهی روی: %s%% (%d tasks)',
                    $first_group->discount_percent,
                    $first_group->count
                )
            ];
        } catch (Exception $e) {
            
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function unschedule_processor()
    {
        $hook = 'sync_basalam_process_discount_tasks';
        wp_clear_scheduled_hook($hook);
    }

    
    private function convert_to_basalam_ids($product_ids = [], $variation_ids = [])
    {
        $basalam_product_ids = [];
        $basalam_variation_ids = [];

        
        foreach ($product_ids as $wc_product_id) {
            $basalam_id = get_post_meta($wc_product_id, 'sync_basalam_product_id', true);
            if ($basalam_id) {
                $basalam_product_ids[] = $basalam_id;
            }
        }

        
        foreach ($variation_ids as $wc_variation_id) {
            $basalam_id = get_post_meta($wc_variation_id, 'sync_basalam_variation_id', true);
            if ($basalam_id) {
                $basalam_variation_ids[] = $basalam_id;
            }
        }

        return [
            'product_ids' => $basalam_product_ids,
            'variation_ids' => $basalam_variation_ids
        ];
    }

    
    public function process_grouped_discounts($items)
    {
        if (empty($items)) {
            
            return;
        }

        
        $grouped_items = [];
        foreach ($items as $item) {
            $discount_percent = $item['discount_percent'] ?? 0;
            $active_days = $item['active_days'] ?? null;

            
            $group_key = $discount_percent . '_' . ($active_days ?: 'default');

            if (!isset($grouped_items[$group_key])) {
                $grouped_items[$group_key] = [
                    'discount_percent' => $discount_percent,
                    'active_days' => $active_days,
                    'product_ids' => [],
                    'variation_ids' => []
                ];
            }

            
            if (!empty($item['product_id'])) {
                $basalam_product_id = get_post_meta($item['product_id'], 'sync_basalam_product_id', true);
                if ($basalam_product_id) {
                    $grouped_items[$group_key]['product_ids'][] = $basalam_product_id;
                }
            }
            if (!empty($item['variation_id'])) {
                $basalam_variation_id = get_post_meta($item['variation_id'], 'sync_basalam_variation_id', true);
                if ($basalam_variation_id) {
                    $grouped_items[$group_key]['variation_ids'][] = $basalam_variation_id;
                }
            }
        }

        

        
        foreach ($grouped_items as $group) {
            
            $group['product_ids'] = array_unique($group['product_ids']);
            $group['variation_ids'] = array_unique($group['variation_ids']);

            $this->process_direct_discount(
                $group['product_ids'],
                $group['variation_ids'],
                $group['discount_percent'],
                $group['active_days']
            );
        }
    }

    
    public function create_discount_tasks($items)
    {
        if (empty($items)) {
            return ['success' => false, 'message' => 'No items provided'];
        }

        $created_count = 0;
        $failed_count = 0;

        foreach ($items as $item) {
            try {
                $basalam_product_id = $item['basalam_product_id'] ?? null;
                $basalam_variation_id = $item['basalam_variation_id'] ?? null;
                $discount_percent = $item['discount_percent'] ?? 0;
                $active_days = $item['active_days'] ?? null;

                
                if (!$basalam_product_id && !$basalam_variation_id) {
                    
                    $failed_count++;
                    continue;
                }

                
                $task_id = $this->task_model->create([
                    'product_id' => $basalam_product_id,
                    'variation_id' => $basalam_variation_id,
                    'discount_percent' => $discount_percent,
                    'active_days' => $active_days,
                    'status' => Sync_Basalam_Discount_Task_Model::STATUS_PENDING
                ]);

                if ($task_id) {
                    $created_count++;
                    
                    
                    
                    
                    
                    
                } else {
                    $failed_count++;
                }
            } catch (Exception $e) {
                
                $failed_count++;
            }
        }

        $message = sprintf(
            'Created %d discount tasks successfully. %d failed.',
            $created_count,
            $failed_count
        );

        

        return [
            'success' => $created_count > 0,
            'message' => $message,
            'created' => $created_count,
            'failed' => $failed_count
        ];
    }

    
    public function create_discount_tasks_from_wc_ids($items)
    {
        $basalam_items = [];

        foreach ($items as $item) {
            $wc_product_id = $item['product_id'] ?? null;
            $wc_variation_id = $item['variation_id'] ?? null;

            $basalam_product_id = null;
            $basalam_variation_id = null;

            if ($wc_product_id) {
                $basalam_product_id = get_post_meta($wc_product_id, 'sync_basalam_product_id', true);
            }
            if ($wc_variation_id) {
                $basalam_variation_id = get_post_meta($wc_variation_id, 'sync_basalam_variation_id', true);
            }

            if ($basalam_product_id || $basalam_variation_id) {
                $basalam_items[] = [
                    'basalam_product_id' => $basalam_product_id,
                    'basalam_variation_id' => $basalam_variation_id,
                    'discount_percent' => $item['discount_percent'] ?? 0,
                    'active_days' => $item['active_days'] ?? null
                ];
            }
        }

        return $this->create_discount_tasks($basalam_items);
    }

    
    public function apply_discount_with_wc_ids($wc_product_ids, $wc_variation_ids, $discount_percent, $active_days = null)
    {
        
        $converted = $this->convert_to_basalam_ids($wc_product_ids, $wc_variation_ids);

        if (empty($converted['product_ids']) && empty($converted['variation_ids'])) {
            return [
                'success' => false,
                'message' => 'No valid Basalam IDs found for provided WooCommerce IDs'
            ];
        }

        
        $this->process_direct_discount(
            $converted['product_ids'],
            $converted['variation_ids'],
            $discount_percent,
            $active_days
        );

        return [
            'success' => true,
            'message' => sprintf(
                'Processing discount for %d products and %d variations',
                count($converted['product_ids']),
                count($converted['variation_ids'])
            )
        ];
    }

    
    private function track_discount_status($group, $basalam_product_ids, $basalam_variation_ids)
    {
        try {
            $action = $group->action;
            $timestamp = current_time('mysql');

            
            foreach ($basalam_product_ids as $basalam_product_id) {
                $wc_product_id = $this->get_wc_product_id_by_basalam_id($basalam_product_id);
                if ($wc_product_id) {
                    if ($action === Sync_Basalam_Discount_Task_Model::ACTION_APPLY) {
                        
                        update_post_meta($wc_product_id, '_sync_basalam_discount_applied', $timestamp);
                        update_post_meta($wc_product_id, '_sync_basalam_discount_percent', $group->discount_percent);
                    } else {
                        
                        delete_post_meta($wc_product_id, '_sync_basalam_discount_applied');
                        delete_post_meta($wc_product_id, '_sync_basalam_discount_percent');
                    }
                }
            }

            
            foreach ($basalam_variation_ids as $basalam_variation_id) {
                $wc_variation_id = $this->get_wc_variation_id_by_basalam_id($basalam_variation_id);
                if ($wc_variation_id) {
                    if ($action === Sync_Basalam_Discount_Task_Model::ACTION_APPLY) {
                        
                        update_post_meta($wc_variation_id, '_sync_basalam_discount_applied', $timestamp);
                        update_post_meta($wc_variation_id, '_sync_basalam_discount_percent', $group->discount_percent);
                    } else {
                        
                        delete_post_meta($wc_variation_id, '_sync_basalam_discount_applied');
                        delete_post_meta($wc_variation_id, '_sync_basalam_discount_percent');
                    }
                }
            }

            
            
            
            
            
            
        } catch (Exception $e) {
            
        }
    }

    
    private function get_wc_product_id_by_basalam_id($basalam_product_id)
    {
        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = 'sync_basalam_product_id' 
             AND meta_value = %s 
             LIMIT 1",
            $basalam_product_id
        ));

        return $result ? (int)$result : null;
    }

    
    private function get_wc_variation_id_by_basalam_id($basalam_variation_id)
    {
        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = 'sync_basalam_variation_id' 
             AND meta_value = %s 
             LIMIT 1",
            $basalam_variation_id
        ));

        return $result ? (int)$result : null;
    }

    
    public function create_remove_discount_task($wc_product_id, $wc_variation_id = null)
    {
        try {
            $basalam_product_id = null;
            $basalam_variation_id = null;

            if ($wc_product_id) {
                $basalam_product_id = get_post_meta($wc_product_id, 'sync_basalam_product_id', true);
            }
            if ($wc_variation_id) {
                $basalam_variation_id = get_post_meta($wc_variation_id, 'sync_basalam_variation_id', true);
            }

            if (!$basalam_product_id && !$basalam_variation_id) {
                return ['success' => false, 'message' => 'No Basalam IDs found'];
            }

            
            $task_id = $this->task_model->create([
                'product_id' => $basalam_product_id,
                'variation_id' => $basalam_variation_id,
                'discount_percent' => 0, 
                'active_days' => 0, 
                'action' => Sync_Basalam_Discount_Task_Model::ACTION_REMOVE,
                'status' => Sync_Basalam_Discount_Task_Model::STATUS_PENDING
            ]);

            if ($task_id) {
                
                
                
                
                

                return ['success' => true, 'message' => 'Remove discount task created', 'task_id' => $task_id];
            } else {
                return ['success' => false, 'message' => 'Failed to create remove discount task'];
            }
        } catch (Exception $e) {
            
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
