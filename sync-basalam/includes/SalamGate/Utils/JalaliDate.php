<?php

namespace SyncBasalam\SalamGate\Utils;

if (! defined('ABSPATH')) exit;

/**
 * تبدیل تاریخ جلالی ↔ میلادی — الگوریتم استاندارد jalaali (پورت PHP).
 *
 * محاسبات تقویمی خالص‌اند (بدون timestamp) تا خطای منطقه زمانی، تاریخ را
 * یک روز جابه‌جا نکند. سال کبیسه جلالی (اسفند ۳۰ روزه) رعایت می‌شود.
 */
final class JalaliDate
{
    /** شکست‌های چرخه ۳۳ ساله الگوریتم jalaali. */
    private const BREAKS = [
        -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635,
        2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178,
    ];

    /**
     * جلالی → میلادی. سال ۱..۱۴۰۰+ / بازه الگوریتم.
     *
     * @return array{gy:int, gm:int, gd:int}
     */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jdn = self::j2d($jy, $jm, $jd);
        $g = self::d2g($jdn);

        return ['gy' => $g['gy'], 'gm' => $g['gm'], 'gd' => $g['gd']];
    }

    /**
     * میلادی → جلالی.
     *
     * @return array{jy:int, jm:int, jd:int}
     */
    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        $jdn = self::g2d($gy, $gm, $gd);

        return self::d2j($jdn);
    }

    public static function isLeapJalali(int $jy): bool
    {
        return self::jalCal($jy, false)['leap'] === 0;
    }

    /**
     * طول ماه جلالی (اسفند: ۳۰ در کبیسه، وگرنه ۲۹).
     */
    public static function monthLength(int $jy, int $jm): int
    {
        if ($jm <= 6) {
            return 31;
        }
        if ($jm <= 11) {
            return 30;
        }

        return self::isLeapJalali($jy) ? 30 : 29;
    }

    /**
     * اعتبار تاریخ جلالی (شامل روز ۳۰ اسفند فقط در کبیسه).
     */
    public static function isValidJalali(int $jy, int $jm, int $jd): bool
    {
        if ($jy < 1 || $jm < 1 || $jm > 12 || $jd < 1) {
            return false;
        }

        return $jd <= self::monthLength($jy, $jm);
    }

    /**
     * جلالی → ISO میلادی YYYY-MM-DD (یا null اگر نامعتبر).
     */
    public static function jalaliToIso(int $jy, int $jm, int $jd): ?string
    {
        if (!self::isValidJalali($jy, $jm, $jd)) {
            return null;
        }

        $g = self::toGregorian($jy, $jm, $jd);

        return sprintf('%04d-%02d-%02d', $g['gy'], $g['gm'], $g['gd']);
    }

    /**
     * سن در تاریخ مرجع (پیش‌فرض «امروز» محلی سایت) — محاسبه تقویمی، بدون ساعت/تی‌زدی.
     *
     * @param string $birthIso YYYY-MM-DD
     * @param string|null $todayIso YYYY-MM-DD برای تست
     */
    public static function ageOn(string $birthIso, ?string $todayIso = null): ?int
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birthIso, $b)) {
            return null;
        }

        $todayIso = $todayIso ?? current_time('Y-m-d');
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $todayIso, $t)) {
            return null;
        }

        $age = (int) $t[1] - (int) $b[1];
        if ((int) $t[2] < (int) $b[2] || ((int) $t[2] === (int) $b[2] && (int) $t[3] < (int) $b[3])) {
            $age--;
        }

        return $age;
    }

    // ------------------------------------------------------------------
    // الگوریتم jalaali (jalaali-js) — بازنویسی PHP با intdiv و %
    // ------------------------------------------------------------------

    /** @return array{leap:int, gy:int, march:int} */
    private static function jalCal(int $jy, bool $withoutLeap): array
    {
        $breaks = self::BREAKS;
        $bl = count($breaks);
        $gy = $jy + 621;
        $leapJ = -14;
        $jp = $breaks[0];
        $jump = 0;

        if ($jy < $jp || $jy >= $breaks[$bl - 1]) {
            throw new \InvalidArgumentException('سال جلالی خارج از بازه الگوریتم است: ' . $jy);
        }

        for ($i = 1; $i < $bl; $i++) {
            $jm = $breaks[$i];
            $jump = $jm - $jp;
            if ($jy < $jm) {
                break;
            }
            $leapJ += intdiv($jump, 33) * 8 + intdiv($jump % 33, 4);
            $jp = $jm;
        }

        $n = $jy - $jp;
        $leapJ += intdiv($n, 33) * 8 + intdiv($n % 33 + 3, 4);
        if ($jump % 33 === 4 && $jump - $n === 4) {
            $leapJ++;
        }

        $leapG = intdiv($gy, 4) - intdiv((intdiv($gy, 100) + 1) * 3, 4) - 150;
        $march = 20 + $leapJ - $leapG;

        $leap = 0;
        if (!$withoutLeap) {
            if ($jump - $n < 6) {
                $n = $n - $jump + intdiv($jump + 4, 33) * 33;
            }
            $leap = (($n + 1) % 33 - 1) % 4;
            if ($leap === -1) {
                $leap = 4;
            }
        }

        return ['leap' => $leap, 'gy' => $gy, 'march' => $march];
    }

    private static function j2d(int $jy, int $jm, int $jd): int
    {
        $r = self::jalCal($jy, true);

        return self::g2d($r['gy'], 3, $r['march']) + ($jm - 1) * 31 - intdiv($jm, 7) * ($jm - 7) + $jd - 1;
    }

    /** @return array{jy:int, jm:int, jd:int} */
    private static function d2j(int $jdn): array
    {
        $gy = self::d2g($jdn)['gy'];
        $jy = $gy - 621;
        $r = self::jalCal($jy, false);
        $jdn1f = self::g2d($gy, 3, $r['march']);

        $k = $jdn - $jdn1f;
        if ($k >= 0) {
            if ($k <= 185) {
                return ['jy' => $jy, 'jm' => 1 + intdiv($k, 31), 'jd' => $k % 31 + 1];
            }
            $k -= 186;
        } else {
            $jy--;
            $k += 179;
            if ($r['leap'] === 1) {
                $k++;
            }
        }

        return ['jy' => $jy, 'jm' => 7 + intdiv($k, 30), 'jd' => $k % 30 + 1];
    }

    private static function g2d(int $gy, int $gm, int $gd): int
    {
        $d = intdiv(($gy + intdiv($gm - 8, 6) + 100100) * 1461, 4)
            + intdiv(153 * (($gm + 9) % 12) + 2, 5)
            + $gd - 34840408;
        $d = $d - intdiv(intdiv($gy + 100100 + intdiv($gm - 8, 6), 100) * 3, 4) + 752;

        return $d;
    }

    /** @return array{gy:int, gm:int, gd:int} */
    private static function d2g(int $jdn): array
    {
        $j = 4 * $jdn + 139361631;
        $j = $j + intdiv(intdiv(4 * $jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
        $i = intdiv($j % 1461, 4) * 5 + 308;
        $gd = intdiv($i % 153, 5) + 1;
        $gm = intdiv($i, 153) % 12 + 1;
        $gy = intdiv($j, 1461) - 100100 + intdiv(8 - $gm, 6);

        return ['gy' => $gy, 'gm' => $gm, 'gd' => $gd];
    }
}
