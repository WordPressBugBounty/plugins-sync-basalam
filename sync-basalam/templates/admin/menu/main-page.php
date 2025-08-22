<?php
if (! defined('ABSPATH')) exit;
$current_default_weight = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::DEFAULT_WEIGHT);
$current_preparation_time = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::DEFAULT_PREPARATION);
$webhook_id = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::WEBHOOK_ID);
$sync_basalam_token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
$sync_basalam_refresh_token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::REFRESH_TOKEN);
$sync_status_product = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::SYNC_STATUS_PRODUCT);
$sync_status_order = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::SYNC_STATUS_ORDER);
$auto_confirm_order = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::AUTO_CONFIRM_ORDER);
$is_vendor_status = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::IS_VENDOR);
$developer_mode = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::DEVELOPER_MODE);
$static_setting = sync_basalam_Admin_Settings::get_static_settings();
$logo_filename = 'logo.svg';
$woo_queue_status = sync_basalam_Admin_Asset::check_woo_queue_status();
if ($woo_queue_status === false) {
    echo '<div class="notice notice-error basalam-notice-wc"><p class="basalam-p">Queue ووکامرس غیرفعال است ، لطفا ابتدا Queue ووکامرس را فعال نمایید</p></div>';
}
?>

<div class="basalam-container">
    <div class="basalam-header">
        <div class="basalam-header-data">
            <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . "/images/" . $logo_filename) ?>" alt="Basalam">
            <p class="basalam-description basalam-p">این افزونه به شما کمک می‌کند تا محصولات فروشگاه ووکامرس خود را به راحتی
                در باسلام نیز به فروش برسانید. با استفاده از این افزونه، محصولات شما به صورت خودکار در باسلام نیز
                به‌روزرسانی می‌شوند.</p>
        </div>
    </div>

    <?php if (!$webhook_id):
        require_once(sync_basalam_configure()->template_path() . "/admin/menu/main/main-get-webhook.php");

    elseif (!$is_vendor_status):
        require_once(sync_basalam_configure()->template_path() . "/admin/menu/main/main-create-booth.php");

    elseif (!$sync_basalam_token || !$sync_basalam_refresh_token):
        require_once(sync_basalam_configure()->template_path() . "/admin/menu/main/main-get-token.php");
    else:
        require_once(sync_basalam_configure()->template_path() . "/admin/menu/main/main-connected.php");
    endif; ?>

</div>