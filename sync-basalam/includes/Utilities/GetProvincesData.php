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
        $needle = self::normalizePersian($persianName);
        foreach (self::$provinces as $code => $name) {
            if (self::normalizePersian($name) === $needle) return $code;
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
     * Register IR states with WooCommerce when no other plugin has done so.
     * WooCommerce won't persist `billing_state` for a country that has registered
     * states unless the value matches a registered key — and it ships zero states
     * for IR. Without this filter, set_billing_state('یزد') is silently dropped
     * on stores that have no Persian shipping/state plugin active.
     *
     * Safe to call multiple times; if another plugin already provides IR states
     * we leave them alone.
     */
    public static function registerIranStatesFilter(): void
    {
        static $registered = false;
        if ($registered) return;
        $registered = true;

        add_filter('woocommerce_states', function ($states) {
            if (!empty($states['IR'])) return $states;

            $states['IR'] = self::$provinces;

            return $states;
        }, 5);
    }

    /**
     * Whether PWS is running in Tapin mode (state IDs come from tapin.json, 1..31).
     * Otherwise PWS falls back to the `state_city` taxonomy term IDs.
     */
    private static function isTapinEnabled(): bool
    {
        if (!class_exists('\PWS_Tapin')) return false;
        if (!method_exists('\PWS_Tapin', 'is_enable')) return false;

        return (bool) call_user_func(['\PWS_Tapin', 'is_enable']);
    }

    /**
     * Normalize Persian/Arabic text so name comparisons survive small variations
     * (Arabic Yeh/Kaf, ZWNJ / half-space, repeated whitespace, BOM, etc.).
     */
    private static function normalizePersian(?string $value): string
    {
        if ($value === null) return '';

        $value = str_replace(
            ["\xEF\xBB\xBF", "\xE2\x80\x8C", "\xC2\xA0", 'ي', 'ك', 'ﮐ', 'ﮏ', 'ﯼ', 'ﯽ'],
            ['',             ' ',            ' ',         'ی', 'ک', 'ک', 'ک', 'ی', 'ی'],
            $value
        );

        $value = preg_replace('/\s+/u', ' ', $value);

        return trim((string) $value);
    }

    /**
     * Set order address with PWS compatibility.
     *
     * - When PWS+Tapin is active: store Tapin numeric state/city IDs in both the
     *   WC billing_state/billing_city fields (so WC's state lookup resolves the
     *   Persian name correctly) AND in PWS's `_billing_state_id`/`_billing_city_id`
     *   meta (which Tapin label submission reads).
     * - When PWS is active without Tapin: resolve the `state_city` taxonomy
     *   term IDs and use those.
     * - Otherwise fall back to plain Persian names.
     */
    public static function setOrderAddress($order, $addressData, $type = 'billing')
    {
        if (!$order || !isset($addressData['province']) || !isset($addressData['city'])) {
            return;
        }

        $province = trim((string) $addressData['province']);
        $city     = trim((string) $addressData['city']);

        if ($province === '' && $city === '') {
            return;
        }

        // WooCommerce validates billing_state against the registered keys of
        // states['IR'], so the state value must be a valid KEY (numeric ID in
        // PWS, 3-letter code without PWS). The city field has no such
        // validation, so we always keep the human-readable Persian name there —
        // otherwise the admin order screen would render the raw term ID.
        $stateValue = $province;
        $cityValue  = $city;
        $stateMetaId = null;
        $cityMetaId  = null;

        if (self::isPWSActive()) {
            if (self::isTapinEnabled()) {
                $stateMetaId = self::getTapinStateIdByName($province);
                $cityMetaId  = self::getTapinCityIdByName($city, $stateMetaId);
            } else {
                $stateMetaId = self::getPwsStateTermIdByName($province);
                $cityMetaId  = self::getPwsCityTermIdByName($city, $stateMetaId);
            }

            if ($stateMetaId) $stateValue = (string) $stateMetaId;
        } else {
            // No PWS — make sure WC has IR states registered so it doesn't drop
            // the billing_state on save, then store the code instead of the name.
            self::registerIranStatesFilter();
            $code = self::getCodeByName($province);
            if ($code) {
                $stateValue = $code;
            }
        }

        if ($type === 'billing') {
            $order->set_billing_state($stateValue);
            $order->set_billing_city($cityValue);
        } else {
            $order->set_shipping_state($stateValue);
            $order->set_shipping_city($cityValue);
        }

        if ($stateMetaId) {
            $order->update_meta_data("_{$type}_state_id", $stateMetaId);
        }
        if ($cityMetaId) {
            $order->update_meta_data("_{$type}_city_id", $cityMetaId);
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

        $needle = self::normalizePersian($stateName);
        if ($needle === '') return null;

        foreach ($states as $state_id => $state_title) {
            if (self::normalizePersian((string) $state_title) === $needle) {
                return (int) $state_id;
            }
        }

        return null;
    }

    /**
     * Get Tapin city ID by Persian name.
     * If $stateId is provided the search is constrained to that state — this
     * avoids the failure mode where a null state_id lets PWS_Tapin::cities()
     * search across ALL cities and return the wrong match.
     */
    private static function getTapinCityIdByName($cityName, $stateId = null): ?int
    {
        if (!class_exists('\PWS_Tapin')) return null;

        $needle = self::normalizePersian($cityName);
        if ($needle === '') return null;

        if ($stateId) {
            $cities = call_user_func(['\PWS_Tapin', 'cities'], $stateId);
            if (!$cities) return null;

            foreach ($cities as $city_id => $city_title) {
                if (self::normalizePersian((string) $city_title) === $needle) {
                    return (int) $city_id;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * Resolve a `state_city` taxonomy term ID for the given province name
     * (used when PWS is active but Tapin is not).
     */
    private static function getPwsStateTermIdByName($stateName): ?int
    {
        if (!taxonomy_exists('state_city')) return null;

        $needle = self::normalizePersian($stateName);
        if ($needle === '') return null;

        $terms = get_terms([
            'taxonomy'   => 'state_city',
            'hide_empty' => false,
            'parent'     => 0,
        ]);

        if (is_wp_error($terms) || empty($terms)) return null;

        foreach ($terms as $term) {
            if (self::normalizePersian((string) $term->name) === $needle) {
                return (int) $term->term_id;
            }
        }

        return null;
    }

    /**
     * Resolve a `state_city` taxonomy term ID for the given city name.
     * Scoped to the province term when available.
     */
    private static function getPwsCityTermIdByName($cityName, $stateTermId = null): ?int
    {
        if (!taxonomy_exists('state_city')) return null;

        $needle = self::normalizePersian($cityName);
        if ($needle === '') return null;

        $args = [
            'taxonomy'   => 'state_city',
            'hide_empty' => false,
        ];

        if ($stateTermId) {
            $args['parent'] = $stateTermId;
        }

        $terms = get_terms($args);
        if (is_wp_error($terms) || empty($terms)) return null;

        foreach ($terms as $term) {
            if (self::normalizePersian((string) $term->name) === $needle) {
                return (int) $term->term_id;
            }
        }

        return null;
    }
}
