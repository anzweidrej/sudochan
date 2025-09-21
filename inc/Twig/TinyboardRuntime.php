<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Twig;

use Twig\Extension\RuntimeExtensionInterface;
use Sudochan\Manager\PermissionManager;
use Sudochan\Manager\AuthManager;
use Sudochan\Dispatcher\EventDispatcher;
use Sudochan\Utils\Math;
use Sudochan\Utils\Token;

class TinyboardRuntime implements RuntimeExtensionInterface
{
    /**
     * Return the timezone identifier used in templates.
     *
     * @return string Timezone identifier (e.g. 'Z')
     */
    public function twig_timezone_function(): string
    {
        return 'Z';
    }

    /**
     * Push a value onto an array.
     *
     * @template T
     * @param array<int,T> $array Input array
     * @param T $value Value to push
     * @return array<int,T> Modified array with value appended
     */
    public function twig_push_filter(array $array, mixed $value): array
    {
        array_push($array, $value);
        return $array;
    }

    /**
     * Remove tab, carriage return and newline characters.
     *
     * @param string $data Input string
     * @return string Cleaned string
     */
    public function twig_remove_whitespace_filter(string $data): string
    {
        return preg_replace('/[\t\r\n]/', '', $data);
    }

    /**
     * Format a timestamp using gmdate.
     *
     * @param int|string $date Timestamp or numeric string
     * @param string $format Date format
     * @return string Formatted date
     */
    public function twig_date_filter(int|string $date, string $format): string
    {
        return gmdate($format, (int) $date);
    }

    /**
     * Check a permission for a moderator or user.
     *
     * @param mixed $mod Moderator/user object or identifier
     * @param mixed $permission Permission name or key
     * @param string|null $board Optional board identifier
     * @return bool True if allowed
     */
    public function twig_hasPermission_filter(mixed $mod, mixed $permission, ?string $board = null): bool
    {
        return PermissionManager::hasPermission($permission, $board, $mod);
    }

    /**
     * Get the file extension from a filename.
     *
     * @param string $value Filename
     * @param bool $case_insensitive Lowercase the extension when true
     * @return string File extension
     */
    public function twig_extension_filter(string $value, bool $case_insensitive = true): string
    {
        $ext = mb_substr($value, mb_strrpos($value, '.') + 1);
        if ($case_insensitive) {
            $ext = mb_strtolower($ext);
        }
        return $ext;
    }

    /**
     * Simple sprintf wrapper for Twig.
     *
     * @param string $value Format string
     * @param mixed $var Value to insert
     * @return string Formatted string
     */
    public function twig_sprintf_filter(string $value, mixed $var): string
    {
        return sprintf($value, $var);
    }

    /**
     * Truncate a string to a given length.
     *
     * @param string $value Input string
     * @param int $length Max length
     * @param bool $preserve Preserve whole words when true
     * @param string $separator Trailing separator to append
     * @return string Truncated string
     */
    public function twig_truncate_filter(string $value, int $length = 30, bool $preserve = false, string $separator = '…'): string
    {
        if (mb_strlen($value) > $length) {
            if ($preserve) {
                if (false !== ($breakpoint = mb_strpos($value, ' ', $length))) {
                    $length = $breakpoint;
                }
            }
            return mb_substr($value, 0, $length) . $separator;
        }
        return $value;
    }

    /**
     * Truncate a filename while preserving the extension.
     *
     * @param string $value Filename
     * @param int $length Max length for the name part
     * @param string $separator Separator to indicate truncation
     * @return string Truncated filename
     */
    public function twig_filename_truncate_filter(string $value, int $length = 30, string $separator = '…'): string
    {
        if (mb_strlen($value) > $length) {
            $value = strrev($value);
            $array = array_reverse(explode(".", $value, 2));
            $array = array_map("strrev", $array);

            $filename = &$array[0];
            $extension = isset($array[1]) ? $array[1] : false;

            $filename = mb_substr($filename, 0, $length - ($extension ? mb_strlen($extension) + 1 : 0)) . $separator;

            return implode(".", $array);
        }
        return $value;
    }

    /**
     * Get an aspect ratio string for width and height.
     *
     * @param int $w Width
     * @param int $h Height
     * @return string Ratio formatted with ':'
     */
    public function twig_ratio_function(int $w, int $h): string
    {
        return Math::fraction($w, $h, ':');
    }

    /**
     * Create a secure link with confirmation JS for use in templates.
     *
     * @param string $text Link text
     * @param string $title Link title attribute
     * @param string $confirm_message Confirmation message
     * @param string $href Destination href
     * @return string HTML anchor element
     */
    public function twig_secure_link_confirm(string $text, string $title, string $confirm_message, string $href): string
    {
        global $config;

        return '<a onclick="if (event.which==2) return true;if (confirm(\'' . htmlentities(addslashes($confirm_message)) . '\')) document.location=\'?/' . htmlspecialchars(addslashes($href . '/' . Token::make_secure_link_token($href))) . '\';return false;" title="' . htmlentities($title) . '" href="?/' . $href . '">' . $text . '</a>';
    }

    /**
     * Append a security token to a URL.
     *
     * @param string $href URL or path
     * @return string URL with token appended
     */
    public function twig_secure_link(string $href): string
    {
        return $href . '/' . Token::make_secure_link_token($href);
    }

    /**
     * Close unmatched bidi formatting characters and preserve embedding balance.
     *
     * @param string $data Input string containing bidi characters
     * @return string Cleaned string
     */
    public function bidi_cleanup(string $data): string
    {
        return \Sudochan\Utils\StringFormatter::bidi_cleanup($data);
    }

    /**
     * Build capcode data from a cap identifier using config.
     *
     * @param string|false $cap Cap identifier or false
     * @return array<string,string>|false Capcode array or false when none
     */
    public function capcode(string|false $cap): array|false
    {
        return \Sudochan\Utils\Identity::capcode($cap);
    }

    /**
     * Generate a poster identifier from IP and thread id.
     *
     * @param string $ip Poster IP address
     * @param int $thread Thread ID
     * @return string Poster identifier
     */
    public function poster_id(string $ip, int $thread): string
    {
        return \Sudochan\Utils\Identity::poster_id($ip, $thread);
    }

    /**
     * Return a human readable "ago" time string.
     *
     * @param int $timestamp Unix timestamp
     * @return string Relative time string (e.g. "5 minutes")
     */
    public function ago(int $timestamp): string
    {
        return \Sudochan\Utils\DateRange::ago($timestamp);
    }

    /**
     * Format bytes into a human-readable string (B, KB, MB, ...).
     *
     * @param int|float $size Size in bytes
     * @return string Formatted size with unit
     */
    public function format_bytes(int|float $size): string
    {
        return \Sudochan\Utils\Filesize::format_bytes($size);
    }

    /**
     * Truncate a post body to a given length.
     *
     * @param string $body Post body
     * @param int $length Max length
     * @param bool $preserve Preserve whole words when true
     * @param string $separator Trailing separator to append
     * @return string Truncated body
     */
    public function truncate(string $body, string $url, int|false $max_lines = false, int|false $max_chars = false): string
    {
        return \Sudochan\Utils\TextFormatter::truncate($body, $url, $max_lines, $max_chars);
    }

    /**
     * Get a human-readable, localized time span until the given timestamp.
     *
     * @param int $timestamp Unix timestamp to count down to.
     * @return string Localized string (e.g. "3 days", "1 hour") using ngettext for pluralization.
     */
    public function until(int $timestamp): string
    {
        return \Sudochan\Utils\DateRange::until($timestamp);
    }
}
