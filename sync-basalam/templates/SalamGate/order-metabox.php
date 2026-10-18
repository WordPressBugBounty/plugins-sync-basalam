<?php

if (! defined('ABSPATH')) exit;

use SyncBasalam\SalamGate\FeatureAvailability;
use SyncBasalam\SalamGate\Order\OrderContext;

$order = wc_get_order((int) $orderId);
if (!$order) return;

$available = FeatureAvailability::isAvailable();

?>
<div class="salam-gate-order-box" data-order-id="<?php echo esc_attr((string) $orderId); ?>" data-basalam-order="<?php echo OrderContext::marketFor((int) $orderId) === OrderContext::MARKET_BASALAM ? '1' : '0'; ?>">
    <?php
    $templateBox = __DIR__ . '/order-metabox-box.php';
    if (file_exists($templateBox)) {
        include $templateBox;
    }
    ?>
</div>

<?php
if ($available) {
    include_once __DIR__ . '/parcel-modal.php';
}
