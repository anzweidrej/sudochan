<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Utils;

class StringFormatter
{
    /**
     * Replace placeholders (delim + key + delim) in a string.
     *
     * @param string $str
     * @param array  $vars
     * @param string $delim
     * @return string
     */
    public static function sprintf3(string $str, array $vars, string $delim = '%'): string
    {
        $replaces = [];
        foreach ($vars as $k => $v) {
            $replaces[$delim . $k . $delim] = $v;
        }
        return str_replace(
            array_keys($replaces),
            array_values($replaces),
            $str,
        );
    }

    /**
     * Escape a UTF-8 string for HTML.
     *
     * @param string $utf8
     * @return string
     */
    public static function utf8tohtml(string $utf8): string
    {
        return htmlspecialchars($utf8, ENT_NOQUOTES, 'UTF-8');
    }

    /**
     * Return Unicode code point at byte offset; advances offset.
     *
     * @param string $string
     * @param int    &$offset
     * @return int
     */
    public static function ordutf8(string $string, int &$offset): int
    {
        $code = ord(substr($string, $offset, 1));
        if ($code >= 128) { // otherwise 0xxxxxxx
            if ($code < 224) {
                $bytesnumber = 2;
            } // 110xxxxx
            elseif ($code < 240) {
                $bytesnumber = 3;
            } // 1110xxxx
            elseif ($code < 248) {
                $bytesnumber = 4;
            } // 11110xxx
            $codetemp = $code - 192 - ($bytesnumber > 2 ? 32 : 0) - ($bytesnumber > 3 ? 16 : 0);
            for ($i = 2; $i <= $bytesnumber; $i++) {
                $offset++;
                $code2 = ord(substr($string, $offset, 1)) - 128; //10xxxxxx
                $codetemp = $codetemp * 64 + $code2;
            }
            $code = $codetemp;
        }
        $offset += 1;
        if ($offset >= strlen($string)) {
            $offset = -1;
        }
        return $code;
    }

    /**
     * Strip Unicode combining characters from a string.
     *
     * @param string $str
     * @return string
     */
    public static function strip_combining_chars(string $str): string
    {
        $chars = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);
        $str = '';
        foreach ($chars as $char) {
            $o = 0;
            $ord = self::ordutf8($char, $o);

            if ($ord >= 768 && $ord <= 879) {
                continue;
            }

            if ($ord >= 7616 && $ord <= 7679) {
                continue;
            }

            if ($ord >= 8400 && $ord <= 8447) {
                continue;
            }

            if ($ord >= 65056 && $ord <= 65071) {
                continue;
            }

            $str .= $char;
        }
        return $str;
    }

    /**
     * Replace common ASCII sequences with HTML entities.
     *
     * @param string $body
     * @return string
     */
    public static function unicodify(string $body): string
    {
        $body = str_replace('...', '&hellip;', $body);
        $body = str_replace('&lt;--', '&larr;', $body);
        $body = str_replace('--&gt;', '&rarr;', $body);

        // En and em- dashes are rendered exactly the same in
        // most monospace fonts (they look the same in code
        // editors).
        $body = str_replace('---', '&mdash;', $body); // em dash
        $body = str_replace('--', '&ndash;', $body); // en dash

        return $body;
    }

    /**
     * Close unmatched bidi formatting characters and preserve embedding balance.
     *
     * @param string $data Input string containing bidi characters
     * @return string Cleaned string
     */
    public static function bidi_cleanup(string $data): string
    {
        // Closes all embedded RTL and LTR unicode formatting blocks in a string so that
        // it can be used inside another without controlling its direction.

        $explicits	= '\xE2\x80\xAA|\xE2\x80\xAB|\xE2\x80\xAD|\xE2\x80\xAE';
        $pdf		= '\xE2\x80\xAC';

        preg_match_all("!$explicits!", $data, $m1, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        preg_match_all("!$pdf!", $data, $m2, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        if (count($m1) || count($m2)) {

            $p = [];
            foreach ($m1 as $m) {
                $p[$m[0][1]] = 'push';
            }
            foreach ($m2 as $m) {
                $p[$m[0][1]] = 'pop';
            }
            ksort($p);

            $offset = 0;
            $stack = 0;
            foreach ($p as $pos => $type) {

                if ($type == 'push') {
                    $stack++;
                } else {
                    if ($stack) {
                        $stack--;
                    } else {
                        # we have a pop without a push - remove it
                        $data = substr($data, 0, $pos - $offset)
                            . substr($data, $pos + 3 - $offset);
                        $offset += 3;
                    }
                }
            }

            # now add some pops if your stack is bigger than 0
            for ($i = 0; $i < $stack; $i++) {
                $data .= "\xE2\x80\xAC";
            }

            return $data;
        }

        return $data;
    }

    /**
     * Multibyte-safe substring replace.
     *
     * @param string $string Original string.
     * @param string $replacement Replacement text.
     * @param int $start Start offset in characters.
     * @param int $length Length in characters to replace.
     * @return string Resulting string.
     */
    public static function mb_substr_replace(string $string, string $replacement, int $start, int $length): string
    {
        return mb_substr($string, 0, $start) . $replacement . mb_substr($string, $start + $length);
    }
}
