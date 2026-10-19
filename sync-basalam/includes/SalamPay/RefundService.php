<?php

namespace SyncBasalam\SalamPay;

defined('ABSPATH') || exit;

/** Durable refund intents; only confirmed settlement creates a WooCommerce refund. */
final class RefundService
{
    private const HOOK = 'sync_basalam_salampay_reconcile_refund';
    private const META = '_salampay_refunds';
    private static array $contexts = [];
    private static ?array $settling = null;

    public static function captureContext($refund, array $args): void
    {
        if (self::$settling !== null) {
            $refund->update_meta_data('_salampay_refund_reference_id', self::$settling['reference']);
            $refund->set_refunded_payment(true);
            $refund->set_refunded_by(self::$settling['user_id']);
        } elseif (!empty($args['refund_payment'])) {
            self::$contexts[(int) $args['order_id']] = ['refund' => $refund, 'args' => $args];
        }
    }

    public static function request(int $orderId, $amount = null, string $reason = '')
    {
        $lease = OrderLock::acquire($orderId);
        if (!$lease) {
            return self::error('busy', __('درخواست مالی دیگری برای این سفارش در حال بررسی است.', 'sync-basalam'));
        }
        try {
            $order = wc_get_order($orderId);
            if (!$order || $order->get_payment_method() !== 'salampay'
                || (!$order->get_date_paid() && !$order->get_meta('_salampay_paid'))) {
                return self::error('unpaid', __('فقط سفارش پرداخت‌شده با سلام‌پی قابل بازگشت وجه است.', 'sync-basalam'));
            }
            $records = self::records($order);
            foreach ($records as $record) {
                if (!in_array($record['status'], ['succeeded', 'failed'], true)) {
                    self::schedule($orderId);
                    return self::error('pending', __('درخواست قبلی بازگشت وجه این سفارش هنوز در حال بررسی است؛ آن را تکرار نکنید.', 'sync-basalam'));
                }
            }
            $context = self::$contexts[$orderId] ?? null;
            $available = (float) $order->get_remaining_refund_amount();
            // WC saves a provisional refund before calling the gateway; it will
            // delete it on our pending response. Exclude that record from totals.
            if ($context && $context['refund']->get_id()) {
                foreach ($order->get_refunds() as $refund) {
                    if ($refund->get_id() === $context['refund']->get_id()) {
                        $available += (float) $refund->get_amount();
                        break;
                    }
                }
            }
            $currency = $order->get_currency();
            $requested = Money::rial($amount === null ? $available : $amount, $currency);
            $paidCurrency = (string) $order->get_meta('_salampay_currency');
            $paidAmount = (int) $order->get_meta('_salampay_expected_amount') ?: Money::rial($order->get_total(), $currency);
            $refundedAmount = Money::rial(max(0, (float) $order->get_total() - $available), $currency);
            $remainingPaid = max(0, $paidAmount - $refundedAmount);
            if (($paidCurrency !== '' && $paidCurrency !== $currency) || $requested <= 0
                || $requested > Money::rial($available, $currency) || $requested > $remainingPaid) {
                return self::error('amount', __('مبلغ بازگشت وجه از مانده قابل استرداد سفارش بیشتر است یا معتبر نیست.', 'sync-basalam'));
            }
            $reference = (int) $order->get_meta('_salampay_reference_id');
            if ($reference <= 0) {
                return self::error('reference', __('شناسه مرجع پرداخت سلام‌پی پیدا نشد.', 'sync-basalam'));
            }
            if (trim((string) (Settings::all()['client_secret'] ?? '')) === '') {
                return self::error('config', __('درگاه سلام‌پی تنظیم نشده است.', 'sync-basalam'));
            }
            $refundReference = random_int(1000000000000, 9007199254740991);
            $description = sanitize_text_field($reason) ?: sprintf(__('بازگشت وجه سفارش #%d', 'sync-basalam'), $orderId);
            $records[$refundReference] = [
                'reference' => $refundReference, 'amount' => $requested, 'currency' => $currency,
                'reason' => $description, 'status' => 'unknown', 'attempts' => 0,
                'created_at' => time(), 'user_id' => get_current_user_id(),
                'line_items' => $context['args']['line_items'] ?? [],
                'restock_items' => !empty($context['args']['restock_items']),
            ];
            $order->update_meta_data(self::META, $records);
            $order->save(); // Persist before the network call; a lost response is never retried as a new POST.
            self::schedule($orderId);
            $result = Settings::client()->refund([
                'pay_reference_id' => $reference, 'reference_id' => $refundReference,
                'amount' => $requested, 'description' => $description,
            ]);
            if (is_wp_error($result)) {
                if (ApiClient::definitiveRejection($result)) {
                    $records[$refundReference]['status'] = 'failed';
                    $order->update_meta_data(self::META, $records);
                    $order->save();
                    $order->add_order_note(__('سلام‌پی درخواست بازگشت وجه را نپذیرفت؛ تنظیمات و مانده پرداخت را بررسی کنید.', 'sync-basalam'));
                    return $result;
                }
                $order->add_order_note(__('نتیجه ارسال درخواست بازگشت وجه سلام‌پی مشخص نیست؛ استعلام خودکار انجام می‌شود و درخواست دوباره ارسال نخواهد شد.', 'sync-basalam'));
                return ['status' => 'unknown', 'reference' => $refundReference];
            }
            $records[$refundReference]['status'] = 'pending';
            $order->update_meta_data(self::META, $records);
            $order->save();
            $order->add_order_note(sprintf(__('درخواست بازگشت وجه سلام‌پی ثبت شد و در انتظار تأیید است. شناسه پیگیری: %s', 'sync-basalam'), (string) $refundReference));
            return ['status' => 'pending', 'reference' => $refundReference];
        } catch (\InvalidArgumentException $e) {
            return self::error('currency', __('مبلغ یا واحد پول بازگشت وجه معتبر نیست.', 'sync-basalam'));
        } finally {
            unset(self::$contexts[$orderId]);
            OrderLock::release($orderId, $lease);
        }
    }

    public static function cancelled(int $orderId): void
    {
        if ((Settings::all()['auto_refund_cancelled'] ?? 'no') !== 'yes') {
            return;
        }
        $order = wc_get_order($orderId);
        // is_paid() is false after a paid order changes to cancelled.
        if (!$order || $order->get_payment_method() !== 'salampay' || !$order->get_date_paid()
            || (float) $order->get_remaining_refund_amount() <= 0) {
            return;
        }
        $result = self::request($orderId, null, __('بازگشت مانده وجه سفارش لغوشده', 'sync-basalam'));
        if (is_wp_error($result)) {
            $order->add_order_note($result->get_error_message());
        }
    }

    public static function adminDetails($order): void
    {
        if (!$order || $order->get_payment_method() !== 'salampay') {
            return;
        }
        $records = self::records($order);
        if (!$records) {
            return;
        }
        $labels = [
            'pending' => __('در انتظار تأیید', 'sync-basalam'),
            'unknown' => __('در حال پیگیری نتیجه', 'sync-basalam'),
            'settled' => __('تأییدشده؛ نیازمند ثبت در سفارش', 'sync-basalam'),
            'succeeded' => __('مستردشده', 'sync-basalam'), 'failed' => __('ناموفق', 'sync-basalam'),
        ];
        echo '<div class="clear"></div><p><strong>' . esc_html__('بازگشت وجه سلام‌پی', 'sync-basalam') . '</strong></p>';
        foreach ($records as $reference => $record) {
            echo '<p>' . esc_html((string) $reference) . ' — '
                . wp_kses_post(wc_price(Money::fromRial((int) $record['amount'], $record['currency']), ['currency' => $record['currency']]))
                . ' — ' . esc_html($labels[$record['status']] ?? $labels['unknown']) . '</p>';
            if (!in_array($record['status'], ['succeeded', 'failed'], true) && (int) ($record['attempts'] ?? 0) < 96) {
                self::schedule($order->get_id());
            }
        }
    }

    public static function resume($order): void
    {
        foreach (self::records($order) as $record) {
            if (!in_array($record['status'], ['succeeded', 'failed'], true) && (int) ($record['attempts'] ?? 0) < 96) {
                self::schedule($order->get_id());
                break;
            }
        }
    }

    public static function reconcile(int $orderId): void
    {
        if (!Module::ownsPayments()) {
            return;
        }
        $lease = OrderLock::acquire($orderId);
        if (!$lease) {
            self::schedule($orderId);
            return;
        }
        try {
            $order = wc_get_order($orderId);
            if (!$order || $order->get_payment_method() !== 'salampay') {
                return;
            }
            $records = self::records($order);
            $pending = false;
            foreach ($records as $reference => &$record) {
                if (in_array($record['status'], ['succeeded', 'failed'], true)) {
                    continue;
                }
                $record['attempts'] = (int) ($record['attempts'] ?? 0) + 1;
                $result = Settings::client()->refundStatus((int) $reference);
                if (!is_wp_error($result)) {
                    $adjustment = $result['adjustment'] ?? null;
                    $payReference = (int) $order->get_meta('_salampay_reference_id');
                    if (is_array($adjustment)
                        && PaymentService::integerEquals($adjustment['reference_id'] ?? null, (int) $reference)
                        && PaymentService::integerEquals($adjustment['amount'] ?? null, (int) $record['amount'])
                        && PaymentService::integerEquals($adjustment['pay']['reference_id'] ?? null, $payReference)
                        && (!$order->get_meta('_salampay_payment_id') || PaymentService::integerEquals(
                            $adjustment['pay_id'] ?? null, (int) $order->get_meta('_salampay_payment_id')))) {
                        if (PaymentService::statusValue($result['final_status'] ?? null) === '3') {
                            $record['status'] = 'settled';
                            // Save the confirmed result before creating the WC record.
                            $order->update_meta_data(self::META, $records);
                            $order->save();
                            $local = self::settle($order, $record);
                            if (!is_wp_error($local)) {
                                $record['status'] = 'succeeded';
                                $record['wc_refund_id'] = $local;
                                $order = wc_get_order($orderId);
                                $order->add_order_note(sprintf(__('بازگشت وجه سلام‌پی تأیید و در ووکامرس ثبت شد. شناسه پیگیری: %s', 'sync-basalam'), (string) $reference));
                            }
                        } elseif (in_array(PaymentService::statusName($result['final_status'] ?? null), ['FAILED', 'CANCELLED', 'CANCELED', 'REJECTED'], true)) {
                            $record['status'] = 'failed';
                            $order->add_order_note(sprintf(__('سلام‌پی ناموفق بودن بازگشت وجه را اعلام کرد. شناسه پیگیری: %s', 'sync-basalam'), (string) $reference));
                        }
                    }
                }
                if (!in_array($record['status'], ['succeeded', 'failed'], true)) {
                    $pending = $pending || $record['attempts'] < 96;
                    if ($record['attempts'] === 96) {
                        $order->add_order_note(__('بازگشت وجه سلام‌پی هنوز قطعی نشده است؛ از عملیات سفارش دوباره بررسی کنید.', 'sync-basalam'));
                    }
                }
            }
            unset($record);
            $order->update_meta_data(self::META, $records);
            $order->save();
            if ($pending) {
                self::schedule($orderId, 900);
            }
        } finally {
            OrderLock::release($orderId, $lease);
        }
    }

    private static function settle($order, array $record)
    {
        foreach ($order->get_refunds() as $refund) {
            if (!empty($record['legacy_wc_refund_id']) && $refund->get_id() === (int) $record['legacy_wc_refund_id']) {
                $refund->update_meta_data('_salampay_refund_reference_id', $record['reference']);
                $refund->set_refunded_payment(true);
                $refund->save();
                return $refund->get_id();
            }
            if ((string) $refund->get_meta('_salampay_refund_reference_id') === (string) $record['reference']) {
                return $refund->get_id(); // Recover a crash between WC save and ledger save.
            }
        }
        if ($order->get_currency() !== $record['currency']) {
            return self::error('changed_currency', __('واحد پول سفارش پس از درخواست بازگشت وجه تغییر کرده است.', 'sync-basalam'));
        }
        self::$settling = ['reference' => $record['reference'], 'user_id' => $record['user_id'] ?: 1];
        try {
            $refund = wc_create_refund([
                'order_id' => $order->get_id(), 'amount' => Money::fromRial((int) $record['amount'], $record['currency']),
                'reason' => $record['reason'], 'refund_payment' => false,
                'line_items' => $record['line_items'], 'restock_items' => $record['restock_items'],
            ]);
            return is_wp_error($refund) ? $refund : $refund->get_id();
        } finally {
            self::$settling = null;
        }
    }

    private static function records($order): array
    {
        $records = $order->get_meta(self::META);
        if (is_array($records) && $records) {
            return $records;
        }
        // The supplied standalone version stored one async intent per order.
        $reference = (int) $order->get_meta('_salampay_refund_reference_id');
        $amount = (int) $order->get_meta('_salampay_refund_amount');
        if ($reference > 0 && $amount > 0) {
            $records = [$reference => [
                'reference' => $reference, 'amount' => $amount, 'currency' => $order->get_currency(),
                'reason' => __('پیگیری بازگشت وجه نسخه مستقل سلام‌پی', 'sync-basalam'),
                'status' => 'unknown', 'attempts' => 0, 'created_at' => time(), 'user_id' => 1,
                'line_items' => [], 'restock_items' => false,
            ]];
            // Original manual refunds may already have a finalized WC record.
            foreach ($order->get_refunds() as $refund) {
                if (Money::rial($refund->get_amount(), $order->get_currency()) === $amount) {
                    $records[$reference]['legacy_wc_refund_id'] = $refund->get_id();
                    break;
                }
            }
            $order->update_meta_data(self::META, $records);
            $order->save();
            return $records;
        }
        return [];
    }

    private static function schedule(int $orderId, int $delay = 60): void
    {
        if (!wp_next_scheduled(self::HOOK, [$orderId])) {
            wp_schedule_single_event(time() + $delay, self::HOOK, [$orderId]);
        }
    }

    private static function error(string $code, string $message): \WP_Error
    {
        return new \WP_Error('salampay_refund_' . $code, $message);
    }
}
