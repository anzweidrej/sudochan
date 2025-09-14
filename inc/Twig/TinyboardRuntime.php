<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Twig;

use Twig\Extension\RuntimeExtensionInterface;
use Sudochan\Manager\PermissionManager;
use Sudochan\Manager\AuthManager;
use Sudochan\Dispatcher\EventDispatcher;

class TinyboardRuntime implements RuntimeExtensionInterface
{
    public function twig_timezone_function(): string
    {
        return 'Z';
    }

    /**
     * @template T
     * @param array<int,T> $array
     * @param T $value
     * @return array<int,T>
     */
    public function twig_push_filter(array $array, mixed $value): array
    {
        array_push($array, $value);
        return $array;
    }

    public function twig_remove_whitespace_filter(string $data): string
    {
        return preg_replace('/[\t\r\n]/', '', $data);
    }

    public function twig_date_filter(int|string $date, string $format): string
    {
        return gmdate($format, (int) $date);
    }

    public function twig_hasPermission_filter(mixed $mod, mixed $permission, string|null $board = null): bool
    {
        return PermissionManager::hasPermission($permission, $board, $mod);
    }

    public function twig_extension_filter(string $value, bool $case_insensitive = true): string
    {
        $ext = mb_substr($value, mb_strrpos($value, '.') + 1);
        if ($case_insensitive) {
            $ext = mb_strtolower($ext);
        }
        return $ext;
    }

    public function twig_sprintf_filter(string $value, mixed $var): string
    {
        return sprintf($value, $var);
    }

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

    public function twig_ratio_function(int $w, int $h): string
    {
        return fraction($w, $h, ':');
    }

    public function twig_secure_link_confirm(string $text, string $title, string $confirm_message, string $href): string
    {
        global $config;

        return '<a onclick="if (event.which==2) return true;if (confirm(\'' . htmlentities(addslashes($confirm_message)) . '\')) document.location=\'?/' . htmlspecialchars(addslashes($href . '/' . AuthManager::make_secure_link_token($href))) . '\';return false;" title="' . htmlentities($title) . '" href="?/' . $href . '">' . $text . '</a>';
    }

    public function twig_secure_link(string $href): string
    {
        return $href . '/' . AuthManager::make_secure_link_token($href);
    }
}
