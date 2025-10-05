<?php
if (!defined('ABSPATH')) exit;

class Sync_Basalam_Product_Discount_Handler
{
    private const PRICE_FIELD_SALE = 'sale_strikethrough_price';

    private $discount_service;
    private $price_field;
    private $sync_price_mode;
    private $sync_fields_mode;
    private $task_scheduler;

    public function __construct()
    {
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-discount-task-scheduler.php';

        $this->discount_service = new Sync_Basalam_Discount_Manager();
        $this->price_field = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::PRODUCT_PRICE_FIELD);
        $this->sync_price_mode = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELD_PRICE);
        $this->sync_fields_mode = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::SYNC_PRODUCT_FIELDS);
        $this->task_scheduler = new Sync_Basalam_Discount_Task_Scheduler();
    }

    public function handle(int $product_id)
    {
        try {
            if ($this->sync_fields_mode == 'custom') {
                if (!$this->sync_price_mode) {
                    return false;
                }
            }
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
            
            $this->task_scheduler->start_processor();

            
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

    private function handleSimpleProduct(WC_Product $product): array
    {
        
        if ($this->shouldApplySalePriceDiscount($product)) {
            return $this->scheduleDiscountTasks($product);
        } else {
            return $this->checkAndScheduleRemoveDiscount($product);
        }
    }

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

    private function handleSingleVariation(WC_Product_Variation $variation, int $parent_product_id, $processor): array
    {
        $variation_id = $variation->get_id();
        $has_sale_price = !empty($variation->get_sale_price());
        $has_discount_meta = $this->variationHasDiscountMeta($variation_id);

        
        if ($this->price_field !== self::PRICE_FIELD_SALE) {
            if ($has_discount_meta) {
                $result = $processor->create_remove_discount_task($parent_product_id, $variation_id);
                if ($result['success']) {
                    return ['action' => 'remove', 'success' => true];
                }
            }
            return ['action' => 'none', 'success' => true];
        }

        
        if ($has_sale_price) {
            
            $discount_percent = $this->calculateVariationDiscountPercent($variation);
            if ($discount_percent > 0) {
                $active_days = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::DISCOUNT_DURATION);
                $basalam_product_id = get_post_meta($parent_product_id, 'sync_basalam_product_id', true);
                $basalam_variation_id = get_post_meta($variation_id, 'sync_basalam_variation_id', true);

                if ($basalam_variation_id) {
                    $this->task_scheduler->schedule_discount_task(
                        null, 
                        $discount_percent,
                        $active_days,
                        $basalam_variation_id,
                        0,
                        'apply'
                    );
                    return ['action' => 'apply', 'success' => true];
                }
            }
            return ['action' => 'none', 'success' => true]; 
        } else {
            
            if ($has_discount_meta) {
                $result = $processor->create_remove_discount_task($parent_product_id, $variation_id);
                if ($result['success']) {
                    return ['action' => 'remove', 'success' => true];
                }
            }
            return ['action' => 'none', 'success' => true];
        }
    }

    private function variationHasDiscountMeta(int $variation_id): bool
    {
        $had_discount_applied = get_post_meta($variation_id, '_sync_basalam_discount_applied', true);
        $had_discount_percent = get_post_meta($variation_id, '_sync_basalam_discount_percent', true);
        return !empty($had_discount_applied) || !empty($had_discount_percent);
    }

    private function calculateVariationDiscountPercent(WC_Product_Variation $variation): float
    {
        $regular_price = $variation->get_regular_price();
        $sale_price = $variation->get_sale_price();

        if ($regular_price && $sale_price && $regular_price > $sale_price) {
            return Sync_Basalam_Discount_Manager::calculate_discount_percent($regular_price, $sale_price);
        }

        return 0;
    }

    private function checkAndScheduleRemoveDiscount(WC_Product $product): array
    {
        $tasks_created = 0;
        require_once SYNC_BASALAM_PLUGIN_INCLUDES_DIR . 'services/class-sync-basalam-discount-task-processor.php';
        $processor = new Sync_Basalam_Discount_Task_Processor();

        
        $should_remove_discount = $this->shouldRemoveDiscount($product);

        if (!$should_remove_discount) {
            return $this->success('شرایط حذف تخفیف برقرار نیست.');
        }

        

        if ($product->is_type('simple')) {
            
            $had_discount_applied = get_post_meta($product->get_id(), '_sync_basalam_discount_applied', true);
            $had_discount_percent = get_post_meta($product->get_id(), '_sync_basalam_discount_percent', true);

            if ($had_discount_applied || $had_discount_percent) {
                $result = $processor->create_remove_discount_task($product->get_id());
                if ($result['success']) {
                    $tasks_created++;
                }
            }
        } elseif ($product->is_type('variable')) {
            
            foreach ($product->get_children() as $variation_id) {
                $had_discount_applied = get_post_meta($variation_id, '_sync_basalam_discount_applied', true);
                $had_discount_percent = get_post_meta($variation_id, '_sync_basalam_discount_percent', true);

                if ($had_discount_applied || $had_discount_percent) {
                    

                    
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
            
            $this->task_scheduler->start_processor();

            
            $this->schedule_discount_processor();

            return $this->success("تسک حذف تخفیف برای پردازش در صف قرار گرفت. {$tasks_created} وظیفه حذف تخفیف ایجاد شد.");
        }

        return $this->success('هیچ تخفیف قبلی برای حذف یافت نشد.');
    }

    private function shouldRemoveDiscount(WC_Product $product): bool
    {
        
        $product_has_discount_meta = $this->productHasDiscountMeta($product);

        

        if (!$product_has_discount_meta) {
            return false; 
        }

        
        if ($this->price_field !== self::PRICE_FIELD_SALE) {
            return true;
        }

        
        if ($product->is_type('simple') && !$product->get_sale_price()) {
            return true;
        }

        if ($product->is_type('variable')) {
            
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) continue;

                
                $had_discount_applied = get_post_meta($variation_id, '_sync_basalam_discount_applied', true);
                $had_discount_percent = get_post_meta($variation_id, '_sync_basalam_discount_percent', true);

                if ($had_discount_applied || $had_discount_percent) {
                    
                    if ($this->price_field !== self::PRICE_FIELD_SALE) {
                        return true; 
                    }

                    if (!$variation->get_sale_price()) {
                        return true; 
                    }
                }
            }
        }

        return false;
    }

    private function shouldRemoveDiscountForVariation(WC_Product_Variation $variation): bool
    {
        
        if ($this->price_field !== self::PRICE_FIELD_SALE) {
            return true;
        }

        
        if (!$variation->get_sale_price()) {
            return true;
        }

        return false;
    }

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
