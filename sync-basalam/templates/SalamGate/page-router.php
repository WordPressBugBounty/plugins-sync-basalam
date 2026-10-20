<?php

if (! defined('ABSPATH')) exit;

/**
 * پوسته صفحه canonical سلام‌گیت (پارت ۳).
 *
 * @var array $data  {state: ?string, view: string, shops: array, message: string}
 */

$router = \SyncBasalam\SalamGate\Admin\AccountStateRouter::class;
$sgView = (string) ($data['view'] ?? '');
$connectionUnavailable = in_array($data['state'] ?? '', ['not_connected', 'error_auth', 'error_forbidden'], true);
$protectedView = in_array($sgView, ['send', 'settings', 'activation'], true);
$siteDisabled = ($data['state'] ?? '') === 'disabled';
$accountLoading = ($data['state'] ?? '') === 'loading';
$requiresActiveAccount = in_array($sgView, ['send', 'settings'], true) && ($data['state'] ?? '') !== 'active';
$loadView = !$siteDisabled && !$requiresActiveAccount && !((($connectionUnavailable || $accountLoading) && $protectedView))
    && (in_array($sgView, ['send', 'settings', 'calculator', 'post-offices'], true)
        || ($sgView === 'activation' && in_array($data['state'] ?? '', ['', 'not_registered'], true)));
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
        <?php if ($siteDisabled || (($connectionUnavailable || $accountLoading) && $protectedView) || $requiresActiveAccount) : ?>
            <?php echo $router::renderStatePartial($data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — internal synthetic lock template ?>
        <?php elseif (!empty($data['view'])) : ?>
            <?php
            $viewTemplate = __DIR__ . '/states/view-' . $data['view'] . '.php';
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
