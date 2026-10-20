<?php

namespace SyncBasalam\Tests\Services;

use PHPUnit\Framework\TestCase;
use SyncBasalam\Services\Products\ProductPriceStockRetry;

require_once dirname(__DIR__, 2) . '/includes/Services/Products/ProductPriceStockRetry.php';

class ProductPriceStockRetryTest extends TestCase
{
    /** @dataProvider validationErrors */
    public function testRetriesValidationErrorsWithOnlyPriceAndStock(int $status, bool $throws): void
    {
        $payload = ['name' => 'Rejected name', 'sku' => 'DUPLICATE', 'description' => 'Rejected description', 'primary_price' => 0, 'stock' => 0];
        $requests = [];
        $result = ProductPriceStockRetry::send(function ($data) use (&$requests, $status, $throws) {
            $requests[] = $data;
            if (count($requests) === 1) {
                if ($throws) throw new \RuntimeException('Rejected', $status);
                return ['status_code' => $status];
            }
            return ['status_code' => 200];
        }, $payload);

        self::assertSame(200, $result['status_code']);
        self::assertCount(2, $requests);
        self::assertSame(['primary_price' => 0, 'stock' => 0], $requests[1]);
        self::assertSame($requests[1], $payload);
    }

    public static function validationErrors(): array
    {
        return [[400, false], [400, true], [422, false], [422, true]];
    }

    public function testKeepsVariantIdentityAndRemovesSkus(): void
    {
        $properties = [['property' => 'Color', 'value' => 'Red']];
        $payload = ['data' => [
            ['id' => 10, 'sku' => 'SIMPLE', 'primary_price' => 10000, 'stock' => 0],
            ['id' => 20, 'sku' => 'PARENT', 'variants' => [
                ['id' => 21, 'properties' => $properties, 'sku' => 'VARIANT', 'primary_price' => 20000, 'stock' => 0],
            ]],
        ]];
        $requests = [];
        ProductPriceStockRetry::send(function ($data) use (&$requests) {
            $requests[] = $data;
            return ['status_code' => count($requests) === 1 ? 422 : 202];
        }, $payload);

        self::assertSame(['data' => [
            ['id' => 10, 'primary_price' => 10000, 'stock' => 0],
            ['id' => 20, 'variants' => [
                ['id' => 21, 'properties' => $properties, 'primary_price' => 20000, 'stock' => 0],
            ]],
        ]], $requests[1]);
    }

    /** @dataProvider otherStatuses */
    public function testOtherStatusesDoNotRetryOrBuildFallback(int $status): void
    {
        $payload = ['name' => 'Product', 'stock' => 0];
        $requests = 0;
        $result = ProductPriceStockRetry::send(function () use (&$requests, $status) {
            $requests++;
            return ['status_code' => $status];
        }, $payload, function () {
            self::fail('Fallback must be lazy and limited to 400/422.');
        });

        self::assertSame($status, $result['status_code']);
        self::assertSame(1, $requests);
    }

    public static function otherStatuses(): array
    {
        return [[200], [401], [403], [404], [408], [429], [500], [503]];
    }

    /** @dataProvider validationErrors */
    public function testSecondFailureStopsAfterTwoRequests(int $status, bool $throws): void
    {
        $payload = ['name' => 'Product', 'stock' => 0];
        $requests = 0;
        $failure = new \RuntimeException('Rejected again', $status);
        try {
            $result = ProductPriceStockRetry::send(function () use (&$requests, $status, $throws, $failure) {
                $requests++;
                if ($throws) throw $failure;
                return ['status_code' => $status];
            }, $payload);
            self::assertFalse($throws);
            self::assertSame($status, $result['status_code']);
        } catch (\RuntimeException $exception) {
            self::assertTrue($throws);
            self::assertSame($failure, $exception);
        }
        self::assertSame(2, $requests);
    }

    public function testAlreadyRestrictedAndEmptyUpdatesDoNotRepeat(): void
    {
        foreach ([['stock' => 0], ['name' => 'Product'], ['id' => 10, 'variants' => []]] as $payload) {
            $requests = 0;
            ProductPriceStockRetry::send(function () use (&$requests) {
                $requests++;
                return ['status_code' => 422];
            }, $payload);
            self::assertSame(1, $requests);
        }
    }

    /** @dataProvider mixedOutcomes */
    public function testBoundsMixedReturnedAndThrownFailures(int $firstStatus, bool $firstThrows, int $secondStatus, bool $secondThrows): void
    {
        $payload = ['description' => 'Rejected', 'primary_price' => 12345, 'stock' => 0];
        $requests = [];
        $secondFailure = new \RuntimeException('Second request failed', $secondStatus);
        try {
            $result = ProductPriceStockRetry::send(function ($data) use (&$requests, $firstStatus, $firstThrows, $secondStatus, $secondThrows, $secondFailure) {
                $requests[] = $data;
                if (count($requests) === 1) {
                    if ($firstThrows) throw new \RuntimeException('First request failed', $firstStatus);
                    return ['status_code' => $firstStatus];
                }
                if ($secondThrows) throw $secondFailure;
                return ['status_code' => $secondStatus];
            }, $payload);
            self::assertFalse($secondThrows);
            self::assertSame($secondStatus, $result['status_code']);
        } catch (\RuntimeException $exception) {
            self::assertTrue($secondThrows);
            self::assertSame($secondFailure, $exception);
        }
        self::assertCount(2, $requests);
        self::assertSame(['primary_price' => 12345, 'stock' => 0], $requests[1]);
    }

    public static function mixedOutcomes(): array
    {
        $cases = [];
        foreach ([400, 422] as $first) {
            foreach ([false, true] as $firstThrows) {
                foreach ([200, 400, 422, 503] as $second) {
                    foreach ($second === 200 ? [false] : [false, true] as $secondThrows) {
                        $cases[] = [$first, $firstThrows, $second, $secondThrows];
                    }
                }
            }
        }
        return $cases;
    }

    public function testFallbackBuilderRunsOnceAndCannotRestoreOtherFields(): void
    {
        $payload = ['name' => 'Custom name only'];
        $builds = 0;
        $requests = 0;
        ProductPriceStockRetry::send(function ($data) use (&$requests) {
            $requests++;
            return ['status_code' => $requests === 1 ? '422' : 200];
        }, $payload, function () use (&$builds) {
            $builds++;
            return ['name' => 'Unexpected', 'sku' => 'Unexpected', 'stock' => 0, 'primary_price' => 12345];
        });
        self::assertSame(1, $builds);
        self::assertSame(2, $requests);
        self::assertSame(['stock' => 0, 'primary_price' => 12345], $payload);
    }

    public function testNetworkFailurePreservesPayloadAndException(): void
    {
        $payload = $original = ['name' => 'Product', 'stock' => 0];
        $failure = new \RuntimeException('Connection closed');
        $requests = 0;
        try {
            ProductPriceStockRetry::send(function () use (&$requests, $failure) {
                $requests++;
                throw $failure;
            }, $payload, function () { self::fail('Network errors must not build a price/stock payload.'); });
            self::fail('Expected the network error.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
        self::assertSame(1, $requests);
        self::assertSame($original, $payload);
    }
}
