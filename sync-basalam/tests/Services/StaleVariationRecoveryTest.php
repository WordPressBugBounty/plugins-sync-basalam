<?php

namespace {
    if (!function_exists('esc_html')) {
        function esc_html($value)
        {
            return (string) $value;
        }
    }

    if (!function_exists('is_wp_error')) {
        function is_wp_error($value)
        {
            return false;
        }
    }

    if (!function_exists('wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body($response)
        {
            return $response['body'] ?? '';
        }
    }

    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code($response)
        {
            return $response['response']['code'] ?? 0;
        }
    }

    if (!function_exists('get_option')) {
        function get_option($name, $default = false)
        {
            return $GLOBALS['sync_basalam_test_options'][$name] ?? $default;
        }
    }

    if (!function_exists('update_option')) {
        function update_option($name, $value, $autoload = null)
        {
            $GLOBALS['sync_basalam_test_options'][$name] = $value;
            return true;
        }
    }

    if (!function_exists('wc_get_product')) {
        function wc_get_product($productId)
        {
            return $GLOBALS['sync_basalam_test_products'][$productId] ?? null;
        }
    }

    if (!function_exists('delete_post_meta')) {
        function delete_post_meta($postId, $metaKey)
        {
            $GLOBALS['sync_basalam_deleted_meta'][] = [$postId, $metaKey];
            return true;
        }
    }

    if (!function_exists('get_post_meta')) {
        function get_post_meta($postId, $metaKey, $single = false)
        {
            return $GLOBALS['sync_basalam_test_meta'][$postId][$metaKey] ?? '';
        }
    }

    if (!function_exists('update_post_meta')) {
        function update_post_meta($postId, $metaKey, $value)
        {
            $GLOBALS['sync_basalam_test_meta'][$postId][$metaKey] = $value;
            $GLOBALS['sync_basalam_updated_meta'][] = [$postId, $metaKey, $value];
            return true;
        }
    }

    if (!function_exists('get_post_type')) {
        function get_post_type($postId)
        {
            return 'product';
        }
    }

    if (!function_exists('apply_filters')) {
        function apply_filters($hookName, $value)
        {
            return $value;
        }
    }

    if (!function_exists('do_action')) {
        function do_action($hookName, ...$args)
        {
        }
    }

    if (!function_exists('syncBasalamSettings')) {
        function syncBasalamSettings()
        {
            return new class {
                public function getSettings($setting = null)
                {
                    $settings = $GLOBALS['sync_basalam_test_options']['sync_basalam_settings'] ?? [];

                    return $setting === null ? $settings : ($settings[$setting] ?? null);
                }
            };
        }
    }

    if (!class_exists('WC_Logger')) {
        class WC_Logger
        {
        }
    }

    if (!function_exists('wc_get_logger')) {
        function wc_get_logger()
        {
            return new class {
                public function log($level, $message, $context = [])
                {
                }
            };
        }
    }
}

namespace SyncBasalam\Services\Products {
    if (!function_exists(__NAMESPACE__ . '\\syncBasalamContainer')) {
        function syncBasalamContainer()
        {
            return $GLOBALS['sync_basalam_test_container'];
        }
    }
}

namespace SyncBasalam\Tests\Services {
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use SyncBasalam\Jobs\Exceptions\NonRetryableException;
    use SyncBasalam\Jobs\Exceptions\StaleVariationException;
    use SyncBasalam\Services\Api\ApiResponseHandler;
    use SyncBasalam\Services\Products\UpdateProductVariationsService;
    use SyncBasalam\Services\Products\UpdateSingleProductService;

    require_once dirname(__DIR__, 2) . '/includes/Jobs/Exceptions/JobException.php';
    require_once dirname(__DIR__, 2) . '/includes/Jobs/Exceptions/NonRetryableException.php';
    require_once dirname(__DIR__, 2) . '/includes/Jobs/Exceptions/RetryableException.php';
    require_once dirname(__DIR__, 2) . '/includes/Jobs/Exceptions/StaleVariationException.php';
    require_once dirname(__DIR__, 2) . '/includes/Admin/Settings/SettingsConfig.php';
    require_once dirname(__DIR__, 2) . '/includes/Admin/Settings/SettingsManager.php';
    require_once dirname(__DIR__, 2) . '/includes/Utilities/ProductMetaKey.php';
    require_once dirname(__DIR__, 2) . '/includes/Logger/LoggerInterface.php';
    require_once dirname(__DIR__, 2) . '/includes/Logger/WooLogger.php';
    require_once dirname(__DIR__, 2) . '/includes/Logger/Logger.php';
    require_once dirname(__DIR__, 2) . '/includes/Services/Products/ProductConnection.php';
    require_once dirname(__DIR__, 2) . '/includes/Services/Api/RequestStatusTracker.php';
    require_once dirname(__DIR__, 2) . '/includes/Services/Api/ApiResponseHandler.php';
    require_once dirname(__DIR__, 2) . '/includes/Services/Products/UpdateProductVariationsService.php';
    require_once dirname(__DIR__, 2) . '/includes/Services/Products/UpdateSingleProductService.php';

    class StaleVariationRecoveryTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['sync_basalam_test_options'] = [];
            $GLOBALS['sync_basalam_test_products'] = [];
            $GLOBALS['sync_basalam_deleted_meta'] = [];
            $GLOBALS['sync_basalam_updated_meta'] = [];
            $GLOBALS['sync_basalam_test_meta'] = [
                7196 => ['sync_basalam_product_id' => 47405114],
            ];
            $GLOBALS['sync_basalam_test_container'] = new class {
                public function get($id)
                {
                    return new class {
                        public function canUpdate()
                        {
                            return true;
                        }

                        public function shouldRestrictUpdateFields($refresh = true)
                        {
                            return false;
                        }

                        public function restrictUpdatePayload(array $payload, $refresh = true)
                        {
                            return $payload;
                        }
                    };
                }
            };
            $GLOBALS['wpdb'] = new class {
                public $postmeta = 'wp_postmeta';
                public $posts = 'wp_posts';

                public function prepare($query, ...$args)
                {
                    return $query;
                }

                public function get_results($query)
                {
                    return [];
                }
            };
        }

        protected function tearDown(): void
        {
            unset(
                $GLOBALS['sync_basalam_test_options'],
                $GLOBALS['sync_basalam_test_products'],
                $GLOBALS['sync_basalam_deleted_meta'],
                $GLOBALS['sync_basalam_updated_meta'],
                $GLOBALS['sync_basalam_test_meta'],
                $GLOBALS['sync_basalam_test_container'],
                $GLOBALS['wpdb']
            );
        }

        public function testApiResponsePreservesNotFoundStatusCode(): void
        {
            $handler = new ApiResponseHandler();

            try {
                $handler->handle([
                    'body' => json_encode(['message' => 'not found']),
                    'response' => ['code' => 404],
                ], 'https://core.basalam.com/v4/products/10/variations/20');
                self::fail('Expected a non-retryable 404 exception.');
            } catch (NonRetryableException $exception) {
                self::assertSame(404, $exception->getCode());
                self::assertSame(['message' => 'not found'], $exception->getResponseData());
            }
        }

        public function testVariationUpdaterTurnsRemote404IntoStaleMappingSignal(): void
        {
            $api = new class {
                public $calls = 0;

                public function patch($url, $data)
                {
                    $this->calls++;
                    throw new NonRetryableException('missing', 404);
                }
            };

            $service = new UpdateProductVariationsService($api);

            try {
                $service->updateVariations(47405114, [
                    ['id' => 43666032, 'stock' => 1],
                    ['id' => 43666033, 'stock' => 2],
                ], 7196);
                self::fail('Expected a stale variation mapping exception.');
            } catch (StaleVariationException $exception) {
                self::assertSame(404, $exception->getCode());
                self::assertSame(47405114, $exception->getBasalamProductId());
                self::assertSame(43666032, $exception->getBasalamVariationId());
                self::assertSame(1, $api->calls, 'Recovery should start after the first stale id.');
            }
        }

        public function testVariationUpdaterKeepsTheNormalPerVariationPath(): void
        {
            $api = new class {
                public $requests = [];

                public function patch($url, $data)
                {
                    $this->requests[] = [$url, $data];

                    return ['status_code' => 200, 'body' => '{}'];
                }
            };

            $service = new UpdateProductVariationsService($api);
            $result = $service->updateVariations(47405114, [
                [
                    'id' => 54840740,
                    'primary_price' => 63150000,
                    'stock' => 0,
                    'sku' => 'wooden-bowl',
                    'properties' => [['property' => 'شکل', 'value' => 'گلدان']],
                ],
                [
                    'id' => 54840741,
                    'primary_price' => 64000000,
                    'stock' => 2,
                ],
            ], 7196);

            self::assertSame(['updated' => 2, 'skipped' => 0, 'failed' => 0], $result);
            self::assertSame([
                [
                    'https://core.basalam.com/v4/products/47405114/variations/54840740',
                    ['primary_price' => 63150000, 'stock' => 0, 'sku' => 'wooden-bowl'],
                ],
                [
                    'https://core.basalam.com/v4/products/47405114/variations/54840741',
                    ['primary_price' => 64000000, 'stock' => 2],
                ],
            ], $api->requests);
        }

        public function testRecoveryBuildsFullIdFreePayloadBeforeClearingStoredMappings(): void
        {
            $product = new class {
                public function is_type($type)
                {
                    return $type === 'variable';
                }

                public function get_children()
                {
                    return [10943];
                }
            };

            $variantData = new class {
                public function getVariants($product)
                {
                    return [[
                        'id' => 43666032,
                        'primary_price' => 63150000,
                        'stock' => 0,
                        'properties' => [
                            ['property' => 'شکل', 'value' => 'گلدان'],
                        ],
                    ]];
                }
            };

            $GLOBALS['sync_basalam_test_products'][7196] = $product;

            $service = new UpdateSingleProductService(new \stdClass(), new \stdClass(), $variantData);
            $method = new ReflectionMethod(UpdateSingleProductService::class, 'prepareVariationRemapPayload');
            $method->setAccessible(true);

            $payload = $method->invoke($service, 7196, [
                'id' => 47405114,
                'name' => 'کاسه بزرگ چوبی',
                'variants' => [['id' => 43666032, 'stock' => 0]],
            ]);

            self::assertSame('کاسه بزرگ چوبی', $payload['name']);
            self::assertArrayNotHasKey('id', $payload['variants'][0]);
            self::assertSame(63150000, $payload['variants'][0]['primary_price']);
            self::assertSame('گلدان', $payload['variants'][0]['properties'][0]['value']);
            self::assertSame(
                [[10943, 'sync_basalam_variation_id']],
                $GLOBALS['sync_basalam_deleted_meta']
            );
        }

        public function testCustomModeStaleVariationFallsBackToOneCompleteProductPatch(): void
        {
            $attribute = new class {
                public function get_variation()
                {
                    return true;
                }
            };

            $variation = new class {
                public function get_attribute($name)
                {
                    return 'قرمز';
                }
            };

            $product = new class($attribute) {
                private $attribute;

                public function __construct($attribute)
                {
                    $this->attribute = $attribute;
                }

                public function is_type($type)
                {
                    return $type === 'variable';
                }

                public function get_children()
                {
                    return [10943];
                }

                public function get_attributes()
                {
                    return ['pa_color' => $this->attribute];
                }
            };

            $GLOBALS['sync_basalam_test_products'][7196] = $product;
            $GLOBALS['sync_basalam_test_products'][10943] = $variation;
            $GLOBALS['sync_basalam_test_options']['sync_basalam_settings'] = [
                'sync_product_fields' => 'custom',
                'sync_product_field_variant_price' => 1,
                'sync_product_field_variant_stock' => 1,
            ];

            $variantDataService = new class {
                public function getVariants($product)
                {
                    return [[
                        'id' => 43666032,
                        'primary_price' => 63150000,
                        'stock' => 3,
                        'properties' => [
                            ['property' => 'رنگ', 'value' => 'قرمز'],
                        ],
                    ]];
                }
            };

            $api = new class {
                public $requests = [];

                public function patch($url, $data)
                {
                    $this->requests[] = [$url, $data];

                    if (strpos($url, '/variations/') !== false) {
                        throw new NonRetryableException('missing', 404);
                    }

                    return [
                        'status_code' => 200,
                        'body' => [
                            'variants' => [[
                                'id' => 99990001,
                                'properties' => [
                                    ['value' => ['title' => 'قرمز']],
                                ],
                            ]],
                        ],
                    ];
                }
            };

            $service = new UpdateSingleProductService(
                $api,
                new UpdateProductVariationsService($api),
                $variantDataService
            );
            $result = $service->updateProductInBasalam([
                'id' => 47405114,
                'type' => 'variable',
                'variants' => [[
                    'id' => 43666032,
                    'primary_price' => 63150000,
                    'stock' => 3,
                ]],
            ], 7196);

            self::assertTrue($result['success']);
            self::assertCount(2, $api->requests, 'A stale custom variation must be followed by one product PATCH.');
            self::assertSame(
                'https://core.basalam.com/v4/products/47405114/variations/43666032',
                $api->requests[0][0]
            );
            self::assertSame([
                'primary_price' => 63150000,
                'stock' => 3,
            ], $api->requests[0][1]);
            self::assertSame(
                'https://openapi.basalam.com/v1/products/47405114',
                $api->requests[1][0]
            );
            self::assertSame([
                'id' => 47405114,
                'type' => 'variable',
                'variants' => [[
                    'primary_price' => 63150000,
                    'stock' => 3,
                    'properties' => [
                        ['property' => 'رنگ', 'value' => 'قرمز'],
                    ],
                ]],
            ], $api->requests[1][1]);
            self::assertContains(
                [10943, 'sync_basalam_variation_id', 99990001],
                $GLOBALS['sync_basalam_updated_meta']
            );
        }
    }
}
