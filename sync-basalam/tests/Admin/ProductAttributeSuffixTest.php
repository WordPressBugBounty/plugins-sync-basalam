<?php

namespace SyncBasalam\Admin\Product\Data\Services {
    function syncBasalamSettings()
    {
        return $GLOBALS['attribute_suffix_test_settings'] ?? \syncBasalamSettings();
    }

    function wc_attribute_label($name)
    {
        return $GLOBALS['attribute_suffix_test_labels'][$name] ?? $name;
    }

    function wc_get_product_terms($productId, $taxonomy, $args)
    {
        $GLOBALS['attribute_suffix_test_term_calls'][] = [$productId, $taxonomy, $args];
        return $GLOBALS['attribute_suffix_test_terms'][$taxonomy] ?? [];
    }
}

namespace SyncBasalam\Admin\Components {
    function syncBasalamSettings()
    {
        return $GLOBALS['attribute_suffix_test_settings'] ?? \syncBasalamSettings();
    }

    function esc_attr($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

namespace SyncBasalam\Tests\Admin {
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use ReflectionProperty;
    use SyncBasalam\Admin\Components\SettingPageComponents;
    use SyncBasalam\Admin\Product\Data\Handlers\SimpleProductHandler;
    use SyncBasalam\Admin\Product\Data\Handlers\VariableProductHandler;
    use SyncBasalam\Admin\Product\Data\Services\AttributeService;
    use SyncBasalam\Admin\Settings\SettingsConfig;

    require_once dirname(__DIR__, 2) . '/includes/Admin/Settings/SettingsConfig.php';
    require_once dirname(__DIR__, 2) . '/includes/Admin/Components/SettingPageComponents.php';
    require_once dirname(__DIR__, 2) . '/includes/Admin/Product/Data/Services/MobileDataHandler.php';
    require_once dirname(__DIR__, 2) . '/includes/Admin/Product/Data/Services/GoldDataHandler.php';
    require_once dirname(__DIR__, 2) . '/includes/Admin/Product/Data/Services/AttributeService.php';
    require_once dirname(__DIR__, 2) . '/includes/Admin/Product/Data/Handlers/ProductDataHandlerInterface.php';
    require_once dirname(__DIR__, 2) . '/includes/Admin/Product/Data/Handlers/SimpleProductHandler.php';
    require_once dirname(__DIR__, 2) . '/includes/Admin/Product/Data/Handlers/VariableProductHandler.php';

    class ProductAttributeSuffixTest extends TestCase
    {
        private array $settings;

        protected function setUp(): void
        {
            $this->configure('ناشر, نویسنده');
            $GLOBALS['attribute_suffix_test_labels'] = [];
            $GLOBALS['attribute_suffix_test_terms'] = [];
            $GLOBALS['attribute_suffix_test_term_calls'] = [];
        }

        protected function tearDown(): void
        {
            unset(
                $GLOBALS['attribute_suffix_test_settings'],
                $GLOBALS['attribute_suffix_test_labels'],
                $GLOBALS['attribute_suffix_test_terms'],
                $GLOBALS['attribute_suffix_test_term_calls']
            );
        }

        /** @dataProvider suffixCases */
        public function testBuildsSuffixFromConfiguredAttributeLabels($configured, array $attributes, ?string $expected): void
        {
            $this->configure($configured);

            self::assertSame($expected, (new AttributeService())->getAttributeSuffix($this->product($attributes)));
        }

        public function suffixCases(): array
        {
            $book = ['ناشر' => ['نشر چشمه'], 'نویسنده' => ['صادق هدایت']];

            return [
                'existing single attribute' => ['ناشر', $book, 'نشر چشمه'],
                'two attributes with English comma' => ['ناشر, نویسنده', $book, 'نشر چشمه, صادق هدایت'],
                'two attributes with Persian comma' => ['ناشر، نویسنده', $book, 'نشر چشمه, صادق هدایت'],
                'configured order differs from product order' => ['نویسنده, ناشر', $book, 'صادق هدایت, نشر چشمه'],
                'whitespace around names and values' => [" \tناشر , نویسنده\n", [' ناشر ' => [' نشر چشمه '], 'نویسنده' => [' صادق هدایت ']], 'نشر چشمه, صادق هدایت'],
                'empty entries and mixed separators' => [', ناشر، , نویسنده،', $book, 'نشر چشمه, صادق هدایت'],
                'duplicate names do not repeat values' => ['ناشر, ناشر، نویسنده,ناشر', $book, 'نشر چشمه, صادق هدایت'],
                'first attribute missing' => ['مترجم, ناشر', $book, 'نشر چشمه'],
                'second attribute missing' => ['ناشر, مترجم', $book, 'نشر چشمه'],
                'all attributes missing' => ['مترجم, زبان', $book, null],
                'first attribute empty' => ['ناشر, نویسنده', ['ناشر' => [], 'نویسنده' => ['صادق هدایت']], 'صادق هدایت'],
                'second attribute empty' => ['ناشر, نویسنده', ['ناشر' => ['نشر چشمه'], 'نویسنده' => ['']], 'نشر چشمه'],
                'whitespace value skipped' => ['ناشر, نویسنده', ['ناشر' => [" \t"], 'نویسنده' => ['صادق هدایت']], 'صادق هدایت'],
                'all values empty' => ['ناشر, نویسنده', ['ناشر' => [''], 'نویسنده' => []], null],
                'no product attributes' => ['ناشر, نویسنده', [], null],
                'empty setting' => ['', $book, null],
                'unset setting' => [null, $book, null],
                'only separators and whitespace' => [" , ، \t,", $book, null],
                'multiple values in one attribute' => ['ناشر, نویسنده', ['ناشر' => ['نشر چشمه'], 'نویسنده' => ['صادق هدایت', 'بزرگ علوی']], 'نشر چشمه, صادق هدایت, بزرگ علوی'],
                'zero is a valid attribute value' => ['شماره, ناشر', ['شماره' => ['0'], 'ناشر' => ['نشر چشمه']], '0, نشر چشمه'],
                'zero is a valid attribute label' => ['0, ناشر', ['0' => ['ویژگی صفر'], 'ناشر' => ['نشر چشمه']], 'ویژگی صفر, نشر چشمه'],
                'identical values belong to distinct attributes' => ['ناشر, نویسنده', ['ناشر' => ['مشترک'], 'نویسنده' => ['مشترک']], 'مشترک, مشترک'],
                'three names and mixed commas' => ['ناشر، نویسنده, زبان', $book + ['زبان' => ['فارسی']], 'نشر چشمه, صادق هدایت, فارسی'],
                'unselected attributes are excluded' => ['ناشر, نویسنده', ['زبان' => ['فارسی']] + $book, 'نشر چشمه, صادق هدایت'],
                'labels remain exact matches' => ['ناش, نویسنده', $book, 'صادق هدایت'],
            ];
        }

        /** @dataProvider disabledSettings */
        public function testDisabledFeatureDoesNotReadProductAttributes($enabled): void
        {
            $this->configure('ناشر, نویسنده', $enabled);
            $product = new class {
                public function get_attributes()
                {
                    throw new \RuntimeException('Disabled suffixes must not read attributes.');
                }
            };

            self::assertNull((new AttributeService())->getAttributeSuffix($product));
        }

        public function disabledSettings(): array
        {
            return [['no'], [false], [null], [''], [true]];
        }

        public function testUsesGlobalTaxonomyLabelsAndProductTermNamesAlongsideCustomAttributes(): void
        {
            $GLOBALS['attribute_suffix_test_labels']['pa_publisher'] = 'ناشر';
            $GLOBALS['attribute_suffix_test_terms']['pa_publisher'] = ['نشر چشمه', 'نشر نی'];
            $product = $this->product(['نویسنده' => ['صادق هدایت'], 'pa_publisher' => []], ['pa_publisher']);

            self::assertSame('نشر چشمه, نشر نی, صادق هدایت', (new AttributeService())->getAttributeSuffix($product));
            self::assertSame([[42, 'pa_publisher', ['fields' => 'names']]], $GLOBALS['attribute_suffix_test_term_calls']);
        }

        public function testCombinesTwoGlobalAttributesInConfiguredOrder(): void
        {
            $GLOBALS['attribute_suffix_test_labels'] = ['pa_author' => 'نویسنده', 'pa_publisher' => 'ناشر'];
            $GLOBALS['attribute_suffix_test_terms'] = ['pa_author' => ['صادق هدایت'], 'pa_publisher' => ['نشر چشمه']];
            $product = $this->product(['pa_author' => [], 'pa_publisher' => []], ['pa_author', 'pa_publisher']);

            self::assertSame('نشر چشمه, صادق هدایت', (new AttributeService())->getAttributeSuffix($product));
        }

        public function testSkipsTaxonomyAttributesWithNoAssignedTerms(): void
        {
            $GLOBALS['attribute_suffix_test_labels']['pa_publisher'] = 'ناشر';
            $product = $this->product(['pa_publisher' => [], 'نویسنده' => ['صادق هدایت']], ['pa_publisher']);

            self::assertSame('صادق هدایت', (new AttributeService())->getAttributeSuffix($product));
        }

        /** @dataProvider titleCases */
        public function testGeneratedTitlesPreserveExistingFormattingForBothProductHandlers(
            string $configured, array $attributes, string $prefix, string $suffix, string $baseName, string $expected
        ): void {
            $this->configure($configured);
            $this->settings[SettingsConfig::PRODUCT_PREFIX_TITLE] = $prefix;
            $this->settings[SettingsConfig::PRODUCT_SUFFIX_TITLE] = $suffix;

            foreach ([SimpleProductHandler::class, VariableProductHandler::class] as $handlerClass) {
                self::assertSame($expected, $this->handler($handlerClass)->getName($this->product($attributes, [], $baseName)));
            }
        }

        public function titleCases(): array
        {
            $book = ['ناشر' => ['نشر چشمه'], 'نویسنده' => ['صادق هدایت']];

            return [
                'both values inside one pair of parentheses' => ['ناشر, نویسنده', $book, '', '', 'کتاب', 'کتاب (نشر چشمه, صادق هدایت)'],
                'static prefix and suffix remain' => ['ناشر, نویسنده', $book, 'فروشگاه', 'اصل', 'کتاب', 'فروشگاه کتاب اصل (نشر چشمه, صادق هدایت)'],
                'single setting remains compatible' => ['ناشر', $book, '', '', 'کتاب', 'کتاب (نشر چشمه)'],
                'one missing attribute has no dangling separator' => ['مترجم, ناشر', $book, '', '', 'کتاب', 'کتاب (نشر چشمه)'],
                'no matches has no empty parentheses' => ['مترجم, زبان', $book, 'فروشگاه', 'اصل', 'کتاب', 'فروشگاه کتاب اصل'],
                'empty setting leaves title alone' => ['', $book, '', '', 'کتاب', 'کتاب'],
                'standalone zero value is kept' => ['شماره', ['شماره' => ['0']], '', '', 'کالا', 'کالا (0)'],
                'Persian title is capped at 120 characters' => ['ناشر, نویسنده', $book, '', '', str_repeat('ک', 115), str_repeat('ک', 115) . ' (نشر'],
            ];
        }

        public function testDisabledSuffixDoesNotChangeGeneratedTitle(): void
        {
            $this->configure('ناشر, نویسنده', 'no');
            self::assertSame('کتاب', $this->handler(SimpleProductHandler::class)->getName($this->product(['ناشر' => ['نشر چشمه']])));
        }

        public function testSettingsInputRetainsCommaSeparatedNamesAndShowsTheExample(): void
        {
            $this->configure('ناشر, نویسنده');
            ob_start();
            SettingPageComponents::renderAttributeSuffixPriority();
            $html = ob_get_clean();

            self::assertStringContainsString('value="ناشر, نویسنده"', $html);
            self::assertStringContainsString('placeholder="مثال: ناشر, نویسنده"', $html);
            self::assertStringNotContainsString('disabled', $html);
        }

        public function testDisabledSettingsInputRetainsAndEscapesItsValue(): void
        {
            $this->configure('ناشر, "نویسنده"', 'no');
            ob_start();
            SettingPageComponents::renderAttributeSuffixPriority();
            $html = ob_get_clean();

            self::assertStringContainsString('value="ناشر, &quot;نویسنده&quot;"', $html);
            self::assertStringContainsString('disabled', $html);
        }

        private function configure($names, $enabled = 'yes'): void
        {
            $this->settings = array_merge(SettingsConfig::getDefaultSettings(), [
                SettingsConfig::PRODUCT_ATTRIBUTE_SUFFIX_ENABLED => $enabled,
                SettingsConfig::PRODUCT_ATTRIBUTE_SUFFIX_PRIORITY => $names,
            ]);
            $GLOBALS['attribute_suffix_test_settings'] = new class($this->settings) {
                private array $settings;
                public function __construct(array $settings) { $this->settings = $settings; }
                public function getSettings($key = null) { return $key === null ? $this->settings : ($this->settings[$key] ?? null); }
            };
        }

        private function product(array $attributes, array $taxonomies = [], string $name = 'کتاب')
        {
            $fixtures = [];
            foreach ($attributes as $label => $options) {
                $fixtures[] = new class((string) $label, $options, in_array($label, $taxonomies, true)) {
                    private string $name;
                    private array $options;
                    private bool $taxonomy;
                    public function __construct(string $name, array $options, bool $taxonomy)
                    {
                        $this->name = $name;
                        $this->options = $options;
                        $this->taxonomy = $taxonomy;
                    }
                    public function is_taxonomy(): bool { return $this->taxonomy; }
                    public function get_name(): string { return $this->name; }
                    public function get_options(): array { return $this->options; }
                };
            }

            return new class($fixtures, $name) {
                private array $attributes;
                private string $name;
                public function __construct(array $attributes, string $name) { $this->attributes = $attributes; $this->name = $name; }
                public function get_attributes(): array { return $this->attributes; }
                public function get_name(): string { return $this->name; }
                public function get_id(): int { return 42; }
            };
        }

        private function handler(string $class): SimpleProductHandler
        {
            // Name generation only needs settings and AttributeService; avoid
            // constructing unrelated upload and category API dependencies.
            $handler = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            foreach (['settings' => $this->settings, 'attributeService' => new AttributeService()] as $name => $value) {
                $property = new ReflectionProperty(SimpleProductHandler::class, $name);
                $property->setAccessible(true);
                $property->setValue($handler, $value);
            }
            return $handler;
        }
    }
}
