<?php

namespace SyncBasalam\Registrar;

use SyncBasalam\Registrar\Contracts\RegistrarInterface;
use SyncBasalam\Queue\Tasks\UpdateProduct;
use SyncBasalam\Queue\Tasks\CreateProduct;

defined('ABSPATH') || exit;

class QueueRegistrar implements RegistrarInterface
{
    public static function register(): void
    {
        $container = syncBasalamContainer();
        self::initTasks($container);
        self::initWpBgProcess($container);
    }

    private static function initTasks($container): void
    {
        $taskClasses = [
            'Debug',
            'DailyCheckForceUpdate'
        ];

        foreach ($taskClasses as $className) {
            $fullClassName = 'SyncBasalam\\Queue\\Tasks\\' . $className;
            if (\class_exists($fullClassName) && \is_subclass_of($fullClassName, 'SyncBasalam\\Queue\\QueueAbstract')) {
                $task = $container->get($fullClassName);
                $task->registerHooks();
                if ($task->NEED_SCHEDULE) $task->schedule();
            }
        }
    }

    private static function initWpBgProcess($container): void
    {
        $dispatchers = [
            $container->get(CreateProduct::class),
            $container->get(UpdateProduct::class),
        ];

        foreach ($dispatchers as $dispatcher) {
            $className = \get_class($dispatcher);
            global ${$className};
            ${$className} = $dispatcher;
        }
    }
}
