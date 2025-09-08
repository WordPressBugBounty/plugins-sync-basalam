<?php
if (! defined('ABSPATH')) exit;
class Sync_basalam_Restore_Product_Listener extends sync_basalam_Listener implements sync_basalam_Listener_Interface
{
    use sync_basalam_Check_Product_Sync_Status;
    public function handle($product_id)
    {
        $product = wc_get_product($product_id);

        if (!$product || $product->is_type('variation')) {
            return;
        }

        $sync_status = $this->sync_basalam_Check_Product_Sync_Status();

        if (!$sync_status || !wc_get_product($product_id)) {
            return;
        }

        $product_operations = new sync_basalam_Admin_Product_Operations();
        $product_operations->restore_exist_product($product_id);
    }
}
