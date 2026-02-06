<?php

namespace SyncBasalam\Jobs\Types;

use SyncBasalam\Jobs\AbstractJobType;
use SyncBasalam\Services\Products\AutoConnectProducts;

defined('ABSPATH') || exit;

class AutoConnectProductsJob extends AbstractJobType
{
    public function getType(): string
    {
        return 'sync_basalam_auto_connect_products';
    }

    public function getPriority(): int
    {
        return 6;
    }

    public function execute(array $payload): void
    {
        $page = $payload['page'] ?? 1;

        $autoConnect = new AutoConnectProducts();
        $result = $autoConnect->checkSameProduct(null, $page);

        if (isset($result['has_more']) && $result['has_more']) {
            $totalPage = $result['total_page'] ?? $page + 1;
            $next = min($page + 1, $totalPage);

            $this->jobManager->createJob(
                'sync_basalam_auto_connect_products',
                'pending',
                json_encode(['page' => $next])
            );
        }
    }
}
