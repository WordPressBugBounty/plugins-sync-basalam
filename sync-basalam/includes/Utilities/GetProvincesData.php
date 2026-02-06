<?php

namespace SyncBasalam\Utilities;

class GetProvincesData
{
    public static array $provinces = [
        'KHZ' => 'خوزستان',
        'THR' => 'تهران',
        'ILM' => 'ایلام',
        'BHR' => 'بوشهر',
        'ADL' => 'اردبیل',
        'ESF' => 'اصفهان',
        'YZD' => 'یزد',
        'KRH' => 'کرمانشاه',
        'KRN' => 'کرمان',
        'HDN' => 'همدان',
        'GZN' => 'قزوین',
        'ZJN' => 'زنجان',
        'LRS' => 'لرستان',
        'ABZ' => 'البرز',
        'EAZ' => 'آذربایجان شرقی',
        'WAZ' => 'آذربایجان غربی',
        'CHB' => 'چهارمحال و بختیاری',
        'SKH' => 'خراسان جنوبی',
        'RKH' => 'خراسان رضوی',
        'NKH' => 'خراسان شمالی',
        'SMN' => 'سمنان',
        'FRS' => 'فارس',
        'QHM' => 'قم',
        'KRD' => 'کردستان',
        'KBD' => 'کهگیلویه و بویراحمد',
        'GLS' => 'گلستان',
        'GIL' => 'گیلان',
        'MZN' => 'مازندران',
        'MKZ' => 'مرکزی',
        'HRZ' => 'هرمزگان',
        'SBN' => 'سیستان و بلوچستان',
    ];

    public static function getCodeByName(string $persianName): ?string
    {
        foreach (self::$provinces as $code => $name) {
            if (trim($name) === trim($persianName)) return $code;
        }

        return null;
    }

    public static function getNameByCode(string $code): ?string
    {
        return self::$provinces[$code] ?? null;
    }

    /**
     * Check if Persian WooCommerce Shipping plugin is active.
     */
    public static function isPWSActive(): bool
    {
        return in_array(
            'persian-woocommerce-shipping/woocommerce-shipping.php',
            (array) get_option('active_plugins', [])
        );
    }

    /**
     * Set order address with PWS compatibility
     * If PWS is active, sets Tapin state/city IDs as meta data
     * Otherwise, uses normal WooCommerce state/city fields.
     */
    public static function setOrderAddress($order, $addressData, $type = 'billing')
    {
        if (!$order || !isset($addressData['province']) || !isset($addressData['city'])) {
            return;
        }

        $province = trim($addressData['province']);
        $city = trim($addressData['city']);

        if (self::isPWSActive()) {
            // PWS is active - set Tapin IDs
            $state_id = self::getTapinStateIdByName($province);
            $city_id = self::getTapinCityIdByName($city, $state_id);

            if ($state_id) {
                $order->update_meta_data("_{$type}_state_id", $state_id);
                // Also set the state name for WooCommerce
                if ($type === 'billing') {
                    $order->set_billing_state($province);
                } else {
                    $order->set_shipping_state($province);
                }
            }

            if ($city_id) {
                $order->update_meta_data("_{$type}_city_id", $city_id);
                // Also set the city name for WooCommerce
                if ($type === 'billing') {
                    $order->set_billing_city($city);
                } else {
                    $order->set_shipping_city($city);
                }
            }
        } else {
            // PWS is not active - use normal WooCommerce fields
            if ($type === 'billing') {
                $order->set_billing_state($province);
                $order->set_billing_city($city);
            } else {
                $order->set_shipping_state($province);
                $order->set_shipping_city($city);
            }
        }
    }

    /**
     * Get Tapin state ID by Persian name.
     */
    private static function getTapinStateIdByName($stateName): ?int
    {
        if (!class_exists('\PWS_Tapin')) return null;

        $states = call_user_func(['\PWS_Tapin', 'states']);
        if (!$states) return null;

        $stateName = trim($stateName);
        foreach ($states as $state_id => $state_title) {
            if (trim($state_title) === $stateName) return (int) $state_id;
        }

        return null;
    }

    /**
     * Get Tapin city ID by Persian name.
     */
    private static function getTapinCityIdByName($cityName, $stateId = null): ?int
    {
        if (!class_exists('\PWS_Tapin')) return null;

        $cities = call_user_func(['\PWS_Tapin', 'cities'], $stateId);
        if (!$cities) return null;

        $cityName = trim($cityName);
        foreach ($cities as $city_id => $city_title) {
            if (trim($city_title) === $cityName) return (int) $city_id;
        }

        return null;
    }
}
