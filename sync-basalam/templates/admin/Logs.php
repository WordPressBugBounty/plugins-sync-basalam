<?php

use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

$logs = Logger::getLogs();

if (isset($logs['error'])) {
    echo '<div class="notice notice-error"><p class="basalam-p">' . esc_html($logs['error']) . '</p></div>';

    return;
}

$logs_by_level = $logs['logs_by_level'];
$current_tab = $logs['current_tab'];
$current_page = $logs['current_page'];
$per_page = $logs['per_page'];

$tabs = [
    'info'    => 'اطلاعات',
    'warning' => 'هشدارها',
    'error'   => 'خطاها',
    'debug'   => 'دیباگ',
    'alert'   => 'توجه',
];

$total_logs = 0;
foreach ($logs_by_level as $level_logs) {
    $total_logs += count($level_logs);
}
?>
<div class="basalam-container basalam-p">
    <!-- Header with Title and Clear Button -->
    <div class="log-basalam-header-section">
        <div class="log-basalam-header-content">
            <div class="log-basalam-header-left">
                <h2 class="log-basalam-heading basalam-h">لاگ‌های پلاگین ووسلام</h2>
            </div>
            <div class="log-basalam-header-actions">
                <button id="basalam-clear-logs-btn" class="log-basalam-clear-logs-button" data-nonce="<?php echo esc_attr(wp_create_nonce('basalam_clear_logs_nonce')); ?>">
                    <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/trash.svg'); ?>" alt="حذف" class="log-basalam-btn-icon">
                    <span>حذف همه لاگ‌ها</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Tabs Section -->
    <div class="log-basalam-tabs-modern">
        <?php foreach ($tabs as $tab => $label): ?>
            <a href="<?php echo esc_url(add_query_arg([
                            'page'  => 'sync_basalam_logs',
                            'tab'   => $tab,
                            'paged' => 1,
                        ], admin_url('admin.php'))); ?>"
                class="log-basalam-tab-button-modern <?php echo ($tab === $current_tab) ? 'log-basalam-active' : ''; ?>">
                <span class="log-basalam-tab-icon log-basalam-tab-icon-<?php echo esc_attr($tab); ?>"></span>
                <span class="log-basalam-tab-label"><?php echo esc_html($label); ?></span>
                <span class="log-basalam-tab-count-modern"><?php echo esc_html(count($logs_by_level[$tab])); ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php foreach ($tabs as $tab => $label): ?>
        <div id="basalam-<?php echo esc_attr($tab); ?>" class="log-basalam-tab-content <?php echo ($tab === $current_tab) ? 'log-basalam-active' : ''; ?>">
            <?php
            $logs = $logs_by_level[$tab];
        $total_logs = count($logs);
        $total_pages = ceil($total_logs / $per_page);
        $offset = ($current_page - 1) * $per_page;
        $current_logs = array_slice($logs, $offset, $per_page);
        if (empty($current_logs)): ?>
                <div class="log-basalam-empty-state">
                    <div class="log-basalam-empty-icon">📋</div>
                    <h3>هیچ لاگی در این بخش وجود ندارد</h3>
                    <p>لاگ‌های جدید در اینجا نمایش داده خواهند شد</p>
                </div>
            <?php else: ?>
                <div class="log-basalam-log-count-modern">نمایش <?php echo esc_html(($offset + 1) . '-' . min($offset + $per_page, $total_logs) . ' از ' . $total_logs); ?> لاگ</div>
                <ul class="log-basalam-log-list-modern">
                    <?php foreach ($current_logs as $log): ?>
                        <?php
                        // Extract product_id from context if available (supports both 'product_id' and legacy 'شناسه_محصول')
                        $product_id = null;
                        $edit_url = null;
                        $view_url = null;

                        if (!empty($log['context'])) {
                            $context_data = json_decode(json_encode($log['context']), true);

                            // Check for product_id (new format) or شناسه_محصول (old format)
                            if (!empty($context_data['product_id'])) {
                                $product_id = intval($context_data['product_id']);
                            } elseif (!empty($context_data['شناسه_محصول'])) {
                                $product_id = intval($context_data['شناسه_محصول']);
                            }

                            // Generate URLs if product_id exists
                            if ($product_id) {
                                $edit_url = esc_url(admin_url("post.php?post=$product_id&action=edit"));
                                $view_url = esc_url(get_permalink($product_id));
                            }
                        }
                        ?>
                        <li class="log-basalam-log-item-modern log-basalam-<?php echo esc_attr($tab); ?>">
                            <div class="log-basalam-log-header-modern">
                                <div class="log-basalam-log-meta">
                                    <span class="log-basalam-log-date-modern"><?php echo esc_html($log['date']); ?></span>
                                    <span class="log-basalam-log-level-badge log-basalam-level-<?php echo esc_attr(strtolower($log['level'])); ?>"><?php echo esc_html($log['level']); ?></span>
                                </div>
                                <?php if (!empty($log['context'])): ?>
                                    <div class="log-basalam-context-toggle-modern" data-toggle="context">جزئیات</div>
                                <?php endif; ?>
                            </div>
                            <div class="log-basalam-log-message-modern">
                                <?php echo esc_html($log['message']); ?>
                            </div>
                            <?php if (!empty($log['context'])): ?>
                                <div class="log-basalam-log-context-modern">
                                    <div class="log-basalam-context-content">
                                        <pre class="log-basalam-context-json"><?php echo esc_html(json_encode($context_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                                        <?php if (!empty($edit_url) && !empty($view_url)): ?>
                                            <div class="log-basalam-product-actions-modern">
                                                <a href="<?php echo esc_url($edit_url); ?>" target="_blank" class="log-basalam-btn-modern log-basalam-edit-btn basalam-a" title="ویرایش محصول">
                                                    <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/update.svg'); ?>" alt="ویرایش">
                                                    ویرایش محصول
                                                </a>
                                                <a href="<?php echo esc_url($view_url); ?>" target="_blank" class="log-basalam-btn-modern log-basalam-view-btn basalam-a" title="مشاهده محصول">
                                                    <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/info.svg'); ?>" alt="مشاهده">
                                                    مشاهده محصول
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($total_pages > 1): ?>
                    <div class="log-basalam-pagination-modern">
                        <?php
                        $current_url = add_query_arg([
                            'page' => 'sync_basalam_logs',
                            'tab'  => $tab,
                        ], admin_url('admin.php'));

                    if ($current_page > 1): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', 1, $current_url)); ?>" class="log-basalam-page-link-modern basalam-first">«</a>
                        <?php endif;

                    if ($current_page > 1): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', $current_page - 1, $current_url)); ?>" class="log-basalam-page-link-modern basalam-prev">‹</a>
                        <?php endif;

                    $start_page = max(1, min($current_page - 2, $total_pages - 4));
                    $end_page = min($total_pages, max(5, $current_page + 2));

                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', $i, $current_url)); ?>"
                                class="log-basalam-page-link-modern <?php echo ($i === $current_page) ? 'log-basalam-active' : ''; ?>">
                                <?php echo esc_html($i); ?>
                            </a>
                        <?php endfor;

                    if ($current_page < $total_pages): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', $current_page + 1, $current_url)); ?>" class="log-basalam-page-link-modern basalam-next">›</a>
                        <?php endif;

                    if ($current_page < $total_pages): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', $total_pages, $current_url)); ?>" class="log-basalam-page-link-modern basalam-last">»</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- Clear Logs Confirmation Modal -->
<div id="basalam-clear-logs-modal" class="log-basalam-modal">
    <div class="log-basalam-modal-content">
        <div class="log-basalam-modal-header">
            <h3>حذف همه لاگ‌ها</h3>
            <button class="log-basalam-modal-close">&times;</button>
        </div>
        <div class="log-basalam-modal-body">
            <p>آیا مطمئن هستید که می‌خواهید همه لاگ‌های پلاگین ووسلام را حذف کنید؟</p>
            <p class="log-basalam-warning-text"> این عملیات غیرقابل بازگشت است!</p>
        </div>
        <div class="log-basalam-modal-footer">
            <button class="log-basalam-btn-modern log-basalam-btn-secondary" id="basalam-cancel-clear">انصراف</button>
            <button class="log-basalam-btn-modern log-basalam-btn-danger" id="basalam-confirm-clear">حذف همه لاگ‌ها</button>
        </div>
    </div>
</div>