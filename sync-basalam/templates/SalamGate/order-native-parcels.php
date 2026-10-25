<?php

defined('ABSPATH') || exit;

use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\Contracts\LabelEligibility;
use SyncBasalam\SalamGate\FeatureAvailability;
use SyncBasalam\SalamGate\Order\BasalamParcelOrder;
use SyncBasalam\SalamGate\Services\ParcelService;
use SyncBasalam\SalamGate\Settings\SalamGateSettings;

include __DIR__ . '/order-source-status.php';

if (!FeatureAvailability::isVisible()) : ?>
    <p class="basalam-p sg-order-helper">سلام‌گیت غرفه فعال نیست؛ وضعیت اتصال را از <a href="<?php echo esc_url(admin_url('admin.php?page=salam_gate')); ?>">صفحه سلام‌گیت</a> بررسی کنید.</p>
    <?php return;
endif;
require_once dirname(__DIR__, 2) . '/includes/SalamGate/Order/BasalamParcelOrder.php';
$nativeEntries = BasalamParcelOrder::entries($order);
?>
<section class="sg-native-parcels" data-sg-native-parcels>
    <p class="basalam-p sg-order-helper">مرسوله موردنظر سفارش باسلام را انتخاب کنید. اقلام، هزینه ارسال و رهگیری هر مرسوله جداست؛ هزینه از تراز همان سفارش در باسلام کسر می‌شود.</p>
    <button type="button" class="basalam-button basalam-p sg-order-primary" data-sg-native-parcels-load>مشاهده مرسوله‌های سفارش</button>
    <div data-sg-native-parcels-rows aria-live="polite"></div>
    <button type="button" class="button" data-sg-native-parcels-more hidden>دریافت موارد بیشتر</button>
</section>
<?php if ($nativeEntries !== []) : ?>
<section class="sg-native-stored-parcels">
    <h4 class="sg-order-section-heading">ارسال‌های ثبت‌شده این سفارش</h4>
    <?php foreach ($nativeEntries as $nativeId => $scope) :
        $sgId = (int) $scope->get_meta('_salam_gate_parcel_id');
        if ($sgId < 1) { continue; }
        $state = (int) $scope->get_meta('_salam_gate_status');
        $paid = ParcelStatuses::hasReceipt($state);
        $tracking = trim((string) $scope->get_meta('_salam_gate_tracking_code'));
        $unknown = !$paid && ($scope->get_meta('_salam_gate_pay_unknown') || $scope->get_meta('_salam_gate_paid_at') || $scope->get_meta('_salam_gate_invoice_id'));
        ?>
        <article class="sg-native-stored-card" data-basalam-parcel-id="<?php echo esc_attr((string) $nativeId); ?>">
            <strong>مرسوله باسلام <span dir="ltr">#<?php echo esc_html((string) $nativeId); ?></span></strong>
            <p class="sg-order-helper">وضعیت ارسال: <?php echo esc_html($unknown ? 'در حال بررسی پرداخت' : ParcelStatuses::title($state)); ?></p>
            <?php if ($tracking !== '') : ?><p class="sg-order-helper">کد رهگیری: <span dir="ltr"><?php echo esc_html($tracking); ?></span></p><?php endif; ?>
            <button type="button" class="button salam-gate-open-modal" data-sg-native-has-local="1" data-basalam-parcel-id="<?php echo esc_attr((string) $nativeId); ?>"<?php echo $paid ? ' data-sg-native-receipt="1"' : ''; ?>><?php echo $paid ? 'مشاهده رسید و رهگیری' : 'ادامه و بررسی ارسال'; ?></button>
            <?php if (LabelEligibility::hasPrintableReceipt($scope->get_meta('_salam_gate_parcel_id'), $scope->get_meta('_salam_gate_status'), $scope->get_meta('_salam_gate_tracking_code'))) :
                $labelTemplate = (string) SalamGateSettings::instance()->get(SalamGateSettings::KEY_LABEL_TEMPLATE_DEFAULT);
                $labelTitles = ['invoice' => 'فاکتور کامل', 'invoice_new' => 'فاکتور کامل (طراحی جدید)', 'summary' => 'لیبل خلاصه', 'label_10x15' => 'لیبل ۱۰×۱۵']; ?>
                <div class="sg-order-label-panel" data-sg-order-label-scope>
                    <div class="salam-gate-label-row">
                        <select class="salam-gate-label-template" aria-label="قالب برچسب مرسوله <?php echo esc_attr((string) $nativeId); ?>">
                            <?php foreach (ParcelService::LABEL_TEMPLATES as $value) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $labelTemplate); ?>><?php echo esc_html($labelTitles[$value]); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button salam-gate-get-label" data-basalam-parcel-id="<?php echo esc_attr((string) $nativeId); ?>"><span class="dashicons dashicons-printer" aria-hidden="true"></span> چاپ برچسب پستی</button>
                    </div>
                    <p class="salam-gate-label-status sg-order-helper" role="status" aria-live="polite" hidden></p>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>
