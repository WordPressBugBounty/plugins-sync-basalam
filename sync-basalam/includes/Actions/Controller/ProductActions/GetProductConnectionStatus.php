<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Admin\Product\elements\ProductList\StatusColumn;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Services\Products\ProductOwnership;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

class GetProductConnectionStatus extends ActionController
{
    public function __invoke()
    {
        $productId = $this->request['product_id'] ?? null;
        if (!is_scalar($productId) || !ctype_digit((string) $productId) || (int) $productId <= 0
            || get_post_type((int) $productId) !== 'product') {
            wp_send_json_error(['message' => 'محصول معتبر نیست.'], 400);
        }
        $productId = (int) $productId;
        if (!current_user_can('edit_post', $productId)) {
            wp_send_json_error(['message' => 'شما مجاز به بررسی این محصول نیستید.'], 403);
        }
        $vendorId = (int) syncBasalamSettings()->getSettings(SettingsConfig::VENDOR_ID);
        $requestedVendor = $this->request['vendor_id'] ?? null;
        if (!is_scalar($requestedVendor) || (string) $requestedVendor !== (string) $vendorId) {
            wp_send_json_error(['message' => 'اتصال غرفه تغییر کرده است. صفحه را دوباره بارگذاری کنید.'], 409);
        }

        // Read the mapping server-side. This endpoint never accepts a remote ID
        // or starts a product write; it only warms the same check as the editor.
        $remoteId = get_post_meta($productId, ProductMetaKey::basalamProductId($vendorId), true);
        (new ProductOwnership())->inspect($remoteId, $vendorId);

        // Render from the current mapping, so a concurrent reconnect is not
        // overwritten with the result for the previous remote product.
        ob_start();
        StatusColumn::renderStatusColumnContent('sync_basalam_status', $productId);
        $html = ob_get_clean();
        wp_send_json_success(['product_id' => $productId, 'html' => $html]);
    }
}
