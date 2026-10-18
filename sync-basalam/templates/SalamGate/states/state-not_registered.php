<?php

if (! defined('ABSPATH')) exit;

/**
 * صفحه معرفی سلام‌گیت (حالت «بدون ثبت») — پارت ۳.
 * ساختار مطابق مشاهدات پنل باسلام (references/ui-evidence.md §/salam-gate/landing):
 * معرفی + تصویر، بسترهای فروش، مزایا، لینک ماشین‌حساب، راهنمای چهار مرحله،
 * مراکز تحویل، FAQ و دکمه برجسته فعال‌سازی پایین معرفی.
 * متن‌ها بازنویسی با معنای مرجع‌اند؛ کپی رسمی پنل نیستند (در parity-matrix با منبع PROP ثبت شده‌اند).
 */

$sgUrl = static function (string $view): string {
    return admin_url('admin.php?page=salam_gate&view=' . $view);
};

$benefits = [
    ['benefit-financial', 'مزایای مالی', 'ارسال با نرخ قراردادی پست و تسویه شفاف هزینه هر مرسوله هنگام ثبت.'],
    ['benefit-batch', 'ارسال یکجا', 'چند سفارش بیرون از باسلام را یک‌جا ثبت، پرداخت و تحویل کنید.'],
    ['benefit-tracking', 'رهگیری', 'برای هر مرسوله بارکد پستی و کد رهگیری صادر می‌شود و روند تحویل قابل پیگیری است.'],
    ['benefit-discount', 'تخفیف پستی', 'تخفیف‌های قراردادی در محاسبه هزینه ارسال اعمال می‌شود.'],
];

$steps = [
    ['۱', 'ثبت و فعال‌سازی', 'اطلاعات هویتی و آدرس فرستنده را ثبت می‌کنید؛ پس از تأیید باسلام حساب فعال می‌شود.'],
    ['۲', 'ثبت مرسوله', 'مشخصات گیرنده، وزن، بسته‌بندی و ارزش مرسوله را وارد و نرخ ارسال را می‌بینید.'],
    ['۳', 'پرداخت و بارکد', 'هزینه ارسال را پرداخت می‌کنید؛ بارکد پستی و کد رهگیری صادر می‌شود.'],
    ['۴', 'تحویل و پیگیری', 'بسته را به مرکز تحویل می‌برید و تحویل تا گیرنده را با کد رهگیری دنبال می‌کنید.'],
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
    <section class="sg-hero">
        <div class="sg-hero__text">
            <span class="sg-intro__eyebrow">ارسال از فروشگاه شما، با سلام‌گیت</span>
            <h3 class="sg-hero__title">از ثبت سفارش تا تحویل بسته به پست</h3>
            <p class="sg-hero__desc">
                سفارش‌های ووکامرس، باسلام و سایر بسترهای فروش را با سلام‌گیت بفرستید.
                هزینه ارسال را پیش از پرداخت ببینید، برچسب پستی بگیرید و وضعیت مرسوله را پیگیری کنید.
            </p>
            <div class="sg-hero__actions">
                <a class="sg-button sg-button--primary sg-button--lg" href="#sg-activation" data-sg-open-activation>فعال‌سازی سلام‌گیت</a>
                <a class="sg-button sg-button--secondary" href="<?php echo esc_url($sgUrl('calculator')); ?>">ماشین‌حساب هزینه ارسال</a>
            </div>
        </div>
        <div class="sg-hero__art" aria-hidden="true">
            <svg viewBox="0 0 200 160" xmlns="http://www.w3.org/2000/svg" role="img" focusable="false">
                <rect x="30" y="55" width="90" height="65" rx="6" fill="none" stroke="currentColor" stroke-width="4"/>
                <path d="M30 78h90" stroke="currentColor" stroke-width="4"/>
                <circle cx="52" cy="66" r="5" fill="currentColor"/>
                <rect x="118" y="42" width="52" height="36" rx="5" fill="currentColor" opacity="0.15"/>
                <path d="M118 50h52" stroke="currentColor" stroke-width="3" stroke-dasharray="5 5"/>
                <path d="M148 92c0 10-10 14-22 22-12-8-22-12-22-22a11 11 0 0 1 22-3 11 11 0 0 1 22 3z" fill="currentColor" opacity="0.8"/>
            </svg>
        </div>
    </section>

    <!-- بسترهای فروش -->
    <section class="sg-section">
        <h4 class="sg-section__title">از هر بستر فروش، ارسال کنید</h4>
        <p class="sg-section__desc">از سفارش‌های فروشگاه ووکامرس و باسلام تا سفارش‌هایی که در شبکه‌های اجتماعی می‌گیرید.</p>
    </section>

    <!-- مزایا -->
    <section class="sg-section">
        <h4 class="sg-section__title">چرا سلام‌گیت؟</h4>
        <div class="sg-benefits">
            <?php foreach ($benefits as [$id, $title, $text]) : ?>
                <div class="sg-benefit" id="<?php echo esc_attr($id); ?>">
                    <strong class="sg-benefit__title"><?php echo esc_html($title); ?></strong>
                    <p class="sg-benefit__text"><?php echo esc_html($text); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- راهنمای چهار مرحله -->
    <section class="sg-section">
        <h4 class="sg-section__title">مسیر ارسال در چهار مرحله</h4>
        <ol class="sg-steps">
            <?php foreach ($steps as [$num, $title, $text]) : ?>
                <li class="sg-step">
                    <span class="sg-step__num" aria-hidden="true"><?php echo esc_html($num); ?></span>
                    <strong class="sg-step__title"><?php echo esc_html($title); ?></strong>
                    <p class="sg-step__text"><?php echo esc_html($text); ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <!-- مراکز تحویل -->
    <section class="sg-section sg-section--highlight">
        <h4 class="sg-section__title">مراکز تحویل در شهر شما</h4>
        <p class="sg-section__desc">مرسوله‌ها را به نزدیک‌ترین مرکز تحویل پست بدهید؛ لیست مراکز بر اساس شهر شما نمایش داده می‌شود.</p>
        <a class="sg-button sg-button--secondary" href="<?php echo esc_url($sgUrl('post-offices')); ?>">مشاهده مراکز تحویل</a>
    </section>

    <!-- پرسش‌های متداول -->
    <section class="sg-section">
        <h4 class="sg-section__title">پرسش‌های متداول</h4>
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

    <?php include dirname(__DIR__) . '/terms-of-use.php'; ?>

    <!-- دکمه برجسته پایین معرفی -->
    <section class="sg-cta">
        <p class="sg-cta__text">آماده ارسال هستید؟ اطلاعات فرستنده را ثبت کنید و وضعیت فعال‌سازی را همین‌جا پیگیری کنید.</p>
        <a class="sg-button sg-button--primary sg-button--lg sg-cta__btn" href="#sg-activation" data-sg-open-activation>فعال‌سازی سلام‌گیت</a>
    </section>
</div>
