<?php

namespace SyncBasalam\SalamGate\Admin;

defined('ABSPATH') || exit;

/** Cached presentation only; opening the workflow still performs its own fresh preflight. */
final class OrderListAction
{
    /** @return array{mode:string,label:string,market:string,description:string,url:string,awaiting_payment:bool} */
    public static function forOrder(\WC_Order $order): array
    {
        return \SyncBasalam\SalamGate\Order\ShipmentActionPolicy::forOrder($order);
    }

    public static function render(\WC_Order $order, ?array $state = null): void
    {
        if (!current_user_can('manage_woocommerce')) { return; }
        $state = $state ?? self::forOrder($order);
        $mode = $state['mode'];
        $source = OrderShippingStatus::sourceOrderStatus($order);
        $sourceAttributes = $source !== null ? ' data-sg-source-order-label="' . esc_attr($source['label'])
            . '" data-sg-source-order-tone="' . esc_attr($source['tone'])
            . '" data-sg-source-order-description="' . esc_attr($source['description']) . '"' : '';
        if ($mode === 'cancelled') {
            // Inspect the purchased order in our Persian read-only preview,
            // with its editor URL as a fallback. Never enter the shipping workflow.
            echo '<a class="sg-order-list-action sg-order-list-action--cancelled" data-sg-order-details-open="" data-order-id="'
                . esc_attr((string) $order->get_id()) . '"' . $sourceAttributes . ' href="' . esc_url($state['url'])
                . '" aria-label="' . esc_attr('مشاهده جزئیات، سفارش ' . $order->get_order_number()) . '">'
                . '<span class="dashicons dashicons-visibility" aria-hidden="true"></span><span>مشاهده جزئیات</span></a>';
            return;
        }
        $icon = $mode === 'edit' ? 'plus-alt2' : ($mode === 'payment' ? 'controls-repeat'
            : ($mode === 'receipt' ? 'visibility' : ($mode === 'native' ? 'archive' : 'info-outline')));
        $content = '<span class="dashicons dashicons-' . esc_attr($icon) . '" aria-hidden="true"></span>'
            . '<span>' . esc_html($state['label']) . '</span>';
        $attributes = ' class="sg-order-list-action sg-order-list-action--' . esc_attr($mode)
            . '" title="' . esc_attr($state['description']) . '" data-order-id="'
            . esc_attr((string) $order->get_id()) . '"' . $sourceAttributes;
        if ($mode === 'blocked') {
            echo '<span' . $attributes . ' aria-disabled="true" tabindex="-1">' . $content . '</span>';
            return;
        }
        if (in_array($mode, ['edit', 'payment', 'native', 'receipt'], true)) {
            $attributes .= ' data-sg-order-list-open="" data-sg-order-list-mode="' . esc_attr($mode)
                . '"';
        }
        echo '<a' . $attributes . ' href="' . esc_url($state['url']) . '" aria-label="'
            . esc_attr($state['label'] . '، سفارش ' . $order->get_order_number()) . '">' . $content . '</a>';
    }

    /** Always select an exact native child; a parent order is never a financial parcel. */

    /** Leaf state from saved metadata/options only, for website or one exact native child. */

    /** Reuse the row object; validating its original mapping must not reload/migrate it. */

}
