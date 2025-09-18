<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Utils;

class Obfuscation
{
    /**
     * Normalize comment and return MD5 of lowercase letters-only text.
     *
     * @param string $str
     * @return string
     */
    public static function make_comment_hex(string $str)
    {
        // remove cross-board citations
        // the numbers don't matter
        $str = preg_replace('!>>>/[A-Za-z0-9]+/!', '', $str);

        if (extension_loaded('intl') && function_exists('transliterator_transliterate')) {
            $trans = transliterator_transliterate('Any-Latin; Latin-ASCII', $str);
            if ($trans !== null && $trans !== false) {
                $str = $trans;
            }
        } elseif (function_exists('iconv')) {
            $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
        }

        $str = mb_strtolower($str, 'UTF-8');

        // strip all non-alphabet characters
        $str = preg_replace('/[^a-z]/', '', $str);

        return md5($str);
    }
}
