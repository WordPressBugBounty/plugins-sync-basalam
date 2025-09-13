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
        $this->task_model = new Sync_Basalam_Discount_Task();
        $this->logger = Sync_basalam_Logger::getInstance();
    }

    public function process_discount_tasks($product_ids = [], $variation_ids = [], $discount_percent = null, $active_days = null)
    {
        try {
            if (empty($product_ids) && empty($variation_ids)) {
                // Process only the first group per execution for better performance and API rate limiting
                $first_group = $this->task_model->get_first_pending_task_group();

                if (!$first_group) {
                    $this->logger->info('No pending discount tasks to process.', []);
                    return;
                }

                $this->logger->info(sprintf(
                    'Processing first discount task group: %s%% for %d days with %d tasks',
                    $first_group->discount_percent,
                    $first_group->active_days,
                    $first_group->count
                ), []);

                $this->process_task_group($first_group);
            } else {
                // Direct processing without database check
                $this->process_direct_discount($product_ids, $variation_ids, $discount_percent, $active_days);
            }

            $this->cleanup_old_tasks();
        } catch (Exception $e) {
            $this->logger->error('Error processing discount tasks: ' . $e->getMessage(), []);
        }
    }

    private function process_direct_discount($product_ids, $variation_ids, $discount_percent, $active_days)
    {
        try {
            // Filter out empty values
            $product_ids = array_filter($product_ids);
            $variation_ids = array_filter($variation_ids);

            if (empty($product_ids) && empty($variation_ids)) {
                $this->logger->warning('No product or variation IDs provided for discount processing.', []);
                return;
            }

            $this->logger->info(
                sprintf(
                    'Processing direct discount: %s%% for %d days, %d products, %d variations',
                    $discount_percent,
                    $active_days,
                    count($product_ids),
                    count($variation_ids)
                ),
                []
            );

            // Apply discount through Basalam API
            $result = $this->discount_manager->apply(
                $discount_percent,
                $product_ids,
                $variation_ids,
                $active_days
            );

            // Log the API response for debugging
            $this->logger->debug(
                sprintf(
                    'API Response for %s%% discount: %s',
                    $discount_percent,
                    json_encode($result)
                ),
                []
            );

            // Check for successful response
            if ($result && isset($result['status_code']) && $result['status_code'] === 200) {
                $this->logger->info(
                    sprintf(
                        'Successfully applied %s%% discount to %d products and %d variations',
                        $discount_percent,
                        count($product_ids),
                        count($variation_ids)
                    ),
                    []
                );
            } else {
                // Extract error message from API response
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

                // Create detailed error for logging
                $detailed_error_log = $error_message;
                if ($result && isset($result['body'])) {
                    $detailed_error_log .= ' | Response Body: ' . json_encode($result['body']);
                }
                if ($result && isset($result['status_code'])) {
                    $detailed_error_log .= ' | Status Code: ' . $result['status_code'];
                }

                $this->logger->error(
                    sprintf(
                        'Failed to apply %s%% discount: %s',
                        $discount_percent,
                        $detailed_error_log
                    ),
                    []
                );
            }
        } catch (Exception $e) {
            $this->logger->error(
                sprintf(
                    'Exception while processing direct discount %s%%: %s',
                    $discount_percent,
                    $e->getMessage()
                ),
                []
            );
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

            // The IDs in database are already Basalam IDs (stored as product_id and variation_id)
            // Just filter out null/empty values
            $product_ids = array_filter($raw_product_ids, function ($id) {
                return !empty($id) && $id !== 'NULL' && $id !== null;
            });

            $variation_ids = array_filter($raw_variation_ids, function ($id) {
                return !empty($id) && $id !== 'NULL' && $id !== null;
            });

            // Ensure we have at least some IDs to process
            if (empty($product_ids) && empty($variation_ids)) {
                $this->logger->warning(sprintf(
                    'No valid Basalam IDs found in discount group: %s%% for %d days. Task IDs: %s',
                    $group->discount_percent,
                    $group->active_days,
                    $group->task_ids
                ), []);

                // Mark tasks as failed due to missing IDs
                $this->task_model->update_multiple_status(
                    $task_ids,
                    Sync_Basalam_Discount_Task::STATUS_FAILED,
                    'No valid Basalam product or variation IDs found'
                );
                return;
            }

            $this->logger->info(
                sprintf(
                    'Processing discount group: %s%% for %d days, %d products, %d variations',
                    $group->discount_percent,
                    $group->active_days,
                    count($product_ids),
                    count($variation_ids)
                ),
                []
            );

            // Mark tasks as processing
            $this->task_model->update_multiple_status($task_ids, Sync_Basalam_Discount_Task::STATUS_PROCESSING);

            // Apply or remove discount through Basalam API based on action
            if ($group->action === Sync_Basalam_Discount_Task::ACTION_REMOVE) {
                $result = $this->discount_manager->remove($product_ids, $variation_ids);
            } else {
                $result = $this->discount_manager->apply(
                    $group->discount_percent,
                    $product_ids,
                    $variation_ids,
                    $group->active_days
                );
            }

            // Log the API response for debugging
            $this->logger->debug(
                sprintf(
                    'API Response for %s%% discount: %s',
                    $group->discount_percent,
                    json_encode($result)
                ),
                []
            );

            // Check for successful response - API returns status_code and body
            if ($result && isset($result['status_code']) && $result['status_code'] === 202) {
                // Mark tasks as completed
                $this->task_model->update_multiple_status($task_ids, Sync_Basalam_Discount_Task::STATUS_COMPLETED);

                // Track discount status in postmeta based on action
                $this->track_discount_status($group, $product_ids, $variation_ids);

                $action_text = $group->action === Sync_Basalam_Discount_Task::ACTION_REMOVE ? 'removed' : 'applied';
                $this->logger->info(
                    sprintf(
                        'Successfully %s %s%% discount to %d products and %d variations',
                        $action_text,
                        $group->discount_percent,
                        count($product_ids),
                        count($variation_ids)
                    ),
                    []
                );
            } else {
                // Extract error message from API response
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

                // Create detailed error message
                $detailed_error = $error_message;
                if ($result && isset($result['body'])) {
                    $detailed_error .= ' | Response Body: ' . json_encode($result['body']);
                }
                if ($result && isset($result['status_code'])) {
                    $detailed_error .= ' | Status Code: ' . $result['status_code'];
                }

                // Mark tasks as failed
                $this->task_model->update_multiple_status(
                    $task_ids,
                    Sync_Basalam_Discount_Task::STATUS_FAILED,
                    $detailed_error
                );

                $this->logger->error(
                    sprintf(
                        'Failed to apply %s%% discount: %s. Response: %s',
                        $group->discount_percent,
                        $error_message,
                        json_encode($result)
                    ),
                    []
                );
            }
        } catch (Exception $e) {
            $task_ids = !empty($group->task_ids) ? explode(',', $group->task_ids) : [];
            $this->task_model->update_multiple_status(
                $task_ids,
                Sync_Basalam_Discount_Task::STATUS_FAILED,
                $e->getMessage()
            );

            $this->logger->error(
                sprintf(
                    'Exception while processing discount group %s%%: %s',
                    $group->discount_percent,
                    $e->getMessage()
                ),
                []
            );
        }
    }

    private function cleanup_old_tasks()
    {
        $deleted_count = $this->task_model->delete_old_completed_tasks(30);
        if ($deleted_count > 0) {
            $this->logger->info("Cleaned up {$deleted_count} old discount tasks.", []);
        }
    }

    public static function schedule_recurring_processor()
    {
        $hook = 'sync_basalam_process_discount_tasks';

        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time(), 'every_minute', $hook);
        }
    }

    /**
     * Process only one group of discount tasks per execution
     * This method should be called by cron job every minute
     */
    public function process_single_discount_group()
    {
        try {
            // Get only the first pending group
            $first_group = $this->task_model->get_first_pending_task_group();

            if (!$first_group) {
                $this->logger->debug('No pending discount task groups to process.', []);
                return ['success' => true, 'message' => 'No pending tasks'];
            }

            $this->logger->info(sprintf(
                'Processing single discount group: %s%% for %d days with %d tasks',
                $first_group->discount_percent,
                $first_group->active_days,
                $first_group->count
            ), []);

            $this->process_task_group($first_group);

            return [
                'success' => true,
                'message' => sprintf(
                    'Processed discount group: %s%% (%d tasks)',
                    $first_group->discount_percent,
                    $first_group->count
                )
            ];
        } catch (Exception $e) {
            $this->logger->error('Error processing single discount group: ' . $e->getMessage(), []);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function unschedule_processor()
    {
        $hook = 'sync_basalam_process_discount_tasks';
        wp_clear_scheduled_hook($hook);
    }

    /**
     * Convert WooCommerce product/variation IDs to Basalam IDs
     * 
     * @param array $product_ids WooCommerce product IDs
     * @param array $variation_ids WooCommerce variation IDs
     * @return array ['product_ids' => array, 'variation_ids' => array] with Basalam IDs
     */
    private function convert_to_basalam_ids($product_ids = [], $variation_ids = [])
    {
        $basalam_product_ids = [];
        $basalam_variation_ids = [];

        // Convert product IDs
        foreach ($product_ids as $wc_product_id) {
            $basalam_id = get_post_meta($wc_product_id, 'sync_basalam_product_id', true);
            if ($basalam_id) {
                $basalam_product_ids[] = $basalam_id;
            }
        }

        // Convert variation IDs
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

    /**
     * Process multiple discount items grouped by discount percentage
     * 
     * @param array $items Array of items with structure:
     *              ['product_id' => int, 'variation_id' => int, 'discount_percent' => float, 'active_days' => int]
     *              Note: product_id and variation_id should be WooCommerce post IDs, will be converted to Basalam IDs
     */
    public function process_grouped_discounts($items)
    {
        if (empty($items)) {
            $this->logger->warning('No items provided for grouped discount processing.', []);
            return;
        }

        // Group items by discount_percent and active_days
        $grouped_items = [];
        foreach ($items as $item) {
            $discount_percent = $item['discount_percent'] ?? 0;
            $active_days = $item['active_days'] ?? null;

            // Create a unique key for grouping
            $group_key = $discount_percent . '_' . ($active_days ?: 'default');

            if (!isset($grouped_items[$group_key])) {
                $grouped_items[$group_key] = [
                    'discount_percent' => $discount_percent,
                    'active_days' => $active_days,
                    'product_ids' => [],
                    'variation_ids' => []
                ];
            }

            // Convert WooCommerce IDs to Basalam IDs
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

        $this->logger->info(sprintf('Processing %d discount groups from %d items', count($grouped_items), count($items)), []);

        // Process each group with a single API request
        foreach ($grouped_items as $group) {
            // Remove duplicates
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

    /**
     * Create and save discount tasks to database with Basalam IDs
     * 
     * @param array $items Array of items with structure:
     *              ['basalam_product_id' => string, 'basalam_variation_id' => string, 'discount_percent' => float, 'active_days' => int]
     * @return array Result with status and message
     */
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

                // Skip if no Basalam ID provided
                if (!$basalam_product_id && !$basalam_variation_id) {
                    $this->logger->warning('No Basalam ID provided in item', []);
                    $failed_count++;
                    continue;
                }

                // Create task in database with Basalam IDs
                $task_id = $this->task_model->create([
                    'product_id' => $basalam_product_id,
                    'variation_id' => $basalam_variation_id,
                    'discount_percent' => $discount_percent,
                    'active_days' => $active_days,
                    'status' => Sync_Basalam_Discount_Task::STATUS_PENDING
                ]);

                if ($task_id) {
                    $created_count++;
                    $this->logger->debug(sprintf(
                        'Created discount task - Basalam Product: %s, Basalam Variation: %s, Discount: %s%%',
                        $basalam_product_id ?: 'N/A',
                        $basalam_variation_id ?: 'N/A',
                        $discount_percent
                    ), []);
                } else {
                    $failed_count++;
                }
            } catch (Exception $e) {
                $this->logger->error('Error creating discount task: ' . $e->getMessage(), []);
                $failed_count++;
            }
        }

        $message = sprintf(
            'Created %d discount tasks successfully. %d failed.',
            $created_count,
            $failed_count
        );

        $this->logger->info($message, []);

        return [
            'success' => $created_count > 0,
            'message' => $message,
            'created' => $created_count,
            'failed' => $failed_count
        ];
    }

    /**
     * Create discount tasks from WooCommerce product IDs
     * Converts WC IDs to Basalam IDs and saves to database
     * 
     * @param array $items Array with WooCommerce IDs
     * @return array Result
     */
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

    /**
     * Process discount tasks with WooCommerce IDs directly (without saving to DB)
     * 
     * @param array $wc_product_ids WooCommerce product IDs
     * @param array $wc_variation_ids WooCommerce variation IDs
     * @param float $discount_percent Discount percentage
     * @param int $active_days Number of active days
     * @return array Result with status and message
     */
    public function apply_discount_with_wc_ids($wc_product_ids, $wc_variation_ids, $discount_percent, $active_days = null)
    {
        // Convert to Basalam IDs
        $converted = $this->convert_to_basalam_ids($wc_product_ids, $wc_variation_ids);

        if (empty($converted['product_ids']) && empty($converted['variation_ids'])) {
            return [
                'success' => false,
                'message' => 'No valid Basalam IDs found for provided WooCommerce IDs'
            ];
        }

        // Process directly
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

    /**
     * Track discount status in WooCommerce postmeta
     * 
     * @param object $group The discount task group
     * @param array $basalam_product_ids Basalam product IDs
     * @param array $basalam_variation_ids Basalam variation IDs
     */
    private function track_discount_status($group, $basalam_product_ids, $basalam_variation_ids)
    {
        try {
            $action = $group->action;
            $timestamp = current_time('mysql');

            // Track for products
            foreach ($basalam_product_ids as $basalam_product_id) {
                $wc_product_id = $this->get_wc_product_id_by_basalam_id($basalam_product_id);
                if ($wc_product_id) {
                    if ($action === Sync_Basalam_Discount_Task::ACTION_APPLY) {
                        // Mark product as discounted
                        update_post_meta($wc_product_id, '_sync_basalam_discount_applied', $timestamp);
                        update_post_meta($wc_product_id, '_sync_basalam_discount_percent', $group->discount_percent);
                    } else {
                        // Remove discount tracking
                        delete_post_meta($wc_product_id, '_sync_basalam_discount_applied');
                        delete_post_meta($wc_product_id, '_sync_basalam_discount_percent');
                    }
                }
            }

            // Track for variations
            foreach ($basalam_variation_ids as $basalam_variation_id) {
                $wc_variation_id = $this->get_wc_variation_id_by_basalam_id($basalam_variation_id);
                if ($wc_variation_id) {
                    if ($action === Sync_Basalam_Discount_Task::ACTION_APPLY) {
                        // Mark variation as discounted
                        update_post_meta($wc_variation_id, '_sync_basalam_discount_applied', $timestamp);
                        update_post_meta($wc_variation_id, '_sync_basalam_discount_percent', $group->discount_percent);
                    } else {
                        // Remove discount tracking
                        delete_post_meta($wc_variation_id, '_sync_basalam_discount_applied');
                        delete_post_meta($wc_variation_id, '_sync_basalam_discount_percent');
                    }
                }
            }

            $this->logger->debug(sprintf(
                'Tracked discount status: %s for %d products and %d variations',
                $action,
                count($basalam_product_ids),
                count($basalam_variation_ids)
            ), []);
        } catch (Exception $e) {
            $this->logger->error('Error tracking discount status: ' . $e->getMessage(), []);
        }
    }

    /**
     * Get WooCommerce product ID by Basalam product ID
     * 
     * @param string $basalam_product_id
     * @return int|null WooCommerce product ID or null if not found
     */
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

    /**
     * Get WooCommerce variation ID by Basalam variation ID
     * 
     * @param string $basalam_variation_id
     * @return int|null WooCommerce variation ID or null if not found
     */
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

    /**
     * Create remove discount task for a WooCommerce product/variation
     * 
     * @param int $wc_product_id WooCommerce product ID
     * @param int|null $wc_variation_id WooCommerce variation ID (optional)
     * @return array Result with status and message
     */
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

            // Create remove task
            $task_id = $this->task_model->create([
                'product_id' => $basalam_product_id,
                'variation_id' => $basalam_variation_id,
                'discount_percent' => 0, // Not needed for remove action
                'active_days' => 0, // Not needed for remove action
                'action' => Sync_Basalam_Discount_Task::ACTION_REMOVE,
                'status' => Sync_Basalam_Discount_Task::STATUS_PENDING
            ]);

            if ($task_id) {
                $this->logger->info(sprintf(
                    'Created remove discount task for WC Product: %s, WC Variation: %s',
                    $wc_product_id ?: 'N/A',
                    $wc_variation_id ?: 'N/A'
                ), []);

                return ['success' => true, 'message' => 'Remove discount task created', 'task_id' => $task_id];
            } else {
                return ['success' => false, 'message' => 'Failed to create remove discount task'];
            }
        } catch (Exception $e) {
            $this->logger->error('Error creating remove discount task: ' . $e->getMessage(), []);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
