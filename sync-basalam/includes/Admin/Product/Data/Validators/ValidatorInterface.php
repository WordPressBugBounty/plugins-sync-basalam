<?php

namespace SyncBasalam\Admin\Product\Data\Validators;

defined('ABSPATH') || exit;

interface ValidatorInterface
{
    public function validate($product): void;
}