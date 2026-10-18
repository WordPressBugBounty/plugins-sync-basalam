<?php

namespace SyncBasalam\SalamGate\Admin;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\ShopStatuses;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Paths;
use SyncBasalam\SalamGate\Services\ShopsService;
use SyncBasalam\SalamGate\Services\ConnectionCache;
use SyncBasalam\SalamGate\Services\AccountService;

if (! defined('ABSPATH')) exit;

/**
 * Router وضعیت حساب سلام‌گیت — مسیر اصلی تجربه (پارت ۳).
 *
 * حالت‌ها (enum صریح؛ هیچ‌کدام با دیگری قابل جایگزینی نیست):
 * - not_connected   اتصال ووسلام به بسالم برقرار نیست (توکن نیست)
 * - loading         در حال دریافت (فقط مسیر async/AJAX؛ رندر سرور مستقیم resolve می‌کند)
 * - error_network   خطای ارتباط — کاربر تازه‌وارد فرض نمی‌شود، فرم ثبت تکراری نمی‌آید
 * - error_auth      احراز هویت رد شده (401) — راهنمای اتصال مجدد
 * - error_forbidden مجوز عملیات ناکافی (403) — تأیید دسترسی‌های سلام‌گیت، نه انقضای توکن
 * - not_registered  بدون ثبت (فهرست غرفه‌ها خالی) → صفحه معرفی/فعال‌سازی
 * - pending         ثبت در انتظار تأیید (15)
 * - rejected        رد شده (17)
 * - inactive        غیرفعال (16) → فعال‌سازی مجدد
 * - active          فعال (14) → داشبورد (پارت ۵؛ تا آن‌موقع حالت توسعه صریح)
 *
 * اولویت وضعیت‌ها با چند غرفه (تصمیم پیشنهادی؛ چندغرفه‌ای کامل در پارت ۵):
 * active > pending > inactive > rejected — اولین تجربه قابل‌اقدام مسلط می‌شود.
 */
class AccountStateRouter
{
    public const STATE_NOT_CONNECTED  = 'not_connected';
    public const STATE_LOADING        = 'loading';
    public const STATE_ERROR_NETWORK  = 'error_network';
    public const STATE_ERROR_AUTH     = 'error_auth';
    public const STATE_ERROR_FORBIDDEN = 'error_forbidden';
    public const STATE_NOT_REGISTERED = 'not_registered';
    public const STATE_PENDING        = 'pending';
    public const STATE_REJECTED       = 'rejected';
    public const STATE_INACTIVE       = 'inactive';
    public const STATE_ACTIVE         = 'active';

    public const ALL_STATES = [
        self::STATE_NOT_CONNECTED,
        self::STATE_LOADING,
        self::STATE_ERROR_NETWORK,
        self::STATE_ERROR_AUTH,
        self::STATE_ERROR_FORBIDDEN,
        self::STATE_NOT_REGISTERED,
        self::STATE_PENDING,
        self::STATE_REJECTED,
        self::STATE_INACTIVE,
        self::STATE_ACTIVE,
    ];

    /** viewهای مجاز روی مسیر canonical. */
    public const VIEW_STATES  = ['calculator', 'post-offices', 'tracking', 'activation', 'settings', 'send'];

    public static function register()
    {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueAssets']);
        add_action('wp_ajax_salam_gate_account_state', [Ajax\StatusActions::class, 'accountState']);
        add_action('wp_ajax_salam_gate_tracking', [Ajax\TrackingActions::class, 'lookup']);
    }

    /**
     * رندر صفحه canonical — پوسته + وضعیت resolve‌شده (یا view توسعه‌ای).
     */
    public static function render()
    {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی به سلام‌گیت مجاز نیست؛ با مدیر فروشگاه تماس بگیرید.');
        }

        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : '';

        $data = [
            'state'   => null,
            'view'    => in_array($view, self::VIEW_STATES, true) ? $view : '',
            'shops'   => [],
            'message' => '',
        ];

        if ($data['view'] === '') {
            $data = self::resolveForPage();
        } else {
            // viewها هم وضعیت واقعی حساب را بدانند (مثلاً ویزارد ثبت فقط برای «بدون ثبت»).
            // refresh/بازگشت کاربر وضعیت سرور را بازیابی می‌کند — دوباره ثبت نمی‌زند.
            $resolved = self::resolveForPage();
            $data['state'] = $resolved['state'];
            $data['shops'] = $resolved['shops'];
            $data['message'] = $resolved['message'];
        }

        $template = __DIR__ . '/../../../templates/SalamGate/page-router.php';
        if (file_exists($template)) {
            require_once $template;
        }
    }

    /**
     * Page navigation must not wait for an external API or OAuth renewal.
     * Only a current cache belonging to the saved connection is trusted here;
     * a miss renders a loading shell whose read-only AJAX request resolves the
     * account. Operational handlers continue to use their existing guards.
     *
     * @return array{state: string, shops: array, message: string}
     */
    public static function resolveForPage(): array
    {
        $token = syncBasalamSettings()->getSettings(SettingsConfig::TOKEN);
        if (!is_string($token) || trim($token) === '') {
            return ['state' => self::STATE_NOT_CONNECTED, 'shops' => [], 'message' => ''];
        }

        $cached = ConnectionCache::getForToken(ShopsService::CACHE_NAME, $token);
        if (isset($cached['data']['shops']) && is_array($cached['data']['shops'])) {
            $shops = $cached['data']['shops'];
            if ($shops === [] && in_array($cached['data']['registration_state'] ?? null, ['pending', 'unknown'], true)) {
                return self::registrationWaiting($cached['data']['registration_state']);
            }
            return ['state' => self::stateForShops($shops), 'shops' => $shops, 'message' => ''];
        }

        return ['state' => self::STATE_LOADING, 'shops' => [], 'message' => ''];
    }

    /**
     * وضعیت فعلی حساب را از دنیای واقعی resolve می‌کند (خالص نیست — WP-facing).
     *
     * @return array{state: string, shops: array, message: string}
     */
    public static function resolveCurrent(bool $fresh = false): array
    {
        try {
            $token = ClientFactory::make()->token();
        } catch (\Throwable $exception) {
            $token = null;
        }

        if ($token === null) {
            return ['state' => self::STATE_NOT_CONNECTED, 'shops' => [], 'message' => ''];
        }

        try {
            $service = new ShopsService();
            $identity = ConnectionCache::identityHash();
            $shops = $service->getShops($fresh);
            if ($shops === []) {
                $registration = $service->registrationState((new AccountService())->profile($fresh), $shops);
                ConnectionCache::assertCurrentIdentity($identity);
                if ($registration !== null) {
                    ConnectionCache::setForIdentity(ShopsService::CACHE_NAME, ['shops' => $shops, 'registration_state' => $registration], ShopsService::CACHE_TTL, $identity);
                    return self::registrationWaiting($registration);
                }
            }
        } catch (SalamGateException $exception) {
            if ($exception->isKind(SalamGateException::KIND_UNAUTHENTICATED)) {
                return ['state' => self::STATE_ERROR_AUTH, 'shops' => [], 'message' => $exception->getMessage()];
            }
            if ($exception->isKind(SalamGateException::KIND_FORBIDDEN)) {
                return ['state' => self::STATE_ERROR_FORBIDDEN, 'shops' => [], 'message' => $exception->getMessage()];
            }

            // خطای شبکه/سرور: کاربر تازه‌وارد فرض نمی‌شود — فرم ثبت تکراری ممنوع.
            return ['state' => self::STATE_ERROR_NETWORK, 'shops' => [], 'message' => $exception->getMessage()];
        }

        return [
            'state' => self::stateForShops($shops),
            'shops' => $shops,
            'message' => '',
        ];
    }

    /** A queued/ambiguous local attempt is not proof of an active postal shop. */
    private static function registrationWaiting(string $registration): array
    {
        return [
            'state' => self::STATE_PENDING,
            'shops' => [],
            'message' => $registration === 'pending'
                ? 'درخواست فعال‌سازی ثبت شده و هنوز نتیجه نهایی آن در فهرست حساب‌ها ظاهر نشده است. دوباره ثبت یا پرداخت نکنید؛ وضعیت را بررسی کنید.'
                : 'نتیجه درخواست فعال‌سازی قبلی هنوز مشخص نیست. برای جلوگیری از پرداخت دوباره، ثبت را تکرار نکنید و وضعیت را بررسی کنید.',
        ];
    }

    /**
     * نگاشت فهرست غرفه‌ها → وضعیت مسیر (خالص و تست‌پذیر).
     *
     * @param array<int, array{status_id?: int}> $shops خروجی نرمال‌شده ShopNormalizer
     */
    public static function stateForShops(array $shops): string
    {
        if ($shops === []) {
            return self::STATE_NOT_REGISTERED;
        }

        $statuses = [];
        foreach ($shops as $shop) {
            $statuses[] = (int) ($shop['status_id'] ?? 0);
        }

        if (in_array(ShopStatuses::ACTIVE, $statuses, true)) {
            return self::STATE_ACTIVE;
        }
        if (in_array(ShopStatuses::PENDING_APPROVAL, $statuses, true)) {
            return self::STATE_PENDING;
        }
        if (in_array(ShopStatuses::INACTIVE, $statuses, true)) {
            return self::STATE_INACTIVE;
        }
        if (in_array(ShopStatuses::REJECTED, $statuses, true)) {
            return self::STATE_REJECTED;
        }

        return self::STATE_ERROR_NETWORK; // وضعیت ناشناخته — محافظه‌کارانه، نه تازه‌وارد
    }

    /**
     * HTML جزء وضعیت (برای رندر سروری و AJAX refresh).
     */
    public static function renderStatePartial(array $data): string
    {
        $state = in_array($data['state'] ?? '', self::ALL_STATES, true) ? $data['state'] : self::STATE_LOADING;
        $template = __DIR__ . '/../../../templates/SalamGate/states/state-' . $state . '.php';

        ob_start();
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="sg-state" data-state="' . esc_attr($state) . '"></div>';
        }

        return (string) ob_get_clean();
    }

    /**
     * assetهای پایه (توکن‌های رنگ/فونت ووسلام) باید واقعاً روی صفحه سلام‌گیت enqueue شوند.
     *
     * گیت enqueue پایه صفحات سلام‌گیت را پوشش نمی‌دهد (فال‌بک sync_basalam در برخی
     * مسیرها فعال نمی‌شود و هندل‌ها register نمی‌شوند)؛ پس اگر register نبودند،
     * خودمان با URL فایل‌های افزونه پایه register و enqueue می‌کنیم.
     */
    public static function enqueueAssets(string $hookSuffix)
    {
        $isSalamGatePage = strpos($hookSuffix, 'salam_gate') !== false;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $isOrderScreen = $screen && in_array($screen->id, ['shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders'], true);

        if (!$isSalamGatePage && !$isOrderScreen) {
            return;
        }

        self::ensureBaseStyle('basalam-admin-font-style', 'assets/css/font.css');
        if ($isSalamGatePage) {
            self::ensureBaseStyle('basalam-admin-style', 'assets/css/style.css');
        }
        self::ensureBaseStyle('basalam-admin-toast-style', 'assets/css/toast.css');
        self::ensureBaseScript('basalam-admin-toast-script', 'assets/js/toast.js');

        $pluginFile = dirname(__DIR__, 3) . '/sync-basalam.php';
        $stylePath = dirname(__DIR__, 3) . '/assets/salam-gate/styles/salam-gate-admin.css';
        $scriptPath = dirname(__DIR__, 3) . '/assets/salam-gate/js/salam-gate-admin.js';

        wp_enqueue_style(
            'woosalam-salam-gate-admin',
            plugins_url('assets/salam-gate/styles/salam-gate-admin.css', $pluginFile),
            ['woosalam-salam-gate-style', 'basalam-admin-font-style'],
            file_exists($stylePath) ? (string) filemtime($stylePath) : null
        );
        $extraStyle = $isOrderScreen ? 'salam-gate-order' : 'salam-gate-layout';
        $extraPath = dirname(__DIR__, 3) . '/assets/salam-gate/styles/' . $extraStyle . '.css';
        if (file_exists($extraPath)) {
            wp_enqueue_style(
                'woosalam-' . $extraStyle,
                plugins_url('assets/salam-gate/styles/' . $extraStyle . '.css', $pluginFile),
                ['woosalam-salam-gate-admin'],
                (string) filemtime($extraPath)
            );
        }
        if ($isSalamGatePage && isset($_GET['view']) && $_GET['view'] === 'settings') {
            $settingsStylePath = dirname(__DIR__, 3) . '/assets/salam-gate/styles/salam-gate-settings.css';
            if (file_exists($settingsStylePath)) {
                wp_enqueue_style(
                    'woosalam-salam-gate-settings',
                    plugins_url('assets/salam-gate/styles/salam-gate-settings.css', $pluginFile),
                    ['woosalam-salam-gate-layout'],
                    (string) filemtime($settingsStylePath)
                );
            }
        }
        if ($isSalamGatePage) {
            $activationStylePath = dirname(__DIR__, 3) . '/assets/salam-gate/styles/salam-gate-activation.css';
            if (file_exists($activationStylePath)) {
                wp_enqueue_style('woosalam-salam-gate-activation', plugins_url('assets/salam-gate/styles/salam-gate-activation.css', $pluginFile), ['woosalam-salam-gate-layout'], (string) filemtime($activationStylePath));
            }
        }
        if ($isSalamGatePage && ($_GET['view'] ?? '') === 'send') {
            foreach (['salam-gate-order', 'salam-gate-basalam-orders'] as $asset) {
                $path = dirname(__DIR__, 3) . '/assets/salam-gate/styles/' . $asset . '.css';
                if (file_exists($path)) wp_enqueue_style('woosalam-' . $asset, plugins_url('assets/salam-gate/styles/' . $asset . '.css', $pluginFile), ['woosalam-salam-gate-admin'], (string) filemtime($path));
            }
            $basalamOrdersScript = dirname(__DIR__, 3) . '/assets/salam-gate/js/salam-gate-basalam-orders.js';
            if (file_exists($basalamOrdersScript)) wp_enqueue_script('woosalam-salam-gate-basalam-orders', plugins_url('assets/salam-gate/js/salam-gate-basalam-orders.js', $pluginFile), ['woosalam-salam-gate-script'], (string) filemtime($basalamOrdersScript), true);
        }
        if ($isOrderScreen || ($isSalamGatePage && (($_GET['view'] ?? '') === 'send' || ($_GET['page'] ?? '') === 'salam_gate_parcels'))) {
            $nativeStyle = dirname(__DIR__, 3) . '/assets/salam-gate/styles/salam-gate-order-parcels.css';
            if (file_exists($nativeStyle)) wp_enqueue_style('woosalam-salam-gate-order-parcels', plugins_url('assets/salam-gate/styles/salam-gate-order-parcels.css', $pluginFile), ['woosalam-salam-gate-admin'], (string) filemtime($nativeStyle));
        }
        if ($isOrderScreen || ($isSalamGatePage && ($_GET['view'] ?? '') === 'send')) {
            $nativeScript = dirname(__DIR__, 3) . '/assets/salam-gate/js/salam-gate-order-parcels.js';
            if (file_exists($nativeScript)) wp_enqueue_script('woosalam-salam-gate-order-parcels', plugins_url('assets/salam-gate/js/salam-gate-order-parcels.js', $pluginFile), ['woosalam-salam-gate-script'], (string) filemtime($nativeScript), true);
        }
        wp_enqueue_script(
            'woosalam-salam-gate-admin',
            plugins_url('assets/salam-gate/js/salam-gate-admin.js', $pluginFile),
            ['woosalam-salam-gate-script'],
            file_exists($scriptPath) ? (string) filemtime($scriptPath) : null,
            true
        );
        if ($isSalamGatePage) {
            $navigationScript = dirname(__DIR__, 3) . '/assets/salam-gate/js/salam-gate-navigation.js';
            wp_enqueue_script('woosalam-salam-gate-navigation', plugins_url('assets/salam-gate/js/salam-gate-navigation.js', $pluginFile), ['woosalam-salam-gate-admin'], (string) filemtime($navigationScript), true);
        }
        if ($isSalamGatePage && ($_GET['view'] ?? '') === 'tracking') {
            $trackingStyle = dirname(__DIR__, 3) . '/assets/salam-gate/styles/salam-gate-tracking.css';
            $trackingScript = dirname(__DIR__, 3) . '/assets/salam-gate/js/salam-gate-tracking.js';
            wp_enqueue_style('woosalam-salam-gate-tracking', plugins_url('assets/salam-gate/styles/salam-gate-tracking.css', $pluginFile), ['woosalam-salam-gate-layout'], (string) filemtime($trackingStyle));
            wp_enqueue_script('woosalam-salam-gate-tracking', plugins_url('assets/salam-gate/js/salam-gate-tracking.js', $pluginFile), ['jquery', 'woosalam-salam-gate-admin'], (string) filemtime($trackingScript), true);
        }
        foreach (['salam-gate-feedback', 'salam-gate-payment'] as $asset) {
            $path = dirname(__DIR__, 3) . '/assets/salam-gate/styles/' . $asset . '.css';
            if (file_exists($path)) wp_enqueue_style('woosalam-' . $asset, plugins_url('assets/salam-gate/styles/' . $asset . '.css', $pluginFile), ['woosalam-salam-gate-admin'], (string) filemtime($path));
        }
        $paymentScript = dirname(__DIR__, 3) . '/assets/salam-gate/js/salam-gate-payment.js';
        if (file_exists($paymentScript)) wp_enqueue_script('woosalam-salam-gate-payment', plugins_url('assets/salam-gate/js/salam-gate-payment.js', $pluginFile), ['woosalam-salam-gate-script'], (string) filemtime($paymentScript), true);
        $isBulkOrderList = $screen && in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'], true) && empty($_GET['action']);
        if ($isBulkOrderList) {
            $bulkStyle = dirname(__DIR__, 3) . '/assets/salam-gate/styles/salam-gate-bulk-shipping.css';
            $bulkScript = dirname(__DIR__, 3) . '/assets/salam-gate/js/salam-gate-bulk-shipping.js';
            if (file_exists($bulkStyle)) wp_enqueue_style('woosalam-salam-gate-bulk-shipping', plugins_url('assets/salam-gate/styles/salam-gate-bulk-shipping.css', $pluginFile), ['woosalam-salam-gate-admin', 'woosalam-salam-gate-payment'], (string) filemtime($bulkStyle));
            if (file_exists($bulkScript)) {
                wp_enqueue_script('woosalam-salam-gate-bulk-shipping', plugins_url('assets/salam-gate/js/salam-gate-bulk-shipping.js', $pluginFile), ['woosalam-salam-gate-script', 'woosalam-salam-gate-admin', 'woosalam-salam-gate-payment'], (string) filemtime($bulkScript), true);
                wp_localize_script('woosalam-salam-gate-bulk-shipping', 'woosalamBulkShippingConfig', ['maxItems' => 20, 'orderAction' => OrderBulkActions::ACTION]);
            }
        }
        if ($isSalamGatePage && ($_GET['page'] ?? '') === 'salam_gate_parcels') {
            $checkoutScript = dirname(__DIR__, 3) . '/assets/salam-gate/js/salam-gate-parcels-checkout.js';
            $parcelsStyle = dirname(__DIR__, 3) . '/assets/salam-gate/styles/salam-gate-parcels.css';
            if (file_exists($checkoutScript)) wp_enqueue_script('woosalam-salam-gate-parcels-checkout', plugins_url('assets/salam-gate/js/salam-gate-parcels-checkout.js', $pluginFile), ['woosalam-salam-gate-script', 'woosalam-salam-gate-payment'], (string) filemtime($checkoutScript), true);
            if (file_exists($parcelsStyle)) wp_enqueue_style('woosalam-salam-gate-parcels', plugins_url('assets/salam-gate/styles/salam-gate-parcels.css', $pluginFile), ['woosalam-salam-gate-admin', 'woosalam-salam-gate-layout'], (string) filemtime($parcelsStyle));
            $cancelDialogScript = dirname(__DIR__, 3) . '/assets/salam-gate/js/salam-gate-cancel-dialog.js';
            $cancelDialogStyle = dirname(__DIR__, 3) . '/assets/salam-gate/styles/salam-gate-cancel-dialog.css';
            wp_enqueue_script('woosalam-salam-gate-cancel-dialog', plugins_url('assets/salam-gate/js/salam-gate-cancel-dialog.js', $pluginFile), ['woosalam-salam-gate-script'], (string) filemtime($cancelDialogScript), true);
            wp_enqueue_style('woosalam-salam-gate-cancel-dialog', plugins_url('assets/salam-gate/styles/salam-gate-cancel-dialog.css', $pluginFile), ['woosalam-salam-gate-parcels'], (string) filemtime($cancelDialogStyle));
        }
        $pickerStyle = dirname(__DIR__, 3) . '/assets/salam-gate/styles/salam-gate-pickers.css';
        $pickerScript = dirname(__DIR__, 3) . '/assets/salam-gate/js/salam-gate-pickers.js';
        if (file_exists($pickerStyle)) wp_enqueue_style('woosalam-salam-gate-pickers', plugins_url('assets/salam-gate/styles/salam-gate-pickers.css', $pluginFile), ['woosalam-salam-gate-admin'], (string) filemtime($pickerStyle));
        if (file_exists($pickerScript)) wp_enqueue_script('woosalam-salam-gate-pickers', plugins_url('assets/salam-gate/js/salam-gate-pickers.js', $pluginFile), ['woosalam-salam-gate-admin', 'woosalam-salam-gate-payment'], (string) filemtime($pickerScript), true);
        $moneyScript = dirname(__DIR__, 3) . '/assets/salam-gate/js/salam-gate-money.js';
        if (file_exists($moneyScript)) wp_enqueue_script('woosalam-salam-gate-money', plugins_url('assets/salam-gate/js/salam-gate-money.js', $pluginFile), ['woosalam-salam-gate-script'], (string) filemtime($moneyScript), true);
        $validationStyle = dirname(__DIR__, 3) . '/assets/salam-gate/styles/salam-gate-validation.css';
        $validationScript = dirname(__DIR__, 3) . '/assets/salam-gate/js/salam-gate-validation.js';
        if (file_exists($validationStyle)) wp_enqueue_style('woosalam-salam-gate-validation', plugins_url('assets/salam-gate/styles/salam-gate-validation.css', $pluginFile), ['woosalam-salam-gate-admin'], (string) filemtime($validationStyle));
        if (file_exists($validationScript)) wp_enqueue_script('woosalam-salam-gate-validation', plugins_url('assets/salam-gate/js/salam-gate-validation.js', $pluginFile), ['woosalam-salam-gate-script', 'woosalam-salam-gate-money'], (string) filemtime($validationScript), true);
        $requestedView = isset($_GET['view']) && is_string($_GET['view']) ? $_GET['view'] : '';
        if ($isOrderScreen || ($isSalamGatePage && $requestedView === 'send')) {
            foreach (['css' => 'styles', 'js' => 'js'] as $extension => $folder) {
                $path = dirname(__DIR__, 3) . '/assets/salam-gate/' . $folder . '/salam-gate-order-source-status.' . $extension;
                if (!file_exists($path)) continue;
                $url = plugins_url('assets/salam-gate/' . $folder . '/salam-gate-order-source-status.' . $extension, $pluginFile);
                if ($extension === 'css') wp_enqueue_style('woosalam-salam-gate-order-source-status', $url, ['woosalam-salam-gate-admin'], (string) filemtime($path));
                else wp_enqueue_script('woosalam-salam-gate-order-source-status', $url, ['woosalam-salam-gate-script'], (string) filemtime($path), true);
            }
            foreach (['css' => 'styles', 'js' => 'js'] as $extension => $folder) {
                $path = dirname(__DIR__, 3) . '/assets/salam-gate/' . $folder . '/salam-gate-product-search.' . $extension;
                if (!file_exists($path)) continue;
                $url = plugins_url('assets/salam-gate/' . $folder . '/salam-gate-product-search.' . $extension, $pluginFile);
                if ($extension === 'css') wp_enqueue_style('woosalam-salam-gate-product-search', $url, ['woosalam-salam-gate-admin'], (string) filemtime($path));
                else wp_enqueue_script('woosalam-salam-gate-product-search', $url, ['woosalam-salam-gate-script'], (string) filemtime($path), true);
            }
        }
        $motionPath = dirname(__DIR__, 3) . '/assets/salam-gate/styles/salam-gate-motion.css';
        if (file_exists($motionPath)) {
            $dependencies = ['woosalam-salam-gate-admin'];
            foreach (['woosalam-salam-gate-style', 'woosalam-salam-gate-order', 'woosalam-salam-gate-layout',
                'woosalam-salam-gate-parcels', 'woosalam-salam-gate-payment', 'woosalam-salam-gate-order-parcels',
                'woosalam-salam-gate-basalam-orders', 'woosalam-salam-gate-order-source-status'] as $handle) {
                if (wp_style_is($handle, 'enqueued')) $dependencies[] = $handle;
            }
            wp_enqueue_style('woosalam-salam-gate-motion', plugins_url('assets/salam-gate/styles/salam-gate-motion.css', $pluginFile),
                $dependencies, (string) filemtime($motionPath));
        }
        $mobilePath = dirname(__DIR__, 3) . '/assets/salam-gate/styles/salam-gate-mobile.css';
        if (file_exists($mobilePath)) {
            $dependencies = ['woosalam-salam-gate-admin'];
            foreach (['woosalam-salam-gate-motion', 'woosalam-salam-gate-settings', 'woosalam-salam-gate-activation',
                'woosalam-salam-gate-order', 'woosalam-salam-gate-parcels', 'woosalam-salam-gate-pickers',
                'woosalam-salam-gate-layout', 'woosalam-salam-gate-payment', 'woosalam-salam-gate-feedback'] as $handle) {
                if (wp_style_is($handle, 'enqueued')) $dependencies[] = $handle;
            }
            wp_enqueue_style('woosalam-salam-gate-mobile', plugins_url('assets/salam-gate/styles/salam-gate-mobile.css', $pluginFile),
                $dependencies, (string) filemtime($mobilePath));
        }
        $iconsPath = dirname(__DIR__, 3) . '/assets/salam-gate/styles/salam-gate-icons.css';
        if (file_exists($iconsPath)) {
            $dependencies = ['woosalam-salam-gate-admin'];
            if (wp_style_is('woosalam-salam-gate-mobile', 'enqueued')) $dependencies[] = 'woosalam-salam-gate-mobile';
            wp_enqueue_style('woosalam-salam-gate-icons', plugins_url('assets/salam-gate/styles/salam-gate-icons.css', $pluginFile),
                $dependencies, (string) filemtime($iconsPath));
        }
    }

    private static function ensureBaseStyle(string $handle, string $relativePath): void
    {
        if (wp_style_is($handle, 'registered') || wp_style_is($handle, 'enqueued')) {
            wp_enqueue_style($handle);

            return;
        }

        $baseFile = Paths::root() . '/sync-basalam.php';
        if (!file_exists($baseFile)) {
            return;
        }

        wp_register_style($handle, plugins_url($relativePath, $baseFile), [], null);
        wp_enqueue_style($handle);
    }

    private static function ensureBaseScript(string $handle, string $relativePath): void
    {
        if (wp_script_is($handle, 'registered') || wp_script_is($handle, 'enqueued')) {
            wp_enqueue_script($handle);
            return;
        }
        $baseFile = Paths::root() . '/sync-basalam.php';
        if (!file_exists($baseFile)) return;
        wp_register_script($handle, plugins_url($relativePath, $baseFile), [], null, true);
        wp_enqueue_script($handle);
    }
}
