<?php

use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

if (!syncBasalamSettings()->getSettings(SettingsConfig::TOKEN)) {
    require_once(syncBasalamPlugin()->templatePath() . "/admin/main/NotConnected.php");
    return;
}
?>

<div class="basalam-container">
    <div class="basalam-header">
        <div class="basalam-header-data">
            <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/images/basalam.svg'); ?>" alt="Basalam">
            <div class="basalam-text-right basalam-flex-fill">
                <h2 class="basalam-h basalam-margin-bottom-8">اتصال دسته‌بندی‌ها</h2>
                <p class="basalam-p basalam-text-right">
                    هنگام ایجاد یا آپدیت محصول در باسلام، پلاگین ابتدا اتصال دسته‌بندی میان ووکامرس و باسلام را بررسی می‌کند و در صورت وجود از آن استفاده خواهد شد. اگر اتصالی وجود نداشته باشد، دسته‌بندی بر اساس عنوان محصول و با کمک هوش مصنوعی تعیین می‌شود.
                </p>
            </div>
        </div>
    </div>

    <!-- Warning Note -->
    <div class="basalam-bg-warning-section">
        <div class="basalam-flex-start basalam-gap-10">
            <span class="dashicons dashicons-warning basalam-warning-20"></span>
            <div class="basalam-flex-fill">
                <p class="basalam-p basalam-margin-warning-justify">
                    اگر قبلاً محصولات خود را به باسلام اضافه کرده‌اید و می‌خواهید دسته‌بندی آن‌ها را تغییر دهید، باید این کار را از طریق پنل باسلام انجام دهید:
                    <a href="https://vendor.basalam.com/change-categories" target="_blank" class="basalam-warning-underline">
                        تغییر دسته‌بندی محصولات در باسلام
                    </a>
                    <br>
                    اتصال دسته‌بندی در این صفحه تنها برای محصولاتی که هنوز به باسلام اضافه نشده‌اند یا محصولات جدید اعمال می‌شود.
                </p>
            </div>
        </div>
    </div>

    <!-- Mapping Actions Section -->
    <div class="basalam-setup-wizard basalam-margin-bottom-20">
        <div class="basalam-step basalam-opacity-1">
            <h3 class="basalam-h basalam-margin-bottom-16 basalam-text-right">انجام اتصال</h3>
            <p class="basalam-p basalam-text-right basalam-margin-bottom-20">دسته‌بندی ووکامرس را انتخاب کنید و سپس دسته‌بندی مربوطه در باسلام را انتخاب کنید.</p>

            <div class="selected-categories basalam-margin-20-0">
                <div class="basalam-grid-3">
                    <div id="selected-woo-category" class="basalam-padding-box-center">
                        دسته‌بندی ووکامرس انتخاب نشده
                    </div>
                    <span class="dashicons dashicons-arrow-left-alt2 basalam-arrow-large"></span>
                    <div id="selected-basalam-category" class="basalam-padding-box-center">
                        دسته‌بندی باسلام انتخاب نشده
                    </div>
                </div>
            </div>

            <div class="basalam-text-center basalam-margin-top-20">
                <button id="create-mapping-btn" class="basalam-btn basalam-btn-primary basalam-margin-left-10 basalam-padding-0-20" disabled>
                    ایجاد اتصال
                </button>
                <button id="clear-selection-btn" class="basalam-btn basalam-btn-secondary basalam-margin-right-10 basalam-padding-0-20">
                    پاک کردن انتخاب
                </button>
            </div>
        </div>
    </div>

    <!-- Categories Grid -->
    <div class="category-mapping-grid basalam-grid-2">
        <!-- WooCommerce Categories Section -->
        <div class="basalam-setup-wizard">
            <div class="basalam-step basalam-opacity-1-padding">
                <h3 class="basalam-h basalam-margin-bottom-16 basalam-text-right">
                    <span class="dashicons dashicons-wordpress basalam-margin-left-8"></span>
                    دسته‌بندی‌های ووکامرس
                </h3>

                <div class="search-container basalam-margin-bottom-15">
                    <input type="text" id="woo-category-search" placeholder="جستجوی دسته‌بندی..."
                        class="basalam-input-search">
                </div>

                <div id="woo-categories-list" class="basalam-height-400-scroll">
                    <div class="loading-spinner basalam-text-center-padding">
                        <span class="spinner is-active"></span>
                        در حال بارگذاری دسته‌بندی‌ها...
                    </div>
                </div>
            </div>
        </div>

        <!-- Basalam Categories Section -->
        <div class="basalam-setup-wizard">
            <div class="basalam-step basalam-opacity-1-padding">
                <h3 class="basalam-h basalam-margin-bottom-16 basalam-text-right">
                    <span class="dashicons dashicons-external basalam-margin-left-8"></span>
                    دسته‌بندی‌های باسلام
                </h3>

                <div class="search-container basalam-margin-bottom-15">
                    <input type="text" id="basalam-category-search" placeholder="جستجوی دسته‌بندی..."
                        class="basalam-input-search">
                </div>

                <div id="basalam-categories-list" class="basalam-height-400-scroll">
                    <div class="loading-spinner basalam-text-center-padding">
                        <span class="spinner is-active"></span>
                        در حال بارگذاری دسته‌بندی‌ها...
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Current Mappings -->
    <div class="basalam-setup-wizard basalam-margin-top-20">
        <div class="basalam-step basalam-opacity-1">
            <h3 class="basalam-h basalam-margin-bottom-16 basalam-text-right">
                <span class="dashicons dashicons-networking basalam-margin-left-8"></span>
                اتصال‌های فعلی
            </h3>
            <div id="mappings-list">
                <div class="loading-spinner basalam-text-center-padding">
                    <span class="spinner is-active"></span>
                    در حال بارگذاری اتصال‌ها...
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .category-item {
        padding: 12px 15px;
        border-bottom: 1px solid var(--basalam-gray-200);
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-family: PelakFA;
        color: var(--basalam-gray-700);
    }

    .category-item.selectable:hover {
        background-color: var(--basalam-gray-100);
    }

    .category-item.non-selectable:hover {
        background-color: var(--basalam-gray-50);
    }

    .category-item.selected {
        background-color:
            border-right: 3px solid var(--basalam-primary-color);
        color: var(--basalam-gray-800);
        font-weight: 600;
    }

    .category-item:last-child {
        border-bottom: none;
    }

    .category-hierarchy {
        font-size: 12px;
        color: var(--basalam-gray-500);
        margin-top: 4px;
        font-family: PelakFA;
    }

    .mapping-item {
        display: grid;
        grid-template-columns: 1fr auto 1fr auto;
        gap: 15px;
        align-items: center;
        padding: 15px;
        border-bottom: 1px solid var(--basalam-gray-200);
        font-family: PelakFA;
    }

    .mapping-item:last-child {
        border-bottom: none;
    }

    .mapping-arrow {
        color: var(--basalam-gray-500);
        font-size: 18px;
    }

    .delete-mapping-btn {
        background: var(--basalam-danger-color);
        color: white;
        border: none;
        padding: 6px 12px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
        font-family: PelakFA;
        transition: background 0.2s ease;
    }

    .delete-mapping-btn:hover {
        background: var(--basalam-danger-hover);
    }

    .no-categories {
        text-align: center;
        padding: 40px 20px;
        color: var(--basalam-gray-500);
        font-family: PelakFA;
    }

    .basalam-category-parent {
        font-size: 12px;
        color: var(--basalam-gray-500);
        margin-bottom: 5px;
        font-family: PelakFA;
    }

    .basalam-category-wrapper {
        position: relative;
    }

    .children-container {
        margin-right: 15px;
        border-right: 2px solid var(--basalam-gray-200);
    }

    .expand-icon {
        transition: transform 0.2s;
        color: var(--basalam-gray-500);
    }

    .expand-icon:hover {
        color: var(--basalam-primary-color);
    }

    .basalam-btn {
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-family: PelakFA;
        font-size: 14px;
        transition: all 0.2s ease;
        text-decoration: none;
        display: inline-block;
    }

    .basalam-btn-primary {
        background: var(--basalam-primary-color);
        color: white;
    }

    .basalam-btn-primary:hover:not(:disabled) {
        background: var(--basalam-primary-hover);
    }

    .basalam-btn-primary:disabled {
        background: var(--basalam-gray-400);
        cursor: not-allowed;
    }

    .basalam-btn-secondary {
        background: var(--basalam-gray-200);
        color: var(--basalam-gray-700);
    }

    .basalam-btn-secondary:hover {
        background: var(--basalam-gray-300);
    }
</style>

<script>
    jQuery(document).ready(function($) {
        let selectedWooCategory = null;
        let selectedBasalamCategory = null;
        let wooCategories = [];
        let basalamCategories = [];
        let categoryMappings = [];

        loadWooCommerceCategories();
        loadBasalamCategories();
        loadCategoryMappings();

        $('#woo-category-search').on('input', function() {
            const searchTerm = $(this).val().toLowerCase();
            filterWooCategories(searchTerm);
        });

        $('#basalam-category-search').on('input', function() {
            const searchTerm = $(this).val().toLowerCase();
            filterBasalamCategories(searchTerm);
        });

        $('#create-mapping-btn').on('click', function() {
            if (selectedWooCategory && selectedBasalamCategory) {
                createCategoryMapping();
            }
        });

        $('#clear-selection-btn').on('click', function() {
            clearSelection();
        });

        function loadWooCommerceCategories() {
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'get_woocommerce_categories',
                    _wpnonce: '<?php echo wp_create_nonce('get_woocommerce_categories_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        wooCategories = response.data;
                        renderWooCategories(wooCategories);
                    } else {
                        $('#woo-categories-list').html('<div class="no-categories">خطا در بارگذاری دسته‌بندی‌ها</div>');
                    }
                },
                error: function() {
                    $('#woo-categories-list').html('<div class="no-categories">خطا در اتصال به سرور</div>');
                }
            });
        }

        function loadBasalamCategories() {
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'get_basalam_categories',
                    _wpnonce: '<?php echo wp_create_nonce('get_basalam_categories_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        basalamCategories = response.data;
                        renderBasalamCategories(basalamCategories);
                    } else {
                        $('#basalam-categories-list').html('<div class="no-categories">خطا در بارگذاری دسته‌بندی‌ها</div>');
                    }
                },
                error: function() {
                    $('#basalam-categories-list').html('<div class="no-categories">خطا در اتصال به سرور</div>');
                }
            });
        }

        function loadCategoryMappings() {
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'get_category_mappings',
                    _wpnonce: '<?php echo wp_create_nonce('get_category_mappings_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        categoryMappings = response.data;
                        renderCategoryMappings(categoryMappings);
                    } else {
                        $('#mappings-list').html('<div class="no-categories">هیچ اتصالی یافت نشد</div>');
                    }
                },
                error: function() {
                    $('#mappings-list').html('<div class="no-categories">خطا در بارگذاری اتصال‌ها</div>');
                }
            });
        }

        function renderWooCategories(categories) {
            let html = '';
            if (categories.length === 0) {
                html = '<div class="no-categories">دسته‌بندی‌ای یافت نشد</div>';
            } else {
                categories.forEach(function(category) {
                    const hierarchy = category.hierarchy ? `<div class="category-hierarchy">${category.hierarchy}</div>` : '';
                    html += `
                    <div class="category-item woo-category" data-id="${category.id}" data-name="${category.name}">
                        <div>
                            <strong>${category.name}</strong>
                            ${hierarchy}
                        </div>
                        <span class="category-count">${category.count} محصول</span>
                    </div>
                `;
                });
            }
            $('#woo-categories-list').html(html);

            $('.woo-category').on('click', function() {
                $('.woo-category').removeClass('selected');
                $(this).addClass('selected');

                selectedWooCategory = {
                    id: $(this).data('id'),
                    name: $(this).data('name')
                };

                updateSelectedCategory('woo');
                updateMappingButton();
            });
        }

        function renderBasalamCategories(categories, parentElement = null, level = 0, parentIds = []) {
            if (!parentElement) {
                $('#basalam-categories-list').empty();
                parentElement = $('#basalam-categories-list');
            }

            if (categories.length === 0 && level === 0) {
                parentElement.html('<div class="no-categories">دسته‌بندی‌ای یافت نشد</div>');
                return;
            }

            categories.forEach(function(category) {
                const hasChildren = category.children && category.children.length > 0;
                const paddingRight = level;
                const isSelectable = level === 2;
                const currentPath = [...parentIds, category.id];

                const categoryHtml = `
                <div class="basalam-category-wrapper basalam-dynamic-padding-right" data-padding="${paddingRight}">
                    <div class="category-item basalam-category ${isSelectable ? 'selectable' : 'non-selectable'}"
                         data-id="${category.id}"
                         data-name="${category.name}"
                         data-level="${level}"
                         data-path='${JSON.stringify(currentPath)}'>
                        <div class="basalam-flex-width-100">
                            ${hasChildren ? `<span class="expand-icon dashicons dashicons-arrow-left-alt2 basalam-cursor-pointer-small" data-category-id="${category.id}"></span>` : '<span class="basalam-width-20-block"></span>'}
                            <strong class="basalam-flex-1">${category.name}</strong>
                        </div>
                    </div>
                    ${hasChildren ? `<div class="children-container basalam-display-none" id="children-${category.id}"></div>` : ''}
                </div>
            `;

                const categoryElement = $(categoryHtml);
                // Apply dynamic padding from data attribute
                categoryElement.find('.basalam-dynamic-padding-right').css('padding-right', categoryElement.find('.basalam-dynamic-padding-right').data('padding') + 'px');
                parentElement.append(categoryElement);

                if (hasChildren) {
                    renderBasalamCategories(category.children, categoryElement.find(`#children-${category.id}`), level + 1, currentPath);
                }
            });

            $('.expand-icon').off('click').on('click', function(e) {
                e.stopPropagation();
                const categoryId = $(this).data('category-id');
                const childrenContainer = $(`#children-${categoryId}`);

                if (childrenContainer.is(':visible')) {
                    childrenContainer.slideUp(200);
                    $(this).removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-left-alt2');
                } else {
                    childrenContainer.slideDown(200);
                    $(this).removeClass('dashicons-arrow-left-alt2').addClass('dashicons-arrow-down-alt2');
                }
            });

            $('.basalam-category.selectable').off('click').on('click', function(e) {
                e.stopPropagation();
                $('.basalam-category').removeClass('selected');
                $(this).addClass('selected');

                const categoryPath = JSON.parse($(this).attr('data-path'));
                selectedBasalamCategory = {
                    id: $(this).data('id'),
                    name: $(this).data('name'),
                    path: categoryPath
                };

                updateSelectedCategory('basalam');
                updateMappingButton();
            });

            $('.basalam-category.non-selectable').off('click').on('click', function(e) {
                e.stopPropagation();
                const categoryElement = $(this);
                const categoryId = categoryElement.data('id');
                const expandIcon = categoryElement.find('.expand-icon');
                const childrenContainer = $(`#children-${categoryId}`);

                if (childrenContainer.length > 0) {
                    if (childrenContainer.is(':visible')) {
                        childrenContainer.slideUp(200);
                        expandIcon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-left-alt2');
                    } else {
                        childrenContainer.slideDown(200);
                        expandIcon.removeClass('dashicons-arrow-left-alt2').addClass('dashicons-arrow-down-alt2');
                    }
                }
            });
        }

        function renderCategoryMappings(mappings) {
            let html = '';
            if (mappings.length === 0) {
                html = '<div class="no-categories">هیچ اتصالی ایجاد نشده است</div>';
            } else {
                mappings.forEach(function(mapping) {
                    html += `
                    <div class="mapping-item">
                        <div><strong>${mapping.woo_category_name}</strong></div>
                        <span class="mapping-arrow dashicons dashicons-arrow-left-alt2"></span>
                        <div><strong>${mapping.basalam_category_name}</strong></div>
                        <button class="delete-mapping-btn" data-mapping-id="${mapping.id}">حذف</button>
                    </div>
                `;
                });
            }
            $('#mappings-list').html(html);

            $('.delete-mapping-btn').on('click', function() {
                const mappingId = $(this).data('mapping-id');
                if (confirm('آیا از حذف این اتصال اطمینان دارید؟')) {
                    deleteCategoryMapping(mappingId);
                }
            });
        }

        function filterWooCategories(searchTerm) {
            const filtered = wooCategories.filter(category =>
                category.name.toLowerCase().includes(searchTerm)
            );
            renderWooCategories(filtered);
        }

        function filterBasalamCategories(searchTerm) {
            if (!searchTerm) {
                renderBasalamCategories(basalamCategories);
                return;
            }

            function filterTree(categories) {
                let filtered = [];
                categories.forEach(function(category) {
                    let includeCategory = category.name.toLowerCase().includes(searchTerm);
                    let filteredChildren = [];

                    if (category.children && category.children.length > 0) {
                        filteredChildren = filterTree(category.children);
                        if (filteredChildren.length > 0) {
                            includeCategory = true;
                        }
                    }

                    if (includeCategory) {
                        filtered.push({
                            ...category,
                            children: filteredChildren
                        });
                    }
                });
                return filtered;
            }

            const filtered = filterTree(basalamCategories);
            renderBasalamCategories(filtered);

            if (searchTerm) {
                $('.children-container').show();
                $('.expand-icon').removeClass('dashicons-arrow-left-alt2').addClass('dashicons-arrow-down-alt2');
            }
        }

        function updateSelectedCategory(type) {
            if (type === 'woo' && selectedWooCategory) {
                $('#selected-woo-category').html(`<strong>${selectedWooCategory.name}</strong>`);
            } else if (type === 'basalam' && selectedBasalamCategory) {
                $('#selected-basalam-category').html(`<strong>${selectedBasalamCategory.name}</strong>`);
            }
        }

        function updateMappingButton() {
            if (selectedWooCategory && selectedBasalamCategory) {
                $('#create-mapping-btn').prop('disabled', false);
            } else {
                $('#create-mapping-btn').prop('disabled', true);
            }
        }

        function clearSelection() {
            selectedWooCategory = null;
            selectedBasalamCategory = null;

            $('.category-item').removeClass('selected');
            $('#selected-woo-category').text('دسته‌بندی ووکامرس انتخاب نشده');
            $('#selected-basalam-category').text('دسته‌بندی باسلام انتخاب نشده');

            updateMappingButton();
        }

        function createCategoryMapping() {
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'create_category_mapping',
                    woo_category_id: selectedWooCategory.id,
                    woo_category_name: selectedWooCategory.name,
                    basalam_category_ids: selectedBasalamCategory.path,
                    basalam_category_name: selectedBasalamCategory.name,
                    _wpnonce: '<?php echo wp_create_nonce('create_category_mapping_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        alert('اتصال با موفقیت ایجاد شد!');
                        clearSelection();
                        loadCategoryMappings();
                    } else {
                        alert('خطا در ایجاد اتصال: ' + response.data);
                    }
                },
                error: function() {
                    alert('خطا در اتصال به سرور');
                }
            });
        }

        function deleteCategoryMapping(mappingId) {
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'delete_category_mapping',
                    mapping_id: mappingId,
                    _wpnonce: '<?php echo wp_create_nonce('delete_category_mapping_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        alert('اتصال با موفقیت حذف شد!');
                        loadCategoryMappings();
                    } else {
                        alert('خطا در حذف اتصال: ' + response.data);
                    }
                },
                error: function() {
                    alert('خطا در اتصال به سرور');
                }
            });
        }

    });
</script>