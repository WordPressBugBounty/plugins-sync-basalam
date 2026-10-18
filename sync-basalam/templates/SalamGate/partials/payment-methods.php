<?php
// Presentation only: the original select remains the payment handler's source of truth.
if (! defined('ABSPATH')) exit;

$sgPaymentSelectClass = $sgPaymentSelectClass ?? '';
$sgPaymentSelectId = $sgPaymentSelectId ?? '';
$sgPaymentRadioName = $sgPaymentRadioName ?? 'sg_payment_method';
$sgPaymentDisabled = !empty($sgPaymentDisabled);
$sgPaymentDefault = (string) ($sgPaymentDefault ?? '20');
$sgPaymentDefault = in_array($sgPaymentDefault, ['20', '21'], true) ? $sgPaymentDefault : '20';
$sgPaymentMethods = [
    '20' => ['title' => 'تراز غرفه‌دار', 'description' => 'پرداخت از تراز غرفه متصل در باسلام', 'icon' => 'store'],
    '21' => ['title' => 'کیف پول', 'description' => 'پرداخت از کیف پول حساب متصل در باسلام', 'icon' => 'wallet'],
];
?>
<div class="sg-payment-picker" data-sg-payment-picker>
    <select <?php if ($sgPaymentSelectId !== '') : ?>id="<?php echo esc_attr($sgPaymentSelectId); ?>"<?php endif; ?> class="sg-payment-native-select <?php echo esc_attr($sgPaymentSelectClass); ?>" aria-label="روش پرداخت هزینه ارسال" <?php disabled($sgPaymentDisabled); ?>>
        <?php foreach ($sgPaymentMethods as $sgMethodValue => $sgMethod) : ?>
            <option value="<?php echo esc_attr($sgMethodValue); ?>" <?php selected($sgPaymentDefault, $sgMethodValue); ?>><?php echo esc_html($sgMethod['title']); ?></option>
        <?php endforeach; ?>
    </select>
    <div class="sg-payment-cards" role="radiogroup" aria-label="روش پرداخت هزینه ارسال">
        <?php foreach ($sgPaymentMethods as $sgMethodValue => $sgMethod) : ?>
            <label class="sg-payment-card<?php echo $sgPaymentDefault === (string) $sgMethodValue ? ' is-selected' : ''; ?>">
                <input type="radio" name="<?php echo esc_attr($sgPaymentRadioName); ?>" value="<?php echo esc_attr($sgMethodValue); ?>" <?php echo $sgPaymentDefault === (string) $sgMethodValue ? 'checked' : ''; ?> <?php disabled($sgPaymentDisabled); ?>>
                <span class="sg-payment-card__icon" data-payment-icon="<?php echo esc_attr($sgMethod['icon']); ?>" aria-hidden="true">
                    <?php if ($sgMethod['icon'] === 'wallet') : ?>
                        <svg viewBox="0 0 24 24" fill="none"><path d="M20 8V5a2 2 0 0 0-2-2H6a3 3 0 0 0 0 6h14v10H6a3 3 0 0 1-3-3V6m17 7h-4v3h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?php else : ?>
                        <svg viewBox="0 0 24 24" fill="none"><path d="m3 9 2-5h14l2 5m-18 0v2a3 3 0 0 0 5 2 3 3 0 0 0 4 0 3 3 0 0 0 4 0 3 3 0 0 0 5-2V9H3Zm2 5v6h14v-6M9 20v-5h6v5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?php endif; ?>
                </span>
                <span class="sg-payment-card__content"><strong><?php echo esc_html($sgMethod['title']); ?></strong><small><?php echo esc_html($sgMethod['description']); ?></small></span>
                <span class="sg-payment-card__check" aria-hidden="true">✓</span>
            </label>
        <?php endforeach; ?>
    </div>
    <div class="sg-wallet-status" data-sg-wallet-status role="status">
        <div><span class="sg-wallet-status__title"><?php echo $sgPaymentDefault === '21' ? 'موجودی کیف پول' : 'موجودی تراز غرفه'; ?></span><strong class="sg-wallet-status__amount">در حال دریافت…</strong><small hidden></small></div>
        <div class="sg-wallet-status__actions">
            <button type="button" class="sg-wallet-status__refresh" data-sg-balance-refresh><span class="dashicons dashicons-update" aria-hidden="true"></span><span>به‌روزرسانی موجودی</span></button>
            <button type="button" class="sg-wallet-status__retry" data-sg-balance-retry hidden>تلاش دوباره</button>
            <a data-sg-wallet-topup hidden href="https://wallet.basalam.com/?from=vendor" target="_blank" rel="noopener noreferrer">شارژ کیف پول <span aria-hidden="true">↗</span></a>
        </div>
    </div>
</div>
