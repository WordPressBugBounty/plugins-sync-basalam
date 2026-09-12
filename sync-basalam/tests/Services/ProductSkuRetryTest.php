<?php

namespace SyncBasalam\Tests\Services;

use PHPUnit\Framework\TestCase;
use SyncBasalam\Services\Products\ProductSkuRetry;

class ProductSkuRetryTest extends TestCase
{
    public function testRemovesProductAndVariationSkusWithoutChangingOtherFields(): void
    {
        $payload = [
            'name'     => 'محصول تست',
            'sku'      => 'PRODUCT-1',
            'variants' => [
                ['id' => 1, 'sku' => 'VARIANT-1', 'stock' => 2],
                ['id' => 2, 'sku' => 'VARIANT-2', 'stock' => 3],
            ],
        ];

        $withoutSkus = ProductSkuRetry::withoutSkus($payload);

        self::assertSame('PRODUCT-1', $payload['sku']);
        self::assertSame('VARIANT-1', $payload['variants'][0]['sku']);
        self::assertArrayNotHasKey('sku', $withoutSkus);
        self::assertArrayNotHasKey('sku', $withoutSkus['variants'][0]);
        self::assertArrayNotHasKey('sku', $withoutSkus['variants'][1]);
        self::assertSame($payload['variants'][0]['stock'], $withoutSkus['variants'][0]['stock']);
        self::assertSame($payload['variants'][1]['stock'], $withoutSkus['variants'][1]['stock']);
    }

    public function testDetectsDuplicateSkuFromFieldsOnly(): void
    {
        $error = [
            'status_code' => 422,
            'body'        => json_encode([
                'messages' => [
                    ['fields' => ['sku'], 'message' => 'مقدار نامعتبر است'],
                ],
            ]),
        ];

        self::assertTrue(ProductSkuRetry::isDuplicateSkuError($error));
    }

    public function testDetectsDuplicateSkuFromMessageWhenFieldIsMissing(): void
    {
        $error = [
            'status_code' => 422,
            'body'        => [
                'messages' => [
                    ['fields' => [], 'message' => 'شناسه sku محصولات باید یکتا باشد'],
                ],
            ],
        ];

        self::assertTrue(ProductSkuRetry::isDuplicateSkuError($error));
    }

    public function testDetectsDuplicateSkuInPerItemBatchResults(): void
    {
        self::assertTrue(ProductSkuRetry::isDuplicateSkuError([
            'id'            => 91,
            'has_error'     => true,
            'error_message' => 'شناسه sku محصولات باید یکتا باشد.',
        ]));

        self::assertTrue(ProductSkuRetry::isDuplicateSkuError([
            'id'     => 92,
            'status' => 'failed',
            'error'  => [
                'code'    => 'SKU_DUPLICATE',
                'message' => 'SKU already exists',
            ],
        ]));
    }

    public function testDoesNotDetectUnrelatedOrSuccessfulResponses(): void
    {
        self::assertFalse(ProductSkuRetry::isDuplicateSkuError([
            'status_code' => 422,
            'body'        => [
                'messages' => [
                    ['fields' => ['name'], 'message' => 'نام کالا تکراری است'],
                ],
            ],
        ]));

        self::assertFalse(ProductSkuRetry::isDuplicateSkuError([
            'status_code' => 200,
            'body'        => [
                'messages' => [
                    ['fields' => ['sku'], 'message' => 'SKU already exists'],
                ],
            ],
        ]));
    }
}
