<?php

namespace {
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
        function wc_get_product($id)
        {
            return $GLOBALS['sync_basalam_test_products'][$id] ?? null;
        }
    }
    if (!function_exists('absint')) {
        function absint($value) { return abs((int) $value); }
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
    }
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id() { return $GLOBALS['sync_basalam_oauth_test_user_id'] ?? 0; }
    }
}

namespace SyncBasalam\Registrar\ProductListeners {
    function syncBasalamContainer() { return $GLOBALS['product_sync_test_container']; }
    function wc_get_product($id) { return \wc_get_product($id); }
    function get_post_type($id) { return $GLOBALS['product_sync_test_post_type'] ?? 'product'; }
    function get_post_status($id) { return $GLOBALS['product_sync_test_post_status'] ?? 'publish'; }
    function get_post_meta($id, $key, $single = false)
    {
        return $GLOBALS['product_sync_test_meta'][$id][$key] ?? '';
    }
}

namespace SyncBasalam\Jobs\Types {
    function syncBasalamContainer() { return $GLOBALS['product_sync_test_container']; }
    function wc_get_product($id) { return \wc_get_product($id); }
}

namespace SyncBasalam\Utilities {
    function syncBasalamSettings()
    {
        return new class {
            public function getSettings($key = null)
            {
                return \SyncBasalam\Admin\Settings\SettingsManager::getSettings($key);
            }
        };
    }
}

namespace SyncBasalam\Tests\Registrar {
    use PHPUnit\Framework\TestCase;
    use SyncBasalam\Admin\Settings\SettingsConfig;
    use SyncBasalam\Admin\Settings\SettingsManager;
    use SyncBasalam\Admin\Settings\SettingsPageHandler;
    use SyncBasalam\Jobs\Types\CreateSingleProductJob;
    use SyncBasalam\Jobs\Types\UpdateSingleProductJob;
    use SyncBasalam\Registrar\ProductListeners\ArchiveProduct;
    use SyncBasalam\Registrar\ProductListeners\CreateWooProduct;
    use SyncBasalam\Registrar\ProductListeners\RestoreProduct;
    use SyncBasalam\Registrar\ProductListeners\UpdateWooProduct;
    use SyncBasalam\Utilities\ProductMetaKey;

    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

    class ProductSyncOperationsTest extends TestCase
    {
        private $jobs;
        private $policy;

        protected function setUp(): void
        {
            $GLOBALS['sync_basalam_test_options'] = [];
            $GLOBALS['sync_basalam_test_products'] = [42 => new class {
                public function is_type($type) { return false; }
            }];
            $GLOBALS['product_sync_test_meta'] = [];
            $this->jobs = new class {
                public array $created = [];
                public function hasProductJobInProgress($id, $type)
                {
                    foreach ($this->created as $job) {
                        if ($job['type'] === $type && $job['payload']['product_id'] === $id) return true;
                    }
                    return false;
                }
                public function createJob($type, $status, $payload)
                {
                    $this->created[] = ['type' => $type, 'status' => $status, 'payload' => json_decode($payload, true)];
                }
            };
            $this->policy = new class {
                public bool $create = true;
                public bool $update = true;
                public bool $restricted = false;
                public function canCreate() { return $this->create; }
                public function canUpdate() { return $this->update; }
                public function canUpdateProductStatus() { return $this->update; }
                public function shouldRestrictUpdateFields($refresh) { return $this->restricted; }
            };
            $GLOBALS['product_sync_test_container'] = new class($this->policy) {
                private $policy;
                public function __construct($policy) { $this->policy = $policy; }
                public function get($id) { return $this->policy; }
            };
        }

        protected function tearDown(): void
        {
            $_POST = [];
            unset(
                $GLOBALS['sync_basalam_test_options'], $GLOBALS['sync_basalam_test_products'],
                $GLOBALS['product_sync_test_container'], $GLOBALS['product_sync_test_meta'],
                $GLOBALS['product_sync_test_post_type'], $GLOBALS['product_sync_test_post_status']
            );
        }

        public static function operations(): array
        {
            return [
                'both disabled' => [false, false],
                'creation only' => [true, false],
                'update only' => [false, true],
                'both enabled' => [true, true],
            ];
        }

        /** @dataProvider operations */
        public function testNewProductUsesCreationSetting(bool $create, bool $update): void
        {
            $this->configure($create, $update);
            (new CreateWooProduct($this->jobs))->handle(42);
            $this->assertJob($create, 'sync_basalam_create_single_product');
        }

        /** @dataProvider operations */
        public function testEditingAnUnconnectedProductUsesCreationSetting(bool $create, bool $update): void
        {
            $this->configure($create, $update);
            (new UpdateWooProduct($this->jobs))->handle(42);
            $this->assertJob($create, 'sync_basalam_create_single_product');
        }

        /** @dataProvider operations */
        public function testEditingAConnectedProductUsesUpdateSetting(bool $create, bool $update): void
        {
            $this->configure($create, $update);
            $this->connectProduct();
            (new CreateWooProduct($this->jobs))->handle(42);
            (new UpdateWooProduct($this->jobs))->handle(42);
            $this->assertJob($update, 'sync_basalam_update_single_product');
        }

        public function testOverlappingSaveHooksQueueOnlyOneCreation(): void
        {
            $this->configure(true, true);
            $create = new CreateWooProduct($this->jobs);
            $create->handle(42); // save_post
            $create->handle(42); // woocommerce_new_product
            (new UpdateWooProduct($this->jobs))->handle(42);
            $this->assertJob(true, 'sync_basalam_create_single_product');
        }

        public function testRepeatedUpdateHooksQueueOnlyOneUpdate(): void
        {
            $this->configure(true, true);
            $this->connectProduct();
            $listener = new UpdateWooProduct($this->jobs);
            $listener->handle(42);
            $listener->handle(42);
            $this->assertJob(true, 'sync_basalam_update_single_product');
        }

        public function testDraftAndVariationProductsAreNotCreated(): void
        {
            $this->configure(true, true);
            $GLOBALS['product_sync_test_post_status'] = 'draft';
            (new CreateWooProduct($this->jobs))->handle(42);
            (new UpdateWooProduct($this->jobs))->handle(42);
            $GLOBALS['product_sync_test_post_status'] = 'publish';
            $GLOBALS['sync_basalam_test_products'][42] = new class {
                public function is_type($type) { return $type === 'variation'; }
            };
            (new CreateWooProduct($this->jobs))->handle(42);
            (new UpdateWooProduct($this->jobs))->handle(42);
            self::assertSame([], $this->jobs->created);
        }

        public function testMissingProductsAndOtherPostTypesAreIgnored(): void
        {
            $this->configure(true, true);
            (new CreateWooProduct($this->jobs))->handle(404);
            (new UpdateWooProduct($this->jobs))->handle(404);
            $GLOBALS['product_sync_test_post_type'] = 'post';
            (new CreateWooProduct($this->jobs))->handle(42);
            (new UpdateWooProduct($this->jobs))->handle(42);
            self::assertSame([], $this->jobs->created);
        }

        public function testInactiveVendorCannotCreateAnUnconnectedProduct(): void
        {
            $this->configure(true, true);
            $this->policy->create = false;
            (new UpdateWooProduct($this->jobs))->handle(42);
            self::assertSame([], $this->jobs->created);
        }

        public function testSuspendedVendorCannotUpdateAConnectedProduct(): void
        {
            $this->configure(true, true);
            $this->connectProduct();
            $this->policy->update = false;
            (new UpdateWooProduct($this->jobs))->handle(42);
            self::assertSame([], $this->jobs->created);
        }

        public function testCreationDoesNotDependOnTheCustomUpdateFieldSelection(): void
        {
            $this->configure(true, false);
            SettingsManager::updateSettings([SettingsConfig::SYNC_PRODUCT_FIELDS => 'custom', SettingsConfig::SYNC_PRODUCT_FIELD_SKU => 0]);
            (new UpdateWooProduct($this->jobs))->handle(42);
            $this->assertJob(true, 'sync_basalam_create_single_product');
        }

        public function testCustomUpdateWithoutFieldsIsNotQueued(): void
        {
            $this->configure(true, true);
            $this->connectProduct();
            SettingsManager::updateSettings([SettingsConfig::SYNC_PRODUCT_FIELDS => 'custom', SettingsConfig::SYNC_PRODUCT_FIELD_SKU => 0]);
            (new UpdateWooProduct($this->jobs))->handle(42);
            self::assertSame([], $this->jobs->created);
            $this->policy->restricted = true;
            (new UpdateWooProduct($this->jobs))->handle(42);
            $this->assertJob(true, 'sync_basalam_update_single_product');
        }

        /** @dataProvider operations */
        public function testArchiveAndRestoreUseUpdateSetting(bool $create, bool $update): void
        {
            $this->configure($create, $update);
            $operations = new class {
                public array $calls = [];
                public function archiveExistProduct($id) { $this->calls[] = ['archive', $id]; }
                public function restoreExistProduct($id) { $this->calls[] = ['restore', $id]; }
            };
            (new ArchiveProduct($operations))->handle(42);
            (new RestoreProduct($operations))->handle(42);
            self::assertSame($update ? [['archive', 42], ['restore', 42]] : [], $operations->calls);
        }

        /** @dataProvider operations */
        public function testQueuedAutomaticJobsRecheckTheirOwnSetting(bool $create, bool $update): void
        {
            $this->configure($create, $update);
            $operations = new class {
                public array $calls = [];
                public function createNewProduct($id, $category) { $this->calls[] = 'create'; return ['success' => true]; }
                public function updateExistProduct($id, $category) { $this->calls[] = 'update'; return ['success' => true]; }
            };
            $created = (new CreateSingleProductJob($this->jobs, $operations))->execute(['product_id' => 42, 'automatic' => true]);
            $updated = (new UpdateSingleProductJob($this->jobs, $operations))->execute(['product_id' => 42, 'automatic' => true]);
            self::assertSame(!$create, $created->getData()['skipped'] ?? false);
            self::assertSame(!$update, $updated->getData()['skipped'] ?? false);
            self::assertSame(array_merge($create ? ['create'] : [], $update ? ['update'] : []), $operations->calls);
        }

        public function testManualJobsCanRunWithAutomaticSyncDisabled(): void
        {
            $this->configure(false, false);
            $operations = new class {
                public function createNewProduct($id, $category) { return ['success' => true]; }
                public function updateExistProduct($id, $category) { return ['success' => true]; }
            };
            foreach ([CreateSingleProductJob::class, UpdateSingleProductJob::class] as $job) {
                $result = (new $job($this->jobs, $operations))->execute(['product_id' => 42]);
                self::assertTrue($result->getData()['result']['success']);
            }
        }

        /** @dataProvider legacyValues */
        public function testLegacyStatusMigratesBothOperations($legacy, bool $enabled): void
        {
            $GLOBALS['sync_basalam_test_options']['sync_basalam_settings'] = [SettingsConfig::SYNC_STATUS_PRODUCT => $legacy];
            self::assertSame($enabled, SettingsManager::isProductCreationSyncEnabled());
            self::assertSame($enabled, SettingsManager::isProductUpdateSyncEnabled());
            self::assertSame($enabled, $GLOBALS['sync_basalam_test_options']['sync_basalam_settings'][SettingsConfig::SYNC_STATUS_PRODUCT_CREATE]);
        }

        public static function legacyValues(): array
        {
            return [[true, true], [1, true], ['1', true], ['true', true], [false, false], [0, false], ['0', false], ['', false], ['false', false]];
        }

        public function testUnrelatedSettingsSavePreservesSeparateChoices(): void
        {
            $this->configure(true, false);
            SettingsManager::updateSettings([SettingsConfig::DEFAULT_WEIGHT => 200]);
            self::assertTrue(SettingsManager::isProductCreationSyncEnabled());
            self::assertFalse(SettingsManager::isProductUpdateSyncEnabled());
            SettingsManager::updateSettings([SettingsConfig::SYNC_STATUS_PRODUCT_CREATE => '0', SettingsConfig::SYNC_STATUS_PRODUCT_UPDATE => '1']);
            self::assertFalse(SettingsManager::isProductCreationSyncEnabled());
            self::assertTrue(SettingsManager::isProductUpdateSyncEnabled());
            self::assertTrue(SettingsManager::getSettings(SettingsConfig::SYNC_STATUS_PRODUCT));
        }

        public function testFreshInstallPersistsItsDefaultSettings(): void
        {
            self::assertFalse(SettingsManager::isProductCreationSyncEnabled());
            self::assertFalse(SettingsManager::isProductUpdateSyncEnabled());
            $settings = SettingsManager::getSettings();
            self::assertSame($settings, $GLOBALS['sync_basalam_test_options']['sync_basalam_settings']);
            self::assertSame($settings[SettingsConfig::WEBHOOK_HEADER_TOKEN], SettingsManager::getSettings(SettingsConfig::WEBHOOK_HEADER_TOKEN));
        }

        public function testLegacyToggleStillControlsBothOperations(): void
        {
            $this->configure(true, false);
            SettingsManager::updateSettings([SettingsConfig::SYNC_STATUS_PRODUCT => '']);
            self::assertFalse(SettingsManager::isProductCreationSyncEnabled());
            self::assertFalse(SettingsManager::isProductUpdateSyncEnabled());
            SettingsManager::updateSettings([SettingsConfig::SYNC_STATUS_PRODUCT => '1']);
            self::assertTrue(SettingsManager::isProductCreationSyncEnabled());
            self::assertTrue(SettingsManager::isProductUpdateSyncEnabled());
        }

        public function testMainProductSyncButtonTogglesBothOperations(): void
        {
            $this->configure(true, false);

            $_POST = [
                'sync_basalam_toggle_product' => '1',
                'sync_basalam_settings' => [
                    SettingsConfig::SYNC_STATUS_PRODUCT_CREATE => '1',
                    SettingsConfig::SYNC_STATUS_PRODUCT_UPDATE => '0',
                ],
            ];
            self::assertTrue(SettingsPageHandler::saveSettings());
            self::assertFalse(SettingsManager::isProductCreationSyncEnabled());
            self::assertFalse(SettingsManager::isProductUpdateSyncEnabled());

            $_POST = [
                'sync_basalam_toggle_product' => '1',
                'sync_basalam_settings' => [
                    SettingsConfig::SYNC_STATUS_PRODUCT_CREATE => '0',
                    SettingsConfig::SYNC_STATUS_PRODUCT_UPDATE => '0',
                ],
            ];
            self::assertTrue(SettingsPageHandler::saveSettings());
            self::assertTrue(SettingsManager::isProductCreationSyncEnabled());
            self::assertTrue(SettingsManager::isProductUpdateSyncEnabled());
        }

        public function testCreationOnlyAndStoppingSyncCanBeSavedWithEmptyCustomUpdateFields(): void
        {
            $this->configure(true, true);
            SettingsManager::updateSettings([SettingsConfig::SYNC_PRODUCT_FIELDS => 'custom', SettingsConfig::SYNC_PRODUCT_FIELD_SKU => 0]);
            foreach (['1', '0'] as $create) {
                $_POST = ['sync_basalam_settings' => [SettingsConfig::SYNC_STATUS_PRODUCT_CREATE => $create, SettingsConfig::SYNC_STATUS_PRODUCT_UPDATE => '0']];
                self::assertTrue(SettingsPageHandler::saveSettings());
                self::assertSame($create === '1', SettingsManager::isProductCreationSyncEnabled());
                self::assertFalse(SettingsManager::isProductUpdateSyncEnabled());
            }
            $_POST = ['sync_basalam_settings' => [SettingsConfig::SYNC_STATUS_PRODUCT_UPDATE => '1']];
            self::assertFalse(SettingsPageHandler::saveSettings());
            self::assertFalse(SettingsManager::isProductUpdateSyncEnabled());
        }

        private function configure(bool $create, bool $update): void
        {
            SettingsManager::updateSettings([
                SettingsConfig::SYNC_STATUS_PRODUCT_CREATE => $create ? '1' : '0',
                SettingsConfig::SYNC_STATUS_PRODUCT_UPDATE => $update ? '1' : '0',
            ]);
        }

        private function connectProduct(): void
        {
            $GLOBALS['product_sync_test_meta'][42][ProductMetaKey::basalamProductId()] = 777;
        }

        private function assertJob(bool $enabled, string $type): void
        {
            self::assertSame($enabled ? [['type' => $type, 'status' => 'pending', 'payload' => ['product_id' => 42, 'automatic' => true]]] : [], $this->jobs->created);
        }
    }
}
