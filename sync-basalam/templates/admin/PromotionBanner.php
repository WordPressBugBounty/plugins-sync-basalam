<?php

defined('ABSPATH') || exit;
/** @var array $slides */
?>
<aside class="notice notice-info woosalam-promotion-banner" dir="rtl" aria-label="خدمات ووسلام" data-woosalam-promotions>
    <div class="woosalam-promotion-banner__slides" id="woosalam-promotion-slides">
        <?php foreach ($slides as $slide) : ?>
            <div class="woosalam-promotion-banner__slide" id="woosalam-promotion-<?php echo esc_attr($slide['id']); ?>" data-promotion-slide data-promotion-name="<?php echo esc_attr($slide['name']); ?>">
                <div class="woosalam-promotion-banner__brand">
                    <?php if (!empty($slide['logo'])) : ?>
                        <img src="<?php echo esc_url($slide['logo']); ?>" alt="<?php echo esc_attr($slide['name']); ?>" width="90" height="32">
                    <?php else : ?>
                        <span class="woosalam-promotion-banner__icon" aria-hidden="true"><?php echo $slide['icon']; ?></span>
                        <strong><?php echo esc_html($slide['name']); ?></strong>
                    <?php endif; ?>
                </div>
                <p><?php echo esc_html($slide['text']); ?></p>
                <div class="woosalam-promotion-banner__actions">
                    <a class="button button-primary" href="<?php echo esc_url($slide['url']); ?>"<?php if ($slide['external']) : ?> target="_blank" rel="noopener noreferrer"<?php endif; ?>><?php echo esc_html($slide['cta']); ?></a>
                    <button type="submit" form="woosalam-promotion-dismiss-<?php echo esc_attr($slide['id']); ?>" class="button" title="<?php echo esc_attr($slide['dismiss_hint']); ?>">دیگر نمایش نده</button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (count($slides) > 1) : ?>
        <div class="woosalam-promotion-banner__controls" data-promotion-controls hidden>
            <button type="button" data-promotion-previous aria-label="اسلاید قبلی" aria-controls="woosalam-promotion-slides"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12h14m-6-6 6 6-6 6"/></svg></button>
            <div class="woosalam-promotion-banner__dots">
                <?php foreach ($slides as $slide) : ?>
                    <button type="button" data-promotion-dot aria-label="نمایش <?php echo esc_attr($slide['name']); ?>" aria-controls="woosalam-promotion-<?php echo esc_attr($slide['id']); ?>" aria-pressed="false"><span></span></button>
                <?php endforeach; ?>
            </div>
            <button type="button" data-promotion-next aria-label="اسلاید بعدی" aria-controls="woosalam-promotion-slides"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M19 12H5m6-6-6 6 6 6"/></svg></button>
        </div>
    <?php endif; ?>
</aside>
