<?php

use SyncBasalam\Services\TicketServiceManager;
use SyncBasalam\Utilities\DateConverter;
use SyncBasalam\Admin\Components;

defined('ABSPATH') || exit;

$page = $_GET['paged'] ?? 1;
$ticketManager = new TicketServiceManager();
$fetchTickets = $ticketManager->fetchAllTickets($page);
$statusMap = TicketServiceManager::ticketStatuses();

if (TicketServiceManager::isUnauthorized($fetchTickets)) {
    Components::renderUnauthorizedError();
    return;
}

$tickets = isset($fetchTickets['body']) ? json_decode($fetchTickets['body'], true) : null;
?>
<div class="basalam-container">
    <div class="basalam-header basalam-margin-top-17">
        <div class="basalam-header-data ticket-header-data">
            <div class="ticket-header-information">
                <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/images/basalam.svg'); ?>" alt="Basalam">
                <div>
                    <h1 class="basalam-h basalam-text-justify ticket-header-data-heading">پشتیبانی ووسلام</h1>
                    <p class="basalam-p basalam-margin-top-17 basalam-text-right">در این صفحه میتوانید با پشتیبانی ووسلام ارتباط بگیرید</p>
                </div>
            </div>

            <a href="<?php echo esc_url(add_query_arg(['page' => 'sync_basalam_new_ticket',], admin_url('admin.php'))); ?>" class="basalam-primary-button basalam-p basalam-btn-no-margin-bottom">ایجاد تیکت جدید</a>
        </div>

    </div>
</div>
<?php
?>
<div class="basalam-container ticket-container">

    <div class="ticket-list">
        <?php
        if (!empty($tickets['data'])) {
            foreach ($tickets['data'] as $ticket) {
        ?>
                <div class="ticket-card">
                    <div class="ticket-card__field">
                        <p class="basalam-p basalam-p__right-align ticket-card__field-label">عنوان:</p>
                        <p class="basalam-p basalam-p__right-align ticket-card__field-value"><?php echo $ticket['title'] ?></p>
                    </div>
                    <div class="ticket-card__field">
                        <p class="basalam-p basalam-p__right-align ticket-card__field-label">موضوع:</p>
                        <p class="basalam-p basalam-p__right-align ticket-card__field-value"><?php echo $ticket['subject'] ?></p>
                    </div>
                    <div class="ticket-card__field">
                        <p class="basalam-p basalam-p__right-align ticket-card__field-label">وضعیت:</p>
                        <p class="basalam-p basalam-p__right-align ticket-card__field-value ticket-card__field-status ticket-card__field-status--pending">
                            <?php echo $statusMap[$ticket['status']] ?? $ticket['status']; ?></p>
                    </div>
                    <div class="ticket-card__field">
                        <p class="basalam-p basalam-p__right-align ticket-card__field-label">آخرین آپدیت:</p>
                        <p class="basalam-p basalam-p__right-align ticket-card__field-value"><?php echo DateConverter::utcToJalaliDateTime($ticket['updated_at']) ?></p>
                    </div>
                    <div class="ticket-card__field ticket-card__actions">
                        <a class="basalam-primary-button basalam-p basalam-btn-no-margin-bottom" href="<?php echo esc_url(add_query_arg(['page' => 'sync_basalam_ticket', 'ticket_id'  => $ticket['id'],], admin_url('admin.php'))); ?>">مشاهده تیکت</a>
                    </div>
                </div>

            <?php
            }

            ?>
    </div>
</div>

<div class="pagination">

    <?php
            $total_page = $tickets['total_page'];
            $current_page = $tickets['page'] ?? 0;

            if (1 < $current_page) {
    ?>
        <a class="pagination-link basalam-p" href="<?php echo esc_url(add_query_arg(['page' => 'sync_basalam_tickets', 'paged'  => $page - 1,], admin_url('admin.php'))); ?>"
            class="log-basalam-page-link-modern">
            قبلی
        </a>
    <?php
            }
            if ($total_page > $current_page) {
    ?>
        <a class="pagination-link basalam-p" href=" <?php echo esc_url(add_query_arg(['page' => 'sync_basalam_tickets', 'paged'  => $page + 1,], admin_url('admin.php'))); ?>"
            class="log-basalam-page-link-modern">
            بعدی
        </a>
</div>
<?php
            }
        } else {
?>
<p class="basalam-p basalam-margin-top-17" style="text-align: center !important;display: flex;justify-content: center;">تیکتی یافت نشد.</p>
<?php
        }
