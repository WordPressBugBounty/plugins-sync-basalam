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
            'woocommerce_update_product' => UpdateWooProduct::class,
            'save_post'                  => CreateWooProduct::class,
            'untrashed_post'             => RestoreProduct::class,
            'wp_trash_post'              => ArchiveProduct::class,
        ];

        foreach ($listeners as $event => $listenerClass) {
            \add_action($event, function ($data) use ($listenerClass, $event) {
                $listener = syncBasalamContainer()->get($listenerClass);
                $listener->initHook($event, $data);
            }, 10, 2);
        }
    }
}
