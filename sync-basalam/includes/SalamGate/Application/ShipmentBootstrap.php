<?php
namespace SyncBasalam\SalamGate\Application;

use SyncBasalam\SalamGate\Contracts\ShopStatuses;
use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\CategoriesService;
use SyncBasalam\SalamGate\Services\ReferenceDataService;
use SyncBasalam\SalamGate\Services\ShopsService;

defined('ABSPATH') || exit;

/** ShipmentBootstrap: explicit inputs, returned outcomes, no HTTP response termination. */
final class ShipmentBootstrap
{
    private array $input;
    private ExecutionContext $context;

    public function __construct(array $input, ?ExecutionContext $context = null)
    {
        $this->input = $input;
        $this->context = $context ?? ExecutionContext::individual();
    }

    public function bootstrap(): array
    {
        $errors = [];
        $stale = [];
        try {
            $shops = (new ShopsService())->getShops();
            $activeShops = array_values(array_filter($shops, fn (array $s): bool =>
                (int) ($s['status_id'] ?? 0) === ShopStatuses::ACTIVE
            ));
            if ($activeShops === []) {
                return OperationResult::failure(['message' => 'آدرس فرستنده فعالی پیدا نشد؛ وضعیت حساب سلام‌گیت را در بخش ارسال مرسوله بررسی کنید.',
                    'code' => 'account_unavailable'], 409);
                return OperationResult::failure(['message' => 'عملیات کامل نشد.']);
            }
        } catch (SalamGateException $e) {
            // An expired active snapshot can discover a new denial here. Do
            // not fan out to categories/boxes/locations after the account fails.
            return OperationResult::failure(['message' => $e->firstMessage(), 'code' => 'account_unavailable'], 409);
            return OperationResult::failure(['message' => 'عملیات کامل نشد.']);
        }
        try {
            $categories = (new CategoriesService())->all();
            if ($categories === []) $errors['categories'] = 'نوع‌های مرسوله دریافت نشد؛ دوباره تلاش کنید.';
        } catch (\Throwable $e) {
            $categories = [];
            $errors['categories'] = 'نوع‌های مرسوله دریافت نشد؛ دوباره تلاش کنید.';
        }
        $references = new ReferenceDataService();
        try {
            $result = $references->getBoxTypesWithState();
            $boxTypes = $result['data'];
            $stale['box_types'] = $result['stale'];
        } catch (\Throwable $e) {
            $boxTypes = [];
            $errors['box_types'] = 'نوع‌های بسته‌بندی دریافت نشد؛ دوباره تلاش کنید.';
        }
        try {
            $result = $references->getProvinceTreeWithState();
            $provinces = $result['data'];
            $stale['provinces'] = $result['stale'];
        } catch (\Throwable $e) {
            $provinces = [];
            $errors['provinces'] = 'فهرست استان‌ها و شهرها دریافت نشد؛ دوباره تلاش کنید.';
        }

        // Keep existing Mixin parcels readable, but do not offer it for new orders.
        $selectableMarkets = array_values(array_diff(DirectShipment::MARKETS, ['mixin']));
        return OperationResult::success([
            'shops' => $activeShops,
            'categories' => $categories,
            'box_types' => $boxTypes,
            'provinces' => $provinces,
            // Basalam is a real-order picker, never an arbitrary direct-market create.
            'markets' => current_user_can('manage_woocommerce') ? array_merge(['basalam'], $selectableMarkets) : $selectableMarkets,
            'ready' => $errors === [],
            'errors' => $errors,
            'stale' => $stale,
        ]);
    }
}
