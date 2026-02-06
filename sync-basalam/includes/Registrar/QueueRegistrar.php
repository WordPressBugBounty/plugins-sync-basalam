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
        self::initTasks();
        self::initWpBgProcess();
    }

    private static function initTasks(): void
    {
        $taskClasses = [
            'Debug',
            'DailyCheckForceUpdate'
        ];

        foreach ($taskClasses as $className) {
            $fullClassName = 'SyncBasalam\\Queue\\Tasks\\' . $className;
            if (\class_exists($fullClassName) && \is_subclass_of($fullClassName, 'SyncBasalam\\Queue\\QueueAbstract')) {
                $task = new $fullClassName();
                $task->registerHooks();
                if ($task->NEED_SCHEDULE) $task->schedule();
            }
        }
    }

    private static function initWpBgProcess(): void
    {
        $dispatchers = [
            new CreateProduct(),
            new UpdateProduct(),
        ];

        foreach ($dispatchers as $dispatcher) {
            $className = \get_class($dispatcher);
            global ${$className};
            ${$className} = $dispatcher;
        }
    }
}
