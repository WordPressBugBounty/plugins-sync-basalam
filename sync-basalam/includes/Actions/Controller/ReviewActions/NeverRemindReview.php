<?php

namespace SyncBasalam\Actions\Controller\ReviewActions;

use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;

class NeverRemindReview extends ActionController
{
    public function __invoke()
    {
        update_option('sync_basalam_review_never_remind', true);
        wp_send_json_success();
    }
}
