<?php

defined('ABSPATH') || exit;

use SyncBasalam\SalamGate\Admin\ConnectionGate;

$connectionLock = ['surface' => 'woosalam', 'state' => ConnectionGate::currentState()];
include dirname(__DIR__) . '/Shared/connection-lock.php';
