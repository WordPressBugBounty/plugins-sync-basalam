<?php

namespace SyncBasalam\Registrar\ProductListeners;

defined('ABSPATH') || exit;

abstract class ProductListenerAbstract
{
    use ProductStatusTrait;

    abstract public function handle($data);

    public function initHook($event, $data)
    {
        return $this->handle($data);
    }
}
