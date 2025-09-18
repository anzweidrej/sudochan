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
    public function twig_hasPermission_filter(mixed $mod, mixed $permission, string|null $board = null): bool
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
     * Build capcode data from a cap identifier using config.
     *
     * @param string|false $cap Cap identifier or false
     * @return array<string,string>|false Capcode array or false when none
     */
    public function capcode(string|false $cap): array|false
    {
        global $config;

        if (!$cap) {
            return false;
        }

        $capcode = [];
        if (isset($config['custom_capcode'][$cap])) {
            if (is_array($config['custom_capcode'][$cap])) {
                $capcode['cap'] = sprintf($config['custom_capcode'][$cap][0], $cap);
                if (isset($config['custom_capcode'][$cap][1])) {
                    $capcode['name'] = $config['custom_capcode'][$cap][1];
                }
                if (isset($config['custom_capcode'][$cap][2])) {
                    $capcode['trip'] = $config['custom_capcode'][$cap][2];
                }
            } else {
                $capcode['cap'] = sprintf($config['custom_capcode'][$cap], $cap);
            }
        } else {
            $capcode['cap'] = sprintf($config['capcode'], $cap);
        }

        return $capcode;
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
        global $config;

        if ($id = EventDispatcher::event('poster-id', $ip, $thread)) {
            return $id;
        }

        // Confusing, hard to brute-force, but simple algorithm
        return substr(sha1(sha1($ip . $config['secure_trip_salt'] . $thread) . $config['secure_trip_salt']), 0, $config['poster_id_length']);
    }

    /**
     * Return a human readable "ago" time string.
     *
     * @param int $timestamp Unix timestamp
     * @return string Relative time string (e.g. "5 minutes")
     */
    public function ago(int $timestamp): string
    {
        $difference = time() - $timestamp;
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

    /**
     * Format bytes into a human-readable string (B, KB, MB, ...).
     *
     * @param int|float $size Size in bytes
     * @return string Formatted size with unit
     */
    public function format_bytes(int|float $size): string
    {
        $units = [' B', ' KB', ' MB', ' GB', ' TB'];
        for ($i = 0; $size >= 1024 && $i < 4; $i++) {
            $size /= 1024;
        }
        return round($size, 2) . $units[$i];
    }
}
