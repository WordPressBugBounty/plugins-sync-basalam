<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Cancel_Connect_Products extends Sync_BasalamController
{
    public function __invoke()
    {
        update_option('sync_basalam_cancel_auto_connect_task', 1);
    }
}
