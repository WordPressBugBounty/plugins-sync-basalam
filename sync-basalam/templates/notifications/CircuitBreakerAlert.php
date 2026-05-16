<?php

defined('ABSPATH') || exit;

use SyncBasalam\Services\Api\CircuitBreaker;

$state = $circuitBreaker->getState();
$failureCount = $circuitBreaker->getFailureCount();
$lastFailure = $circuitBreaker->getLastFailure();

if ($state === CircuitBreaker::STATE_CLOSED) return;

$noticeClass = $state === CircuitBreaker::STATE_OPEN ? 'notice notice-error' : 'notice notice-warning';
$stateLabel = $state === CircuitBreaker::STATE_OPEN ? 'OPEN' : 'HALF_OPEN';
$description = $state === CircuitBreaker::STATE_OPEN
    ? 'ارتباط با باسلام به دلیل خطای سمت باسلام، به طور موقت قطع و بعد از دقایقی در صورت در دسترس بودن سرویس های باسلام، ارتباط مجددا ایجاد میشود.'
    : 'ووسلام در حال تست بازگشت ارتباط با باسلام است و فقط یک درخواست آزمایشی را اجرا میکند.';
?>
<div class="<?php echo esc_attr($noticeClass); ?> basalam-notice-flex">
    <p class="basalam-p basalam-text-right basalam-padding-0">
        <?php echo esc_html($description); ?>
    </p>
</div>
