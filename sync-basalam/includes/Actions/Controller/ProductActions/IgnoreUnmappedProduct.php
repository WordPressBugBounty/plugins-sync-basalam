<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedProductsManager;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedProductsRepository;

defined('ABSPATH') || exit;

class IgnoreUnmappedProduct extends ActionController
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

        if (!$repository->transition($rowId, [UnmappedProductsRepository::STATE_OPEN, UnmappedProductsRepository::STATE_FAILED], UnmappedProductsRepository::STATE_IGNORED)) {
            wp_send_json_error(['message' => 'این ردیف هم‌اکنون در حال پردازش یا تعیین‌تکلیف‌شده است.'], 409);
        }

        $allResolved = $manager->releaseLockIfResolved();

        wp_send_json_success([
            'message' => 'این محصول نادیده گرفته شد.',
            'row_state' => UnmappedProductsRepository::STATE_IGNORED,
            'all_resolved' => $allResolved,
        ]);
    }
}
