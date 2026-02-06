<?php

namespace SyncBasalam\Queue\Tasks;

use SyncBasalam\Admin\Product\ProductOperations;
use SyncBasalam\AsyncBackgroundProcess;

defined('ABSPATH') || exit;

class UpdateProduct extends AsyncBackgroundProcess
{
    protected $action = 'sync_basalam_update_single_product';
    protected $batch_size = 1;

    protected function task($item)
    {
        $operator = new ProductOperations();
        $operator->updateExistProduct($item['id']);

        return false;
    }

    protected function complete()
    {
        parent::complete();
    }

    public function isActive()
    {
        return get_site_transient($this->identifier . '_process_lock') !== false;
    }
}
