<?php

namespace SyncBasalam\Services\Api;

use SyncBasalam\Jobs\Exceptions\NonRetryableException;

defined('ABSPATH') || exit;

class BlockedHttpRequestException extends NonRetryableException{}
