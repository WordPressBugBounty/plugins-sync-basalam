<?php

use SyncBasalam\Admin\Settings\SettingsConfig;

defined("ABSPATH") || exit;

$BasalamAccessToken = syncBasalamSettings()->getSettings(SettingsConfig::TOKEN);
$syncBasalamVendorId = syncBasalamSettings()->getSettings(SettingsConfig::VENDOR_ID);
?>

<div class="order-tracking-box">
    <?php if (!$BasalamAccessToken): ?>
        <p class="basalam-p basalam-font-12">
            دسترسی های لازم دریافت نشده است ، ابتدا دسترسی ها را
            <a href="/wp-admin/admin.php?page=sync_basalam" target="_blank">دریافت</a> نمایید.
        </p>
    <?php else: ?>

        <?php if ($orderStatus == "bslm-wait-vendor"): ?>
            <?php require syncBasalamPlugin()->templatePath("orders/Statuses/Pending.php"); ?>
            <?php require syncBasalamPlugin()->templatePath("orders/Popups/CancelOrder.php"); ?>
            <?php require syncBasalamPlugin()->templatePath("orders/Popups/ShippingMethod.php"); ?>

        <?php elseif ($orderStatus == "bslm-preparation"): ?>
            <?php require syncBasalamPlugin()->templatePath("orders/Statuses/Preparation.php"); ?>
            <?php require syncBasalamPlugin()->templatePath("orders/Popups/DelayRequest.php"); ?>
            <?php require syncBasalamPlugin()->templatePath("orders/Popups/ShippingMethod.php"); ?>
            <?php require syncBasalamPlugin()->templatePath("orders/Popups/RequestCancel.php"); ?>

        <?php elseif ($orderStatus == "bslm-shipping"): ?>
            <?php require syncBasalamPlugin()->templatePath("orders/Statuses/Shipping.php"); ?>

        <?php elseif ($orderStatus == "bslm-rejected"): ?>
            <?php require syncBasalamPlugin()->templatePath("orders/Statuses/Cancelled.php"); ?>

        <?php elseif ($orderStatus == "bslm-completed"): ?>
            <?php require syncBasalamPlugin()->templatePath("orders/Statuses/Completed.php"); ?>

        <?php endif; ?>
    <?php endif; ?>
</div>