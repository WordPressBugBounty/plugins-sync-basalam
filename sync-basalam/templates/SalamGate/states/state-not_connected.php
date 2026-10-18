<?php

if (! defined('ABSPATH')) exit;

/** @var array $data */

$connectionLock = ['surface' => 'salam-gate', 'state' => 'not_connected'];
?>
<div class="sg-state sg-state--not-connected" data-state="not_connected">
    <?php include dirname(__DIR__, 2) . '/Shared/connection-lock.php'; ?>
</div>
