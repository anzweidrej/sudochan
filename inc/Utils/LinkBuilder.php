<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Utils;

use Sudochan\Dispatcher\EventDispatcher;

class LinkBuilder
{
    /**
     * Embed link or legacy HTML.
     *
     * @param string $link
     * @return string
     */
    public static function embed_html(string $link): string
    {
        global $config;

        foreach ($config['embedding'] as $embed) {
            if ($html = preg_replace($embed[0], $embed[1], $link)) {
                if ($html == $link) {
                    continue;
                } // Nope

                $html = str_replace('%%tb_width%%', $config['embed_width'], $html);
                $html = str_replace('%%tb_height%%', $config['embed_height'], $html);

                return $html;
            }
        }

        if ($link[0] == '<') {
            // Prior to v0.9.6-dev-8, HTML code for embedding was stored in the database instead of the link.
            return $link;
        }

        return 'Embedding error.';
    }

    /**
     * Build an HTML anchor for a matched URL.
     *
     * @param array $matches Regex capture groups for the URL and trailing text.
     * @return string HTML anchor plus trailing text.
     */
    public static function markup_url(array $matches): string
    {
        global $config, $markup_urls;

        $url = $matches[1];
        $after = $matches[2];

        $markup_urls[] = $url;

        $link = (object) [
            'href' => $url,
            'text' => $url,
            'rel' => 'nofollow',
            'target' => '_blank',
        ];

        EventDispatcher::event('markup-url', $link);
        $link = (array) $link;

        $parts = [];
        foreach ($link as $attr => $value) {
            if ($attr == 'text' || $attr == 'after') {
                continue;
            }
            $parts[] = $attr . '="' . $value . '"';
        }
        if (isset($link['after'])) {
            $after = $link['after'] . $after;
        }
        return '<a ' . implode(' ', $parts) . '>' . $link['text'] . '</a>' . $after;
    }
}
