<?php
defined('ABSPATH') || exit;
?>

<div class="wrap">
    <div class="basalam-info-top-section">
        <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . "/images/basalam-logotype.svg") ?>" alt="Basalam">
    </div>
    <?php require_once(syncBasalamPlugin()->templatePath() . "/admin/info/InfoConnected.php"); ?>
</div>