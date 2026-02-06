<?php

namespace SyncBasalam\Admin\Product\Data;

use SyncBasalam\Admin\Product\Data\Strategies\DataStrategyInterface;
use SyncBasalam\Admin\Product\Data\Validators\ValidatorChain;
use SyncBasalam\Admin\Product\ProductDataFactory;

defined('ABSPATH') || exit;

class ProductDataBuilder
{
    private array $data = [];
    private ?ValidatorChain $validator;
    private ProductDataFactory $factory;
    private DataStrategyInterface $strategy;

    public function __construct(
        ?ValidatorChain $validator,
        ProductDataFactory $factory
    ) {
        $this->validator = $validator;
        $this->factory = $factory;
        $this->initializeData();
    }

    public function setStrategy(DataStrategyInterface $strategy): self
    {
        $this->strategy = $strategy;
        return $this;
    }

    public function fromWooProduct(int $productId): self
    {
        $product = wc_get_product($productId);

        if (!$product) throw new \InvalidArgumentException("Product with ID {$productId} not found");

        // Validate product first
        if ($this->validator) $this->validator->validate($product);

        // Use factory to get appropriate data handler
        $handler = $this->factory->createHandler($product);

        // Apply strategy to collect data
        $this->data = $this->strategy->collect($product, $handler);

        return $this;
    }

    public function withCategoryIds(array $categoryIds = null): self
    {
        if ($categoryIds !== null) $this->data['category_ids'] = $categoryIds;
        return $this;
    }

    public function build(): array
    {
        return $this->data;
    }

    public function reset(): self
    {
        $this->initializeData();
        return $this;
    }

    private function initializeData(): void
    {
        $this->data = [
            'name' => '',
            'description' => '',
            'category_id' => null,
            'category_ids' => [],
            'primary_price' => null,
            'stock' => null,
            'weight' => null,
            'package_weight' => null,
            'photo' => null,
            'photos' => [],
            'status' => 2976,
            'preparation_days' => null,
            'unit_type' => 6304,
            'unit_quantity' => 1,
            'is_wholesale' => false,
            'variants' => [],
            'product_attribute' => [],
        ];
    }
}
