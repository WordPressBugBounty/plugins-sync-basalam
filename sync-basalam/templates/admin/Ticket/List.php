<?php

use SyncBasalam\Services\TicketServiceManager;
use SyncBasalam\Utilities\DateConverter;
use SyncBasalam\Admin\Components;

defined('ABSPATH') || exit;

$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

$ticketManager = new TicketServiceManager();
$fetchTickets  = $ticketManager->fetchAllTickets($page);
$statusMap     = TicketServiceManager::ticketStatuses();

if (TicketServiceManager::isUnauthorized($fetchTickets)) {
    Components::renderUnauthorizedError();
    return;
}

$tickets = isset($fetchTickets['body']) ? json_decode($fetchTickets['body'], true) : [];

$total_pages  = intval($tickets['total_page'] ?? 1);
$current_page = intval($tickets['page'] ?? $page);


$base_url = add_query_arg(['page' => 'sync_basalam_tickets'],admin_url('admin.php'));

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

            <a href="<?php echo esc_url(add_query_arg(['page' => 'sync_basalam_new_ticket'],admin_url('admin.php'))); ?>"class="basalam-primary-button basalam-p basalam-btn-no-margin-bottom">ایجاد تیکت جدید
            </a>
        </div>
    </div>
</div>


<div class="basalam-container ticket-container">
    <div class="ticket-list">
        <?php if (!empty($tickets['data'])): ?>
            <?php foreach ($tickets['data'] as $ticket): ?>
                <div class="ticket-card">
                    <div class="ticket-card__field">
                        <p class="basalam-p ticket-card__field-label">عنوان:</p>
                        <p class="basalam-p ticket-card__field-value"> <?php echo esc_html($ticket['title'] ?? ''); ?> </p>
                    </div>

                    <div class="ticket-card__field">
                        <p class="basalam-p ticket-card__field-label">موضوع:</p>
                        <p class="basalam-p ticket-card__field-value"><?php echo esc_html($ticket['subject'] ?? ''); ?></p>
                    </div>

                    <div class="ticket-card__field">
                        <p class="basalam-p ticket-card__field-label">وضعیت:</p>
                        <p class="basalam-p ticket-card__field-value ticket-card__field-status"> <?php echo esc_html($statusMap[$ticket['status'] ?? ''] ?? ($ticket['status'] ?? ''));?> </p>
                    </div>

                    <div class="ticket-card__field">
                        <p class="basalam-p ticket-card__field-label">آخرین آپدیت:</p>
                        <p class="basalam-p ticket-card__field-value"><?php echo esc_html(DateConverter::utcToJalaliDateTime($ticket['updated_at'] ?? ''));?></p>
                    </div>

                    <div class="ticket-card__field ticket-card__actions">
                        <a
                            class="basalam-primary-button basalam-p basalam-btn-no-margin-bottom"
                            href="<?php echo esc_url(add_query_arg(
                                [
                                    'page'      => 'sync_basalam_ticket',
                                    'ticket_id' => intval($ticket['id'] ?? 0),
                                ],
                                admin_url('admin.php')
                            )); ?>"
                        >
                            مشاهده تیکت
                        </a>
                    </div>
                </div>

            <?php endforeach; ?>
        <?php else: ?>
            <p class="basalam-p basalam-margin-top-17" style="text-align:center;">تیکتی یافت نشد.</p>
        <?php endif; ?>
    </div>
</div>


<?php if ($total_pages > 1): ?>

<div class="pagination">

<?php

$range = 2;

if ($current_page > 1) {
    echo '<a class="pagination-link basalam-p" href="' . esc_url(add_query_arg('paged', $current_page - 1, $base_url)) . '">قبلی</a>';
}


if ($current_page > ($range + 1)) {
    echo '<a class="pagination-link basalam-p" href="' . esc_url(add_query_arg('paged', 1, $base_url)) .'">1</a>';
}


if ($current_page > ($range + 2)) {
    echo '<span class="pagination-dots">...</span>';
}


$start = max(1, $current_page - $range);
$end   = min($total_pages, $current_page + $range);

for ($i = $start; $i <= $end; $i++) {
    $class = 'pagination-link basalam-p';
    if ($i === $current_page) $class .= ' pagination-link--active';

    echo '<a class="' . esc_attr($class) . '" href="' . esc_url(add_query_arg('paged', $i, $base_url)) . '">' . $i . '</a>';
}


if ($current_page < ($total_pages - $range - 1)) {
    echo '<span class="pagination-dots">...</span>';
}


if ($current_page < ($total_pages - $range)) {
    echo '<a class="pagination-link basalam-p" href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '">' . $total_pages . '</a>';
}


if ($current_page < $total_pages) {
    echo '<a class="pagination-link basalam-p" href="' . esc_url(add_query_arg('paged', $current_page + 1, $base_url)) . '">بعدی</a>';
}
?>

</div>

<?php endif; ?>