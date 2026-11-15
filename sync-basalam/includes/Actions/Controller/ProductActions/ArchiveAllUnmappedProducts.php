<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedProductsManager;

defined('ABSPATH') || exit;

class ArchiveAllUnmappedProducts extends ActionController
{
    public function __invoke()
    {
        $manager = new UnmappedProductsManager();
        $result = $manager->startBulkArchive();

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code']);
        }

        wp_send_json_success(array_merge(['message' => $result['message']], $manager->statusSummary()), $result['status_code']);
    }
}
