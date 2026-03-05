<?php

use SyncBasalam\Services\TicketServiceManager;
use SyncBasalam\Utilities\DateConverter;
use SyncBasalam\Admin\Components\CommonComponents;
use SyncBasalam\Utilities\TicketUserResolver;

defined('ABSPATH') || exit;

$ticket_id = isset($_GET['ticket_id']) ? intval($_GET['ticket_id']) : 0;

$ticketManager = new TicketServiceManager();
$fetchTicket = $ticketManager->fetchTicket($ticket_id);

if (TicketServiceManager::isUnauthorized($fetchTicket)) {
    CommonComponents::renderUnauthorizedError();
    return;
}

$ticket = isset($fetchTicket['body']) ? json_decode($fetchTicket['body'], true) : null;

if (empty($ticket)) {
    echo 'تیکت یافت نشد.';
    return;
}

$isTicketClosed = TicketServiceManager::isTicketClosed($ticket);

?>
<div class="basalam-container">
    <div class="ticket-items__answer">
        <header class="ticket-items__answer-header">
            <h2 class="basalam-h">پاسخ تیکت</h2>
            <a class="basalam-p" style="color: black !important;direction: ltr;display:flex" href=" <?php echo esc_url(add_query_arg(['page' => 'sync_basalam_tickets', 'paged'  => 1,], admin_url('admin.php'))); ?>">بازگشت به لیست تیکت ها</a>
        </header>
        <?php if ($isTicketClosed): ?>
            <div class="ticket-items__answer-closed" role="status" aria-live="polite">
                <span class="ticket-items__answer-closed-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="10" rx="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </span>
                <p class="ticket-items__answer-closed-text basalam-p">این تیکت بسته شده است و امکان ارسال پاسخ جدید وجود ندارد.</p>
            </div>
        <?php else: ?>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="Basalam-form">
                <?php wp_nonce_field('create_ticket_item_nonce', '_wpnonce'); ?>
                <input type="hidden" name="action" value="create_ticket_item">
                <input type="hidden" name="ticket_id" value="<?php echo esc_attr($ticket_id); ?>">
                <div class="ticket-items__answer-inputs">
                    <div class="ticket-items__answer-control">
                        <label for="ticket-answer-textarea" class="ticket-items__answer-control-label basalam-p">متن پاسخ خود را وارد کنید</label>
                        <textarea id="ticket-answer-textarea" name="content" class="basalam-input ticket-items__answer-input"></textarea>
                    </div>

                    <div class="create-ticket__extra-info">
                        <p class="create-ticket__extra-info-title basalam-p">اطلاعات دسترسی (اختیاری)</p>
                        <div class="ticket-access-grid">
                            <div class="ticket-access-card ticket-access-card--dashboard">
                                <p class="ticket-access-card__title basalam-p">اطلاعات پیشخوان</p>
                                <div class="create-ticket__control">
                                    <label for="ticket-dashboard-login-url" class="create-ticket__label basalam-p">آدرس لاگین پیشخوان</label>
                                    <input type="text" name="dashboard_login_url" id="ticket-dashboard-login-url" class="basalam-input create-ticket__input">
                                </div>
                                <div class="create-ticket__control">
                                    <label for="ticket-dashboard-username" class="create-ticket__label basalam-p">نام کاربری پیشخوان</label>
                                    <input type="text" name="dashboard_username" id="ticket-dashboard-username" class="basalam-input create-ticket__input">
                                </div>
                                <div class="create-ticket__control">
                                    <label for="ticket-dashboard-password" class="create-ticket__label basalam-p">رمز عبور پیشخوان</label>
                                    <input type="text" name="dashboard_password" id="ticket-dashboard-password" class="basalam-input create-ticket__input">
                                </div>
                            </div>

                            <div class="ticket-access-card ticket-access-card--host-panel">
                                <p class="ticket-access-card__title basalam-p">کنترل پنل هاست</p>
                                <div class="create-ticket__control">
                                    <label for="ticket-host-panel-login-url" class="create-ticket__label basalam-p">آدرس لاگین کنترل پنل هاست</label>
                                    <input type="text" name="host_panel_login_url" id="ticket-host-panel-login-url" class="basalam-input create-ticket__input">
                                </div>
                                <div class="create-ticket__control">
                                    <label for="ticket-host-panel-username" class="create-ticket__label basalam-p">نام کاربری کنترل پنل هاست</label>
                                    <input type="text" name="host_panel_username" id="ticket-host-panel-username" class="basalam-input create-ticket__input">
                                </div>
                                <div class="create-ticket__control">
                                    <label for="ticket-host-panel-password" class="create-ticket__label basalam-p">رمز عبور کنترل پنل هاست</label>
                                    <input type="text" name="host_panel_password" id="ticket-host-panel-password" class="basalam-input create-ticket__input">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ticket-items__answer-control">
                        <label class="ticket-items__answer-control-label basalam-p">پیوست تصویر (اختیاری)</label>
                        <div class="ticket-file-upload" id="ticket-file-upload-reply">
                            <label for="ticket-file-reply" class="ticket-file-upload__label">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                    <polyline points="17 8 12 3 7 8" />
                                    <line x1="12" y1="3" x2="12" y2="15" />
                                </svg>
                                انتخاب تصویر
                            </label>
                            <input type="file" name="_ticket_file" id="ticket-file-reply" class="ticket-file-upload__input" accept="image/jpeg,image/png,image/webp,image/bmp,image/avif">
                            <div class="ticket-file-upload__previews"></div>
                        </div>
                    </div>
                    <div class="ticket-items__answer-actions">
                        <button type="submit" class="ticket-items__answer-submit basalam-primary-button">ارسال</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <div class="ticket-items">
        <?php
        foreach ($ticket['data']['items'] as $ticketItem) {
            if ($ticketItem['type'] != 'content') continue;
            $isAdmin = $ticketItem['user']['is_admin'];
            $creatorUser = TicketUserResolver::getLabel($ticketItem['user']);
        ?>
            <div class="ticket-items__item-wrapper">
                <div class="ticket-items__item <?php if ($isAdmin) echo 'ticket-items__item--admin' ?>">
                    <div class="ticket-items__item-stats">
                        <p class="ticket-items__item-name basalam-p"><?php echo esc_html($creatorUser); ?></p>

                        <p class="ticket-items__item-date basalam-p">
                            <?php echo esc_html(DateConverter::utcToJalaliDateTime($ticketItem['created_at'])) ?>
                        </p>
                    </div>

                    <div class="ticket-items__item-content-wrapper">
                        <p class="ticket-items__item-content basalam-p">
                            <?php echo esc_html($ticketItem['content']) ?>
                        </p>
                        <?php
                        $itemFiles = $ticketItem['files'] ?? $ticketItem['media'] ?? $ticketItem['attachments'] ?? [];
                        if (!empty($itemFiles)):
                        ?>
                            <div class="ticket-items__item-files">
                                <?php foreach ($itemFiles as $file):
                                    $fileUrl = $file['url'] ?? $file['path'] ?? null;
                                    if (!$fileUrl) continue;
                                ?>
                                    <a href="<?php echo esc_url($fileUrl); ?>" target="_blank" class="ticket-items__item-file-link">
                                        <img src="<?php echo esc_url($fileUrl); ?>" alt="" class="ticket-items__item-file-img">
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php
        }
        ?>
    </div>
</div>
<?php if (!$isTicketClosed): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            ticketFileUpload('ticket-file-reply', '<?php echo wp_create_nonce('upload_ticket_media_nonce'); ?>');
        });
    </script>
<?php endif; ?>