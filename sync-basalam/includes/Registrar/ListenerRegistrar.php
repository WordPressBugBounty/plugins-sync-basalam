<?php

namespace SyncBasalam\Registrar;

use SyncBasalam\Registrar\Contracts\RegistrarInterface;
use SyncBasalam\Registrar\ProductListeners\RestoreProduct;
use SyncBasalam\Registrar\ProductListeners\ArchiveProduct;
use SyncBasalam\Registrar\ProductListeners\UpdateWooProduct;
use SyncBasalam\Registrar\ProductListeners\CreateWooProduct;

defined('ABSPATH') || exit;

class ListenerRegistrar implements RegistrarInterface
{
    public static function register(): void
    {
        $listeners = [
            'woocommerce_update_product' => new UpdateWooProduct(),
            'save_post'                  => new CreateWooProduct(),
            'untrashed_post'             => new RestoreProduct(),
            'wp_trash_post'              => new ArchiveProduct(),
        ];

        foreach ($listeners as $event => $listener) {
            \add_action($event, function ($data) use ($listener, $event) {
                $listener->initHook($event, $data);
            }, 10, 2);
        }
    }
}