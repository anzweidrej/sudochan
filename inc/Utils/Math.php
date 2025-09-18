<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Utils;

class Math
{
    /**
     * Return the greatest common divisor (GCD) of two positive integers.
     *
     * @param int $a
     * @param int $b
     * @return int
     */
    public static function hcf(int $a, int $b): int
    {
        $gcd = 1;
        if ($a > $b) {
            $a = $a + $b;
            $b = $a - $b;
            $a = $a - $b;
        }
        if ($b == (round($b / $a)) * $a) {
            $gcd = $a;
        } else {
            for ($i = round($a / 2); $i; $i--) {
                if ($a == round($a / $i) * $i && $b == round($b / $i) * $i) {
                    $gcd = $i;
                    $i = false;
                }
            }
        }
        return $gcd;
    }

    /**
     * Simplify a fraction and return as "{num}{sep}{den}".
     *
     * @param int $numerator
     * @param int $denominator
     * @param string $sep
     * @return string
     */
    public static function fraction(int $numerator, int $denominator, string $sep): string
    {
        $gcf = self::hcf($numerator, $denominator);
        $numerator = $numerator / $gcf;
        $denominator = $denominator / $gcf;

        return "{$numerator}{$sep}{$denominator}";
    }
}
