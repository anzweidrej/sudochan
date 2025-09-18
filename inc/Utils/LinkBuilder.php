<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Utils;

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
}
