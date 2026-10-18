<?php

namespace SyncBasalam\SalamGate\Order;

use SyncBasalam\SalamGate\SalamGateException;

defined('ABSPATH') || exit;

/** Goods-only declared-value default; never a postage invoice or merchant settlement. */
final class BasalamGoodsValue
{
    private const PRODUCT_PRICE = 7052;
    private const VENDOR_PRODUCT_DISCOUNT = 7053;

    /**
     * Original unit prices remain untouched. Report amounts are signed LINE totals,
     * so a discount is added once, not multiplied by quantity or subtracted twice.
     * Platform coupons have no verified per-line allocation in this contract.
     *
     * @return array{gross_rial:int,vendor_discount_rial:int,value_rial:int}
     */
    public static function fromItems(array $items): array
    {
        if ($items === []) { throw self::error(); }
        $gross = 0;
        $value = 0;
        foreach ($items as $item) {
            if (!is_array($item)) { throw self::error(); }
            $price = self::integer($item['price'] ?? null);
            $quantity = $item['quantity'] ?? null;
            // The order-processing contract also returns whole quantities as floats.
            if (is_float($quantity) && is_finite($quantity) && floor($quantity) === $quantity
                && $quantity > 0 && $quantity < PHP_INT_MAX) {
                $quantity = (int) $quantity;
            }
            $quantity = self::integer($quantity);
            if ($price === null || $price < 1 || $quantity === null || $quantity < 1
                || $price > intdiv(PHP_INT_MAX, $quantity)) { throw self::error(); }
            $lineGross = $price * $quantity;
            $discount = 0;
            $seen = [];
            // An absent legacy report is different from a corrupt supplied report.
            if (array_key_exists('financial_report', $item)
                && (!is_array($item['financial_report'])
                    || (array_key_exists('report_items', $item['financial_report'])
                        && !is_array($item['financial_report']['report_items'])))) {
                throw self::error();
            }
            $reports = $item['financial_report']['report_items'] ?? [];
            if (!is_array($reports)) { throw self::error(); }
            foreach ($reports as $row) {
                if (!is_array($row)) { throw self::error(); }
                $kind = self::goodsRowKind($row);
                if ($kind === null) { continue; }
                if (isset($seen[$kind])) { throw self::error(); }
                $seen[$kind] = true;
                $amount = self::integer($row['amount'] ?? null);
                if ($amount === null) { throw self::error(); }
                if ($kind === self::PRODUCT_PRICE) {
                    if ($amount !== $lineGross) { throw self::error(); }
                } else {
                    // A positive discount or a reduction larger than the goods is ambiguous.
                    if ($amount > 0 || $amount < -$lineGross) { throw self::error(); }
                    $discount = $amount;
                }
            }
            if ($quantity > 1 && !isset($seen[self::PRODUCT_PRICE])) { throw self::error(); }
            $lineValue = $lineGross + $discount;
            if ($gross > PHP_INT_MAX - $lineGross || $value > PHP_INT_MAX - $lineValue) {
                throw self::error();
            }
            $gross += $lineGross;
            $value += $lineValue;
        }
        return ['gross_rial' => $gross, 'vendor_discount_rial' => $value - $gross, 'value_rial' => $value];
    }

    private static function goodsRowKind(array $row): ?int
    {
        $title = is_string($row['title'] ?? null)
            ? trim(strtr($row['title'], ['ي' => 'ی', 'ك' => 'ک', "\u{200c}" => ' '])) : '';
        $title = preg_replace('/\s+/u', ' ', $title);
        $titleKind = match ($title) {
            'قیمت محصول' => self::PRODUCT_PRICE,
            'تخفیف محصول غرفه' => self::VENDOR_PRODUCT_DISCOUNT,
            default => null,
        };
        if (!array_key_exists('id', $row)) { return $titleKind; }
        $id = self::integer($row['id']);
        $idKind = in_array($id, [self::PRODUCT_PRICE, self::VENDOR_PRODUCT_DISCOUNT], true) ? $id : null;
        if (($idKind !== null || $titleKind !== null) && $idKind !== $titleKind) { throw self::error(); }
        return $idKind;
    }

    private static function integer($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)$/D', $value))) {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT);
        return $number === false ? null : $number;
    }

    private static function error(): SalamGateException
    {
        return new SalamGateException(
            'قیمت یا تخفیف کالاهای سفارش باسلام قابل تأیید نیست؛ دوباره اطلاعات سفارش را دریافت کنید. مبلغ اصلی کالاها تغییر داده نمی‌شود.',
            422, [], SalamGateException::KIND_VALIDATION
        );
    }
}
