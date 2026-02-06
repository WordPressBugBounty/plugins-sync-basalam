<?php

namespace SyncBasalam\Admin\Product\Operations;

use SyncBasalam\Admin\Product\Operations\AbstractProductOperation;
use SyncBasalam\Services\Products\UpdateSingleProductService;

defined('ABSPATH') || exit;

class ArchiveProduct extends AbstractProductOperation
{
    private UpdateSingleProductService $updateProductService;
    private const STATUS_ARCHIVED = 3790;

    public function __construct()
    {
        parent::__construct();
        $this->updateProductService = new UpdateSingleProductService();
    }

    
    protected function run(int $product_id, array $args = []): array
    {
        $result = $this->updateProductService->updateProductStatus($product_id, self::STATUS_ARCHIVED);

        if (!$result) throw new \Exception('بایگانی وضعیت محصول ناموفق بود');

        return [
            'success' => true,
            'message' => sprintf('محصول با موفقیت بایگانی شد.'),
            'status_code' => 200,
            'product_id' => $product_id,
            'archived' => true
        ];
    }

    public function validate(int $product_id): bool
    {
        if (!parent::validate($product_id)) return false;

        $validation = $this->validator->validateBasalamConnection($product_id);
        if (!$validation['valid']) {
            $this->validator->logValidationResult($product_id, $validation, $this->getOperationNameFromClass());
            return false;
        }

        return true;
    }
}