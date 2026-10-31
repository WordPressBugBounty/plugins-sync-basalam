<?php

namespace SyncBasalam\Services\Orders;

use Automattic\WooCommerce\Utilities\OrderUtil;
use SyncBasalam\SalamPay\Money;

defined('ABSPATH') || exit;

class OrderStatistics
{
    public static function getSnapshot(): array
    {
        $now = current_datetime();

        return ['counts' => self::getSummary($now), 'amounts' => self::getAmounts($now)];
    }

    public static function getSummary(?\DateTimeImmutable $now = null): ?array
    {
        if (!function_exists('wc_get_orders')) {
            return null;
        }

        $now = $now ?? current_datetime();
        $end = $now->getTimestamp();
        $statuses = self::getStatuses();
        $counts = [];

        foreach (self::getPeriods($now) as $period => $start) {
            if (!$statuses) {
                $counts[$period] = 0;
                continue;
            }
            $args = [
                'type' => 'shop_order',
                'status' => $statuses,
                'limit' => 1,
                'return' => 'ids',
                'paginate' => true,
            ];

            if ($start !== null) {
                // Epoch bounds retain site-local midnight, including DST changes.
                $args['date_created'] = $start->getTimestamp() . '...' . $end;
            }

            $result = wc_get_orders($args);
            $counts[$period] = (int) $result->total;
        }

        return $counts;
    }

    public static function getAmounts(?\DateTimeImmutable $now = null): ?array
    {
        if (!function_exists('wc_get_orders')) return null;

        global $wpdb;
        $now = $now ?? current_datetime();
        $periods = self::getPeriods($now);
        $amounts = ['unit' => 'IRR'] + array_fill_keys(array_keys($periods), 0);
        $statuses = self::getStatuses();
        if (!$statuses) return $amounts;

        $statusSlots = implode(',', array_fill(0, count($statuses), '%s'));
        $hpos = class_exists(OrderUtil::class) && OrderUtil::custom_orders_table_usage_is_enabled();
        if ($hpos) {
            $source = "SELECT date_created_gmt AS created, total_amount AS raw_total, currency
                FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND status IN ({$statusSlots})";
        } else {
            // Resolve each metadata ID once, using the first row just like
            // get_post_meta(). Correlated value subqueries are re-evaluated for
            // every aggregate/window after MySQL merges the derived tables.
            // Grouping IDs prevents that repeated work and duplicate amounts.
            $source = "SELECT p.post_date_gmt AS created,
                totals.meta_value AS raw_total, currencies.meta_value AS currency
                FROM {$wpdb->posts} p
                LEFT JOIN (
                    SELECT post_id,
                        MIN(CASE WHEN meta_key = '_order_total' THEN meta_id END) AS total_id,
                        MIN(CASE WHEN meta_key = '_order_currency' THEN meta_id END) AS currency_id
                    FROM {$wpdb->postmeta}
                    WHERE meta_key IN ('_order_total', '_order_currency')
                    GROUP BY post_id
                ) first_meta ON first_meta.post_id = p.ID
                LEFT JOIN {$wpdb->postmeta} totals ON totals.meta_id = first_meta.total_id
                LEFT JOIN {$wpdb->postmeta} currencies ON currencies.meta_id = first_meta.currency_id
                WHERE p.post_type = 'shop_order' AND p.post_status IN ({$statusSlots})";
        }
        $source = $wpdb->prepare($source, $statuses);

        $factorCases = [];
        foreach (['IRR', 'IRT', 'TOMAN', 'IRHR', 'IRHT'] as $currency) {
            $factorCases[] = $wpdb->prepare('WHEN %s THEN %d', $currency, Money::rial(1, $currency));
        }
        $factor = 'CASE UPPER(currency) ' . implode(' ', $factorCases) . ' ELSE NULL END';
        $converted = "SELECT created, CASE WHEN raw_total REGEXP '^[0-9]+([.][0-9]+)?$'
            THEN ROUND(CAST(raw_total AS DECIMAL(38,8)) * ({$factor}), 0)
            ELSE NULL END AS amount_irr FROM ({$source}) source_orders";
        $columns = [];
        foreach ($periods as $period => $start) {
            $window = $start === null ? '1=1' : $wpdb->prepare(
                'created BETWEEN %s AND %s',
                gmdate('Y-m-d H:i:s', $start->getTimestamp()),
                gmdate('Y-m-d H:i:s', $now->getTimestamp())
            );
            $columns[] = "COALESCE(SUM(CASE WHEN {$window} THEN amount_irr ELSE 0 END), 0) AS {$period}";
            $columns[] = "COUNT(CASE WHEN ({$window}) AND amount_irr IS NULL THEN 1 END) AS {$period}_invalid";
        }

        // WooCommerce has no sum query API. Aggregate once against the active
        // store; every value is prepared and all SQL identifiers are internal.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row('SELECT ' . implode(', ', $columns) . " FROM ({$converted}) converted_orders", ARRAY_A);
        if (!is_array($row) || $wpdb->last_error) return null;

        foreach ($periods as $period => $start) {
            $value = (string) $row[$period];
            $max = (string) PHP_INT_MAX;
            $validInteger = ctype_digit($value) && (strlen($value) < strlen($max)
                || (strlen($value) === strlen($max) && strcmp($value, $max) <= 0));
            $amounts[$period] = (int) $row[$period . '_invalid'] === 0 && $validInteger ? (int) $value : null;
        }

        return $amounts;
    }

    private static function getStatuses(): array
    {
        return array_values(array_diff(array_keys(wc_get_order_statuses()), [
            'wc-checkout-draft', 'wc-auto-draft', 'auto-draft', 'wc-trash', 'trash',
        ]));
    }

    private static function getPeriods(\DateTimeImmutable $now): array
    {
        $today = $now->setTime(0, 0, 0);

        return [
            'today' => $today,
            'last_7_days' => $today->modify('-6 days'),
            'last_30_days' => $today->modify('-29 days'),
            'total' => null,
        ];
    }
}
