<?php

namespace SyncBasalam\Admin\Product\Operations;

use SyncBasalam\Admin\Product\Operations\ProductOperationInterface;
use SyncBasalam\Admin\Product\Validators\ProductStatusValidator;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

abstract class AbstractProductOperation implements ProductOperationInterface
{
    protected ProductStatusValidator $validator;

    public function __construct()
    {
        $this->validator = new ProductStatusValidator();
    }

    final public function execute(int $product_id, array $args = []): array
    {
        try {
            $validation = $this->validate($product_id);
            
            if (!$validation) return $this->buildValidationErrorResult($product_id);

            $result = $this->run($product_id, $args);

            $this->logSuccess($product_id, $result);

            return $result;
        } catch (\Throwable $th) {
            return $this->handleException($th, $product_id);
        }
    }

    abstract protected function run(int $product_id, array $args = []): array;

    public function validate(int $product_id): bool
    {
        $result = $this->validator->validate($product_id);

        if (!$result['valid']) $this->validator->logValidationResult($product_id, $result, $this->getPersianOperationName());

        return $result['valid'];
    }

    protected function handleException(\Throwable $exception, int $product_id): array
    {
        update_post_meta($product_id, 'sync_basalam_product_sync_status', 'no');

        Logger::error(sprintf("خطا در %s: %s", $this->getPersianOperationName(), $exception->getMessage()), [
            'product_id' => $product_id,
            'عملیات' => $this->getPersianOperationName(),
        ]);

        return $this->buildErrorResult($exception, $product_id);
    }

    protected function buildErrorResult(\Throwable $exception, int $product_id): array
    {
        return [
            'success' => false,
            'message' => sprintf('فرایند %s محصول ناموفق بود: %s', $this->getPersianOperationName(), $exception->getMessage()),
            'error' => $exception->getMessage(),
            'status_code' => $this->getStatusCodeFromException($exception),
            'product_id' => $product_id
        ];
    }

    protected function buildValidationErrorResult(int $product_id): array
    {
        $validation = $this->validator->validate($product_id);

        return [
            'success' => false,
            'message' => $validation['message'],
            'error' => 'validation_failed',
            'status_code' => 422,
            'product_id' => $product_id,
            'validation_error' => true
        ];
    }

    protected function logSuccess(int $product_id, array $result): void
    {
        if ($result['success']) {
            Logger::info(sprintf("%s با موفقیت انجام شد.", $this->getPersianOperationName()), [
                'product_id' => $product_id,
                'عملیات' => $this->getPersianOperationName(),
                'نتیجه' => $result
            ]);
        }
    }

    protected function getStatusCodeFromException(\Throwable $exception): int
    {
        if (method_exists($exception, 'getCode') && is_int($exception->getCode())) return $exception->getCode();
        return 400;
    }

    protected function getOperationNameFromClass(): string
    {
        $className = static::class;
        $className = substr($className, strrpos($className, '\\') + 1);
        return lcfirst($className);
    }

    protected function getPersianOperationName(): string
    {
        $operationNames = [
            'updateProduct' => 'بروزرسانی محصول',
            'createProduct' => 'ایجاد محصول',
            'restoreProduct' => 'بازگردانی محصول',
            'archiveProduct' => 'بایگانی محصول',
        ];

        $operationName = $this->getOperationNameFromClass();
        return $operationNames[$operationName] ?? $operationName;
    }
}
