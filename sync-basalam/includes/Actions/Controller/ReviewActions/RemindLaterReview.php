<?php

namespace SyncBasalam\Actions\Controller\ReviewActions;

use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;

class RemindLaterReview extends ActionController
{
    public function __invoke()
    {
        set_transient('sync_basalam_remind_later_review', true, DAY_IN_SECONDS);
        wp_send_json_success();
    }
}
