<?php

namespace SyncBasalam\Admin\Settings;

use SyncBasalam\Admin\Settings;

defined('ABSPATH') || exit;

class SettingsConfig
{
    public const DEFAULT_WEIGHT = "default_weight";
    public const DEFAULT_PACKAGE_WEIGHT = "default_package_weight";
    public const DEFAULT_PREPARATION = "default_preparation";
    public const DEFAULT_STOCK_QUANTITY = "default_stock_quantity";
    public const WEBHOOK_ID = "webhook_id";
    public const TOKEN = "token";
    public const REFRESH_TOKEN = "refresh_token";
    public const HAMSALAM_TOKEN = "hamsalam_token";
    public const HAMSALAM_BUSINESS_ID = "hamsalam_business_id";
    public const VENDOR_ID = "vendor_id";
    public const IS_VENDOR = "is_vendor";
    public const SYNC_STATUS_PRODUCT = "sync_status_product";
    public const SYNC_STATUS_ORDER = "sync_status_order";
    public const DEVELOPER_MODE = "developer_mode";
    public const INCREASE_PRICE_VALUE = "increase_price_value";
    public const ROUND_PRICE = "round_price";
    public const EXPIRE_TOKEN_TIME = "expire_token_time";
    public const WEBHOOK_HEADER_TOKEN = "webhook_header_token";
    public const PRODUCT_PREFIX_TITLE = "product_prefix_title";
    public const PRODUCT_SUFFIX_TITLE = "product_suffix_title";
    public const SYNC_PRODUCT_FIELDS = "sync_product_fields";
    public const SYNC_PRODUCT_FIELD_NAME = "sync_product_field_name";
    public const SYNC_PRODUCT_FIELD_PHOTOS = "sync_product_field_photos";
    public const SYNC_PRODUCT_FIELD_PRICE = "sync_product_field_price";
    public const SYNC_PRODUCT_FIELD_STOCK = "sync_product_field_stock";
    public const SYNC_PRODUCT_FIELD_WEIGHT = "sync_product_field_weight";
    public const SYNC_PRODUCT_FIELD_DESCRIPTION = "sync_product_field_description";
    public const SYNC_PRODUCT_FIELD_ATTR = "sync_product_field_attr";
    public const AUTO_CONFIRM_ORDER = "auto_confirm_order";
    public const ALL_PRODUCTS_WHOLESALE = "all_products_wholesale";
    public const ADD_ATTR_TO_DESC_PRODUCT = "add_attr_to_desc_product";
    public const ADD_SHORT_DESC_TO_DESC_PRODUCT = "add_short_desc_to_desc_product";
    public const PRODUCT_PRICE_FIELD = "product_price_field";
    public const ORDER_STATUES_TYPE = "order_statues_type";
    public const DISCOUNT_DURATION = "discount_duration";
    public const DISCOUNT_REDUCTION_PERCENT = "discount_reduction_percent";
    public const TASKS_PER_MINUTE = "tasks_per_minute";
    public const TASKS_PER_MINUTE_AUTO = "tasks_per_minute_auto";
    public const PRODUCT_ATTRIBUTE_SUFFIX_ENABLED = "product_attribute_suffix_enabled";
    public const PRODUCT_ATTRIBUTE_SUFFIX_PRIORITY = "product_attribute_suffix_priority";
    public const SAFE_STOCK = "safe_stock";
    public const ORDER_SHIPPING_METHOD = "order_shipping_method";
    public const CUSTOMER_PREFIX_NAME = "customer_prefix_name";
    public const CUSTOMER_SUFFIX_NAME = "customer_suffix_name";

    public static function getDefaultSettings(): array
    {
        return [
            self::DEFAULT_WEIGHT                    => 100,
            self::DEFAULT_PACKAGE_WEIGHT            => 50,
            self::DEFAULT_PREPARATION               => 1,
            self::WEBHOOK_ID                        => null,
            self::TOKEN                             => null,
            self::WEBHOOK_HEADER_TOKEN              => Settings::generateToken(),
            self::REFRESH_TOKEN                     => null,
            self::SYNC_STATUS_PRODUCT               => false,
            self::SYNC_STATUS_ORDER                 => false,
            self::DEVELOPER_MODE                    => false,
            self::VENDOR_ID                         => null,
            self::IS_VENDOR                         => true,
            self::INCREASE_PRICE_VALUE              => 0,
            self::ROUND_PRICE                       => 'none',
            self::EXPIRE_TOKEN_TIME                 => null,
            self::PRODUCT_PREFIX_TITLE              => null,
            self::PRODUCT_SUFFIX_TITLE              => null,
            self::HAMSALAM_TOKEN                    => null,
            self::HAMSALAM_BUSINESS_ID              => null,
            self::DEFAULT_STOCK_QUANTITY            => 1,
            self::SYNC_PRODUCT_FIELDS               => 'all',
            self::SYNC_PRODUCT_FIELD_NAME           => 0,
            self::SYNC_PRODUCT_FIELD_PHOTOS         => 0,
            self::SYNC_PRODUCT_FIELD_PRICE          => 0,
            self::SYNC_PRODUCT_FIELD_STOCK          => 0,
            self::SYNC_PRODUCT_FIELD_WEIGHT         => 0,
            self::SYNC_PRODUCT_FIELD_DESCRIPTION    => 0,
            self::SYNC_PRODUCT_FIELD_ATTR           => 0,
            self::AUTO_CONFIRM_ORDER                => false,
            self::ALL_PRODUCTS_WHOLESALE            => 'none',
            self::ADD_ATTR_TO_DESC_PRODUCT          => false,
            self::ADD_SHORT_DESC_TO_DESC_PRODUCT    => false,
            self::PRODUCT_PRICE_FIELD               => 'original_price',
            self::ORDER_STATUES_TYPE                => 'woosalam_statuses',
            self::DISCOUNT_DURATION                 => 20,
            self::DISCOUNT_REDUCTION_PERCENT        => 0,
            self::TASKS_PER_MINUTE                  => 20,
            self::TASKS_PER_MINUTE_AUTO             => true,
            self::PRODUCT_ATTRIBUTE_SUFFIX_ENABLED  => false,
            self::PRODUCT_ATTRIBUTE_SUFFIX_PRIORITY => '',
            self::SAFE_STOCK                        => 0,
            self::ORDER_SHIPPING_METHOD             => 'basalam',
            self::CUSTOMER_PREFIX_NAME              => null,
            self::CUSTOMER_SUFFIX_NAME              => null,
        ];
    }
}
