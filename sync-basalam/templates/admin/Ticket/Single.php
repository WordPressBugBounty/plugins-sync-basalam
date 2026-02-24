<?php

use SyncBasalam\Services\TicketServiceManager;
use SyncBasalam\Utilities\DateConverter;
use SyncBasalam\Admin\Components\CommonComponents;
use SyncBasalam\Utilities\TicketUserResolver;
defined('ABSPATH') || exit;

$ticket_id = $_GET['ticket_id'] ?? 0;

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

?>
<div class="basalam-container">
    <div class="ticket-items__answer">
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="Basalam-form">
            <header class="ticket-items__answer-header">
                <h2 class="basalam-h">پاسخ تیکت</h2>
                <a class="basalam-p" style="color: black !important;direction: ltr;display:flex" href=" <?php echo esc_url(add_query_arg(['page' => 'sync_basalam_tickets', 'paged'  => 1,], admin_url('admin.php'))); ?>">بازگشت به لیست تیکت ها</a>
            </header>
            <?php wp_nonce_field('create_ticket_item_nonce', '_wpnonce'); ?>
            <input type="hidden" name="action" value="create_ticket_item">
            <input type="hidden" name="ticket_id" value="<?php echo $_GET['ticket_id'] ?>">
            <div class="ticket-items__answer-inputs">
                <div class="ticket-items__answer-control">
                    <label for="ticket-answer-textarea" class="ticket-items__answer-control-label basalam-p">متن پاسخ خود را وارد کنید</label>
                    <textarea id="ticket-answer-textarea" name="content" class="basalam-input ticket-items__answer-input"></textarea>
                </div>
                <div class="ticket-items__answer-control">
                    <label class="ticket-items__answer-control-label basalam-p">پیوست تصویر (اختیاری)</label>
                    <div class="ticket-file-upload" id="ticket-file-upload-reply">
                        <label for="ticket-file-reply" class="ticket-file-upload__label">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
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
                        <p class="ticket-items__item-name basalam-p"><?php echo esc_html($creatorUser);?></p>

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
<script>
ticketFileUpload('ticket-file-reply', '<?php echo wp_create_nonce('upload_ticket_media_nonce'); ?>');
</script>
