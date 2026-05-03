<?php

defined('ABSPATH') || exit;
?>
<fieldset class="inline-edit-col-right sync-basalam-bulk-edit">
    <div class="inline-edit-col sync-basalam-bulk-edit__panel">
        <div class="sync-basalam-bulk-edit__header">
            <p class="basalam-p sync-basalam-bulk-edit__title">ویرایش گروهی ووسلام</p>
            <p class="basalam-p basalam-font-12 sync-basalam-bulk-edit__desc">تنظیمات باسلام را فقط برای محصولات انتخاب‌شده یکجا اعمال کنید.</p>
        </div>

        <div class="sync-basalam-bulk-edit__fields">
            <div class="sync-basalam-bulk-edit__section sync-basalam-bulk-edit__section--type">
                <label class="alignleft sync-basalam-bulk-edit__field">
                    <?php echo wp_kses_post(\SyncBasalam\Admin\Components\CommonComponents::renderLabelWithTooltip('نوع محصول باسلام', 'اگر این گزینه را فعال کنید، محصول در باسلام به صورت محصول واحددار ارسال می‌شود و می‌توانید واحد و مقدار آن را مشخص کنید.')); ?>
                    <select name="sync_basalam_bulk_product_type_action" class="sync-basalam-bulk-select basalam-select basalam-font-pelak-12">
                        <option value="keep">بدون تغییر</option>
                        <option value="yes">فعال</option>
                        <option value="no">غیرفعال</option>
                    </select>
                </label>

                <div class="sync-basalam-bulk-type-fields">
                    <label class="alignleft sync-basalam-bulk-edit__field">
                        <span class="title basalam-p basalam-font-12">واحد محصول</span>
                        <select name="sync_basalam_bulk_product_unit" class="sync-basalam-bulk-select basalam-select basalam-font-pelak-12">
                            <?php foreach ($units as $unitId => $label) : ?>
                                <option value="<?php echo esc_attr($unitId); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="alignleft sync-basalam-bulk-edit__field">
                        <span class="title basalam-p basalam-font-12">مقدار محصول</span>
                        <input type="number" name="sync_basalam_bulk_product_value" min="1" step="1" value="1" class="basalam-input basalam-font-pelak-12">
                    </label>
                </div>
            </div>

            <span class="sync-basalam-bulk-edit__divider" aria-hidden="true"></span>

            <div class="sync-basalam-bulk-edit__section sync-basalam-bulk-edit__section--wholesale">
                <label class="alignleft sync-basalam-bulk-edit__field">
                    <?php echo wp_kses_post(\SyncBasalam\Admin\Components\CommonComponents::renderLabelWithTooltip('محصول عمده', 'با فعال کردن این گزینه، محصولات انتخاب‌شده در باسلام با حالت عمده ثبت یا به‌روزرسانی می‌شوند.')); ?>
                    <select name="sync_basalam_bulk_wholesale_action" class="sync-basalam-bulk-select basalam-select basalam-font-pelak-12">
                        <option value="keep">بدون تغییر</option>
                        <option value="yes">فعال</option>
                        <option value="no">غیرفعال</option>
                    </select>
                </label>
            </div>

            <span class="sync-basalam-bulk-edit__divider" aria-hidden="true"></span>

            <div class="sync-basalam-bulk-edit__section sync-basalam-bulk-edit__section--increase basalam-form-group basalam-form-group-full basalam-p">
                <label class="alignleft sync-basalam-bulk-edit__field">
                    <?php echo wp_kses_post(\SyncBasalam\Admin\Components\CommonComponents::renderLabelWithTooltip('افزایش قیمت اختصاصی', 'اگر برای این بخش مقداری تنظیم کنید، برای محصولات انتخاب‌شده به جای افزایش قیمت سراسری تنظیمات استفاده می‌شود. مقدار 1 تا 100 درصد، بیشتر از 100 مبلغ ثابت تومانی.')); ?>
                    <select name="sync_basalam_bulk_increase_action" class="sync-basalam-bulk-select basalam-select basalam-font-pelak-12">
                        <option value="keep">بدون تغییر</option>
                        <option value="set">تنظیم مقدار</option>
                        <option value="clear">حذف و استفاده از تنظیم اصلی</option>
                    </select>
                </label>

                <div class="sync-basalam-bulk-edit__field sync-basalam-bulk-increase-value">
                    <div class="basalam-input-container">
                        <input type="text" id="sync-basalam-bulk-increase-price-input" data-role="increase-price-input" value="" class="basalam-input basalam-p percentage-input basalam-font-pelak-12" inputmode="numeric" autocomplete="off">
                        <span class="percentage-unit basalam-p basalam-min-width-0 basalam-font-13">درصد</span>
                    </div>
                    <div class="basalam-flex-end-gap-4 basalam-margin-top-8">
                        <input type="checkbox" id="sync-basalam-bulk-toggle-percentage" class="toggle-percentage" name="sync_basalam_bulk_toggle_percentage">
                        <label class="basalam-font-10" for="sync-basalam-bulk-toggle-percentage">کارمزد دسته‌بندی</label>
                    </div>
                    <input type="hidden" id="sync-basalam-bulk-final-value" data-role="increase-price-hidden" name="sync_basalam_bulk_increase_value" value="">
                </div>
            </div>
        </div>
    </div>
</fieldset>
