<?php
if (! defined('ABSPATH')) exit;
class Sync_basalam_Convert_Fa_Num
{
    static function convert_numbers_to_english($string)
    {
        $persian_numbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic_numbers  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $english_numbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $string = str_replace($persian_numbers, $english_numbers, $string);
        $string = str_replace($arabic_numbers, $english_numbers, $string);

        return $string;
    }
}
