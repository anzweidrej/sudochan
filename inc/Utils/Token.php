<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Utils;

class Token
{
    /**
     * Make a short secure token for a URI.
     *
     * @param string $uri
     * @return string 8-char token
     */
    public static function make_secure_link_token(string $uri): string
    {
        global $mod, $config;
        return substr(sha1($config['cookies']['salt'] . '-' . $uri . '-' . $mod['id']), 0, 8);
    }

    /**
     * Return an HTML confirm link that appends a secure token on confirm.
     *
     * @param string $text
     * @param string $title
     * @param string $confirm_message
     * @param string $href
     * @return string HTML anchor
     */
    public static function secure_link_confirm(string $text, string $title, string $confirm_message, string $href): string
    {
        global $config;

        return '<a onclick="if (event.which==2) return true;if (confirm(\'' . htmlentities(addslashes($confirm_message)) . '\')) document.location=\'?/' . htmlspecialchars(addslashes($href . '/' . self::make_secure_link_token($href))) . '\';return false;" title="' . htmlentities($title) . '" href="?/' . $href . '">' . $text . '</a>';
    }

    /**
     * Append a secure token to an href.
     *
     * @param string $href
     * @return string
     */
    public static function secure_link(string $href): string
    {
        return $href . '/' . self::make_secure_link_token($href);
    }
}
