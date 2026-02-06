<?php

namespace SyncBasalam\Queue\Tasks;

use SyncBasalam\Admin\Product\ProductOperations;
use SyncBasalam\AsyncBackgroundProcess;

defined('ABSPATH') || exit;

class CreateProduct extends AsyncBackgroundProcess
{
    protected $action = 'CreateSingleProduct';
    protected $batch_size = 1;

    protected function task($item)
    {
        $operator = new ProductOperations();
        $operator->createNewProduct($item['id'], null);

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
