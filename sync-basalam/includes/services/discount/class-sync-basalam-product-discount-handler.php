<?php
if (!defined('ABSPATH')) exit;

class Sync_Basalam_Product_Discount_Handler
{
    private const PRICE_FIELD_SALE = 'sale_price';

    private $discount_service;
    private $price_field;
    private $task_scheduler;

    public function __construct()
    {
        $this->discount_service = new Sync_Basalam_Discount_Manager();
        $this->price_field = sync_basalam_Admin_Settings::get_settings(
            sync_basalam_Admin_Settings::PRODUCT_PRICE_FIELD
        );

        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-discount-task-scheduler.php';
        $this->task_scheduler = new Sync_Basalam_Discount_Task_Scheduler();
    }

    public function handle(int $product_id): array
    {
        try {
            $product = wc_get_product($product_id);
            if (!$product) {
                throw new Exception('محصول یافت نشد.');
            }

            if ($product->is_type('variable')) {
                return $this->handleVariableProduct($product);
            } else {
                return $this->handleSimpleProduct($product);
            }
        } catch (Throwable $th) {
            return $this->error('عملیات تخفیف ناموفق بود', $th);
        }
    }

    private function scheduleDiscountTasks(WC_Product $product): array
    {
        $active_days = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::DISCOUNT_DURATION);
        $tasks_created = 0;

        if ($product->is_type('simple')) {
            $sale_price = $product->get_sale_price();
            $regular_price = $product->get_regular_price();

             if ($sale_price && $regular_price) {
                 $discount_percent = Sync_Basalam_Discount_Manager::calculate_discount_percent($regular_price, $sale_price);
                 $basalam_p_id = get_post_meta($product->get_id(), 'sync_basalam_product_id', true);
                 if ($discount_percent > 0 && $basalam_p_id) {
                     // Always schedule discount task, regardless of previous state
                     $this->task_scheduler->schedule_discount_task(
                         $basalam_p_id,
                         $discount_percent,
                         $active_days,
                         null,
                         0,
                         'apply'
                     );
                     $tasks_created++;
                 }
             }
        } elseif ($product->is_type('variable')) {
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation && $variation->get_sale_price() && $variation->get_regular_price()) {
                    $discount_percent = Sync_Basalam_Discount_Manager::calculate_discount_percent(
                        $variation->get_regular_price(),
                        $variation->get_sale_price()
                    );

                    $basalam_p_id = get_post_meta($product->get_id(), 'sync_basalam_product_id', true);
                    $basalam_v_id = get_post_meta($variation_id, 'sync_basalam_variation_id', true);

                     if ($discount_percent > 0) {
                         // Always schedule discount task, regardless of previous state
                         $this->task_scheduler->schedule_discount_task(
                             null,
                             $discount_percent,
                             $active_days,
                             $basalam_v_id,
                             0,
                             'apply'
                         );
                         $tasks_created++;
                     }
                }
            }
        }

        if ($tasks_created > 0) {
            // Start the processor if not already running
            $this->task_scheduler->start_processor();

            // Also schedule the cron job if not already scheduled
            $this->schedule_discount_processor();

            return $this->success("تخفیف برای پردازش در صف قرار گرفت. {$tasks_created} وظیفه تخفیف ایجاد شد.");
        }

        return $this->success('هیچ تخفیف معتبری برای اعمال یافت نشد.');
    }

    private function resolveStrategy(WC_Product $product): Sync_Basalam_Discount_Strategy
    {
        if ($product->is_type('variable')) {
            return new Sync_Basalam_Variable_Discount_Strategy($this->discount_service);
        }
        return new Sync_Basalam_Simple_Discount_Strategy($this->discount_service);
    }

    private function shouldApplySalePriceDiscount(WC_Product $product): bool
    {
        // Only apply discount if price field is set to sale_price and product has sale price
        if ($this->price_field !== self::PRICE_FIELD_SALE) {
            return false;
        }

        if ($product->is_type('simple') && $product->get_sale_price()) {
            return true;
        }

        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation && $variation->get_sale_price()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Handle simple product discount logic
     * 
     * @param WC_Product $product
     * @return array
     */
    private function handleSimpleProduct(WC_Product $product): array
    {
        // Always try to apply discount if conditions are met, regardless of previous state
        if ($this->shouldApplySalePriceDiscount($product)) {
            return $this->scheduleDiscountTasks($product);
        } else {
            return $this->checkAndScheduleRemoveDiscount($product);
        }
    }

    /**
     * Handle variable product discount logic - check each variation individually
     * 
     * @param WC_Product $product
     * @return array
     */
    private function handleVariableProduct(WC_Product $product): array
    {
        $tasks_created = 0;
        $apply_tasks = 0;
        $remove_tasks = 0;

        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-discount-task-processor.php';
        $processor = new Sync_Basalam_Discount_Task_Processor();

        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) continue;

            $result = $this->handleSingleVariation($variation, $product->get_id(), $processor);
            if ($result['action'] === 'apply') {
                $apply_tasks++;
                $tasks_created++;
            } elseif ($result['action'] === 'remove') {
                $remove_tasks++;
                $tasks_created++;
            }
        }

        if ($tasks_created > 0) {
            // Start the processor if not already running
            $this->task_scheduler->start_processor();
            $this->schedule_discount_processor();

            $message = "پردازش محصول متغیر انجام شد. ";
            if ($apply_tasks > 0) {
                $message .= "{$apply_tasks} تسک اعمال تخفیف ایجاد شد. ";
            }
            if ($remove_tasks > 0) {
                $message .= "{$remove_tasks} تسک حذف تخفیف ایجاد شد. ";
            }

            return $this->success($message);
        }

        return $this->success('هیچ تغییری در تخفیف وریشن‌ها لازم نیست.');
    }

    /**
     * Handle discount logic for a single variation
     * 
     * @param WC_Product_Variation $variation
     * @param int $parent_product_id
     * @param Sync_Basalam_Discount_Task_Processor $processor
     * @return array
     */
    private function handleSingleVariation(WC_Product_Variation $variation, int $parent_product_id, $processor): array
    {
        $variation_id = $variation->get_id();
        $has_sale_price = !empty($variation->get_sale_price());
        $has_discount_meta = $this->variationHasDiscountMeta($variation_id);

        // Case 1: Price field is "original" - remove any existing discounts
        if ($this->price_field !== self::PRICE_FIELD_SALE) {
            if ($has_discount_meta) {
                $result = $processor->create_remove_discount_task($parent_product_id, $variation_id);
                if ($result['success']) {
                    return ['action' => 'remove', 'success' => true];
                }
            }
            return ['action' => 'none', 'success' => true];
        }

        // Case 2: Price field is "sale_price"
        if ($has_sale_price) {
            // Variation has sale price - always apply discount (regardless of previous state)
            $discount_percent = $this->calculateVariationDiscountPercent($variation);
            if ($discount_percent > 0) {
                $active_days = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::DISCOUNT_DURATION);
                $basalam_product_id = get_post_meta($parent_product_id, 'sync_basalam_product_id', true);
                $basalam_variation_id = get_post_meta($variation_id, 'sync_basalam_variation_id', true);

                if ($basalam_variation_id) {
                    $this->task_scheduler->schedule_discount_task(
                        null, // product_id null for variations
                        $discount_percent,
                        $active_days,
                        $basalam_variation_id,
                        0,
                        'apply'
                    );
                    return ['action' => 'apply', 'success' => true];
                }
            }
            return ['action' => 'none', 'success' => true]; // No discount to apply or no basalam ID
        } else {
            // Variation has no sale price but has discount meta - should remove discount
            if ($has_discount_meta) {
                $result = $processor->create_remove_discount_task($parent_product_id, $variation_id);
                if ($result['success']) {
                    return ['action' => 'remove', 'success' => true];
                }
            }
            return ['action' => 'none', 'success' => true];
        }
    }

    /**
     * Check if variation has discount meta
     * 
     * @param int $variation_id
     * @return bool
     */
    private function variationHasDiscountMeta(int $variation_id): bool
    {
        $had_discount_applied = get_post_meta($variation_id, '_sync_basalam_discount_applied', true);
        $had_discount_percent = get_post_meta($variation_id, '_sync_basalam_discount_percent', true);
        return !empty($had_discount_applied) || !empty($had_discount_percent);
    }

    /**
     * Calculate discount percentage for variation
     * 
     * @param WC_Product_Variation $variation
     * @return float
     */
    private function calculateVariationDiscountPercent(WC_Product_Variation $variation): float
    {
        $regular_price = $variation->get_regular_price();
        $sale_price = $variation->get_sale_price();

        if ($regular_price && $sale_price && $regular_price > $sale_price) {
            return Sync_Basalam_Discount_Manager::calculate_discount_percent($regular_price, $sale_price);
        }

        return 0;
    }

    /**
     * Check if product had discount before and schedule remove task if needed
     * This is called when product is updated but no longer qualifies for discount
     */
    private function checkAndScheduleRemoveDiscount(WC_Product $product): array
    {
        $tasks_created = 0;
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-discount-task-processor.php';
        $processor = new Sync_Basalam_Discount_Task_Processor();

        // Check if we should remove discount based on current conditions
        $should_remove_discount = $this->shouldRemoveDiscount($product);

        if (!$should_remove_discount) {
            return $this->success('شرایط حذف تخفیف برقرار نیست.');
        }

        // Debug log

        if ($product->is_type('simple')) {
            // Check if simple product had discount before (check both applied and percent meta)
            $had_discount_applied = get_post_meta($product->get_id(), '_sync_basalam_discount_applied', true);
            $had_discount_percent = get_post_meta($product->get_id(), '_sync_basalam_discount_percent', true);

            if ($had_discount_applied || $had_discount_percent) {
                $result = $processor->create_remove_discount_task($product->get_id());
                if ($result['success']) {
                    $tasks_created++;
                }
            }
        } elseif ($product->is_type('variable')) {
            // Check variations for previous discounts
            foreach ($product->get_children() as $variation_id) {
                $had_discount_applied = get_post_meta($variation_id, '_sync_basalam_discount_applied', true);
                $had_discount_percent = get_post_meta($variation_id, '_sync_basalam_discount_percent', true);

                if ($had_discount_applied || $had_discount_percent) {
                    // Debug log

                    // Check if this specific variation should have discount removed
                    $variation = wc_get_product($variation_id);
                    if ($variation) {
                        $should_remove = $this->shouldRemoveDiscountForVariation($variation);

                        if ($should_remove) {
                            $result = $processor->create_remove_discount_task($product->get_id(), $variation_id);
                            if ($result['success']) {
                                $tasks_created++;
                            } else {
                            }
                        }
                    }
                }
            }
        }

        if ($tasks_created > 0) {
            // Start the processor if not already running
            $this->task_scheduler->start_processor();

            // Also schedule the cron job if not already scheduled
            $this->schedule_discount_processor();

            return $this->success("تسک حذف تخفیف برای پردازش در صف قرار گرفت. {$tasks_created} وظیفه حذف تخفیف ایجاد شد.");
        }

        return $this->success('هیچ تخفیف قبلی برای حذف یافت نشد.');
    }

    /**
     * Check if discount should be removed for a product
     * 
     * @param WC_Product $product
     * @return bool
     */
    private function shouldRemoveDiscount(WC_Product $product): bool
    {
        // Check if product had any discount meta (applied or percent)
        $product_has_discount_meta = $this->productHasDiscountMeta($product);

        // Debug log

        if (!$product_has_discount_meta) {
            return false; // No discount to remove
        }

        // If price field is set to original, remove discount
        if ($this->price_field !== self::PRICE_FIELD_SALE) {
            return true;
        }

        // If price field is sale_price but product has no sale price, remove discount
        if ($product->is_type('simple') && !$product->get_sale_price()) {
            return true;
        }

        if ($product->is_type('variable')) {
            // For variable products, check each variation that has discount meta
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) continue;

                // Check if this variation has discount meta
                $had_discount_applied = get_post_meta($variation_id, '_sync_basalam_discount_applied', true);
                $had_discount_percent = get_post_meta($variation_id, '_sync_basalam_discount_percent', true);

                if ($had_discount_applied || $had_discount_percent) {
                    // This variation has discount meta, check if it should be removed
                    if ($this->price_field !== self::PRICE_FIELD_SALE) {
                        return true; // Remove discount if price field is not sale_price
                    }

                    if (!$variation->get_sale_price()) {
                        return true; // Remove discount if variation has no sale price
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if discount should be removed for a specific variation
     * This method assumes the variation already has discount meta
     * 
     * @param WC_Product_Variation $variation
     * @return bool
     */
    private function shouldRemoveDiscountForVariation(WC_Product_Variation $variation): bool
    {
        // If price field is set to original, remove discount
        if ($this->price_field !== self::PRICE_FIELD_SALE) {
            return true;
        }

        // If price field is sale_price but variation has no sale price, remove discount
        if (!$variation->get_sale_price()) {
            return true;
        }

        return false;
    }

    /**
     * Check if product has any discount meta data
     * 
     * @param WC_Product $product
     * @return bool
     */
    private function productHasDiscountMeta(WC_Product $product): bool
    {
        if ($product->is_type('simple')) {
            $had_discount_applied = get_post_meta($product->get_id(), '_sync_basalam_discount_applied', true);
            $had_discount_percent = get_post_meta($product->get_id(), '_sync_basalam_discount_percent', true);
            return !empty($had_discount_applied) || !empty($had_discount_percent);
        }

        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $variation_id) {
                $had_discount_applied = get_post_meta($variation_id, '_sync_basalam_discount_applied', true);
                $had_discount_percent = get_post_meta($variation_id, '_sync_basalam_discount_percent', true);
                if (!empty($had_discount_applied) || !empty($had_discount_percent)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Schedule the discount processor cron job if not already scheduled
     */
    private function schedule_discount_processor()
    {
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-discount-task-processor.php';
        Sync_Basalam_Discount_Task_Processor::schedule_recurring_processor();
    }

    private function success(string $message): array
    {
        return [
            'success' => true,
            'message' => $message,
            'status_code' => 200
        ];
    }

    private function error(string $message, Throwable $exception): array
    {
        return [
            'success' => false,
            'message' => $message . ' : ' . $exception->getMessage(),
            'error' => $exception->getMessage(),
            'status_code' => 400
        ];
    }
}
