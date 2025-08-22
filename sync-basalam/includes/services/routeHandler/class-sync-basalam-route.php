<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Route
{
    public static function postAction($actionName, $className)
    {
        add_action('admin_post_' . $actionName, function () use ($actionName, $className) {
            $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';

            if (!wp_verify_nonce($nonce, $actionName . '_nonce')) {
                wp_die('دسترسی غیرمجاز!');
            }
            $handler = new $className();

            do_action('before_' . $actionName, $_POST);

            $result = $handler();

            do_action('after_' . $actionName, $result, $_POST);
            $redirectTo = isset($_POST['redirect_to']) ? esc_url_raw($_POST['redirect_to']) : wp_get_referer();
            wp_redirect($redirectTo ?: admin_url());
            exit;
        });
    }
    public static function postAjax($actionName, $className)
    {
        add_action('wp_ajax_' . $actionName, function () use ($actionName, $className) {
            $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';

            if (!wp_verify_nonce($nonce, $actionName . '_nonce')) {
                wp_die('دسترسی غیرمجاز!');
            }

            $handler = new $className();

            do_action('before_' . $actionName, $_POST);

            $result = $handler();

            do_action('after_' . $actionName, $result, $_POST);
        });
    }
}
