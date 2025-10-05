<?php

class SyncBasalamJobsRunner
{
    private static $instance = null;

    private $job_types_priority = [
        'sync_basalam_update_bulk_products' => 1,
        'sync_basalam_update_all_products' => 2,
        'sync_basalam_full_update_products' => 3,
        'sync_basalam_update_single_product' => 4,
        'sync_basalam_create_single_product' => 5,
        'sync_basalam_create_all_products' => 6
    ];

    private $job_manager;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    function __construct()
    {
        add_action('init', [$this, 'check_and_run_jobs']);
        $this->job_manager = SyncBasalamJobManager::get_instance();
    }

    public function check_and_run_jobs()
    {
        $tasks_per_minute = sync_basalam_Admin_Settings::get_effective_tasks_per_minute();
        $threshold_seconds = 60 / $tasks_per_minute;

        asort($this->job_types_priority);

        foreach ($this->job_types_priority as $job_type => $priority) {
            $last_run = get_option($job_type, 0);

            if (time() - intval($last_run) >= $threshold_seconds) {

                if ($job_type === 'sync_basalam_create_all_products') {
                    if (!$this->are_all_create_single_jobs_completed()) {
                        continue;
                    }
                }

                if ($job_type === 'sync_basalam_full_update_products') {
                    if (!$this->are_all_update_single_jobs_completed()) {
                        continue;
                    }
                }

                $job = $this->job_manager->get_job(['job_type' => $job_type, 'status' => 'pending']);

                if ($job) {
                    $this->job_manager->update_job(['status' => 'processing', 'started_at' => time()], ['id' => $job->id]);

                    $this->execute_job($job);

                    update_option($job_type, time());
                }
            }
        }
    }

    private function execute_job($job)
    {
        $job_type = $job->job_type;
        $payload = json_decode($job->payload, true);

        if ($job_type === 'sync_basalam_update_all_products') {
            $this->update_all_products();
            $this->job_manager->update_job(['status' => 'completed', 'started_at' => time()], ['id' => $job->id]);
        } elseif ($job_type === 'sync_basalam_full_update_products') {
            $this->full_update_products($payload);
            $this->job_manager->update_job(['status' => 'completed', 'started_at' => time()], ['id' => $job->id]);
        } elseif ($job_type === 'sync_basalam_update_single_product') {
            $this->update_single_product($payload);
            $this->job_manager->update_job(['status' => 'completed', 'started_at' => time()], ['id' => $job->id]);
        } elseif ($job_type === 'sync_basalam_create_single_product') {
            $this->create_single_product($payload);
            $this->job_manager->update_job(['status' => 'completed', 'started_at' => time()], ['id' => $job->id]);
        } elseif ($job_type === 'sync_basalam_create_all_products') {
            $this->create_all_products($payload);
            $this->job_manager->update_job(['status' => 'completed', 'started_at' => time()], ['id' => $job->id]);
        }
    }

    private function update_all_products()
    {
        sync_basalam_Product_Queue_Manager::update_all_products_in_basalam();
    }
    private function update_single_product($payload)
    {
        $product_id = $payload['product_id'] ?? $payload; 
        if ($product_id) {
            $product_operations = new sync_basalam_Admin_Product_Operations();
            $product_operations->update_exist_product($product_id, null);
        }
    }
    private function create_single_product($payload)
    {
        $product_id = $payload['product_id'] ?? $payload;
        if ($product_id) {
            $product_operations = new sync_basalam_Admin_Product_Operations();
            $product_operations->create_new_product($product_id, null);
        }
    }

    private function create_all_products($payload)
    {
        $offset = $payload['offset'] ?? 0;
        $include_out_of_stock = $payload['include_out_of_stock'] ?? false;

        $task = new Sync_basalam_Chunk_Create_Products_Task();
        $task->push([
            'offset' => $offset,
            'include_out_of_stock' => $include_out_of_stock
        ]);
        $task->save()->dispatch();
    }

    private function full_update_products($payload)
    {
        $offset = $payload['offset'] ?? 0;

        $posts_per_page = 200;
        $batch_data = [
            'posts_per_page' => $posts_per_page,
            'offset'         => $offset,
        ];

        $product_ids = sync_basalam_Product_Queue_Manager::get_products_for_update($batch_data);

        if (!$product_ids) {
            delete_option('last_offset_full_update_products');
            return;
        }

        
        foreach ($product_ids as $product_id) {
            $this->job_manager->create_job(
                'sync_basalam_update_single_product',
                'pending',
                json_encode(['product_id' => $product_id])
            );
        }

        
        update_option('last_offset_full_update_products', $offset + $posts_per_page);

        
        $next_batch_data = json_encode([
            'offset' => $offset + $posts_per_page
        ]);

        $this->job_manager->create_job(
            'sync_basalam_full_update_products',
            'pending',
            $next_batch_data
        );
    }

    private function are_all_create_single_jobs_completed()
    {
        
        $pending_job = $this->job_manager->get_job([
            'job_type' => 'sync_basalam_create_single_product',
            'status' => 'pending'
        ]);

        if ($pending_job) {
            return false;
        }

        return true;
    }

    private function are_all_update_single_jobs_completed()
    {
        
        $pending_job = $this->job_manager->get_job([
            'job_type' => 'sync_basalam_update_single_product',
            'status' => 'pending'
        ]);

        if ($pending_job) {
            return false;
        }

        return true;
    }
}
