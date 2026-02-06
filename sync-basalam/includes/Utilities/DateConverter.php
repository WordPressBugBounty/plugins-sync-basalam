<?php

namespace SyncBasalam\Utilities;

defined('ABSPATH') || exit;

class DateConverter
{
    public static function utcToJalaliDateTime(string $utcDatetime): ?string
    {
        try {
            $utcDate = new \DateTime($utcDatetime, new \DateTimeZone('UTC'));
            $tehranDate = $utcDate->setTimezone(new \DateTimeZone('Asia/Tehran'));

            $g_y = (int) $tehranDate->format('Y');
            $g_m = (int) $tehranDate->format('m');
            $g_d = (int) $tehranDate->format('d');

            [$jy, $jm, $jd] = self::gregorianToJalaliArray($g_y, $g_m, $g_d);

            return sprintf(
                '%04d/%02d/%02d - %s',
                $jy,
                $jm,
                $jd,
                $tehranDate->format('H:i:s')
            );
        } catch (\Exception $e) {
            error_log('Invalid date format: ' . $utcDatetime . ' - ' . $e->getMessage());
            return null;
        }
    }

    public static function gregorianToJalaliArray(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;

        $days = 355666 + (365 * $gy)
            + floor(($gy2 + 3) / 4)
            - floor(($gy2 + 99) / 100)
            + floor(($gy2 + 399) / 400)
            + $gd + $g_d_m[$gm - 1];

        $jy = -1595 + (33 * floor($days / 12053));
        $days %= 12053;
        $jy += 4 * floor($days / 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += floor(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }

        $jm = ($days < 186)
            ? 1 + floor($days / 31)
            : 7 + floor(($days - 186) / 30);

        $jd = 1 + (($days < 186)
            ? ($days % 31)
            : (($days - 186) % 30));

        return [$jy, $jm, $jd];
    }
}