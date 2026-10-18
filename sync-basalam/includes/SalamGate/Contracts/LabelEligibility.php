<?php

namespace SyncBasalam\SalamGate\Contracts;

defined('ABSPATH') || exit;

/** Printing is available only with a paid invoice and an issued postal receipt. */
final class LabelEligibility
{
    /** Local order metadata is an additional preflight, not invoice proof. */
    public static function hasPrintableReceipt($id, $status, $tracking): bool
    {
        $id = self::integer($id);
        $status = self::integer($status);

        return $id !== null && $id > 0 && $status !== null
            && !self::cancellationBlocksPrint($id)
            && ParcelStatuses::hasReceipt($status)
            && is_string($tracking) && trim($tracking) !== '';
    }

    public static function canPrint(array $parcel): bool
    {
        return is_int($parcel['id'] ?? null) && $parcel['id'] > 0
            && !self::cancellationBlocksPrint($parcel['id'])
            && ($parcel['invoice_status'] ?? null) === 2
            && is_int($parcel['status_id'] ?? null)
            && ParcelStatuses::hasReceipt($parcel['status_id'])
            && is_string($parcel['tracking_code'] ?? null)
            && trim($parcel['tracking_code']) !== '';
    }

    /** Check the API list shape before lossy casts can turn malformed data into proof. */
    public static function canPrintListItem(array $parcel): bool
    {
        $status = is_array($parcel['status'] ?? null) ? ($parcel['status']['id'] ?? null) : null;
        $invoice = is_array($parcel['invoice'] ?? null) ? ($parcel['invoice']['status'] ?? null) : null;

        return self::canPrint([
            'id' => self::integer($parcel['id'] ?? null),
            'status_id' => self::integer($status),
            'invoice_status' => self::integer($invoice),
            'tracking_code' => $parcel['tracking_code'] ?? null,
        ]);
    }

    /** Completed registration is separate from printing: a previously paid voided parcel stays in history. */
    public static function isRegisteredListItem(array $parcel): bool
    {
        $id = self::integer($parcel['id'] ?? null);
        $status = self::integer(is_array($parcel['status'] ?? null) ? ($parcel['status']['id'] ?? null) : null);
        $invoice = self::integer(is_array($parcel['invoice'] ?? null) ? ($parcel['invoice']['status'] ?? null) : null);
        return $id !== null && $id > 0 && $invoice === 2 && $status !== null
            && (ParcelStatuses::hasReceipt($status) || ParcelStatuses::isVoided($status))
            && is_string($parcel['tracking_code'] ?? null) && trim($parcel['tracking_code']) !== '';
    }

    /** Distinguish a valid unfinished parcel from a malformed successful API response. */
    public static function validListShape(array $parcel): bool
    {
        if (self::integer($parcel['id'] ?? null) === null || !is_array($parcel['status'] ?? null)
            || self::integer($parcel['status']['id'] ?? null) === null) { return false; }
        if (isset($parcel['invoice']) && (!is_array($parcel['invoice']) || self::integer($parcel['invoice']['status'] ?? null) === null)) { return false; }
        return !isset($parcel['tracking_code']) || is_string($parcel['tracking_code']);
    }

    private static function cancellationBlocksPrint(int $id): bool
    {
        if (!function_exists('get_option')) { return false; }
        $cancel = get_option('salam_gate_parcel_cancel_' . $id, null);
        return is_array($cancel) && in_array($cancel['state'] ?? '', ['pending', 'review', 'retryable', 'confirmed'], true);
    }

    private static function integer($value): ?int
    {
        if (is_int($value)) return $value;
        if (!is_string($value) || !preg_match('/^[1-9]\d*$/D', $value)) return null;
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $integer === false ? null : $integer;
    }
}
