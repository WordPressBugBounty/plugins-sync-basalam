<?php

namespace SyncBasalam\Registrar\ProductListeners;

defined('ABSPATH') || exit;

abstract class ProductListenerAbstract
{
    use ProductStatusTrait;

    protected static $instances = [];

    abstract public function handle($data);

    public static function fetch($data)
    {
        $class = get_called_class();

        if (!isset(self::$instances[$class])) {
            self::$instances[$class] = new $class();
        }

        return self::$instances[$class]->handle($data);
    }

    public function initHook($event, $data)
    {
        return $this->fetch($data);
    }
}
