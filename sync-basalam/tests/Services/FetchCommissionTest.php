<?php

namespace SyncBasalam\Tests\Services;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Services\Products\FetchCommission;

class FetchCommissionTest extends TestCase
{
    private $apiService;

    protected function setUp(): void
    {
        FetchCommission::resetProductCommissionCache();

        $this->apiService = new class {
            public array $requestedUrls = [];
            public array $response = [
                'status_code' => 200,
                'body' => '{}',
            ];
            public ?\Throwable $exception = null;

            public function get($url): array
            {
                $this->requestedUrls[] = $url;

                if ($this->exception !== null) {
                    throw $this->exception;
                }

                return $this->response;
            }
        };

        $apiService = $this->apiService;
        $GLOBALS['sync_basalam_test_container'] = new class($apiService) {
            private $apiService;

            public function __construct($apiService)
            {
                $this->apiService = $apiService;
            }

            public function get(string $service)
            {
                if ($service !== ApiServiceManager::class) {
                    throw new RuntimeException('Unexpected service requested: ' . $service);
                }

                return $this->apiService;
            }
        };
    }

    protected function tearDown(): void
    {
        FetchCommission::resetProductCommissionCache();
        unset($GLOBALS['sync_basalam_test_container']);
    }

    public function testProductCommissionUsesProductEndpointAndReadsEffectiveCommission(): void
    {
        $this->apiService->response['body'] = json_encode([
            'commission_data' => [
                'commission_percent' => 4,
            ],
            'category_commission_data' => [
                'commission_percent' => 6.5,
            ],
        ]);

        self::assertSame(4, FetchCommission::fetchProductCommission(57983220));
        self::assertSame(
            ['https://core.basalam.com/api_v2/commission/products/57983220/percent'],
            $this->apiService->requestedUrls
        );
    }

    public function testProductCommissionIsFetchedOncePerProductDuringTheRequest(): void
    {
        $productId = 57983221;
        $this->apiService->response['body'] = json_encode([
            'commission_data' => [
                'commission_percent' => 4,
            ],
        ]);

        self::assertSame(4, FetchCommission::fetchProductCommission($productId));
        self::assertSame(4, FetchCommission::fetchProductCommission($productId));
        self::assertSame(
            [sprintf(Endpoints::PRODUCT_COMMISSION, $productId)],
            $this->apiService->requestedUrls
        );
    }

    public function testCategoryCommissionKeepsTheExistingCreateFlow(): void
    {
        $this->apiService->response['body'] = json_encode([
            'commission_data' => [
                'commission_percent' => 6.5,
            ],
        ]);

        self::assertSame(6.5, FetchCommission::fetchCategoryCommission([11, 22, 33]));
        self::assertSame(
            [Endpoints::COMMISSION . '?product.category.level1=11&product.category.level2=22&product.category.level3=33'],
            $this->apiService->requestedUrls
        );
    }

    /**
     * @dataProvider invalidProductIdProvider
     */
    public function testInvalidProductIdReturnsZeroWithoutRequest($productId): void
    {
        self::assertSame(0, FetchCommission::fetchProductCommission($productId));
        self::assertSame([], $this->apiService->requestedUrls);
    }

    public function invalidProductIdProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'non numeric string' => ['not-an-id'],
            'zero' => [0],
            'negative integer' => [-10],
        ];
    }

    /**
     * @dataProvider invalidResponseProvider
     */
    public function testInvalidProductCommissionResponseReturnsZero($body): void
    {
        $this->apiService->response['body'] = $body;

        self::assertSame(0, FetchCommission::fetchProductCommission(57983220));
    }

    public function invalidResponseProvider(): array
    {
        return [
            'malformed JSON' => ['not-json'],
            'empty response' => [''],
            'missing commission data' => [json_encode([])],
            'missing commission percent' => [json_encode(['commission_data' => []])],
            'non numeric percent' => [json_encode(['commission_data' => ['commission_percent' => 'unknown']])],
        ];
    }

    public function testProductCommissionRequestFailureReturnsZero(): void
    {
        $this->apiService->exception = new RuntimeException('Network failure');

        self::assertSame(0, FetchCommission::fetchProductCommission(57983220));
    }
}
