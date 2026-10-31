<?php

if (! defined('ABSPATH')) exit;

/**
 * صفحه معرفی سلام‌گیت (حالت «بدون ثبت») — پارت ۳.
 * ساختار مطابق مشاهدات پنل باسلام (references/ui-evidence.md §/salam-gate/landing):
 * معرفی + تصویر، مزایا،
 * FAQ و دکمه برجسته فعال‌سازی پایین معرفی.
 * متن‌ها بازنویسی با معنای مرجع‌اند؛ کپی رسمی پنل نیستند (در parity-matrix با منبع PROP ثبت شده‌اند).
 */

$benefits = [
    ['benefit-financial', 'money-alt', 'هزینهٔ شفاف', 'کرایه و جزئیات هزینهٔ هر مرسوله را پیش از پرداخت ببینید.'],
    ['benefit-batch', 'archive', 'ارسال گروهی', 'چند سفارش را در یک مسیر برای ثبت و ارسال آماده کنید.'],
    ['benefit-tracking', 'printer', 'برچسب پستی', 'پس از پرداخت، برچسب مرسوله و کد رهگیری را دریافت کنید.'],
    ['benefit-discount', 'tag', 'نرخ قراردادی', 'از نرخ‌ها و تخفیف‌های قراردادی سلام‌گیت استفاده کنید.'],
];

$faqs = [
    ['چه سفارش‌هایی را می‌توانم ارسال کنم؟', 'سفارش‌های فروشگاه‌های بیرون از باسلام: اینستاگرام، ایتا، بله، تلگرام، وب‌سایت شخصی، دیجی‌کالا و سایر؛ سفارش‌های باسلام نیز از همان مسیر سلام‌گیت قابل ارسال‌اند.'],
    ['ارسال چگونه انجام می‌شود؟', 'مرسوله از طریق سرویس سلام‌گیت باسلام در پست ثبت می‌شود؛ پس از پرداخت و دریافت برچسب، بسته را به مرکز تحویل انتخاب‌شده می‌برید.'],
    ['هزینه ارسال چگونه تسویه می‌شود؟', 'هزینه هر مرسوله هنگام ثبت نمایش داده و همان‌جا پرداخت می‌شود؛ فاکتور و تراکنش‌ها در سوابق شما ثبت می‌شود.'],
    ['مراحل ارسال چیست؟', 'ثبت مرسوله و انتخاب نرخ، پرداخت و صدور بارکد پستی، تحویل بسته به مرکز پست، و پیگیری تا تحویل گیرنده با کد رهگیری.'],
    ['مرکز تحویل چیست و کجا انتخاب می‌شود؟', 'مرکز تحویل، نقطه تحویل مرسوله‌های شما به پست است و هنگام ثبت اولیه بر اساس شهر/منطقه پستی انتخاب می‌شود؛ تغییر آن در همان شهر از تنظیمات امکان‌پذیر است.'],
    ['بسته‌بندی و برچسب چطور است؟', 'نوع بسته‌بندی را هنگام ثبت انتخاب می‌کنید. پس از پرداخت موفق و صدور کد رهگیری، برچسب پستی را دریافت و روی بسته آماده‌شده بچسبانید.'],
    ['کد رهگیری را کِی می‌گیرم؟', 'پس از پرداخت هزینه ارسال و صدور بارکد پستی، کد رهگیری در جزئیات مرسوله در دسترس است.'],
];
?>
<div class="sg-state sg-state--intro" data-state="not_registered">

    <!-- معرفی -->
    <section class="sg-hero" aria-labelledby="sg-intro-title">
        <div class="sg-hero__text">
            <span class="sg-intro__eyebrow">ارسال مرسوله، از همین فروشگاه</span>
            <h2 class="sg-hero__title" id="sg-intro-title">سفارش‌ها را برای ارسال آماده کنید</h2>
            <p class="sg-hero__desc">
                از محاسبهٔ هزینه تا دریافت برچسب پستی، همهٔ مراحل ارسال را در ووسلام انجام دهید.
                برای شروع، حساب سلام‌گیت خود را فعال کنید.
            </p>
            <div class="sg-hero__actions">
                <a class="sg-button sg-button--primary sg-button--lg" href="#sg-activation" data-sg-open-activation>فعال‌سازی سلام‌گیت<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></a>
            </div>
        </div>
        <div class="sg-hero__art" aria-hidden="true">
            <span class="sg-intro-visual__truck"><?php echo \SyncBasalam\SalamGate\Admin\Menus::shipmentIcon(); ?></span>
            <span class="sg-intro-visual__caption">مسیر ارسال با سلام‌گیت</span>
            <div class="sg-intro-visual__row"><span class="dashicons dashicons-archive"></span>مشخصات مرسوله</div>
            <div class="sg-intro-visual__row"><span class="dashicons dashicons-location-alt"></span>انتخاب روش ارسال</div>
            <div class="sg-intro-visual__row"><span class="dashicons dashicons-printer"></span>بارکد و برچسب پستی</div>
        </div>
    </section>

    <!-- مزایا -->
    <section class="sg-section">
        <h2 class="sg-section__title">ابزارهای ارسال، کنار سفارش‌های شما</h2>
        <div class="sg-benefits">
            <?php foreach ($benefits as [$id, $icon, $title, $text]) : ?>
                <div class="sg-benefit" id="<?php echo esc_attr($id); ?>">
                    <span class="sg-benefit__icon dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                    <strong class="sg-benefit__title"><?php echo esc_html($title); ?></strong>
                    <p class="sg-benefit__text"><?php echo esc_html($text); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- پرسش‌های متداول -->
    <section class="sg-section">
        <h2 class="sg-section__title">پیش از شروع، بیشتر بدانید</h2>
        <div class="sg-faq">
            <?php foreach ($faqs as [$q, $a]) : ?>
                <details class="sg-faq__item">
                    <summary class="sg-faq__q"><?php echo esc_html($q); ?></summary>
                    <p class="sg-faq__a"><?php echo esc_html($a); ?></p>
                </details>
            <?php endforeach; ?>
        </div>
        <p class="sg-faq__more">
            <a href="https://hamsalam.ir" target="_blank" rel="noopener noreferrer">پشتیبانی همسلام</a>
        </p>
    </section>

    <details class="sg-intro-terms">
        <summary>قوانین استفاده از سلام‌گیت<span>پیش از فعال‌سازی مطالعه کنید</span></summary>
        <?php include dirname(__DIR__) . '/terms-of-use.php'; ?>
    </details>

    <!-- دکمه برجسته پایین معرفی -->
    <section class="sg-cta">
        <div><h2 class="sg-section__title">برای اولین ارسال آماده‌اید؟</h2><p class="sg-cta__text">با تکمیل اطلاعات فرستنده، فعال‌سازی حساب را شروع کنید.</p></div>
        <a class="sg-button sg-button--primary sg-button--lg sg-cta__btn" href="#sg-activation" data-sg-open-activation>فعال‌سازی سلام‌گیت</a>
    </section>
</div>
