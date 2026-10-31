<?php

if (! defined('ABSPATH')) exit;

/**
 * پوسته صفحه canonical سلام‌گیت (پارت ۳).
 *
 * @var array $data  {state: ?string, view: string, shops: array, message: string}
 */

$router = \SyncBasalam\SalamGate\Admin\AccountStateRouter::class;
$sgView = \SyncBasalam\SalamGate\Admin\PageChrome::viewForState(
    (string) ($data['view'] ?? ''),
    (string) ($data['state'] ?? 'loading')
);
$accountLoading = ($data['state'] ?? '') === 'loading';
// Do not render tool controllers while account access is unknown or unavailable.
// Direct URLs use the same dashboard state as the main menu, without extra API reads.
$loadView = in_array($sgView, ['send', 'settings', 'calculator', 'post-offices', 'activation'], true);
?>
<!-- WordPress relocates admin notices here, outside the SalamGate styling boundary. -->
<hr class="wp-header-end">
<div class="wrap salam-gate-page sg-router" data-sg-root data-sg-account-state="<?php echo esc_attr((string) ($data['state'] ?? 'loading')); ?>"<?php if ($accountLoading) : ?> data-sg-account-deferred="true"<?php endif; ?>>
    <?php include __DIR__ . '/page-header.php'; ?>

    <div id="sg-state-container" class="sg-router__body<?php echo $loadView ? ' sg-load-region' : ''; ?>"<?php if ($loadView) : ?> data-sg-load data-sg-load-state="loading" aria-busy="true"<?php endif; ?>>
        <?php if ($loadView) : ?>
            <?php include __DIR__ . '/partials/loading-region.php'; ?>
            <div data-sg-load-content inert>
        <?php endif; ?>
        <?php if ($sgView !== '') : ?>
            <?php
            $viewTemplate = __DIR__ . '/states/view-' . $sgView . '.php';
            if (file_exists($viewTemplate)) {
                include $viewTemplate;
            }
            ?>
        <?php else : ?>
            <?php echo $router::renderStatePartial($data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — قالب‌های داخلی escape می‌کنند ?>
        <?php endif; ?>
        <?php if ($loadView) : ?></div><?php endif; ?>
    </div>

    <span class="sg-refresh-state__msg" aria-live="polite"></span>
</div>
