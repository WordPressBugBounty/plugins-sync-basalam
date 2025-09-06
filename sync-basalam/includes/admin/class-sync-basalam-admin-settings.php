<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Admin_Settings
{
    private static $oauth_cache = null;
    const DEFAULT_WEIGHT = "default_weight";
    const DEFAULT_PACKAGE_WEIGHT = "default_package_weight";
    const DEFAULT_PREPARATION = "default_preparation";
    const DEFAULT_STOCK_QUANTITY = "default_stock_quantity";
    const WEBHOOK_ID = "webhook_id";
    const TOKEN = "token";
    const REFRESH_TOKEN = "refresh_token";
    const VENDOR_ID = "vendor_id";
    const IS_VENDOR = "is_vendor";
    const SYNC_STATUS_PRODUCT = "sync_status_product";
    const SYNC_STATUS_ORDER = "sync_status_order";
    const DEVELOPER_MODE = "developer_mode";
    const INCREASE_PRICE_VALUE = "increase_price_value";
    const ROUND_PRICE = "round_price";
    const EXPIRE_TOKEN_TIME = "expire_token_time";
    const WEBHOOK_HEADER_TOKEN = "webhook_header_token";
    const PRODUCT_PREFIX_TITLE = "product_prefix_title";
    const PRODUCT_SUFFIX_TITLE = "product_suffix_title";
    const SYNC_PRODUCT_FIELDS = "sync_product_fields";
    const SYNC_PRODUCT_FIELD_NAME = "sync_product_field_name";
    const SYNC_PRODUCT_FIELD_PHOTOS = "sync_product_field_photos";
    const SYNC_PRODUCT_FIELD_PRICE = "sync_product_field_price";
    const SYNC_PRODUCT_FIELD_STOCK = "sync_product_field_stock";
    const SYNC_PRODUCT_FIELD_WEIGHT = "sync_product_field_weight";
    const SYNC_PRODUCT_FIELD_DESCRIPTION = "sync_product_field_description";
    const SYNC_PRODUCT_FIELD_ATTR = "sync_product_field_attr";
    const AUTO_CONFIRM_ORDER = "auto_confirm_order";
    const ORDER_SHIPPING_METHOD = "order_shipping_method";
    const ALL_PRODUCTS_WHOLESALE = "all_products_wholesale";
    const ADD_ATTR_TO_DESC_PRODUCT = "add_attr_to_desc_product";
    const ADD_SHORT_DESC_TO_DESC_PRODUCT = "add_short_desc_to_desc_product";
    const PRODUCT_PRICE_FIELD = "product_price_field";
    const ORDER_STATUES_TYPE = "order_statues_type";
    const PRODUCT_OPERATION_TYPE = "product_operation_type";


    public static function get_default_settings()
    {
        return array(
            self::DEFAULT_WEIGHT        => 100,
            self::DEFAULT_PACKAGE_WEIGHT        => 50,
            self::DEFAULT_PREPARATION   => 1,
            self::WEBHOOK_ID    => null,
            self::TOKEN         => null,
            self::WEBHOOK_HEADER_TOKEN         => null,
            self::REFRESH_TOKEN => null,
            self::SYNC_STATUS_PRODUCT   => false,
            self::SYNC_STATUS_ORDER   => false,
            self::DEVELOPER_MODE        => false,
            self::VENDOR_ID     => null,
            self::IS_VENDOR     => true,
            self::INCREASE_PRICE_VALUE     => 0,
            self::ROUND_PRICE    => null,
            self::EXPIRE_TOKEN_TIME    => null,
            self::PRODUCT_PREFIX_TITLE    => null,
            self::PRODUCT_SUFFIX_TITLE    => null,
            self::DEFAULT_STOCK_QUANTITY    => 1,
            self::SYNC_PRODUCT_FIELDS => 'all',
            self::SYNC_PRODUCT_FIELD_NAME => 0,
            self::SYNC_PRODUCT_FIELD_PHOTOS => 0,
            self::SYNC_PRODUCT_FIELD_PRICE => 0,
            self::SYNC_PRODUCT_FIELD_STOCK => 0,
            self::SYNC_PRODUCT_FIELD_WEIGHT => 0,
            self::SYNC_PRODUCT_FIELD_DESCRIPTION => 0,
            self::SYNC_PRODUCT_FIELD_ATTR => 0,
            self::AUTO_CONFIRM_ORDER => false,
            self::ORDER_SHIPPING_METHOD => false,
            self::ALL_PRODUCTS_WHOLESALE => 'none',
            self::ADD_ATTR_TO_DESC_PRODUCT => false,
            self::ADD_SHORT_DESC_TO_DESC_PRODUCT => false,
            self::PRODUCT_PRICE_FIELD => 'original_price',
            self::ORDER_STATUES_TYPE => 'woosalam_statuses',
            self::PRODUCT_OPERATION_TYPE => 'optimized',
        );
    }

    // Sanitize input settings values
    public static function sanitize_settings($input)
    {
        $input = array_merge(self::get_settings() ?: [], $input);

        // Sanitize weight and preparation values
        $input[self::DEFAULT_WEIGHT] = absint($input[self::DEFAULT_WEIGHT]);
        $input[self::DEFAULT_PREPARATION] = absint($input[self::DEFAULT_PREPARATION]);

        // Sanitize other fields if necessary
        return $input;
    }


    public static function get_settings($setting = null)
    {
        $settings = get_option('sync_basalam_settings', self::get_default_settings());
        if ($setting == null) {
            $default_settings = self::get_default_settings();

            foreach ($default_settings as $key => $value) {
                if (!array_key_exists($key, $settings)) {
                    $settings[$key] = $value;
                }
            }

            update_option('sync_basalam_settings', $settings);
            return $settings;
        }
        return $settings[$setting] ?? null;
    }

    public static function get_oauth_data($force_refresh = false)
    {
        if (!$force_refresh && self::$oauth_cache !== null) {
            return self::$oauth_cache;
        }
        
        $apiservice = new sync_basalam_External_API_Service;
        $request = $apiservice->send_get_request('https://api.hamsalam.ir/api/v1/basalam-proxy/wp-oauth-data');
        $client_id = $request['data']['client_id'] ?? 779;
        $redirect_uri = $request['data']['redirect_uri'] ?? 'https://api.hamsalam.ir/api/v1/basalam-proxy/wp-get-token';
        
        self::$oauth_cache = [
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
        ];
        
        return self::$oauth_cache;
    }
    public static function get_static_settings($setting = null)
    {
        $site_url = get_site_url();
        $scopes = "vendor.product.write vendor.parcel.write customer.profile.read vendor.profile.read vendor.parcel.read";
        $webhook_token = self::get_settings(sync_basalam_Admin_Settings::WEBHOOK_HEADER_TOKEN);
        $vendor_id = self::get_settings(sync_basalam_Admin_Settings::VENDOR_ID);
        if (!$webhook_token) {
            $webhook_token = self::generate_webhook_token();
        }
        $SITE_URL_WEBHOOK = $site_url . '/wp-json/sync-basalam/v1/order-manager';
        $settings = array(
            'site_url' => $site_url,
            'site_url_webhook' => $SITE_URL_WEBHOOK,
            'url_hijab_detector' => "https://revision.basalam.com/api_v1.0/validation/image/hijab-detector/bulk",
            'url_get_all_sync_basalam_products' => "https://core.basalam.com/v3/vendors/$vendor_id/products",
            'url_like_woo_on_basalam' => "https://apps-api.basalam.com/v1/apps/13/like",
            'get_like_status_url_from_basalam' => "https://apps-api.basalam.com/v1/apps/13",
            'url_get_sync_basalam_account_data' => "https://core.basalam.com/v3/users/me",
            'url_get_sync_basalam_orders' => "https://order-processing.basalam.com/v3/vendor-parcels",
            'get_webhooks_url_from_basalam' => "https://webhook.basalam.com/v1/webhooks",
        );
        
        $oauth_dependent_settings = ['redirect_uri', 'url_req_client', 'url_req_webhook', 'url_req_token'];
        
        if ($setting === null || in_array($setting, $oauth_dependent_settings)) {
            $oauth_data = self::get_oauth_data();
            $client_id = $oauth_data['client_id'];
            $redirect_uri = $oauth_data['redirect_uri'];
            
            // Add OAuth-dependent settings
            $settings['redirect_uri'] = $redirect_uri;
            $settings['url_req_client'] = "https://developers.basalam.com/clients?name=WP-BASALAM&redirect_url=$redirect_uri";
            $settings['url_req_webhook'] = "https://developers.basalam.com/panel/webhooks?events_ids=3,5,7&request_headers=" . urlencode(json_encode(["token" => $webhook_token])) . "&url=" . urlencode($SITE_URL_WEBHOOK);
            $settings['url_req_token'] = "https://basalam.com/accounts/sso?client_id=$client_id&scope=$scopes&redirect_uri=$redirect_uri&state=$site_url";
        }

        if ($setting == null) {
            return $settings;
        }

        return $settings[$setting];
    }

    public static function save_settings()
    {
        $data = isset($_POST['sync_basalam_settings']) ? array_map('sanitize_text_field', wp_unslash($_POST['sync_basalam_settings'])) : [];

        if ($data) {
            self::update_settings($data);

            if (!empty($data[self::DEVELOPER_MODE]) && $data[self::DEVELOPER_MODE] === 'true') {
                $debug_task = new Sync_basalam_debug_Task();
                $debug_task->schedule();
            } else {
                (new Sync_basalam_Cancel_Debug())();
            }
        }

        if (isset($_POST['get_token']) && $_POST['get_token'] == 1) {
            $oauth_data = self::get_oauth_data(true);
            $site_url = get_site_url();
            $scopes = "vendor.product.write vendor.parcel.write customer.profile.read vendor.profile.read vendor.parcel.read";
            $url_req_token = "https://basalam.com/accounts/sso?client_id={$oauth_data['client_id']}&scope=$scopes&redirect_uri={$oauth_data['redirect_uri']}&state=$site_url";
            wp_redirect($url_req_token);
            exit();
        }
    }

    public static function update_settings($data)
    {
        $settings = self::sanitize_settings($data);
        update_option('sync_basalam_settings', $settings);
    }
    public static function save_oauth_data()
    {
        $is_vendor  = isset($_GET['is_vendor']) ? sanitize_text_field(wp_unslash($_GET['is_vendor'])) : true;
        $vendor_id = sanitize_text_field(isset($_GET['vendor_id'])) ? sanitize_text_field(intval($_GET['vendor_id'])) : null;
        $access_token = sanitize_text_field(isset($_GET['access_token'])) ? sanitize_text_field(wp_unslash($_GET['access_token'])) : null;
        $refresh_token = sanitize_text_field(isset($_GET['refresh_token'])) ? sanitize_text_field(wp_unslash($_GET['refresh_token'])) : null;
        $expires_in = sanitize_text_field(isset($_GET['expires_in'])) ? sanitize_text_field(intval($_GET['expires_in'])) : null;
        if ($is_vendor == 'false') {
            $data = [
                sync_basalam_Admin_Settings::IS_VENDOR => false,
            ];
            self::update_settings($data);
            wp_redirect(admin_url('admin.php?page=sync_basalam'));
            exit();
        }
        if ($is_vendor == 'true' && (!$vendor_id || !$access_token || !$refresh_token || !$expires_in)) {
            echo '
            <div class="notice notice-error">
                <p class="basalam-p">اطلاعات غرفه در دسترس نیست. لطفا از تنظیمات صحیح و اتصال به باسلام اطمینان حاصل کنید.</p>
            </div>
            ';
            return false;
        }
        $data = [
            sync_basalam_Admin_Settings::VENDOR_ID => $vendor_id,
            sync_basalam_Admin_Settings::IS_VENDOR => true,
            sync_basalam_Admin_Settings::TOKEN => $access_token,
            sync_basalam_Admin_Settings::REFRESH_TOKEN => $refresh_token,
            sync_basalam_Admin_Settings::EXPIRE_TOKEN_TIME => $expires_in,
        ];
        self::update_settings($data);
        
        // Setup webhooks after getting access token
        $webhookService = new Sync_Basalam_Webhook_Service();
        $webhookService->setupWebhooks();
        
        wp_redirect(admin_url('admin.php?page=sync_basalam'));
        exit();
    }
    static function generate_webhook_token($length = 50)
    {
        $webhook_token = substr(bin2hex(random_bytes($length)), 0, $length);
        $data = [
            sync_basalam_Admin_Settings::WEBHOOK_HEADER_TOKEN => $webhook_token,
        ];
        self::update_settings($data);
        return $webhook_token;
    }
}
