<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Utils;

use Sudochan\Utils\StringFormatter;

class TextFormatter
{
    /**
     * Format a post body as a quoted block.
     *
     * @param string $body Text to quote.
     * @param bool $quote Whether to apply quoting (unused here).
     * @return string Quoted text.
     */
    public static function quote(string $body, bool $quote = true): string
    {
        global $config;

        $body = str_replace('<br/>', "\n", $body);

        $body = strip_tags($body);

        $body = preg_replace("/(^|\n)/", '$1&gt;', $body);

        $body .= "\n";

        if ($config['minify_html']) {
            $body = str_replace("\n", '&#010;', $body);
        }

        return $body;
    }

    /**
     * Create a short snippet for PM/mod view.
     *
     * @param string $body Source text.
     * @param int|null $len Maximum length in characters.
     * @return string HTML snippet.
     */
    public static function pm_snippet(string $body, ?int $len = null): string
    {
        global $config;

        if (!isset($len)) {
            $len = &$config['mod']['snippet_length'];
        }

        // Replace line breaks with some whitespace
        $body = preg_replace('@<br/?>@i', '  ', $body);

        // Strip tags
        $body = strip_tags($body);

        // Unescape HTML characters, to avoid splitting them in half
        $body = html_entity_decode($body, ENT_COMPAT, 'UTF-8');

        // calculate strlen() so we can add "..." after if needed
        $strlen = mb_strlen($body);

        $body = mb_substr($body, 0, $len);

        // Re-escape the characters.
        return '<em>' . StringFormatter::utf8tohtml($body) . ($strlen > $len ? '&hellip;' : '') . '</em>';
    }

    /**
     * Truncate HTML body safely, preserving tags and adding a "too long" link.
     *
     * @param string $body HTML text to truncate.
     * @param string $url URL to the full text.
     * @param int|false $max_lines Maximum allowed lines or false to use config.
     * @param int|false $max_chars Maximum allowed chars or false to use config.
     * @return string Truncated HTML.
     */
    public static function truncate(string $body, string $url, int|false $max_lines = false, int|false $max_chars = false): string
    {
        global $config;

        if ($max_lines === false) {
            $max_lines = $config['body_truncate'];
        }
        if ($max_chars === false) {
            $max_chars = $config['body_truncate_char'];
        }

        // We don't want to risk truncating in the middle of an HTML comment.
        // It's easiest just to remove them all first.
        $body = preg_replace('/<!--.*?-->/s', '', $body);

        $original_body = $body;

        $lines = substr_count($body, '<br/>');

        // Limit line count
        if ($lines > $max_lines) {
            if (preg_match('/(((.*?)<br\/>){' . $max_lines . '})/', $body, $m)) {
                $body = $m[0];
            }
        }

        $body = mb_substr($body, 0, $max_chars);

        if ($body != $original_body) {
            // Remove any corrupt tags at the end
            $body = preg_replace('/<([\w]+)?([^>]*)?$/', '', $body);

            // Open tags
            if (preg_match_all('/<([\w]+)[^>]*>/', $body, $open_tags)) {

                $tags = [];
                for ($x = 0;$x < count($open_tags[0]);$x++) {
                    if (!preg_match('/\/(\s+)?>$/', $open_tags[0][$x])) {
                        $tags[] = $open_tags[1][$x];
                    }
                }

                // List successfully closed tags
                if (preg_match_all('/(<\/([\w]+))>/', $body, $closed_tags)) {
                    for ($x = 0;$x < count($closed_tags[0]);$x++) {
                        unset($tags[array_search($closed_tags[2][$x], $tags)]);
                    }
                }

                // remove broken HTML entity at the end (if existent)
                $body = preg_replace('/&[^;]+$/', '', $body);

                $tags_no_close_needed = ["colgroup", "dd", "dt", "li", "optgroup", "option", "p", "tbody", "td", "tfoot", "th", "thead", "tr", "br", "img"];

                // Close any open tags
                foreach ($tags as &$tag) {
                    if (!in_array($tag, $tags_no_close_needed)) {
                        $body .= "</{$tag}>";
                    }
                }
            } else {
                // remove broken HTML entity at the end (if existent)
                $body = preg_replace('/&[^;]*$/', '', $body);
            }

            $body .= '<span class="toolong">' . sprintf(_('Post too long. Click <a href="%s">here</a> to view the full text.'), $url) . '</span>';
        }

        return $body;
    }
}
