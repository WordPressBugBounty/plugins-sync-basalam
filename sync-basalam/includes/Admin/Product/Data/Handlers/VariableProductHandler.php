<?php

namespace SyncBasalam\Admin\Product\Data\Handlers;

use SyncBasalam\Admin\Product\Data\Services\VariantService;

defined('ABSPATH') || exit;

class VariableProductHandler extends SimpleProductHandler
{
    private VariantService $variantService;

    public function __construct()
    {
        parent::__construct();
        $this->variantService = new VariantService();
    }

    public function getVariants($product): array
    {
        return $this->variantService->getVariants($product);
    }

    public function getPrice($product): ?int
    {
        return null;
    }

    public function getStock($product): int
    {
        return 0;
    }
}