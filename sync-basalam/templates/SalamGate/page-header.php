<?php

defined('ABSPATH') || exit;

/** @var array $data AccountStateRouter data; @var string $sgView Current page/view. */
$sgState = (string) ($data['state'] ?? 'loading');
$sgChrome = \SyncBasalam\SalamGate\Admin\PageChrome::class;
$sgPage = $sgChrome::page($sgView, $sgState);
?>
<header class="sg-page-header">
    <div class="sg-page-header__heading">
        <span class="sg-page-header__icon" data-sg-page-icon="<?php echo esc_attr($sgPage['icon']); ?>" aria-hidden="true">
            <?php if ($sgPage['icon'] === 'shipment') : ?>
                <?php echo \SyncBasalam\SalamGate\Admin\Menus::shipmentIcon(); ?>
            <?php else : ?>
                <span class="dashicons dashicons-<?php echo esc_attr($sgPage['icon']); ?>"></span>
            <?php endif; ?>
        </span>
        <div>
            <h1 class="sg-page-header__title"><?php echo esc_html($sgPage['title']); ?></h1>
            <p class="sg-page-header__subtitle"><?php echo esc_html($sgPage['subtitle']); ?></p>
        </div>
    </div>
</header>
<nav class="sg-page-nav" aria-label="بخش‌های سلام‌گیت" data-sg-nav data-sg-current-view="<?php echo esc_attr($sgView); ?>">
    <?php foreach ($sgChrome::navigation($sgView, $sgState) as $sgNavItem) : ?>
        <a class="sg-page-nav__item<?php echo $sgNavItem['current'] ? ' is-active' : ''; ?>"
           href="<?php echo esc_url($sgNavItem['url']); ?>"
           data-sg-nav-item="<?php echo esc_attr($sgNavItem['id']); ?>"
           <?php if ($sgNavItem['requires_active']) : ?>data-sg-requires-active="true"<?php endif; ?>
           <?php if ($sgNavItem['requires_registration']) : ?>data-sg-requires-registration="true"<?php endif; ?>
           <?php if ($sgNavItem['current']) : ?>aria-current="<?php echo esc_attr($sgNavItem['aria_current']); ?>"<?php endif; ?>
           <?php if (!$sgNavItem['visible']) : ?>hidden<?php endif; ?>><?php echo esc_html($sgNavItem['label']); ?></a>
    <?php endforeach; ?>
</nav>
