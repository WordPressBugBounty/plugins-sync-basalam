<?php

namespace SyncBasalam\Queue\Tasks;

use SyncBasalam\Admin\Product\ProductOperations;
use SyncBasalam\AsyncBackgroundProcess;
use SyncBasalam\Services\Products\ProductOwnership;

defined('ABSPATH') || exit;

class UpdateProduct extends AsyncBackgroundProcess
{
    protected $action = 'sync_basalam_update_single_product';
    protected $batch_size = 1;
    private $operator;

    public function __construct($operator = null)
    {
        parent::__construct();
        $this->operator = $operator ?: syncBasalamContainer()->get(ProductOperations::class);
    }

    protected function task($item)
    {
        if (!(new ProductOwnership())->prepareQueuedUpdate((int) $item['id'])) return false;
        $this->operator->updateExistProduct($item['id']);

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
