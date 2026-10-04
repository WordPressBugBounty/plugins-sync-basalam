<?php

namespace {
    if (!function_exists('is_wp_error')) {
        function is_wp_error($value)
        {
            return false;
        }
    }

    if (!function_exists('esc_url')) {
        function esc_url($url)
        {
            return (string) $url;
        }
    }

    if (!function_exists('esc_html')) {
        function esc_html($value)
        {
            return (string) $value;
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
            return true;
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

    if (!class_exists('WC_Order_Item')) {
        class WC_Order_Item
        {
        }
    }

    if (!class_exists('WC_Order_Item_Product')) {
        class WC_Order_Item_Product extends WC_Order_Item
        {
            private array $meta = [];

            public function update_meta_data($key, $value, $save = true)
            {
                $this->meta[$key] = $value;
                return true;
            }

            public function get_meta_data(): array
            {
                return $this->meta;
            }

            public function set_subtotal($value)
            {
            }

            public function set_total($value)
            {
            }

            public function save()
            {
                return true;
            }
        }
    }

    if (!class_exists('WC_Product')) {
        class WC_Product
        {
        }
    }

    if (!class_exists('WC_Product_Simple')) {
        class WC_Product_Simple extends WC_Product
        {
            private string $name = '';
            private string $status = '';
            private string $sku = '';

            public function set_name($name)
            {
                $this->name = (string) $name;
            }

            public function set_status($status)
            {
                $this->status = (string) $status;
            }

            public function set_sku($sku)
            {
                $this->sku = (string) $sku;
            }

            public function save()
            {
                return $GLOBALS['sync_basalam_placeholder_id'] ?? 900;
            }

            public function get_id()
            {
                return $GLOBALS['sync_basalam_placeholder_id'] ?? 900;
            }
        }
    }

    if (!class_exists('WC_Order')) {
        class WC_Order
        {
            /** @var array<int, WC_Order_Item_Product> */
            private array $items = [];
            private array $meta = [];
            private int $nextItemId = 100;

            public function add_product($product, $quantity = 1, $args = [])
            {
                $itemId = $this->nextItemId++;
                $this->items[$itemId] = new WC_Order_Item_Product();

                return $itemId;
            }

            public function get_item($itemId)
            {
                return $this->items[$itemId] ?? null;
            }

            public function update_meta_data($key, $value, $save = true)
            {
                $this->meta[$key] = $value;
                return true;
            }

            public function get_order_item_meta(): array
            {
                return $this->meta;
            }

            /** @return array<int, WC_Order_Item_Product> */
            public function addedItems(): array
            {
                return $this->items;
            }
        }
    }

    if (!function_exists('wc_get_product')) {
        function wc_get_product($productId)
        {
            return $GLOBALS['sync_basalam_test_products'][$productId] ?? null;
        }
    }

    if (!function_exists('get_posts')) {
        function get_posts($args)
        {
            $key = ($args['meta_key'] ?? '') . '|' . ($args['meta_value'] ?? '');
            $found = $GLOBALS['sync_basalam_test_posts_by_meta'][$key] ?? [];

            if (($args['fields'] ?? '') === 'ids') {
                return $found; // getWooProductVariableId مستقیماً خود شناسه را می‌خواند
            }

            return array_map(static function ($id) {
                return new class ($id) {
                    public $ID;

                    public function __construct($id)
                    {
                        $this->ID = $id;
                    }
                };
            }, $found);
        }
    }

    if (!function_exists('get_woocommerce_currency')) {
        function get_woocommerce_currency()
        {
            return 'IRT';
        }
    }
}

namespace SyncBasalam\Services\Orders {
    if (!function_exists(__NAMESPACE__ . '\\syncBasalamContainer')) {
        function syncBasalamContainer()
        {
            return $GLOBALS['sync_basalam_test_container'];
        }
    }
}

namespace SyncBasalam\Tests\Services {
    use PHPUnit\Framework\TestCase;
    use SyncBasalam\Services\Orders\OrderManager;

    require_once dirname(__DIR__, 2) . '/includes/Admin/Settings/SettingsConfig.php';
    require_once dirname(__DIR__, 2) . '/includes/Admin/Settings/SettingsManager.php';
    require_once dirname(__DIR__, 2) . '/includes/Utilities/ProductMetaKey.php';
    require_once dirname(__DIR__, 2) . '/includes/Logger/LoggerInterface.php';
    require_once dirname(__DIR__, 2) . '/includes/Logger/WooLogger.php';
    require_once dirname(__DIR__, 2) . '/includes/Logger/Logger.php';
    require_once dirname(__DIR__, 2) . '/includes/Services/Orders/OrderManager.php';

    class OrderManagerBasalamItemNameTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['sync_basalam_test_options'] = [
                'sync_basalam_settings' => ['vendor_id' => '123'],
            ];
            $GLOBALS['sync_basalam_test_meta'] = [];
            $GLOBALS['sync_basalam_test_products'] = [
                500 => new \WC_Product(),
            ];
            $GLOBALS['sync_basalam_test_posts_by_meta'] = [];
        }

        public function testExtractsProductTitle(): void
        {
            $item = [
                'product' => ['id' => 10, 'title' => 'شلوار جین مردانه'],
                'quantity' => 1,
            ];

            self::assertSame('شلوار جین مردانه', OrderManager::extractBasalamItemName($item));
        }

        public function testFallsBackToNameFieldWhenTitleIsMissing(): void
        {
            $item = [
                'product' => ['id' => 10, 'name' => 'تی‌شرت نخی'],
            ];

            self::assertSame('تی‌شرت نخی', OrderManager::extractBasalamItemName($item));
        }

        public function testAppendsVariationTitleWhenNotAlreadyIncluded(): void
        {
            $item = [
                'product' => ['id' => 10, 'title' => 'کفش ورزشی'],
                'variation' => ['id' => 55, 'title' => 'سایز ۴۲'],
            ];

            self::assertSame('کفش ورزشی - سایز ۴۲', OrderManager::extractBasalamItemName($item));
        }

        public function testDoesNotDuplicateVariationTitleAlreadyPresentInProductTitle(): void
        {
            $item = [
                'product' => ['id' => 10, 'title' => 'کفش ورزشی سایز ۴۲'],
                'variation' => ['id' => 55, 'title' => 'سایز ۴۲'],
            ];

            self::assertSame('کفش ورزشی سایز ۴۲', OrderManager::extractBasalamItemName($item));
        }

        public function testReturnsEmptyStringWhenNoNameAvailable(): void
        {
            self::assertSame('', OrderManager::extractBasalamItemName(['product' => ['id' => 10]]));
            self::assertSame('', OrderManager::extractBasalamItemName([]));
        }

        public function testMetaKeyIsVisibleForWooCommerceDisplay(): void
        {
            // ووکامرس متاهایی که با «_» شروع نمی‌شوند را در صفحه سفارش نشان می‌دهد.
            self::assertStringStartsNotWith('_', OrderManager::BASALAM_ITEM_NAME_META_KEY);
        }

        public function testStoresVisibleMetaOnOrderItem(): void
        {
            $order = new \WC_Order();
            $orderItemId = $order->add_product(new \WC_Product());

            $result = OrderManager::setBasalamItemNameOnOrderItem(
                $order,
                $orderItemId,
                '  ماگ حرارتی مدل آلفا  '
            );

            self::assertTrue($result);
            self::assertSame(
                ['نام محصول در باسلام' => 'ماگ حرارتی مدل آلفا'],
                $order->get_item($orderItemId)->get_meta_data()
            );
        }

        public function testStoresClickableLinkMetaWhenBasalamProductIdIsGiven(): void
        {
            $order = new \WC_Order();
            $orderItemId = $order->add_product(new \WC_Product());

            OrderManager::setBasalamItemNameOnOrderItem($order, $orderItemId, 'ماگ حرارتی مدل آلفا', 12345);

            $meta = $order->get_item($orderItemId)->get_meta_data();

            self::assertArrayHasKey(OrderManager::BASALAM_ITEM_LINK_META_KEY, $meta);
            self::assertStringStartsNotWith('_', OrderManager::BASALAM_ITEM_LINK_META_KEY);
            self::assertSame(
                '<a href="https://basalam.com/p/12345" target="_blank" rel="noopener">مشاهده</a>',
                $meta[OrderManager::BASALAM_ITEM_LINK_META_KEY]
            );
        }

        public function testSkipsLinkMetaWhenBasalamProductIdIsMissing(): void
        {
            $order = new \WC_Order();
            $orderItemId = $order->add_product(new \WC_Product());

            OrderManager::setBasalamItemNameOnOrderItem($order, $orderItemId, 'ماگ حرارتی مدل آلفا', null);

            $meta = $order->get_item($orderItemId)->get_meta_data();

            self::assertArrayNotHasKey(OrderManager::BASALAM_ITEM_LINK_META_KEY, $meta);
            self::assertArrayHasKey(OrderManager::BASALAM_ITEM_NAME_META_KEY, $meta);
        }

        public function testSkipsMetaWhenItemNameIsEmpty(): void
        {
            $order = new \WC_Order();
            $orderItemId = $order->add_product(new \WC_Product());

            $result = OrderManager::setBasalamItemNameOnOrderItem($order, $orderItemId, '   ');

            self::assertFalse($result);
            self::assertSame([], $order->get_item($orderItemId)->get_meta_data());
        }

        public function testSkipsMetaWhenOrderItemIsMissing(): void
        {
            $order = new \WC_Order();

            $result = OrderManager::setBasalamItemNameOnOrderItem($order, 999, 'ماگ حرارتی');

            self::assertFalse($result);
        }
    }

}

namespace {
    // productExistsByTitle در مسیر placeholder از wpdb استفاده می‌کند.
    if (!isset($GLOBALS['wpdb'])) {
        $GLOBALS['wpdb'] = new class {
            public $prefix = 'wp_';
            public $posts = 'wp_posts';

            public function get_var($query = null, $x = 0, $y = 0)
            {
                return null; // محصول جایگزین هنوز ساخته نشده
            }

            public function prepare($query, ...$args)
            {
                return $query;
            }
        };
    }
}
