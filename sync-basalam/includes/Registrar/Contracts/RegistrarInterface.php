<?php

namespace SyncBasalam\Registrar\Contracts;

defined('ABSPATH') || exit;

interface RegistrarInterface
{
    public static function register(): void;
}
