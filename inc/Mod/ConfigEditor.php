<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Mod;

defined('TINYBOARD') or exit;

use Sudochan\Service\MarkupService;

class ConfigEditor
{
    public static function permission_to_edit_config_var(array|string $varname): bool
    {
        global $config, $mod;

        if (isset($config['mod']['config'][DISABLED])) {
            foreach ($config['mod']['config'][DISABLED] as $disabled_var_name) {
                $disabled_var_name = explode('>', $disabled_var_name);
                if (count($disabled_var_name) === 1) {
                    $disabled_var_name = $disabled_var_name[0];
                }
                if ($varname == $disabled_var_name) {
                    return false;
                }
            }
        }

        $allow_only = false;
        foreach ($config['mod']['groups'] as $perm => $perm_name) {
            if ($perm > $mod['type']) {
                break;
            }
            $allow_only = false;
            if (isset($config['mod']['config'][$perm]) && is_array($config['mod']['config'][$perm])) {
                foreach ($config['mod']['config'][$perm] as $perm_var_name) {
                    if ($perm_var_name == '!') {
                        $allow_only = true;
                        continue;
                    }
                    $perm_var_name = explode('>', $perm_var_name);
                    if (
                        (count($perm_var_name) === 1 && $varname == $perm_var_name[0]) ||
                        (is_array($varname) && array_slice($varname, 0, count($perm_var_name)) == $perm_var_name)
                    ) {
                        return $allow_only ? true : false;
                    }
                }
            }
        }

        return !$allow_only;
    }

    public static function config_vars(): array
    {
        global $config;

        $config_file = file('etc/config.php', FILE_IGNORE_NEW_LINES);
        $conf = [];

        $var = [
            'name' => false,
            'comment' => [],
            'default' => false,
            'default_temp' => false,
            'commented' => false,
            'permissions' => false,
        ];
        $temp_comment = false;
        $line_no = 0;

        foreach ($config_file as $line) {
            if ($temp_comment) {
                $var['comment'][] = $temp_comment;
                $temp_comment = false;
            }

            if (preg_match('!^\s*// ([^$].*)$!', $line, $matches)) {
                if ($var['default'] !== false) {
                    $line = '';
                    $temp_comment = $matches[1];
                } else {
                    $var['comment'][] = $matches[1];
                }
            } elseif ($var['default_temp'] !== false) {
                $var['default_temp'] .= "\n" . $line;
            } elseif (preg_match('!^[\s/]*\$config\[(.+?)\] = (.+?)(;( //.+)?)?$!', $line, $matches)) {
                if (preg_match('!^\s*//\s*!', $line)) {
                    $var['commented'] = true;
                }
                $var['name'] = explode('][', $matches[1]);
                if (count($var['name']) == 1) {
                    $var['name'] = preg_replace('/^\'(.*)\'$/', '$1', end($var['name']));
                } else {
                    foreach ($var['name'] as &$i) {
                        $i = preg_replace('/^\'(.*)\'$/', '$1', $i);
                    }
                }

                if (isset($matches[3])) {
                    $var['default'] = $matches[2];
                } else {
                    $var['default_temp'] = $matches[2];
                }
            }

            if ($var['name'] !== false) {
                if ($var['default_temp']) {
                    $var['default'] = $var['default_temp'];
                }
                if ($var['default'] && $var['default'][0] == '&') {
                    continue; // This is just an alias.
                }
                if (
                    isset($var['default']) && $var['default'] !== false &&
                    !preg_match('/^array|\[\]|function/', $var['default']) &&
                    !preg_match('/^\[/', trim($var['default'])) &&
                    !preg_match('/^Example: /', trim(implode(' ', $var['comment'])))
                ) {
                    $syntax_error = true;
                    $temp = eval('$syntax_error = false;return @' . $var['default'] . ';');
                    if ($syntax_error && $temp === false) {
                        error('Error parsing config.php (line ' . $line_no . ')!', null, $var);
                    } elseif (!isset($temp)) {
                        $var['type'] = 'unknown';
                    } else {
                        $var['type'] = gettype($temp);
                    }

                    if (
                        $var['type'] == 'integer' &&
                        ((is_array($var['name']) && $var['name'][0] == 'mod') || $var['name'] == 'mod') &&
                        (in_array($var['default'], ['JANITOR', 'MOD', 'ADMIN', 'DISABLED']) ||
                            mb_strpos($var['default'], "\$config['mod']") === 0)
                    ) {
                        // Permissions variable
                        $var['permissions'] = true;
                    }

                    unset($var['default_temp']);
                    if (
                        (!is_array($var['name']) || (end($var['name']) != '' && !in_array(reset($var['name']), ['stylesheets'])))
                    ) {
                        $already_exists = false;
                        foreach ($conf as $_var) {
                            if ($var['name'] == $_var['name']) {
                                $already_exists = true;
                            }
                        }
                        if (
                            !$already_exists &&
                            self::permission_to_edit_config_var($var['name'])
                        ) {
                            foreach ($var['comment'] as &$comment) {
                                $comment = preg_replace_callback(
                                    '/((?:https?:\/\/|ftp:\/\/|irc:\/\/)[^\s<>()"]+?(?:\([^\s<>()"]*?\)[^\s<>()"]*?)*)((?:\s|<|>|"|\.||\]|!|\?|,|&#44;|&quot;)*(?:[\s<>()"]|$))/',
                                    [MarkupService::class, 'markup_url'],
                                    $comment,
                                );
                            }
                            $conf[] = $var;
                        }
                    }
                }

                // Reset $var for next variable
                $var = [
                    'name' => false,
                    'comment' => [],
                    'default' => false,
                    'default_temp' => false,
                    'commented' => false,
                    'permissions' => false,
                ];
            }

            if (trim($line) === '') {
                $var['comment'] = [];
            }

            $line_no++;
        }

        return $conf;
    }
}
