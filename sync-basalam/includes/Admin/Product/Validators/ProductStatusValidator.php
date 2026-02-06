<?php

namespace SyncBasalam\Admin\Product\Validators;

use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

class ProductStatusValidator
{
    public function validate(int $product_id): array
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return [
                'valid' => false,
                'message' => sprintf('محصول با شناسه %d وجود ندارد.', $product_id)
            ];
        }

        if ($product->get_status() !== 'publish') return [
            'valid' => false,
            'message' => sprintf('محصول %d منتشر شده نیست.', $product_id)
        ];

        return [
            'valid' => true,
            'message' => sprintf('محصول %d برای عملیات معتبر است.', $product_id)
        ];
    }

    public function validateBasalamConnection(int $product_id): array
    {
        $basalamProductId = get_post_meta($product_id, 'sync_basalam_product_id', true);

        if (empty($basalamProductId)) {
            return [
                'valid' => false,
                'message' => sprintf('محصول %d به باسلام متصل نیست.', $product_id)
            ];
        }

        return [
            'valid' => true,
            'message' => sprintf('محصول %d به باسلام متصل است.', $product_id)
        ];
    }

    public function logValidationResult(int $product_id, array $result, string $operation): void
    {
        if (!$result['valid']) {
            Logger::warning($result['message'], [
                'product_id' => $product_id,
                'عملیات' => $operation,
                'اعتبارسنجی_ناموفق' => true
            ]);
        }
    }
}
