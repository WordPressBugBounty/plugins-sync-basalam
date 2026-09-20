<?php

namespace SyncBasalam\Jobs\Exceptions;

defined('ABSPATH') || exit;

/**
 * A locally stored Basalam variation id no longer exists for the product.
 *
 * Keeping this distinct from a general 404 lets the product update service
 * rebuild only the variation mapping without disconnecting the product.
 */
class StaleVariationException extends NonRetryableException
{
    private $basalamProductId;
    private $basalamVariationId;

    public function __construct($basalamProductId, $basalamVariationId, \Throwable $previous)
    {
        $this->basalamProductId = $basalamProductId;
        $this->basalamVariationId = $basalamVariationId;

        parent::__construct(
            'شناسه متغیر ذخیره‌شده در باسلام دیگر معتبر نیست.',
            404,
            $previous
        );
    }

    public function getBasalamProductId()
    {
        return $this->basalamProductId;
    }

    public function getBasalamVariationId()
    {
        return $this->basalamVariationId;
    }
}
