<?php

namespace SyncBasalam\SalamGate\Contracts;

use SyncBasalam\SalamGate\Contracts\ParcelStatuses;

if (! defined('ABSPATH')) exit;

/**
 * دو adapter مستقل برای دو شکل پاسخ متفاوت — هرگز با یک فرض مشترک پردازش نمی‌شوند:
 *
 * - رکورد لیست (GET /v1/parcels → ParcelResponse):
 *   شامل market/external_id/tracking_code/invoice/category/updated_at.
 * - جزئیات (GET /v1/parcels/{id} یا GET /v1/markets/{m}/parcels/{ext} → ParcelDetailResponse):
 *   بدون tracking_code/invoice/market/external_id/category/updated_at.
 *
 * نبودن این فیلدها در پاسخ معتبر است و مقدار null برمی‌گردد — جعل نمی‌شود.
 */
final class ParcelViewNormalizer
{
    /**
     * رکورد لیست → ساختار نمایش.
     *
     * @return array{id:int, external_id:?int, market:string, receiver_name:string, delivery_node:string,
     *   status_id:int, status_title:string, status_label:string, weight:int, value_toman:int|float|null, shipping_cost_rial:?int, shipping_cost_toman:int|float|null,
     *   tracking_code:?string, invoice_status:?int, items_count:int, created_at:string, is_liquid:bool, is_fragile:bool}
     */
    public static function fromListItem(array $parcel): array
    {
        $statusId = (int) ($parcel['status']['id'] ?? 0);
        $market = (string) ($parcel['market']['value'] ?? '');
        $value = self::unsignedInteger($parcel['value'] ?? null);
        $weight = self::unsignedInteger($parcel['weight'] ?? null);
        $category = $parcel['category']['id'] ?? null;
        $categoryId = (is_int($category) || is_string($category)) ? filter_var($category, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $invoiceStatus = $parcel['invoice']['status'] ?? null;
        $invoiceStatus = (is_int($invoiceStatus) || (is_string($invoiceStatus) && preg_match('/^[1-9]\d*$/D', $invoiceStatus)))
            ? filter_var($invoiceStatus, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $tracking = isset($parcel['tracking_code']) && $parcel['tracking_code'] !== null && $parcel['tracking_code'] !== ''
            ? (string) $parcel['tracking_code']
            : null;
        $invoiceId = $parcel['invoice']['id'] ?? null;
        $invoiceId = (is_int($invoiceId) || is_string($invoiceId))
            ? filter_var($invoiceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        return [
            'id'            => (int) ($parcel['id'] ?? 0),
            'external_id'   => isset($parcel['external_id']) ? (int) $parcel['external_id'] : null,
            'market'        => $market,
            'category_id'   => $categoryId === false ? null : $categoryId,
            'receiver_name' => (string) ($parcel['shipping_info']['receiver']['full_name'] ?? '—'),
            'delivery_node' => (string) ($parcel['shipping_info']['delivery_node']['title'] ?? ''),
            'status_id'     => $statusId,
            'status_title'  => (string) ($parcel['status']['title'] ?? ParcelStatuses::title($statusId)),
            // برچسب نمایشی از ماشین وضعیت (یکدست)؛ عنوان خام سرور فقط fallback وضعیت ناشناخته.
            'status_label'  => ParcelStatuses::isValid($statusId)
                ? ParcelStatuses::title($statusId)
                : (string) ($parcel['status']['title'] ?? 'نامشخص'),
            'weight'        => $weight ?? 0,
            'value_rial'    => $value,
            'value_toman'   => $value !== null ? ($value % 10 === 0 ? intdiv($value, 10) : $value / 10) : null,
            // Invoice amount is the actual final shipping charge after service discounts.
            'shipping_cost_rial' => self::invoiceAmountRial($parcel),
            'shipping_cost_toman' => self::invoiceAmountToman($parcel),
            'tracking_code' => $tracking,
            'invoice_status' => $invoiceStatus === false ? null : $invoiceStatus,
            'invoice_id' => $invoiceId === false ? null : $invoiceId,
            'invoice_created_at' => is_string($parcel['invoice']['created_at'] ?? null) ? $parcel['invoice']['created_at'] : null,
            'items_count'   => count((array) ($parcel['items'] ?? [])),
            'created_at'    => (string) ($parcel['created_at'] ?? ''),
            'updated_at'    => is_string($parcel['updated_at'] ?? null) ? $parcel['updated_at'] : null,
            'is_liquid'     => !empty($parcel['is_liquid']),
            'is_fragile'    => !empty($parcel['is_fragile']),
        ];
    }

    /** API integer fields must not become trusted amounts/weights through a lossy cast. */
    private static function unsignedInteger($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value))) { return null; }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $number === false ? null : $number;
    }

    /** Raw authoritative invoice amount; invalid/missing amounts never become zero. */
    public static function invoiceAmountRial(array $parcel): ?int
    {
        $amount = $parcel['invoice']['amount'] ?? null;
        if (!is_int($amount) && !(is_string($amount) && preg_match('/^(0|[1-9]\d*)$/D', $amount))) { return null; }
        $amount = filter_var($amount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $amount === false ? null : $amount;
    }

    /** @return int|float|null */
    private static function invoiceAmountToman(array $parcel)
    {
        $amount = self::invoiceAmountRial($parcel);
        return $amount === null ? null : ($amount % 10 === 0 ? intdiv($amount, 10) : $amount / 10);
    }

    /**
     * پاسخ جزئیات → همان ساختار نمایش، با null صریح برای فیلدهایی که در این شکل وجود ندارند.
     */
    public static function fromDetail(array $detail): array
    {
        $view = self::fromListItem($detail);

        // ParcelDetailResponse این فیلدها را ندارد — به null صریح تبدیل می‌شود (نه مقدار غلط):
        $view['external_id'] = null;
        $view['market'] = '';
        $view['tracking_code'] = null;
        $view['invoice_status'] = null;
        $view['invoice_id'] = null;
        $view['invoice_created_at'] = null;
        $view['shipping_cost_rial'] = null;
        $view['shipping_cost_toman'] = null;

        return $view;
    }

    /**
     * استخراج آیتم موردنظر از پاسخ لیست بر اساس شناسه — تطبیق صریح، نه data[0].
     */
    public static function findInListItems(array $listBody, int $parcelId): ?array
    {
        foreach ((array) ($listBody['data'] ?? []) as $parcel) {
            if (is_array($parcel) && (int) ($parcel['id'] ?? 0) === $parcelId) {
                return $parcel;
            }
        }

        return null;
    }
}
