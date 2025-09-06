<?php
if (! defined('ABSPATH')) exit;
class Sync_basalam_Date_Converter
{
    private const GREGORIAN_MONTH_DAYS = [0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    
    public static function utc_to_tehran($utc_datetime)
    {
        $utc_date = new DateTime($utc_datetime, new DateTimeZone('UTC'));
        $tehran_date = $utc_date->setTimezone(new DateTimeZone('Asia/Tehran'));
        return $tehran_date;
    }
    
    public static function gregorian_to_jalali($gy, $gm, $gd)
    {
        $jy = $gy > 1600 ? 979 : 0;
        $gy = $gy > 1600 ? $gy - 1600 : $gy - 621;
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = (365 * $gy) + intval(($gy2 + 3) / 4) - intval(($gy2 + 99) / 100) + intval(($gy2 + 399) / 400) - 80 + $gd + array_sum(array_slice(self::GREGORIAN_MONTH_DAYS, 0, $gm));
        $jy += 33 * intval($days / 12053);
        $days %= 12053;
        $jy += 4 * intval($days / 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intval(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        $jm = ($days < 186) ? (1 + intval($days / 31)) : (7 + intval(($days - 186) / 30));
        $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }
}
