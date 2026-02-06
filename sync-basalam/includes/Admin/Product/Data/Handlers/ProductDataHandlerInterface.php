<?php

namespace SyncBasalam\Admin\Product\Data\Handlers;

defined('ABSPATH') || exit;

interface ProductDataHandlerInterface
{
    public function getName($product): string;
    public function getDescription($product): string;
    public function getCategoryId($product): ?int;
    public function getCategoryIds($product): array;
    public function getPrice($product): ?int;
    public function getStock($product): int;
    public function getWeight($product): ?int;
    public function getPackageWeight($product): int;
    public function getMainPhoto($product): ?int;
    public function getGalleryPhotos($product): array;
    public function getPreparationDays($product): int;
    public function getUnitType($product): int;
    public function getUnitQuantity($product): int;
    public function isWholesale($product): bool;
    public function getVariants($product): array;
    public function getAttributes($product): array;
}