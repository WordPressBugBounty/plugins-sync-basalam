<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Admin\Settings\SettingsManager;
use SyncBasalam\JobManager;
use SyncBasalam\Services\Products\ConnectSingleProductService;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedProductsManager;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedProductsRepository;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

/**
 * اتصال یک محصول باسلامِ متصل‌نشده به محصول موجود ووکامرس از داخل گزارش.
 *
 * رفتار پس از اتصال همان رفتار دکمهٔ اتصال فعلی افزونه است: در صورت معتبر بودن
 * تنظیمات بروزرسانی، یک job بروزرسانی تک‌محصول صف می‌شود.
 */
class ConnectUnmappedProduct extends ActionController
{
    public function __invoke()
    {
        $rowId = isset($_POST['row_id']) ? absint($_POST['row_id']) : 0;
        $wooProductId = isset($_POST['woo_product_id']) ? absint($_POST['woo_product_id']) : 0;

        if (!$rowId || !$wooProductId) {
            wp_send_json_error(['message' => 'داده‌های ورودی ناقص است.'], 400);
        }

        $manager = new UnmappedProductsManager();
        $repository = $manager->repository();

        $row = $repository->find($rowId);
        if (!$row || (string) $row->vendor_id !== $manager->vendorId()) {
            wp_send_json_error(['message' => 'ردیف موردنظر پیدا نشد.'], 404);
        }

        if (!in_array($row->state, UnmappedProductsRepository::UNRESOLVED_STATES, true)) {
            wp_send_json_error(['message' => 'این ردیف قبلا تعیین‌تکلیف شده است.'], 409);
        }

        $product = function_exists('wc_get_product') ? wc_get_product($wooProductId) : get_post($wooProductId);
        if (!$product) {
            wp_send_json_error(['message' => 'محصول ووکامرس موردنظر پیدا نشد.'], 404);
        }

        if (!empty(get_post_meta($wooProductId, ProductMetaKey::basalamProductId(), true))) {
            wp_send_json_error(['message' => 'این محصول ووکامرس قبلا به محصول دیگری در باسلام متصل شده است.'], 409);
        }

        try {
            $connected = ConnectSingleProductService::connectProductById($wooProductId, (int) $row->basalam_product_id);
        } catch (\SyncBasalam\Jobs\Exceptions\JobException $exception) {
            wp_send_json_error(['message' => $exception->getMessage()], $exception->shouldRetry() ? 503 : 409);
        } catch (\Exception $exception) {
            wp_send_json_error(['message' => $exception->getMessage()], 500);
        }

        if (!$connected) {
            // یعنی همین محصول باسلام قبلا به محصول ووکامرس دیگری متصل است؛ ردیف بسته می‌شود.
            $repository->transition($rowId, UnmappedProductsRepository::UNRESOLVED_STATES, UnmappedProductsRepository::STATE_CONNECTED);
            $allResolved = $manager->releaseLockIfResolved();
            wp_send_json_success([
                'message' => 'این محصول باسلام قبلا به یک محصول ووکامرس متصل شده بود و از گزارش بسته شد.',
                'row_state' => UnmappedProductsRepository::STATE_CONNECTED,
                'all_resolved' => $allResolved,
            ]);
        }

        if (SettingsManager::isProductUpdateSelectionValid()) {
            syncBasalamContainer()->get(JobManager::class)->createJob(
                'sync_basalam_update_single_product',
                'pending',
                json_encode(['product_id' => $wooProductId])
            );
        }

        $repository->transition($rowId, UnmappedProductsRepository::UNRESOLVED_STATES, UnmappedProductsRepository::STATE_CONNECTED);
        $allResolved = $manager->releaseLockIfResolved();

        wp_send_json_success([
            'message' => 'اتصال محصول با موفقیت انجام شد.',
            'row_state' => UnmappedProductsRepository::STATE_CONNECTED,
            'all_resolved' => $allResolved,
        ]);
    }
}
