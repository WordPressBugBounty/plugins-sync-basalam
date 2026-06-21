<?php

namespace SyncBasalam\Config;

defined('ABSPATH') || exit;

final class Endpoints
{
    // Base hosts
    const OPENAPI_BASE        = 'https://openapi.basalam.com';
    const ORDER_BASE          = 'https://order-processing.basalam.com';
    const CORE_BASE           = 'https://core.basalam.com';
    const UPLOAD_BASE         = 'https://uploadio.basalam.com';
    const CATEGORY_BASE       = 'https://categorydetection.basalam.com';
    const APPS_BASE           = 'https://apps-api.basalam.com';
    const HAMSALAM_BASE       = 'https://api.hamsalam.ir/api/v1';
    const BASALAM_ACCOUNTS    = 'https://basalam.com/accounts/sso';

    // Products

    /** GET all categories */
    const CATEGORIES = self::OPENAPI_BASE . '/v1/categories';

    /** GET category attributes  — sprintf($url, $categoryId) */
    const CATEGORY_ATTRIBUTES = self::OPENAPI_BASE . '/v1/categories/%d/attributes?exclude_multi_selects=true';

    /** POST create product for a vendor — sprintf($url, $vendorId) */
    const PRODUCT_CREATE = self::OPENAPI_BASE . '/v1/vendors/%d/products';

    /** PATCH update an existing product — sprintf($url, $basalamProductId) */
    const PRODUCT_UPDATE = self::OPENAPI_BASE . '/v1/products/%d';

    /** POST batch-update products for a vendor */
    const PRODUCT_BATCH_UPDATE = self::OPENAPI_BASE . '/v1/vendors/%d/products/batch-updates?continue_on_error=true';

    /** GET vendor information — sprintf($url, $vendorId) */
    const VENDOR_INFO = self::OPENAPI_BASE . '/v1/vendors/%d';

    /** GET category-detection prediction — append encoded title as query param */
    const CATEGORY_DETECT = self::CATEGORY_BASE . '/category_detection/api_v1.0/predict/';

    /** POST / PUT file upload */
    const FILE_UPLOAD = self::UPLOAD_BASE . '/v3/files';

    /** GET product data list */
    const PRODUCTS_DATA = self::CORE_BASE . '/v4/products';

    /** GET commission percentage */
    const COMMISSION = self::CORE_BASE . '/api_v2/commission/get_percent';

    /** GET full category tree with max preparation days (core v3) */
    const CATEGORIES_PREPARATION = self::CORE_BASE . '/v3/categories';

    /** GET/POST/PATCH/DELETE webhooks */
    const WEBHOOKS = self::OPENAPI_BASE . '/v1/webhooks';

    /** GET vendor parcels (orders) */
    const VENDOR_PARCELS = self::OPENAPI_BASE . '/v1/vendor-parcels';

    // Orders

    /** POST confirm order (set preparation) */
    const ORDER_CONFIRM = self::ORDER_BASE . '/v1/vendor/set-preparation-order';

    /** POST cancel order */
    const ORDER_CANCEL = self::ORDER_BASE . '/v1/vendor/set-cancel';

    /** GET/POST cancel request for a specific order — sprintf($url, $orderId) */
    const ORDER_CANCEL_REQUEST = self::ORDER_BASE . '/v1/vendor/order/%d/cancel-request';

    /** POST delay / overdue agreement request */
    const ORDER_DELAY = self::ORDER_BASE . '/v1/vendor/orders/%d/new-agreement';

    /** POST set order as shipped (with tracking code) */
    const ORDER_TRACKING = self::ORDER_BASE . '/v2/vendor/set-posted-order';

    /** GET/POST auto-confirm automation config */
    const ORDER_AUTO_CONFIRM_CONFIG = self::ORDER_BASE . '/v1/vendor/automation-config';

    /** GET/PATCH specific order — sprintf($url, $vendorId, $invoiceId) */
    const ORDER_DETAIL = self::ORDER_BASE . '/v2/vendors/%d/orders/%d';

    // Discounts

    /** GET/POST vendor discounts — sprintf($url, $vendorId) */
    const VENDOR_DISCOUNTS = self::OPENAPI_BASE . '/v1/vendors/%d/discounts';

    // Hamsalam

    /** GET OAuth proxy data */
    const HAMSALAM_OAUTH_DATA = self::HAMSALAM_BASE . '/basalam-proxy/wp-oauth-data';

    /** POST OAuth get-token proxy */
    const HAMSALAM_OAUTH_TOKEN = self::HAMSALAM_BASE . '/basalam-proxy/wp-get-token';

    /** GET plugin version detail */
    const HAMSALAM_VERSION_DETAIL = self::HAMSALAM_BASE . '/wp-sites/version-detail';

    /** GET announcements */
    const HAMSALAM_ANNOUNCEMENTS = self::HAMSALAM_BASE . '/announcements';

    /** GET/POST all-businesses */
    const HAMSALAM_BUSINESSES = self::HAMSALAM_BASE . '/all-businesses';

    /** GET Hamsalam auth token */
    const HAMSALAM_AUTH_TOKEN = self::HAMSALAM_BASE . '/auth/basalam/get-token';

    // Tickets (Hamsalam)

    /** GET/POST ticket list */
    const TICKET_LIST = self::HAMSALAM_BASE . '/tickets';

    /** GET ticket subjects */
    const TICKET_SUBJECTS = self::HAMSALAM_BASE . '/tickets/subjects';

    /** GET single ticket — sprintf($url, $ticketId) */
    const TICKET_DETAIL = self::HAMSALAM_BASE . '/tickets/%d';

    /** POST ticket reply items — sprintf($url, $ticketId) */
    const TICKET_ITEMS = self::HAMSALAM_BASE . '/tickets/%d/ticket-items';

    /** POST upload ticket media */
    const TICKET_MEDIA_UPLOAD = self::HAMSALAM_BASE . '/media?type=ticket_item&collection=IMAGE';

    // App Store

    /** POST app review */
    const APP_REVIEW = self::APPS_BASE . '/v1/apps/13/reviews';

    // Helpers

    /** Build the SSO login URL */
    public static function oauthLoginUrl(string $clientId, string $scopes, string $redirectUri, string $siteUrl): string
    {
        return self::BASALAM_ACCOUNTS
            . '?client_id=' . $clientId
            . '&scope=' . $scopes
            . '&redirect_uri=' . $redirectUri
            . '&state=' . $siteUrl;
    }
}
