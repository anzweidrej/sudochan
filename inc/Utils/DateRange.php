<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Utils;

class DateRange
{
    /**
     * Get a human-readable, localized time span until the given timestamp.
     *
     * @param int $timestamp Unix timestamp to count down to.
     * @return string Localized string (e.g. "3 days", "1 hour") using ngettext for pluralization.
     */
    public static function until(int $timestamp): string
    {
        $difference = $timestamp - time();
        if ($difference < 60) {
            return $difference . ' ' . ngettext('second', 'seconds', $difference);
        } elseif ($difference < 60 * 60) {
            return ($num = round($difference / (60))) . ' ' . ngettext('minute', 'minutes', $num);
        } elseif ($difference < 60 * 60 * 24) {
            return ($num = round($difference / (60 * 60))) . ' ' . ngettext('hour', 'hours', $num);
        } elseif ($difference < 60 * 60 * 24 * 7) {
            return ($num = round($difference / (60 * 60 * 24))) . ' ' . ngettext('day', 'days', $num);
        } elseif ($difference < 60 * 60 * 24 * 365) {
            return ($num = round($difference / (60 * 60 * 24 * 7))) . ' ' . ngettext('week', 'weeks', $num);
        }

        return ($num = round($difference / (60 * 60 * 24 * 365))) . ' ' . ngettext('year', 'years', $num);
    }
}
