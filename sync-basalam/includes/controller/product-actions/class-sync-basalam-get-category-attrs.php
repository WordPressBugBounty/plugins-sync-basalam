<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Get_Category_Attrs extends Sync_BasalamController
{
    public function __invoke()
    {
        if (isset($_POST['catID'])) {
            $cat_id = sanitize_text_field(wp_unslash($_POST['catID']));
        } else {
            return false;
        }
        $category_attrs = Sync_basalam_Get_Category_Attr::get_attr($cat_id);

        if ($category_attrs['data']) {
            wp_send_json_success([
                [
                    'attributes' => $category_attrs['data']
                ]
            ]);
        } else {
            wp_send_json_success([
                [
                    'attributes' => []
                ]
            ]);
        }
    }
}
