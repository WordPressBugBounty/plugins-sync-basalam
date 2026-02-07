<?php

defined('ABSPATH') || exit;

use SyncBasalam\Services\TicketServiceManager;
use SyncBasalam\Admin\Components;

$ticketManager = new TicketServiceManager();
$fetchTicketSubjects = $ticketManager->fetchTicketSubjects();

if (TicketServiceManager::isUnauthorized($fetchTicketSubjects)) {
    Components::renderUnauthorizedError();
    return;
}

$TicketSubjects = isset($fetchTicketSubjects['body']) ? json_decode($fetchTicketSubjects['body'], true) : null;
?>
<div class="basalam-container">
    <div class="create-ticket">
        <div class="basalam-header-data" style="justify-content: space-between;">
            <div style="display: flex !important;text-align: center;align-items: center;gap: 1.5rem;">
                <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/images/basalam.svg'); ?>" alt="Basalam">
                <div>
                    <h1 class="basalam-h basalam-text-justify ticket-header-data-heading">ایجاد تیکت</h1>
                    <p class="basalam-p basalam-margin-top-17 basalam-text-right">در این صفحه میتوانید تیکت خود را برای ما ارسال کنید.</p>
                </div>
            </div>
            <a class="basalam-p" style="color: black !important;direction: ltr;display:flex" href=" <?php echo esc_url(add_query_arg(['page' => 'sync_basalam_tickets', 'paged'  => 1,], admin_url('admin.php'))); ?>">بازگشت به لیست تیکت ها</a>
        </div>

        <?php if (!empty($TicketSubjects['data'])): ?>
        <div class="create-ticket__form-container">

            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="Basalam-form">
                <?php wp_nonce_field('create_ticket_nonce', '_wpnonce'); ?>
                <input type="hidden" name="action" value="create_ticket">
                <div class="create-ticket__inputs">
                    <div class="create-ticket__control">
                        <label for="ticket-title" class="create-ticket__label basalam-p">عنوان</label>
                        <input type="text" name="title" id="ticket-title" class="basalam-input create-ticket__input" minlength="3" maxlength="255" required>
                    </div>

                    <div class="create-ticket__control">
                        <label class="create-ticket__label basalam-p">موضوع</label>
                        <select name="subject" id="subject" class="basalam-select basalam-input create-ticket__input" required>
                            <?php foreach ($TicketSubjects['data'] as $subject): ?>
                                <option value="<?= esc_html($subject) ?>"><?= esc_html($subject) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="create-ticket__control">
                        <label for="content" class="create-ticket__label basalam-p ">توضیحات</label>
                        <textarea name="content" id="content" minlength="10" required class="basalam-input create-ticket__input create-ticket__textarea"></textarea>
                    </div>
                </div>
                <div class="create-ticket__actions">
                    <button type="submit" class="create-ticket__submit basalam-primary-button">ارسال</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>