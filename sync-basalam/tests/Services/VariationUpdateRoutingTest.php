<?php

namespace {
    if (!function_exists('esc_html')) {
        function esc_html($value)
        {
            return (string) $value;
        }
    }

    if (!function_exists('wc_get_product')) {
        function wc_get_product($productId)
        {
            return $GLOBALS['sync_basalam_test_products'][$productId] ?? null;
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
}

namespace SyncBasalam\Services\Products {
    if (!function_exists(__NAMESPACE__ . '\\syncBasalamContainer')) {
        function syncBasalamContainer()
        {
            if (!isset($GLOBALS['sync_basalam_test_container'])) {
                throw new \RuntimeException('The test service container has not been configured.');
            }

            return $GLOBALS['sync_basalam_test_container'];
        }
    }
}

namespace SyncBasalam\Tests\Services {
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use SyncBasalam\Services\Products\UpdateSingleProductService;

    require_once dirname(__DIR__, 2) . '/includes/Admin/Settings/SettingsConfig.php';
    require_once dirname(__DIR__, 2) . '/includes/Admin/Settings/SettingsManager.php';
    require_once dirname(__DIR__, 2) . '/includes/Jobs/Exceptions/JobException.php';
    require_once dirname(__DIR__, 2) . '/includes/Jobs/Exceptions/NonRetryableException.php';
    require_once dirname(__DIR__, 2) . '/includes/Jobs/Exceptions/RetryableException.php';
    require_once dirname(__DIR__, 2) . '/includes/Jobs/Exceptions/StaleVariationException.php';
    require_once dirname(__DIR__, 2) . '/includes/Services/Products/UpdateProductVariationsService.php';
    require_once dirname(__DIR__, 2) . '/includes/Services/Products/UpdateSingleProductService.php';

    class VariationUpdateRoutingTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['sync_basalam_test_products'] = [];
            $GLOBALS['sync_basalam_test_options'] = [];
            $GLOBALS['sync_basalam_test_products'][7196] = new class {
                public function is_type($type)
                {
                    return $type === 'variable';
                }
            };
        }

        protected function tearDown(): void
        {
            unset(
                $GLOBALS['sync_basalam_test_products'],
                $GLOBALS['sync_basalam_test_options'],
                $GLOBALS['sync_basalam_test_container']
            );
        }

        public function testAllFieldsModeNeverUsesTheVariationEndpoint(): void
        {
            $this->withActiveVendor();
            $this->withSettings(['sync_product_fields' => 'all']);

            // Even fully connected variations stay inside the product PATCH.
            self::assertFalse($this->shouldUpdateSeparately([
                'variants' => [['id' => 43666032, 'primary_price' => 1, 'stock' => 1, 'properties' => []]],
            ]));
        }

        public function testPriceStockModeNeverUsesTheVariationEndpoint(): void
        {
            $this->withActiveVendor();
            $this->withSettings(['sync_product_fields' => 'price_stock']);

            self::assertFalse($this->shouldUpdateSeparately([
                'variants' => [['id' => 43666032, 'primary_price' => 1, 'stock' => 1]],
            ]));
        }

        public function testCustomModeWithVariationFieldsUsesPerVariationRequests(): void
        {
            $this->withActiveVendor();
            $this->withSettings([
                'sync_product_fields' => 'custom',
                'sync_product_field_variant_price' => 1,
            ]);

            self::assertTrue($this->shouldUpdateSeparately([
                'variants' => [['id' => 43666032, 'primary_price' => 1, 'stock' => 1]],
            ]));
        }

        public function testCustomModeWithoutVariationFieldsDoesNotUsePerVariationRequests(): void
        {
            $this->withActiveVendor();
            $this->withSettings([
                'sync_product_fields' => 'custom',
                'sync_product_field_name' => 1,
            ]);

            self::assertFalse($this->shouldUpdateSeparately([
                'variants' => [['id' => 43666032, 'primary_price' => 1, 'stock' => 1]],
            ]));
        }

        public function testUnconnectedVariationFallsBackToTheProductRequest(): void
        {
            $this->withActiveVendor();
            $this->withSettings([
                'sync_product_fields' => 'custom',
                'sync_product_field_variant_price' => 1,
            ]);

            self::assertFalse($this->shouldUpdateSeparately([
                'variants' => [
                    ['id' => 43666032, 'primary_price' => 1, 'stock' => 1],
                    ['primary_price' => 2, 'stock' => 2], // no Basalam id yet
                ],
            ]));
        }

        public function testRestrictedVendorUsesTheProductRequest(): void
        {
            // A limited inactive vendor must also use one product PATCH. The
            // restricted payload keeps unchanged properties for variant identity
            // and UpdateSingleProductService removes the stored ids before send.
            $this->withRestrictedVendor();
            $this->withSettings(['sync_product_fields' => 'all']);

            self::assertFalse($this->shouldUpdateSeparately([
                'variants' => [['id' => 43666032, 'primary_price' => 1, 'stock' => 1, 'properties' => []]],
            ]));
        }

        private function shouldUpdateSeparately(array $productData): bool
        {
            $service = new UpdateSingleProductService(new \stdClass(), new \stdClass(), new \stdClass());

            $method = new ReflectionMethod(UpdateSingleProductService::class, 'shouldUpdateVariationsSeparately');
            $method->setAccessible(true);

            return $method->invoke($service, 7196, $productData);
        }

        private function withSettings(array $settings): void
        {
            $GLOBALS['sync_basalam_test_options']['sync_basalam_settings'] = $settings;
        }

        private function withActiveVendor(): void
        {
            $GLOBALS['sync_basalam_test_container'] = new class {
                public function get($id)
                {
                    return new class {
                        public function __call($method, $args)
                        {
                            return false; // shouldRestrictUpdateFields, canUpdate, ...
                        }
                    };
                }
            };
        }

        private function withRestrictedVendor(): void
        {
            $GLOBALS['sync_basalam_test_container'] = new class {
                public function get($id)
                {
                    return new class {
                        public function __call($method, $args)
                        {
                            // shouldRestrictUpdateFields() === true
                            if (strpos($method, 'shouldRestrict') === 0) return true;

                            return false;
                        }
                    };
                }
            };
        }
    }
}
