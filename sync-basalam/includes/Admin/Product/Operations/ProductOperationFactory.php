<?php

namespace SyncBasalam\Admin\Product\Operations;

use SyncBasalam\Admin\Product\Operations\ProductOperationInterface;
use SyncBasalam\Admin\Product\Operations\UpdateProduct;
use SyncBasalam\Admin\Product\Operations\CreateProduct;
use SyncBasalam\Admin\Product\Operations\ArchiveProduct;
use SyncBasalam\Admin\Product\Operations\RestoreProduct;

defined('ABSPATH') || exit;

class ProductOperationFactory
{
    private const OPERATIONS = [
        'update' => UpdateProduct::class,
        'create' => CreateProduct::class,
        'archive' => ArchiveProduct::class,
        'restore' => RestoreProduct::class,
    ];

    public static function create(string $type): ProductOperationInterface
    {
        if (!isset(self::OPERATIONS[$type])) {
            throw new \InvalidArgumentException(
                sprintf('Operation type "%s" is not supported. Available types: %s',
                    $type,
                    implode(', ', array_keys(self::OPERATIONS))
                )
            );
        }

        $operationClass = self::OPERATIONS[$type];
        return new $operationClass();
    }

    public static function getAvailableOperations(): array
    {
        return array_keys(self::OPERATIONS);
    }

    public static function isSupported(string $type): bool
    {
        return isset(self::OPERATIONS[$type]);
    }
}