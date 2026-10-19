<?php

namespace SyncBasalam\SalamPay;

defined('ABSPATH') || exit;

final class PaymentService
{
    private const HOOK = 'sync_basalam_salampay_reconcile_payment';

    public static function start(int $orderId)
    {
        $lease = OrderLock::acquire($orderId);
        if (!$lease) {
            return self::error('busy', __('درخواست قبلی سفارش در حال بررسی است؛ کمی بعد دوباره تلاش کنید.', 'sync-basalam'));
        }
        try {
            $order = wc_get_order($orderId);
            if (!$order || $order->get_payment_method() !== 'salampay' || !$order->needs_payment()) {
                return self::error('order', __('سفارش برای پرداخت سلام‌پی معتبر نیست.', 'sync-basalam'));
            }
            if (!Settings::configured()) {
                return self::error('config', __('درگاه سلام‌پی تنظیم نشده است.', 'sync-basalam'));
            }
            $payload = self::payload($order);
            if (is_wp_error($payload)) {
                return $payload;
            }
            $previous = (string) $order->get_meta('_salampay_session_state');
            $key = (string) $order->get_meta('_salampay_session_key');
            if ($key !== '') {
                if ((int) $order->get_meta('_salampay_expected_amount') !== $payload['amount']
                    || (string) $order->get_meta('_salampay_currency') !== $order->get_currency()) {
                    return self::error('changed', __('مبلغ سفارش پس از شروع پرداخت تغییر کرده است؛ بررسی پشتیبانی لازم است.', 'sync-basalam'));
                }
                if ((int) $order->get_meta('_salampay_session_expires') <= time()) {
                    return self::error('expired', __('مهلت نشست پرداخت پایان یافته است؛ برای بررسی نتیجه با پشتیبانی تماس بگیرید.', 'sync-basalam'));
                }
                return self::redirect($key);
            }
            if (in_array($previous, ['creating', 'unknown', 'ready'], true)
                || $order->get_meta('_salampay_reference_id')) {
                return self::error('uncertain', __('پرداخت قبلی این سفارش نیاز به بررسی دارد؛ درخواست تکراری ارسال نشد.', 'sync-basalam'));
            }
            $order->update_meta_data('_salampay_reference_id', $orderId);
            $order->update_meta_data('_salampay_expected_amount', $payload['amount']);
            $order->update_meta_data('_salampay_currency', $order->get_currency());
            $order->update_meta_data('_salampay_session_state', 'creating');
            $order->save();
            self::schedule($orderId);
            $result = Settings::client()->createSession($payload);
            if (is_wp_error($result)) {
                $rejected = ApiClient::definitiveRejection($result);
                $order->update_meta_data('_salampay_session_state', $rejected ? 'rejected' : 'unknown');
                if ($rejected) {
                    $order->delete_meta_data('_salampay_reference_id');
                }
                $order->save();
                return self::error('create', $rejected
                    ? __('شروع پرداخت سلام‌پی ممکن نشد؛ تنظیمات درگاه و اطلاعات سفارش را بررسی کنید.', 'sync-basalam')
                    : __('نتیجه شروع پرداخت مشخص نیست و خودکار بررسی می‌شود؛ درخواست را دوباره ارسال نکنید.', 'sync-basalam'));
            }
            $session = $result['session'] ?? null;
            $expires = is_array($session) && is_string($session['expires_at'] ?? null) ? strtotime($session['expires_at']) : false;
            if (!is_array($session) || !is_string($session['key'] ?? null) || $session['key'] === ''
                || !self::positiveInteger($session['id'] ?? null)
                || !self::integerEquals($session['amount'] ?? null, $payload['amount'])
                || !self::integerEquals($session['reference_id'] ?? null, $orderId)
                || !$expires || $expires <= time()) {
                $order->update_meta_data('_salampay_session_state', 'unknown');
                $order->save();
                return self::error('session', __('پاسخ نشست سلام‌پی معتبر نیست؛ نتیجه پرداخت نیاز به بررسی دارد.', 'sync-basalam'));
            }
            $order->update_meta_data('_salampay_session_id', (int) $session['id']);
            $order->update_meta_data('_salampay_session_key', $session['key']);
            $order->update_meta_data('_salampay_session_expires', $expires);
            $order->update_meta_data('_salampay_session_state', 'ready');
            $order->save();
            $order->update_status('pending', __('مشتری به صفحه پرداخت سلام‌پی منتقل شد.', 'sync-basalam'));
            return self::redirect($session['key']);
        } finally {
            OrderLock::release($orderId, $lease);
        }
    }

    public static function payload($order)
    {
        try {
            $currency = $order->get_currency();
            $amount = Money::rial($order->get_total(), $currency);
            if ($amount <= 1000) {
                return self::error('amount', __('مبلغ پرداخت سلام‌پی باید بیشتر از ۱۰۰۰ ریال باشد.', 'sync-basalam'));
            }
            $mobile = self::mobile($order->get_billing_phone());
            if ($mobile !== '' && !preg_match('/^09[0-9]{9}$/D', $mobile)) {
                return self::error('mobile', __('شماره موبایل صورتحساب برای پرداخت سلام‌پی معتبر نیست.', 'sync-basalam'));
            }
            $items = [];
            $itemsTotal = 0;
            foreach ($order->get_items() as $itemId => $item) {
                $quantity = (int) $item->get_quantity();
                if ($quantity < 1 || (float) $item->get_quantity() !== (float) $quantity) {
                    return self::error('quantity', __('تعداد محصول برای پرداخت سلام‌پی باید عدد صحیح مثبت باشد.', 'sync-basalam'));
                }
                $subtotal = Money::rial((float) $item->get_subtotal() + (float) $item->get_subtotal_tax(), $currency);
                $unit = intdiv($subtotal, $quantity);
                $remainder = $subtotal % $quantity;
                $base = [
                    'id' => $item->get_variation_id() ?: ($item->get_product_id() ?: $itemId),
                    'title' => $item->get_name(), 'category' => ['id' => 1, 'title' => __('محصول ووکامرس', 'sync-basalam')],
                    'metadata' => ['order_item_id' => $itemId],
                ];
                // Split only the rounding remainder, keeping exact Rial totals.
                if ($quantity > $remainder) {
                    $items[] = $base + ['unit_price' => $unit, 'quantity' => $quantity - $remainder];
                }
                if ($remainder) {
                    $items[] = $base + ['unit_price' => $unit + 1, 'quantity' => $remainder];
                }
                $itemsTotal += $subtotal;
            }
            $discount = Money::rial((float) $order->get_discount_total() + (float) $order->get_discount_tax(), $currency);
            $difference = $amount + $discount - $itemsTotal;
            if ($difference > 0) {
                $items[] = ['id' => 0, 'title' => __('هزینه ارسال و سایر هزینه‌های سفارش', 'sync-basalam'),
                    'unit_price' => $difference, 'quantity' => 1, 'category' => ['id' => 1, 'title' => __('هزینه سفارش', 'sync-basalam')]];
            } elseif ($difference < 0) {
                $discount -= $difference; // Negative fees are an additional discount.
            }
            return [
                'amount' => $amount, 'reference_id' => $order->get_id(), 'mobile' => $mobile,
                'callback_url' => add_query_arg(['order_id' => $order->get_id(), 'key' => $order->get_order_key()], rest_url('salampay/v1/callback')),
                'items' => $items, 'discount' => $discount,
                'metadata' => (object) ['integration' => 'woosalam'],
            ];
        } catch (\InvalidArgumentException $e) {
            return self::error('currency', __('مبلغ یا واحد پول سفارش برای سلام‌پی معتبر نیست.', 'sync-basalam'));
        }
    }

    public static function verify(int $orderId)
    {
        $lease = OrderLock::acquire($orderId);
        if (!$lease) {
            return self::error('busy', __('پرداخت سفارش در حال بررسی است.', 'sync-basalam'), 409);
        }
        try {
            $order = wc_get_order($orderId);
            if (!$order || $order->get_payment_method() !== 'salampay' || !$order->get_meta('_salampay_reference_id')) {
                return self::error('order', __('سابقه پرداخت سلام‌پی پیدا نشد.', 'sync-basalam'), 404);
            }
            // Never overwrite an administrator's later completion/refund/cancellation.
            if ($order->get_meta('_salampay_paid') || $order->get_date_paid()) {
                return 'paid';
            }
            $reference = (int) $order->get_meta('_salampay_reference_id');
            $payment = Settings::client()->payment($reference);
            if (is_wp_error($payment)) {
                return self::error('inquiry', __('نتیجه پرداخت هنوز قابل تأیید نیست؛ دوباره بررسی کنید.', 'sync-basalam'), 503);
            }
            $expected = (int) $order->get_meta('_salampay_expected_amount');
            $current = Money::rial($order->get_total(), $order->get_currency());
            $expected = $expected ?: $current; // Existing standalone orders have no snapshot.
            if (!self::positiveInteger($payment['id'] ?? null)
                || !self::integerEquals($payment['reference_id'] ?? null, $reference)
                || !self::integerEquals($payment['amount'] ?? null, $expected)
                || $current !== $expected
                || ($order->get_meta('_salampay_currency') && $order->get_meta('_salampay_currency') !== $order->get_currency())
                || ($order->get_meta('_salampay_session_id') && isset($payment['session_id'])
                    && !self::integerEquals($payment['session_id'], (int) $order->get_meta('_salampay_session_id')))) {
                return self::error('mismatch', __('اطلاعات یا مبلغ پرداخت سلام‌پی با سفارش مطابقت ندارد؛ بررسی پشتیبانی لازم است.', 'sync-basalam'), 409);
            }
            $status = self::statusValue($payment['status'] ?? null);
            if ($status === '3') {
                if ($order->has_status(['cancelled', 'refunded'])) {
                    return self::error('closed', __('پرداخت برای سفارش بسته‌شده دریافت شده است؛ بررسی پشتیبانی لازم است.', 'sync-basalam'), 409);
                }
                $order->update_meta_data('_salampay_payment_id', (int) $payment['id']);
                $order->save();
                $order->payment_complete((string) $payment['id']);
                if (!$order->get_date_paid()) {
                    return self::error('completion', __('ثبت نتیجه پرداخت در سفارش کامل نشد؛ بررسی مجدد لازم است.', 'sync-basalam'), 503);
                }
                $order->update_meta_data('_salampay_paid', 'yes');
                $order->update_meta_data('_salampay_session_state', 'paid');
                $order->save();
                $configured = Settings::all()['success_order_status'] ?? 'auto';
                $configured = str_replace('wc-', '', (string) $configured);
                if (in_array($configured, ['processing', 'completed'], true) && !$order->has_status($configured)) {
                    $order->update_status($configured, __('پرداخت سلام‌پی تأیید شد.', 'sync-basalam'));
                }
                $order->add_order_note(__('پرداخت سلام‌پی پس از استعلام مبلغ و شناسه تأیید شد.', 'sync-basalam'));
                wp_clear_scheduled_hook(self::HOOK, [$orderId]);
                return 'paid';
            }
            $name = self::statusName($payment['status'] ?? null);
            if (in_array($name, ['FAILED', 'CANCELLED', 'CANCELED', 'EXPIRED'], true)) {
                $failedStatus = str_replace('wc-', '', (string) (Settings::all()['failed_order_status'] ?? 'failed'));
                $failedStatus = in_array($failedStatus, ['failed', 'cancelled'], true) ? $failedStatus : 'failed';
                if (!$order->has_status($failedStatus)) {
                    $order->update_status($failedStatus, __('سلام‌پی ناموفق بودن پرداخت را اعلام کرد.', 'sync-basalam'));
                }
                return 'failed';
            }
            // Unknown or in-progress statuses are never treated as failed payments.
            return 'pending';
        } catch (\InvalidArgumentException $e) {
            return self::error('currency', __('واحد پول سفارش با پرداخت سلام‌پی سازگار نیست.', 'sync-basalam'), 409);
        } finally {
            OrderLock::release($orderId, $lease);
        }
    }

    public static function reconcile(int $orderId): void
    {
        if (!Module::ownsPayments()) {
            return;
        }
        $result = self::verify($orderId);
        if ($result === 'paid') {
            return;
        }
        $order = wc_get_order($orderId);
        if (!$order || !$order->get_meta('_salampay_reference_id')) {
            return;
        }
        $attempts = (int) $order->get_meta('_salampay_inquiry_attempts') + 1;
        $order->update_meta_data('_salampay_inquiry_attempts', $attempts);
        $order->save();
        if ($attempts < 96) {
            self::schedule($orderId, 900);
        } elseif ($attempts === 96) {
            $order->add_order_note(__('پیگیری خودکار پرداخت سلام‌پی به نتیجه قطعی نرسید؛ از عملیات سفارش دوباره بررسی کنید.', 'sync-basalam'));
        }
    }

    public static function resume($order): void
    {
        if ($order->get_meta('_salampay_reference_id') && !$order->get_date_paid()
            && (int) $order->get_meta('_salampay_inquiry_attempts') < 96) {
            self::schedule($order->get_id());
        }
    }

    private static function schedule(int $orderId, int $delay = 60): void
    {
        if (!wp_next_scheduled(self::HOOK, [$orderId])) {
            wp_schedule_single_event(time() + $delay, self::HOOK, [$orderId]);
        }
    }

    private static function mobile(string $mobile): string
    {
        $mobile = strtr($mobile, array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
        $mobile = preg_replace('/[^0-9+]/', '', $mobile);
        return preg_replace('/^(?:\+98|0098|98)/', '0', $mobile);
    }

    public static function statusValue($status): string
    {
        $value = is_array($status) ? ($status['value'] ?? $status['id'] ?? '') : $status;
        return is_int($value) || is_string($value) ? (string) $value : '';
    }

    public static function statusName($status): string
    {
        return is_array($status) && is_string($status['name'] ?? null) ? strtoupper($status['name']) : '';
    }

    public static function positiveInteger($value): bool
    {
        return (is_int($value) && $value > 0)
            || (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) && strlen($value) <= 16 && (int) $value > 0);
    }

    public static function integerEquals($value, int $expected): bool
    {
        return self::positiveInteger($value) && (string) $value === (string) $expected;
    }

    private static function redirect(string $key): array
    {
        return ['result' => 'success', 'redirect' => 'https://salampay.basalam.com/payment-interface/' . rawurlencode($key)];
    }

    private static function error(string $code, string $message, int $status = 400): \WP_Error
    {
        return new \WP_Error('salampay_' . $code, $message, ['status' => $status]);
    }
}
