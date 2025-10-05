<?php

class Sync_Basalam_Iran_Provinces_Code
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
            if (trim($name) === trim($persianName)) {
                return $code;
            }
        }
        return null;
    }

    public static function getNameByCode(string $code): ?string
    {
        return self::$provinces[$code] ?? null;
    }
}