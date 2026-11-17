<?php

namespace SyncBasalam\Services\Orders;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Jobs\Exceptions\JobException;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Utilities\OrderManagerUtilities;

class TrackingCodeOrderService
{
    public function trackingCodeOnBasalam(int $orderId, string $trackingCode, string $phoneNumber, int $shippingMethod)
    {
        $trackingCode = $this->normalizeInput($trackingCode);
        $phoneNumber = $this->normalizeInput($phoneNumber);

        if ($orderId <= 0) {
            return [
                'success'     => false,
                'message'     => 'شناسه سفارش نامعتبر است.',
                'status_code' => 400,
            ];
        }

        if ($trackingCode === '') {
            return [
                'success'     => false,
                'message'     => 'لطفاً کد رهگیری را وارد کنید.',
                'status_code' => 400,
            ];
        }

        if ($phoneNumber === '') {
            return [
                'success'     => false,
                'message'     => 'لطفاً شماره تلفن را وارد کنید.',
                'status_code' => 400,
            ];
        }

        if ($shippingMethod <= 0) {
            return [
                'success'     => false,
                'message'     => 'لطفاً روش ارسال را انتخاب کنید.',
                'status_code' => 400,
            ];
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return [
                'success'     => false,
                'message'     => 'سفارش یافت نشد.',
                'status_code' => 400,
            ];
        }

        global $wpdb;
        $invoiceId = (int) OrderManagerUtilities::getInvoiceId($wpdb, $orderId);
        if ($invoiceId <= 0) {
            return [
                'success'     => false,
                'message'     => 'شناسه فاکتور سفارش یافت نشد.',
                'status_code' => 400,
            ];
        }

        $response = $this->sendTrackingCodeToBasalam($invoiceId, $trackingCode, $phoneNumber, $shippingMethod);

        $statusCode = (int) ($response['status_code'] ?? 500);
        if ($statusCode !== 200 && $statusCode !== 201) {
            $errorMessage = $this->errorMessage($response);
            Logger::warning(sprintf('ثبت کد رهگیری سفارش ووکامرس %d (باسلام %d) تأیید نشد؛ وضعیت پاسخ: %d.', $orderId, $invoiceId, $statusCode));

            return [
                'success'     => false,
                'message'     => $errorMessage,
                'status_code' => $statusCode >= 400 && $statusCode <= 599 ? $statusCode : 502,
            ];
        }

        // Use the active WooCommerce datastore, including HPOS. A rejected or
        // uncertain remote write must never leave an apparently saved code.
        try {
            $order->update_meta_data('_basalam_order_tracking_code', $trackingCode);
            if (!$order->update_status('bslm-shipping', 'سفارش توسط ادمین ارسال شد.')) {
                throw new \RuntimeException('Order status was not saved.');
            }
        } catch (\Throwable $e) {
            Logger::error(sprintf('ارسال سفارش باسلام %d تأیید شد، اما ذخیره سفارش ووکامرس %d کامل نشد.', $invoiceId, $orderId));
            return [
                'success'     => false,
                'message'     => 'کد رهگیری در باسلام ثبت شد، اما ذخیره وضعیت سفارش در ووکامرس کامل نشد. کد را دوباره ارسال نکنید و وضعیت سفارش را بررسی کنید.',
                'status_code' => 500,
            ];
        }

        return [
            'success'     => true,
            'message'     => 'کد رهگیری و شماره تلفن با موفقیت ثبت و ارسال شد.',
            'status_code' => 200,
        ];
    }

    private function sendTrackingCodeToBasalam($invoiceId, $trackingCode, $phoneNumber, $shippingMethod)
    {
        $apiUrl = Endpoints::ORDER_TRACKING;
        $body = [
            'order_id'        => $invoiceId,
            'shipping_method' => $shippingMethod,
            'tracking_code'   => $trackingCode,
            'phone_number'    => $phoneNumber,
        ];

        try {
            $apiService = syncBasalamContainer()->get(ApiServiceManager::class);
            return $apiService->post($apiUrl, $body);
        } catch (\Throwable $e) {
            $statusCode = $e instanceof JobException ? (int) $e->getCode() : 500;
            return [
                'status_code' => $statusCode >= 400 && $statusCode <= 599 ? $statusCode : 500,
                'body' => 'خطا در ارسال کد رهگیری: ' . $e->getMessage(),
            ];
        }
    }

    private function errorMessage(array $response): string
    {
        $body = $response['body'] ?? null;
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) $body = $decoded;
        }
        if (is_array($body)) {
            $body = $body['errors'][0]['message'] ?? $body['messages'][0]['message'] ?? $body['message'] ?? null;
        }
        return is_string($body) && trim($body) !== '' ? $body : ($response['error'] ?? 'ثبت کد رهگیری در باسلام تأیید نشد. لطفاً وضعیت سفارش را در باسلام بررسی کنید.');
    }

    private function normalizeInput(string $value): string
    {
        $value = strtr($value, array_combine(
            preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789')
        ));
        return preg_replace('/[\s\x{200c}-\x{200f}\x{202a}-\x{202e}\x{2066}-\x{2069}\x{feff}]+/u', '', $value) ?? trim($value);
    }
}
