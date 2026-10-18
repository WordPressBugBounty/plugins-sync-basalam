<?php

if (! defined('ABSPATH')) exit;

/**
 * view=activation — ویزارد ثبت اولیه سلام‌گیت (پارت ۴).
 * فیلدها و قواعد با فرم و سرویس باسلام تطبیق داده شده‌اند؛ گروه‌بندی
 * «هویت → آدرس → منطقه و مرکز → مرور نهایی» برای این ویزارد است.
 *
 * فقط برای وضعیت «بدون ثبت» فرم نمایش داده می‌شود؛ در هر وضعیت دیگر، وضعیت
 * واقعی سرور نشان داده می‌شود (بازیابی با refresh، بدون ثبت مجدد).
 *
 * @var array $data {state: ?string, shops: array}
 */

$router = \SyncBasalam\SalamGate\Admin\AccountStateRouter::class;
$state = (string) ($data['state'] ?? '');
$backUrl = admin_url('admin.php?page=salam_gate');
?>
<div class="sg-view sg-view--activation" data-view="activation">

<?php if ($state !== '' && $state !== $router::STATE_NOT_REGISTERED): ?>
    <?php if (in_array($state, [$router::STATE_NOT_CONNECTED, $router::STATE_ERROR_AUTH, $router::STATE_ERROR_FORBIDDEN], true)): ?>
        <?php echo $router::renderStatePartial($data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — internal synthetic lock template ?>
    <?php elseif ($state === $router::STATE_ERROR_NETWORK): ?>
        <div class="sg-card sg-card--error">
            <h3 class="sg-card__title">وضعیت حساب نامعلوم است</h3>
            <p class="sg-card__text">
                ارتباط با سلام‌گیت برقرار نشد؛ چون معلوم نیست قبلاً ثبت انجام شده یا نه، فرم ثبت نمایش داده نمی‌شود.
                چند لحظه بعد صفحه را دوباره بارگذاری کنید.
            </p>
        </div>
    <?php else: ?>
        <div class="sg-card">
            <h3 class="sg-card__title">ثبت سلام‌گیت برای این حساب انجام شده است</h3>
            <p class="sg-card__text">وضعیت فعلی حساب شما در صفحه سلام‌گیت نمایش داده می‌شود؛ ثبت مجدد لازم نیست.</p>
            <a class="sg-button sg-button--primary" href="<?php echo esc_url($backUrl); ?>">مشاهده وضعیت حساب</a>
        </div>
    <?php endif; ?>
<?php else: ?>

    <div class="sg-card sg-wizard" data-sg-wizard data-status-url="<?php echo esc_url($backUrl); ?>">
        <header class="sg-activation__header">
            <span class="sg-activation__mark dashicons dashicons-archive" aria-hidden="true"></span>
            <div>
                <h3 class="sg-card__title">فعال‌سازی سلام‌گیت</h3>
                <p class="sg-card__text">اطلاعات فرستنده را تکمیل کنید تا بتوانید از فروشگاه خود با سلام‌گیت مرسوله بفرستید.</p>
            </div>
            <span class="sg-activation__progress" data-wizard-progress aria-live="polite">مرحله ۱ از ۴</span>
        </header>
        <?php
        // پیکربندی سال‌های کبیسه جلالی برای سازگاری روزهای اسفند سمت کلاینت (مرجع: JalaliDate سرور).
        $jalali = \SyncBasalam\SalamGate\Utils\JalaliDate::class;
        $todayParts = array_map('intval', explode('-', current_time('Y-m-d')));
        $jToday = $todayParts !== [] && count($todayParts) === 3
            ? $jalali::toJalali($todayParts[0], $todayParts[1], $todayParts[2])
            : ['jy' => 1400];
        $currentJy = (int) $jToday['jy'];
        $birthBounds = null;
        if (count($todayParts) === 3) {
            // Age is calculated on Gregorian birthdays on the server. Persian
            // year subtraction alone is off by a day around some leap years.
            $boundary = static function (int $years) use ($todayParts, $jalali): array {
                $year = $todayParts[0] - $years;
                $day = $todayParts[2];
                while (!checkdate($todayParts[1], $day, $year) && $day > 1) $day--;
                return $jalali::toJalali($year, $todayParts[1], $day);
            };
            $birthBounds = ['maximum' => $boundary(18), 'excludedMinimum' => $boundary(101)];
        }
        $leapYears = [];
        try {
            for ($y = 1301; $y <= $currentJy; $y++) {
                if ($jalali::isLeapJalali($y)) {
                    $leapYears[] = $y;
                }
            }
        } catch (\Throwable $e) {
            $leapYears = [];
        }
        ?>
        <script type="application/json" data-sg-wizard-config><?php echo wp_json_encode(['currentJy' => $currentJy, 'minimumJy' => 1301, 'birthBounds' => $birthBounds, 'leapYears' => $leapYears]); ?></script>

        <ol class="sg-wizard__steps" data-sg-wizard-steps aria-label="مراحل فعال‌سازی">
            <li class="is-active" data-step-nav="1" aria-current="step"><span class="sg-activation__step-number" aria-hidden="true">۱</span><span>اطلاعات هویتی</span></li>
            <li data-step-nav="2"><span class="sg-activation__step-number" aria-hidden="true">۲</span><span>آدرس فرستنده</span></li>
            <li data-step-nav="3"><span class="sg-activation__step-number" aria-hidden="true">۳</span><span>مرکز تحویل</span></li>
            <li data-step-nav="4"><span class="sg-activation__step-number" aria-hidden="true">۴</span><span>مرور و فعال‌سازی</span></li>
        </ol>

        <form data-sg-wizard-form novalidate>
            <?php wp_nonce_field('basalam_pro_nonce', '_sg_activation_nonce'); ?>

            <!-- مرحله ۱: هویت -->
            <section class="sg-wizard__step is-active" data-step="1" aria-labelledby="sgw-identity-title">
                <div class="sg-activation__intro">
                    <h4 id="sgw-identity-title" tabindex="-1">اطلاعات صاحب حساب باسلام</h4>
                    <p>اطلاعات از حساب باسلام خوانده می‌شود؛ پیش از ادامه آن‌ها را بررسی کنید.</p>
                </div>
                <div class="sg-field">
                    <label class="sg-field__label" for="sgw-mobile">شماره موبایل <span class="sg-req">*</span></label>
                    <input class="sg-field__input" id="sgw-mobile" name="mobile" type="tel" dir="ltr" inputmode="tel" placeholder="09xxxxxxxxx" autocomplete="off" required data-sg-length="11" data-sg-validate data-sg-label="شماره موبایل">
                    <p class="sg-field__error" data-error-for="mobile"></p>
                </div>
                <div class="sg-field">
                    <label class="sg-field__label" for="sgw-national">کد ملی <span class="sg-req">*</span></label>
                    <input class="sg-field__input" id="sgw-national" name="national_code" readonly type="text" dir="ltr" inputmode="numeric" placeholder="۱۰ رقم" autocomplete="off" required data-sg-length="10" data-sg-validate data-sg-label="کد ملی">
                    <p class="sg-field__hint">کد ملی صاحب حساب متصل به باسلام.</p>
                    <p class="sg-field__error" data-error-for="national_code"></p>
                </div>
                <div class="sg-field">
                    <div class="sg-activation__label-row">
                        <label class="sg-field__label" for="sgw-serial">سریال کارت ملی <span class="sg-req">*</span></label>
                        <button type="button" class="sg-activation__help" data-wizard-serial-help aria-haspopup="dialog" aria-controls="sgw-serial-guide"><span class="dashicons dashicons-editor-help" aria-hidden="true"></span>راهنما</button>
                    </div>
                    <input class="sg-field__input" id="sgw-serial" name="national_code_serial" type="text" dir="ltr" minlength="10" maxlength="10" pattern="[A-Za-z0-9]{10}" placeholder="۱۰ حرف و عدد لاتین" autocomplete="off" spellcheck="false" required data-sg-length="10" data-sg-validate data-sg-label="سریال کارت ملی" aria-describedby="sgw-serial-hint">
                    <p class="sg-field__hint" id="sgw-serial-hint">سریال ۱۰ کاراکتری پشت کارت ملی را وارد کنید؛ این شماره با کد ملی فرق دارد.</p>
                    <p class="sg-field__error" data-error-for="national_code_serial"></p>
                </div>
                <div class="sg-field sg-activation__birth-field">
                    <span class="sg-activation__birth-label" id="sgw-birth-label">تاریخ تولد <span class="sg-req">*</span></span>
                    <div class="sg-wizard__date-row" role="group" aria-labelledby="sgw-birth-label">
                        <div class="sg-activation__date-part"><label for="sgw-birth-day">روز تولد <span class="sg-req">*</span></label><select class="sg-field__select" id="sgw-birth-day" name="birth_jd" data-birth="jd" aria-label="روز تولد"></select></div>
                        <div class="sg-activation__date-part"><label for="sgw-birth-month">ماه تولد <span class="sg-req">*</span></label><select class="sg-field__select" id="sgw-birth-month" name="birth_jm" data-birth="jm" aria-label="ماه تولد"></select></div>
                        <div class="sg-activation__date-part"><label for="sgw-birth-year">سال تولد <span class="sg-req">*</span></label><select class="sg-field__select" id="sgw-birth-year" name="birth_jy" data-birth="jy" aria-label="سال تولد"></select></div>
                    </div>
                    <p class="sg-field__error" data-error-for="birth_date"></p>
                </div>
            </section>

            <!-- مرحله ۲: آدرس فرستنده -->
            <section class="sg-wizard__step" data-step="2" aria-labelledby="sgw-address-title">
                <div class="sg-activation__intro">
                    <h4 id="sgw-address-title" tabindex="-1">بسته از کدام آدرس ارسال می‌شود؟</h4>
                    <p>نام، شماره تماس و نشانی دقیق فرستنده روی اطلاعات پستی مرسوله استفاده می‌شوند.</p>
                </div>
                <div class="sg-field sg-activation__wide">
                    <label class="sg-field__label" for="sgw-fullname">نام و نام خانوادگی فرستنده <span class="sg-req">*</span></label>
                    <input class="sg-field__input" id="sgw-fullname" name="full_name" type="text" autocomplete="off" required minlength="3" data-sg-validate data-sg-label="نام و نام خانوادگی">
                    <p class="sg-field__error" data-error-for="full_name"></p>
                </div>
                <div class="sg-field sg-activation__wide">
                    <span class="sg-field__label" id="sgw-location-label">استان و شهر <span class="sg-req">*</span></span>
                    <div class="sg-wizard__row2" role="group" aria-labelledby="sgw-location-label">
                        <select class="sg-field__select" id="sgw-province" name="province_id" data-province aria-label="استان" disabled></select>
                        <select class="sg-field__select" id="sgw-city" name="city_id" data-city aria-label="شهر" disabled></select>
                    </div>
                    <p class="sg-field__error" data-error-for="city_id"></p>
                </div>
                <div class="sg-field sg-activation__wide">
                    <label class="sg-field__label" for="sgw-address">نشانی کامل <span class="sg-req">*</span></label>
                    <textarea class="sg-field__input" id="sgw-address" name="address" rows="3" required minlength="10" maxlength="512" data-sg-validate data-sg-label="آدرس پستی"></textarea>
                    <p class="sg-field__error" data-error-for="address"></p>
                </div>
                <div class="sg-field">
                    <label class="sg-field__label" for="sgw-address-detail">جزئیات آدرس <span class="sg-req">*</span></label>
                    <input class="sg-field__input" id="sgw-address-detail" name="address_detail" type="text" placeholder="پلاک، طبقه، واحد یا نام ساختمان" autocomplete="off" required minlength="3" maxlength="255" data-sg-validate data-sg-label="جزئیات آدرس">
                    <p class="sg-field__error" data-error-for="address_detail"></p>
                </div>
                <div class="sg-field">
                    <label class="sg-field__label" for="sgw-postal">کد پستی <span class="sg-req">*</span></label>
                    <input class="sg-field__input" id="sgw-postal" name="postal_code" type="text" dir="ltr" inputmode="numeric" placeholder="۱۰ رقم" autocomplete="off" required data-sg-length="10" data-sg-validate data-sg-label="کد پستی">
                    <p class="sg-field__error" data-error-for="postal_code"></p>
                </div>
            </section>

            <!-- مرحله ۳: منطقه پستی و مرکز تحویل -->
            <section class="sg-wizard__step" data-step="3" aria-labelledby="sgw-center-title">
                <div class="sg-activation__intro">
                    <h4 id="sgw-center-title" tabindex="-1">مرکز تحویل بسته را انتخاب کنید</h4>
                    <p>بعد از ثبت و پرداخت هر مرسوله، بسته را به مرکز انتخاب‌شده تحویل می‌دهید.</p>
                </div>
                <div class="sg-field">
                    <label class="sg-field__label" for="sgw-ploc">منطقه پستی <span class="sg-req">*</span></label>
                    <select class="sg-field__select" id="sgw-ploc" name="provider_location_id" data-provider-location disabled></select>
                    <p class="sg-field__hint">مناطق تحت پوشش استان انتخاب‌شده در مرحله قبل.</p>
                    <p class="sg-field__state" data-state-for="provider_location_id"></p>
                    <button type="button" class="sg-activation__retry" data-retry-locations hidden>دریافت دوباره مناطق</button>
                    <p class="sg-field__error" data-error-for="provider_location_id"></p>
                </div>
                <div class="sg-field">
                    <label class="sg-field__label" for="sgw-node">مرکز تحویل مرسوله <span class="sg-req">*</span></label>
                    <select class="sg-field__select" id="sgw-node" name="provider_node_id" data-provider-node disabled></select>
                    <p class="sg-field__state" data-state-for="provider_node_id"></p>
                    <button type="button" class="sg-activation__retry" data-retry-nodes hidden>دریافت دوباره مراکز</button>
                    <p class="sg-field__error" data-error-for="provider_node_id"></p>
                </div>
                <div class="sg-activation__center sg-activation__wide" data-selected-center hidden>
                    <span class="dashicons dashicons-location" aria-hidden="true"></span>
                    <div><span class="sg-activation__eyebrow">مرکز تحویل انتخاب‌شده</span><strong data-selected-center-title></strong><p data-selected-center-address></p></div>
                </div>
            </section>

            <!-- مرحله ۴: مرور و ثبت -->
            <section class="sg-wizard__step" data-step="4" aria-labelledby="sgw-review-title">
                <div class="sg-activation__intro">
                    <h4 id="sgw-review-title" tabindex="-1">مرور اطلاعات و پرداخت هزینه فعال‌سازی</h4>
                    <p>تا وقتی دکمه «فعال‌سازی و پرداخت هزینه» را نزنید، ثبت یا پرداختی انجام نمی‌شود.</p>
                </div>
                <div class="sg-activation__summary">
                    <h5><span class="dashicons dashicons-location-alt" aria-hidden="true"></span>فرستنده و مرکز تحویل</h5>
                    <dl class="sg-wizard__review" data-review></dl>
                    <details class="sg-activation__identity"><summary>مشاهده اطلاعات هویتی</summary><dl class="sg-wizard__review" data-review-identity></dl></details>
                </div>
                <div class="sg-activation__payment">
                <div class="sg-field sg-activation__cost">
                    <span class="sg-field__label">هزینه فعال‌سازی سلام‌گیت</span>
                    <p class="sg-wizard__cost"><strong data-cost-display>—</strong> <span>تومان</span></p>
                    <p class="sg-field__hint">پرداخت یک‌باره برای فعال‌سازی سرویس</p>
                </div>
                <div class="sg-field" role="radiogroup" aria-label="روش پرداخت هزینه فعال‌سازی">
                    <span class="sg-field__label">روش پرداخت <span class="sg-req">*</span></span>
                    <div class="sg-wizard__radios" data-pay-methods></div>
                    <div class="sg-activation__balance-actions">
                        <a class="sg-activation__topup" data-wizard-topup href="https://wallet.basalam.com/?from=vendor" target="_blank" rel="noopener noreferrer" hidden><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>شارژ کیف پول باسلام<span aria-hidden="true">↗</span></a>
                        <button type="button" class="sg-activation__balance-refresh" data-wizard-balance-refresh><span class="dashicons dashicons-update" aria-hidden="true"></span><span data-wizard-balance-refresh-label>تازه‌سازی موجودی</span></button>
                    </div>
                    <p class="sg-activation__balance-hint" data-wizard-balance-hint role="status" aria-live="polite" hidden></p>
                    <p class="sg-field__error" data-error-for="pay_method"></p>
                </div>
                <div class="sg-field sg-field--check">
                    <label class="sg-check">
                        <input type="checkbox" name="accept_rules" value="1" data-accept-rules>
                        <span>
                            <a href="https://vendor.basalam.com/salam-gate/terms-of-use" target="_blank" rel="noopener noreferrer">قوانین و شرایط سلام‌گیت</a>
                            را خوانده و می‌پذیرم.
                        </span>
                    </label>
                    <p class="sg-field__error" data-error-for="accept_rules"></p>
                </div>
                </div>
            </section>

            <div class="sg-wizard__notice" data-wizard-notice style="display:none;" role="alert" aria-live="assertive"></div>
            <button type="button" class="sg-button sg-button--secondary" data-wizard-retry hidden>دریافت دوباره اطلاعات فعال‌سازی</button>
            <a class="sg-button sg-button--secondary" data-wizard-check href="<?php echo esc_url($backUrl); ?>" hidden>بررسی وضعیت فعال‌سازی</a>

            <div class="sg-wizard__nav">
                <button type="button" class="sg-button sg-button--secondary" data-wizard-prev style="display:none;">مرحله قبل</button>
                <button type="button" class="sg-button sg-button--primary" data-wizard-next disabled>مرحله بعد</button>
                <button type="button" class="sg-button sg-button--primary" data-wizard-submit style="display:none;" disabled>فعال‌سازی و پرداخت هزینه</button>
            </div>
        </form>

        <dialog id="sgw-serial-guide" class="sg-activation__serial-dialog" data-wizard-serial-dialog aria-labelledby="sgw-serial-guide-title" aria-describedby="sgw-serial-guide-description">
            <header class="sg-activation__dialog-header">
                <div><span class="sg-activation__eyebrow">راهنمای اطلاعات هویتی</span><h4 id="sgw-serial-guide-title">سریال کارت ملی کجاست؟</h4></div>
                <button type="button" class="sg-activation__dialog-close" data-wizard-serial-close aria-label="بستن راهنمای سریال کارت ملی"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
            </header>
            <div class="sg-activation__dialog-body">
                <p id="sgw-serial-guide-description">شماره سریال در <strong>پشت کارت ملی</strong> قرار دارد. ۱۰ کاراکتر آن را با حروف و اعداد لاتین وارد کنید.</p>
                <figure class="sg-activation__serial-figure">
                    <svg viewBox="0 0 600 310" xmlns="http://www.w3.org/2000/svg" role="img" aria-labelledby="sgw-card-illustration-title sgw-card-illustration-description" focusable="false">
                        <title id="sgw-card-illustration-title">محل شماره سریال در پشت کارت ملی</title>
                        <desc id="sgw-card-illustration-description">تصویر آموزشی یک کارت نمونه؛ شماره سریال عمودی در لبه سمت چپ کارت با رنگ نارنجی مشخص شده است.</desc>
                        <defs><pattern id="sgw-card-pattern" width="28" height="28" patternUnits="userSpaceOnUse"><path d="M0 14Q7 0 14 14T28 14" fill="none" stroke="#d9e5e1" stroke-width="1"/></pattern><linearGradient id="sgw-card-fill" x1="0" x2="1" y1="0" y2="1"><stop stop-color="#edf5ef"/><stop offset="1" stop-color="#f7f5ea"/></linearGradient></defs>
                        <rect x="34" y="20" width="532" height="270" rx="20" fill="url(#sgw-card-fill)" stroke="#d6e3dc" stroke-width="2"/>
                        <rect x="34" y="20" width="532" height="270" rx="20" fill="url(#sgw-card-pattern)" opacity=".7"/>
                        <path d="M148 89h328M148 104h235M148 189h158M148 204h235" stroke="#c1d1c9" stroke-width="6" stroke-linecap="round"/>
                        <rect x="401" y="131" width="91" height="65" rx="11" fill="#d8dfd2" stroke="#bac9b8" stroke-width="2"/><path d="M421 131v65m30-65v65m21-65v65m-71-43h91m-91 22h91" stroke="#bac9b8" stroke-width="2"/>
                        <rect x="147" y="238" width="262" height="30" rx="3" fill="#fff" opacity=".8"/><path d="M158 242v22m6-22v22m8-22v22m11-22v22m5-22v22m10-22v22m9-22v22m6-22v22m12-22v22m5-22v22m12-22v22m9-22v22m7-22v22m11-22v22m7-22v22m12-22v22m5-22v22m11-22v22m9-22v22m6-22v22m11-22v22m7-22v22m10-22v22m5-22v22m9-22v22m9-22v22" stroke="#5e6b65" stroke-width="3"/>
                        <rect x="55" y="46" width="45" height="162" rx="10" fill="#fff1e8" stroke="#ff5c35" stroke-width="3"/>
                        <text x="79" y="126" transform="rotate(-90 79 126)" direction="ltr" text-anchor="middle" fill="#a43b1d" font-family="PelakFA, Tahoma, sans-serif" font-weight="700" font-size="17">AB12345678</text>
                        <path d="M223 150h-84m18-15-18 15 18 15" fill="none" stroke="#ff5c35" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <figcaption>تصویر آموزشی؛ شماره روی کارت خودتان را وارد کنید.</figcaption>
                </figure>
                <div class="sg-activation__serial-tip"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><p>کد ملی ۱۰ رقمی روی کارت، شماره سریال نیست. اگر حرفی در سریال وجود دارد، آن را به لاتین وارد کنید.</p></div>
            </div>
            <footer class="sg-activation__dialog-footer"><button type="button" class="sg-button sg-button--primary" data-wizard-serial-close>متوجه شدم</button></footer>
        </dialog>
    </div>

<?php endif; ?>
</div>
