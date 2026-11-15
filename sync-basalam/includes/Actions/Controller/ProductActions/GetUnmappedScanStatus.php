<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Admin\Components\ProductListComponents;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedProductsManager;

defined('ABSPATH') || exit;

class GetUnmappedScanStatus extends ActionController
{
    public function __invoke()
    {
        $manager = new UnmappedProductsManager();

        // زنجیرهٔ مرده نباید وضعیت «در حال بررسی» را برای همیشه نشان دهد.
        $manager->reconcile();

        $summary = $manager->statusSummary();

        // Only the products page requests a bounded, fresh table snapshot.
        if (isset($this->request['products_page'])) {
            $perPage = 20;
            $total = max(0, (int) $summary['counts']['total'] - (int) $summary['counts']['archived']);
            $pages = (int) ceil($total / $perPage);
            $page = min(max(1, absint($this->request['products_page'])), max(1, $pages));
            $vendorId = $manager->vendorId();
            $rows = $vendorId !== '' ? $manager->repository()->paginate($vendorId, $page, $perPage) : [];
            ob_start();
            ProductListComponents::renderUnmappedProductRows($rows, ($page - 1) * $perPage);
            $rowsHtml = ob_get_clean();
            ob_start();
            ProductListComponents::renderUnmappedPagination($page, $pages);
            $paginationHtml = ob_get_clean();
            $summary['products'] = [
                'page' => $page, 'pages' => $pages, 'total' => $total,
                'rows_html' => $rowsHtml, 'pagination_html' => $paginationHtml,
            ];
        }

        wp_send_json_success($summary);
    }
}
