<?php
defined('ABSPATH') || exit;
/** @var bool $disabled Local opt-out, not a provider account check. */
$url = admin_url('admin.php?page=salam_gate');
?>
<aside class="sg-discovery-banner" aria-labelledby="sg-discovery-title" dir="rtl">
    <span class="sg-discovery-banner__icon" aria-hidden="true"><?php echo \SyncBasalam\SalamGate\Admin\Menus::shipmentIcon(); ?></span>
    <div class="sg-discovery-banner__copy">
        <h2 id="sg-discovery-title"><?php echo $disabled ? 'هر وقت خواستی، دوباره با سلام‌گیت بفرست!' : 'حالا میتونی با ووسلام سفارش هات رو به راحتی ارسال کنی!'; ?></h2>
        <p><?php echo $disabled
            ? 'هزینه ارسال رو حساب کن، مراکز تحویل رو ببین یا مرسوله‌هات رو پیگیری کن. برای ثبت مرسوله هم کافیه سلام‌گیت رو دوباره فعال کنی.'
            : 'همین‌جا توی ووسلام، مرسوله‌هات رو تکی یا گروهی ثبت کن و برچسب پستی بگیر. هنوز فعالش نکردی؟ ماشین‌حساب، مراکز تحویل و پیگیری مرسوله رو همین حالا امتحان کن.'; ?></p>
    </div>
    <div class="sg-discovery-banner__actions">
        <a class="sg-discovery-banner__primary" href="<?php echo esc_url($url); ?>"><?php echo $disabled ? 'فعال‌سازی مجدد سلام‌گیت' : 'فعال سازی'; ?></a>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="salam_gate_dismiss_intro">
            <?php wp_nonce_field('salam_gate_dismiss_intro'); ?>
            <button type="submit" class="sg-discovery-banner__dismiss">دیگه نشون نده</button>
        </form>
    </div>
</aside>
