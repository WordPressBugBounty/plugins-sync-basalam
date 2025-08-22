<?php
if (! defined('ABSPATH')) exit;
?>
<div class="notice notice-error" style="display: flex;gap:10px">
    <p class="basalam-p">
        در صورتی که از عملکرد پلاگین ووسلام رضایت دارید، لطفا از ما در جعبه ابزار باسلام حمایت کنید.
    </p>
    <form method="POST" action="">
        <?php wp_nonce_field('sync_basalam_support_action', 'sync_basalam_support_nonce'); ?>
        <input type="hidden" name="sync_basalam_support" value="1">
        <input type="submit" value="حمایت" class="button-primary basalam-p">
    </form>
</div>

<?php
$likeservice = new sync_basalam_Like_Woosalam();
if (
    isset($_POST['sync_basalam_support']) && $_POST['sync_basalam_support'] == 1 &&
    isset($_POST['sync_basalam_support_nonce']) &&
    wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sync_basalam_support_nonce'])), 'sync_basalam_support_action')
) {
    $likeservice->like();
}
