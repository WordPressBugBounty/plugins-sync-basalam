<?php

namespace SyncBasalam\Admin\Product\Data\Strategies;

use SyncBasalam\Admin\Product\Data\Handlers\ProductDataHandlerInterface;

defined('ABSPATH') || exit;

interface DataStrategyInterface
{
    public function collect($product, ProductDataHandlerInterface $handler): array;
}