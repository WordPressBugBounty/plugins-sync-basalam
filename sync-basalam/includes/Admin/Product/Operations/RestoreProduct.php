<?php

namespace SyncBasalam\Admin\Product\Operations;

use SyncBasalam\Admin\Product\Operations\AbstractProductOperation;
use SyncBasalam\Services\Products\UpdateSingleProductService;

defined('ABSPATH') || exit;

class RestoreProduct extends AbstractProductOperation
{
    private UpdateSingleProductService $updateProductService;
    private const STATUS_ACTIVE = 2976;

    public function __construct()
    {
        parent::__construct();
        $this->updateProductService = new UpdateSingleProductService();
    }


    protected function run(int $product_id, array $args = []): array
    {
        $result = $this->updateProductService->updateProductStatus($product_id, self::STATUS_ACTIVE);

        if (!$result) throw new \Exception('بازگردانی وضعیت محصول ناموفق بود');

        return [
            'success' => true,
            'message' => sprintf('محصول با موفقیت بازگردانی شد.'),
            'status_code' => 200,
            'product_id' => $product_id,
            'restored' => true
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
