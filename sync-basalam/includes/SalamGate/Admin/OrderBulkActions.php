<?php

namespace SyncBasalam\SalamGate\Admin;

use SyncBasalam\SalamGate\ClientFactory;
use SyncBasalam\SalamGate\Contracts\UpsertIdentity;
use SyncBasalam\SalamGate\FeatureAvailability;
use SyncBasalam\SalamGate\Order\BasalamShippingPreflight;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\Parcel\ParcelPayloadBuilder;
use SyncBasalam\SalamGate\SalamGateClient;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Services\CreationAttemptService;
use SyncBasalam\SalamGate\Utils\Digits;

if (! defined('ABSPATH')) exit;
require_once __DIR__ . '/../Services/CreationAttemptService.php';

/**
 * Bulk shipping entry on HPOS and legacy order lists. Opening a selection is
 * read-only; creation/quotes and per-parcel payment require explicit modal review.
 * Legacy creation helpers remain available, but are not invoked by the UI action.
 */
class OrderBulkActions
{
    public const ACTION = 'salam_gate_create_parcels';
    private const RESULT_OPTION = 'salam_gate_last_bulk';

    public static function register(): void
    {
        add_filter('bulk_actions-edit-shop_order', [self::class, 'registerAction']);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [self::class, 'registerAction']);
        add_action('handle_bulk_actions-edit-shop_order', [self::class, 'handle'], 10, 3);
        add_action('handle_bulk_actions-woocommerce_page_wc-orders', [self::class, 'handle'], 10, 3);
        add_action('admin_notices', [self::class, 'renderNotice']);
        add_action('admin_footer', [self::class, 'renderBulkHost']);
    }

    public static function registerAction(array $actions): array
    {
        $actions[self::ACTION] = 'ارسال گروهی با سلام‌گیت ووسلام';

        return $actions;
    }

    /**
     * @param array<int, int> $orderIds
     */
    public static function handle(string $redirectUrl, string $action, array $orderIds): string
    {
        if ($action !== self::ACTION) {
            return $redirectUrl;
        }

        if (!current_user_can('manage_woocommerce')) {
            return $redirectUrl;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn (int $id): bool => $id > 0)));
        if (!$ids) return $redirectUrl;
        if (count($ids) > 20) return add_query_arg(['salam_gate_bulk_error' => 'limit'], $redirectUrl);

        // WordPress checks the ordinary bulk-action nonce. The modal then uses
        // its own authorized AJAX boundary; redirecting never creates or pays.
        return add_query_arg(['sg_bulk_orders' => implode(',', $ids)], $redirectUrl);
    }

    public static function renderBulkHost(): void
    {
        if (!current_user_can('manage_woocommerce')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $orderList = $screen && in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'], true)
            && empty($_GET['action']);
        if (!$orderList) return;
        $template = dirname(__DIR__, 3) . '/templates/SalamGate/bulk-shipping-modal.php';
        if (is_file($template)) include_once $template;
    }

    public static function renderNotice(): void
    {
        if (($_GET['salam_gate_bulk_error'] ?? '') === 'limit' && current_user_can('manage_woocommerce')) {
            echo '<div class="notice notice-warning is-dismissible" data-sg-feedback-notice data-sg-feedback-kind="warning"><p>'
                . esc_html('برای ارسال گروهی، حداکثر ۲۰ سفارش را در هر نوبت انتخاب کنید.') . '</p></div>';
            return;
        }
        if (!isset($_GET['salam_gate_bulk']) || $_GET['salam_gate_bulk'] !== '1') {
            return;
        }

        $results = get_option(self::RESULT_OPTION, []);
        if (!is_array($results)) {
            return;
        }

        $created = array_filter($results, static fn (array $result): bool => $result['ok']);
        $failed = array_filter($results, static fn (array $result): bool => !$result['ok']);

        echo '<div class="notice notice-info is-dismissible" data-sg-feedback-notice data-sg-feedback-kind="' . ($failed !== [] ? 'error' : 'success') . '"><p><strong>سلام‌گیت:</strong> '
            . esc_html(sprintf('%s مرسوله ساخته شد', Digits::toPersian((string) count($created))))
            . ($failed !== [] ? ' — ' . esc_html(sprintf('%s سفارش رد شد', Digits::toPersian((string) count($failed)))) : '')
            . '</p>';

        if ($failed !== []) {
            echo '<ul style="margin:4px 0 8px;list-style:disc;padding-inline-start:20px;">';
            foreach ($failed as $result) {
                echo '<li>#' . esc_html((string) $result['order_id']) . ': ' . esc_html($result['message']) . '</li>';
            }
            echo '</ul>';
        }

        echo '<p style="margin:0 0 8px;">نرخ‌ها را از صفحه هر سفارش انتخاب و پرداخت کنید.</p></div>';
    }

    /**
     * ساخت مرسوله برای چند سفارش — نتیجه هر سفارش جداگانه.
     *
     * @param array<int, int> $orderIds
     *
     * @return array<int, array{order_id: int, ok: bool, message: string, parcel_id?: int}>
     */
    public static function createParcels(array $orderIds): array
    {
        $results = [];

        foreach ($orderIds as $orderId) {
            $results[] = self::createParcelForOrder($orderId) + ['order_id' => $orderId];
        }

        return $results;
    }

    /**
     * @return array{ok: bool, message: string, parcel_id?: int}
     */
    public static function createParcelForOrder(int $orderId): array
    {
        $shopId = FeatureAvailability::activeShopId();
        if ($shopId === null) {
            return ['ok' => false, 'message' => 'غرفه سلام‌گیت فعال نیست'];
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return ['ok' => false, 'message' => 'سفارش یافت نشد'];
        }

        $market = OrderContext::marketFor($orderId);

        if (!OrderMetabox::isActionableStatus($market, $order->get_status())) {
            return ['ok' => false, 'message' => 'وضعیت سفارش برای ارسال مناسب نیست'];
        }

        if ((int) $order->get_meta('_salam_gate_parcel_id') > 0) {
            return ['ok' => false, 'message' => 'قبلاً مرسوله سلام‌گیت ساخته شده است'];
        }

        $preflight = null;
        if ($market === OrderContext::MARKET_BASALAM) {
            try {
                require_once __DIR__ . '/../Order/BasalamParcelOrder.php';
                if (\SyncBasalam\SalamGate\Order\BasalamParcelOrder::hasScopes($order)) {
                    return ['ok' => false, 'message' => 'این سفارش مرسوله‌های جداگانه دارد؛ مرسوله موردنظر را از صفحه سفارش انتخاب کنید. ثبت گروهی آن را تغییر نداد.'];
                }
                // Recheck the original order's state, identities and item data;
                // an imported Woo subtotal is not authoritative for Basalam.
                $preflight = BasalamShippingPreflight::check($order);
            } catch (SalamGateException $exception) {
                return ['ok' => false, 'message' => $exception->firstMessage()];
            }
        }

        $externalId = $preflight !== null
            ? ParcelService::positiveId($preflight['identity']['parcel_id'] ?? null)
            : OrderContext::externalIdFor($orderId, $market);
        if ($externalId === null) {
            return ['ok' => false, 'message' => 'ارتباط این سفارش با مرسوله قابل تأیید نیست؛ از صفحه سفارش دوباره بررسی کنید'];
        }

        $builder = new ParcelPayloadBuilder();
        try {
            $payload = $preflight !== null
                ? $builder->assemble($preflight['order_data'], $shopId)
                : $builder->build($order, $shopId, ['external_id' => $externalId], $market);
        } catch (SalamGateException $exception) {
            return ['ok' => false, 'message' => $exception->firstMessage()];
        }

        if ($preflight !== null) {
            if (!BasalamShippingPreflight::isValidDeclaredValue($payload['parcel']['value'] ?? null)) {
                return ['ok' => false, 'message' => 'ارزش مرسوله باید بین ۲٬۰۰۰ تا ۵۰۰٬۰۰۰٬۰۰۰ تومان باشد؛ از صفحه سفارش آن را بررسی کنید'];
            }
            $receiverErrors = BasalamShippingPreflight::receiverInputErrors($payload['receiver'] ?? []);
            if ($receiverErrors !== []) {
                return ['ok' => false, 'message' => 'اطلاعات گیرنده کامل نیست؛ از صفحه سفارش آن را اصلاح کنید. ' . reset($receiverErrors)];
            }
        }

        $validationError = self::validateParcelForCreation($payload);
        if ($validationError !== null) {
            return ['ok' => false, 'message' => $validationError];
        }

        if ($preflight !== null) {
            // Basalam upsert targets an external identity and can update a draft
            // created in its native panel. Bulk creation must never overwrite it.
            try {
                $existing = (new ParcelService())->findByExternalId(OrderContext::MARKET_BASALAM, $externalId);
            } catch (\Throwable $exception) {
                return ['ok' => false, 'code' => 'basalam_recovery_failed',
                    'message' => 'بررسی مرسوله قبلی این سفارش انجام نشد؛ از صفحه سفارش وضعیت ارسال را بررسی کنید و دوباره تلاش کنید'];
            }
            if ($existing !== null) {
                return ['ok' => false, 'code' => 'basalam_parcel_exists',
                    'message' => 'برای این سفارش قبلاً مرسوله سلام‌گیت ثبت شده است؛ پرداخت و پیگیری را از صفحه همین سفارش ادامه دهید. ثبت گروهی آن را تغییر نداد'];
            }
        }

        unset($payload['_warnings'], $payload['_contents'], $payload['_display']);

        try {
            // ساخت گروهی فقط مرسوله جدید می‌سازد (سفارش دارای مرسوله رد می‌شود) —
            // هویت ساخت: شناسه پایدار سایت برای ووکامرس، شناسه تأییدشده مرسوله برای باسلام.
            $creation = (new CreationAttemptService())->runForOrder($shopId, $market, $externalId, $payload,
                static function (int $id) use ($market, $payload): ?int {
                    $response = ClientFactory::make()->post(sprintf(SalamGateClient::PATH_MARKET_PARCELS, $market),
                        UpsertIdentity::forCreate($market, $id) + $payload);
                    return ParcelService::positiveId($response['body']['parcel_id'] ?? null);
                }, [CreationAttemptService::class, 'recoverOrderParcel']);
            if (!$creation['ok']) return ['ok' => false] + $creation['payload'];
            $parcelId = $creation['payload']['parcel_id'];

            $order->update_meta_data('_salam_gate_parcel_id', $parcelId);
            $order->update_meta_data('_salam_gate_external_id', $externalId);
            $order->update_meta_data('_salam_gate_market', $market);
            $order->update_meta_data('_salam_gate_created_at', current_time('mysql'));
            $order->save();
            $order->add_order_note((!empty($creation['payload']['reused']) ? 'ثبت قبلی مرسوله سلام‌گیت بازیابی شد' : 'مرسوله سلام‌گیت ایجاد شد')
                . ' (شناسه ' . $parcelId . ') — نرخ‌ها را انتخاب و پرداخت کنید.');

            return ['ok' => true, 'message' => 'مرسوله ساخته شد', 'parcel_id' => $parcelId];
        } catch (SalamGateException $exception) {
            if (ParcelService::isResultUnknown($exception)) {
                return ['ok' => false, 'message' => 'نتیجه ثبت مرسوله نامعلوم است؛ از فهرست مرسولات بررسی کنید و دوباره ثبت نکنید', 'code' => 'create_unknown'];
            }
            return ['ok' => false, 'message' => $exception->firstMessage()];
        }
    }

    /**
     * اعتبارسنجی پیش از ارسال (پیام فارسی یا null).
     */
    public static function validateParcelForCreation(array $payload): ?string
    {
        $receiver = $payload['receiver'] ?? [];

        if (empty($receiver['city_id'])) {
            return 'شهر گیرنده قابل تشخیص نیست؛ از صفحه سفارش انتخاب دستی لازم است';
        }

        if (empty($receiver['postal_code'])) {
            return 'کد پستی گیرنده نامعتبر است';
        }

        if (!Digits::isValidMobile((string) ($receiver['mobile'] ?? ''))) {
            return 'شماره موبایل گیرنده نامعتبر است';
        }

        if (empty($payload['items'])) {
            return 'سفارش قلمی برای ارسال ندارد';
        }

        return null;
    }
}
