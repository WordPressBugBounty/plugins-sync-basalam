<?php

namespace SyncBasalam\Actions\Controller;

use SyncBasalam\Services\VendorSyncPolicy;

defined('ABSPATH') || exit;

class RefreshVendorStatus extends ActionController
{
    public function __invoke()
    {
        $vendorSyncPolicy = syncBasalamContainer()->get(VendorSyncPolicy::class);
        $vendorSyncPolicy->refresh();
        $state = $vendorSyncPolicy->getFrontendState(false);

        if ($state['mode'] === VendorSyncPolicy::MODE_ACTIVE) {
            wp_send_json_success([
                'message' => 'غرفه شما فعال است و محدودیت‌های همگام‌سازی برداشته شد.',
                'state'   => $state,
            ]);
        }

        wp_send_json_success([
            'message' => 'غرفه شما هنوز در باسلام غیرفعال است.',
            'state'   => $state,
        ]);
    }
}
