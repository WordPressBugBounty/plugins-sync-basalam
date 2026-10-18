<?php

defined('ABSPATH') || exit;

use SyncBasalam\SalamGate\Admin\HelpContent;

$sgHelpHome = admin_url('admin.php?page=salam_gate');
?>
<section class="basalam-faq-section sg-help" data-category="<?php echo esc_attr(HelpContent::CATEGORY); ?>" aria-labelledby="sg-help-title">
    <header class="sg-help__intro">
        <span class="sg-help__mark dashicons dashicons-archive" aria-hidden="true"></span>
        <div>
            <p class="sg-help__eyebrow">راهنمای ارسال با ووسلام پرو</p>
            <h2 id="sg-help-title">چطور با سلام‌گیت سفارش‌ها را ارسال کنیم؟</h2>
            <p>سلام‌گیت به شما کمک می‌کند سفارش‌های ووکامرس، باسلام و شبکه‌های اجتماعی را برای ارسال پستی آماده کنید. در این راهنما، ارسال تکی و گروهی، پرداخت هزینه، چاپ برچسب و پیگیری مسیر بسته را یاد می‌گیرید.</p>
        </div>
    </header>

    <nav class="sg-help__shortcuts" aria-label="دسترسی به بخش‌های سلام‌گیت">
        <a href="<?php echo esc_url($sgHelpHome); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span> ورود به سلام‌گیت</a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=salam_gate_parcels')); ?>"><span class="dashicons dashicons-list-view" aria-hidden="true"></span> مرسوله‌ها و برچسب‌ها</a>
        <a href="#sg-help-bulk-title"><span class="dashicons dashicons-car" aria-hidden="true"></span> راهنمای ارسال گروهی</a>
        <a href="<?php echo esc_url($sgHelpHome . '&view=tracking'); ?>"><span class="dashicons dashicons-search" aria-hidden="true"></span> پیگیری مرسوله</a>
        <a href="<?php echo esc_url($sgHelpHome . '&view=calculator'); ?>"><span class="dashicons dashicons-calculator" aria-hidden="true"></span> برآورد هزینه</a>
        <a href="<?php echo esc_url($sgHelpHome . '&view=post-offices'); ?>"><span class="dashicons dashicons-location-alt" aria-hidden="true"></span> مراکز تحویل</a>
    </nav>

    <section class="sg-help__section" aria-labelledby="sg-help-start-title">
        <h3 id="sg-help-start-title">پیش از ثبت اولین مرسوله</h3>
        <ul class="sg-help__checklist">
            <li>ووسلام را به حساب باسلام خود متصل و سلام‌گیت را فعال کنید.</li>
            <li>نشانی، تلفن و کد پستی فرستنده و مرکز تحویل را بررسی کنید.</li>
            <li>اطلاعات گیرنده را آماده کنید و بسته را با بسته‌بندی وزن کنید.</li>
            <li>برای پرداخت هزینه ارسال، موجودی روش پرداخت را بررسی کنید.</li>
        </ul>
    </section>

    <section class="sg-help__section" aria-labelledby="sg-help-steps-title">
        <h3 id="sg-help-steps-title">مراحل ارسال، از انتخاب سفارش تا تحویل بسته</h3>
        <ol class="sg-help__steps">
            <?php foreach (HelpContent::steps() as $sgHelpStep) : ?>
                <li><h4><?php echo esc_html($sgHelpStep['title']); ?></h4><p><?php echo esc_html($sgHelpStep['text']); ?></p></li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="sg-help__section" aria-labelledby="sg-help-bulk-title">
        <h3 id="sg-help-bulk-title">چطور چند سفارش را گروهی ارسال کنیم؟</h3>
        <p class="sg-help__muted">در فهرست سفارش‌های ووکامرس می‌توانید سفارش‌های باسلامی و غیرباسلامی را با هم انتخاب کنید؛ هر گروه حداکثر ۲۰ بسته دارد.</p>
        <ol class="sg-help__steps">
            <?php foreach (HelpContent::bulkSteps() as $sgHelpStep) : ?>
                <li><h4><?php echo esc_html($sgHelpStep['title']); ?></h4><p><?php echo esc_html($sgHelpStep['text']); ?></p></li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="sg-help__section" aria-labelledby="sg-help-tracking-title">
        <h3 id="sg-help-tracking-title">چطور مسیر مرسوله را پیگیری کنیم؟</h3>
        <ol class="sg-help__steps">
            <?php foreach (HelpContent::trackingSteps() as $sgHelpStep) : ?>
                <li><h4><?php echo esc_html($sgHelpStep['title']); ?></h4><p><?php echo esc_html($sgHelpStep['text']); ?></p></li>
            <?php endforeach; ?>
        </ol>
    </section>

    <aside class="sg-help__important" aria-labelledby="sg-help-important-title">
        <h3 id="sg-help-important-title"><span class="dashicons dashicons-shield" aria-hidden="true"></span> نکات مهم هنگام ثبت و پرداخت</h3>
        <ul>
            <li><strong>اگر نتیجه ثبت یا پرداخت مشخص نیست، دوباره اقدام نکنید.</strong> ابتدا وضعیت همان مرسوله را بررسی کنید؛ قطع‌شدن پاسخ لزوماً به معنی ناموفق‌بودن عملیات نیست.</li>
            <li><strong>سفارش باسلام باید قابل ارسال باشد.</strong> وضعیت سفارش اصلی در باسلام را بررسی کنید؛ سفارش لغوشده را با مبدأ دیگری دوباره ثبت نکنید.</li>
            <li><strong>بعد از پرداخت، بسته را به پست تحویل دهید.</strong> دریافت برچسب و کد رهگیری به معنی تحویل بسته نیست؛ باید آن را به مرکز انتخاب‌شده ببرید.</li>
            <li><strong>هر مرسوله پرداخت و کد رهگیری جدا دارد.</strong> در ارسال تکی یا گروهی، نتیجه هر بسته را جدا بررسی کنید؛ موفقیت یک پرداخت به معنی پرداخت همه بسته‌ها نیست.</li>
        </ul>
    </aside>

    <section class="sg-help__section" aria-labelledby="sg-help-faq-title">
        <h3 id="sg-help-faq-title">پرسش‌های متداول</h3>
        <p class="sg-help__muted">برای دیدن پاسخ، روی پرسش کلیک کنید.</p>
        <div class="sg-help__faqs">
            <?php foreach (HelpContent::faqs() as $sgHelpFaq) : ?>
                <details class="sg-help__faq">
                    <summary><?php echo esc_html($sgHelpFaq['question']); ?></summary>
                    <div><?php foreach ($sgHelpFaq['answer'] as $sgHelpParagraph) : ?>
                        <p><?php echo esc_html($sgHelpParagraph); ?></p>
                    <?php endforeach; ?></div>
                </details>
            <?php endforeach; ?>
        </div>
    </section>

    <footer class="sg-help__footer">
        <h3>اگر به کمک بیشتری نیاز دارید</h3>
        <p>اگر با توضیحات این صفحه مشکل برطرف نشد، از بخش پشتیبانی ووسلام، شماره سفارش یا مرسوله و متن خطا را بفرستید. پیش از ارسال تصویر، اطلاعات شخصی و محرمانه را از آن حذف کنید.</p>
        <div>
            <a href="https://vendor.basalam.com/orders" target="_blank" rel="noopener noreferrer">بررسی سفارش اصلی باسلام ↗</a>
            <a href="https://wallet.basalam.com/?from=vendor" target="_blank" rel="noopener noreferrer">کیف پول باسلام ↗</a>
            <a href="https://vendor.basalam.com/salam-gate/landing" target="_blank" rel="noopener noreferrer">صفحه سلام‌گیت در باسلام ↗</a>
        </div>
        <p class="sg-help__muted">روش‌های ارسال و هزینه‌ها به مشخصات بسته و مسیر آن بستگی دارند. هنگام پرداخت، مبلغ نهایی همان مرسوله را بررسی کنید؛ شرایط پذیرش را هم از بخش قوانین در تنظیمات سلام‌گیت بخوانید.</p>
    </footer>
</section>
