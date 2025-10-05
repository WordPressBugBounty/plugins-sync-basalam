<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Cancel_Update_Jobs extends Sync_basalamController
{
    public function __invoke()
    {
        $job_manager = new SyncBasalamJobManager();

        $job_types = [
            'sync_basalam_update_all_products',
            'sync_basalam_full_update_products',
            'sync_basalam_update_single_product'
        ];

        $deleted_count = 0;
        foreach ($job_types as $job_type) {
            $result = $job_manager->delete_job([
                'job_type' => $job_type,
                'status' => 'pending'
            ]);
            if ($result) {
                $deleted_count += $result;
            }

            $result = $job_manager->delete_job([
                'job_type' => $job_type,
                'status' => 'processing'
            ]);
            if ($result) {
                $deleted_count += $result;
            }
        }

        delete_option('last_offset_update_products');
        delete_option('last_offset_full_update_products');
        delete_option('last_offset_update_products_new');
    }
}
