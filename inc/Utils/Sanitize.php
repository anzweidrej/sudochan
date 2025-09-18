<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Utils;

class Sanitize
{
    /**
     * Extract tinyboard modifiers from the body.
     *
     * @param string $body Post body.
     * @return array Keyed modifiers.
     */
    public static function extract_modifiers(string $body): array
    {
        $modifiers = [];

        if (preg_match_all('@<tinyboard ([\w\s]+)>(.+?)</tinyboard>@us', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (preg_match('/^escape /', $match[1])) {
                    continue;
                }
                $modifiers[$match[1]] = html_entity_decode($match[2]);
            }
        }

        return $modifiers;
    }

    /**
     * Escape markup modifier tags so they are treated as plain text.
     *
     * @param string $string Input string.
     * @return string Escaped string.
     */
    public static function escape_markup_modifiers(string $string): string
    {
        return preg_replace('@<(tinyboard) ([\w\s]+)>@mi', '<$1 escape $2>', $string);
    }

    /**
     * Apply configured wordfilters to a body string.
     *
     * @param string &$body Text to filter.
     * @return void
     */
    public static function wordfilters(string &$body): void
    {
        global $config;

        foreach ($config['wordfilters'] as $filter) {
            if (isset($filter[2]) && $filter[2]) {
                if (is_callable($filter[1])) {
                    $body = preg_replace_callback($filter[0], $filter[1], $body);
                } else {
                    $body = preg_replace($filter[0], $filter[1], $body);
                }
            } else {
                $body = str_ireplace($filter[0], $filter[1], $body);
            }
        }
    }
}
