<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Listener
{
    protected static $instances = [];

    public static function fetch($data)
    {
        $class = get_called_class();

        if (!isset(self::$instances[$class])) {
            self::$instances[$class] = new $class();
        }

        if (self::$instances[$class] instanceof sync_basalam_Listener_Interface) {
            return self::$instances[$class]->handle($data);
        }
    }

    public function init_hook($event, $data)
    {
        return $this->fetch($data);
    }
}
