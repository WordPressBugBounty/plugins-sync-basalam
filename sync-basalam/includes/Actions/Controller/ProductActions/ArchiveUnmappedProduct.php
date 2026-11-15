<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\Products\UnmappedProducts\ArchiveBasalamProductService;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedProductsManager;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedProductsRepository;

defined('ABSPATH') || exit;

class ArchiveUnmappedProduct extends ActionController
{
    public function __invoke()
    {
        $rowId = isset($_POST['row_id']) ? absint($_POST['row_id']) : 0;
        if (!$rowId) {
            wp_send_json_error(['message' => 'شناسهٔ ردیف الزامی است.'], 400);
        }

        $manager = new UnmappedProductsManager();
        $repository = $manager->repository();

        $row = $repository->find($rowId);
        if (!$row || (string) $row->vendor_id !== $manager->vendorId()) {
            wp_send_json_error(['message' => 'ردیف موردنظر پیدا نشد.'], 404);
        }

        // اگر بیرون از گزارش متصل شده باشد، آرشیو آن اشتباه است؛ ردیف بسته می‌شود.
        if ($repository->isMapped((int) $row->basalam_product_id)) {
            $repository->transition($rowId, UnmappedProductsRepository::UNRESOLVED_STATES, UnmappedProductsRepository::STATE_CONNECTED);
            $allResolved = $manager->releaseLockIfResolved();
            wp_send_json_success([
                'message' => 'این محصول در این فاصله به یک محصول ووکامرس متصل شده بود و از گزارش بسته شد.',
                'row_state' => UnmappedProductsRepository::STATE_CONNECTED,
                'all_resolved' => $allResolved,
            ]);
        }

        // claim اتمیک؛ اقدام همزمان صف آرشیو گروهی روی همین ردیف دو بار اعمال نمی‌شود.
        if (!$repository->transition($rowId, [UnmappedProductsRepository::STATE_OPEN, UnmappedProductsRepository::STATE_FAILED], UnmappedProductsRepository::STATE_ARCHIVING)) {
            wp_send_json_error(['message' => 'این ردیف هم‌اکنون در حال پردازش یا تعیین‌تکلیف‌شده است.'], 409);
        }

        try {
            (new ArchiveBasalamProductService())->archive((int) $row->basalam_product_id);
        } catch (\Exception $e) {
            $repository->transition($rowId, [UnmappedProductsRepository::STATE_ARCHIVING], UnmappedProductsRepository::STATE_FAILED, mb_substr($e->getMessage(), 0, 500));
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }

        $repository->transition($rowId, [UnmappedProductsRepository::STATE_ARCHIVING], UnmappedProductsRepository::STATE_ARCHIVED);
        $allResolved = $manager->releaseLockIfResolved();

        wp_send_json_success([
            'message' => 'محصول با موفقیت در باسلام آرشیو شد.',
            'row_state' => UnmappedProductsRepository::STATE_ARCHIVED,
            'all_resolved' => $allResolved,
        ]);
    }
}
