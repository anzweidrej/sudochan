<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Manager\AuthManager;
use Sudochan\Mod\ConfigEditor;
use Sudochan\Service\MarkupService;
use Sudochan\Service\BoardService;
use Sudochan\Manager\PermissionManager;
use Sudochan\Utils\StringFormatter;
use Sudochan\Utils\Token;

class ConfigController
{
    /**
     * Handle the moderator config editor page and submission.
     *
     * @param string|false $board_config Board identifier to edit, or false for global.
     * @return void
     */
    public function mod_config(string|false $board_config = false): void
    {
        global $config, $mod, $board;

        if ($board_config && !BoardService::openBoard($board_config)) {
            error($config['error']['noboard']);
        }

        if (!PermissionManager::hasPermission($config['mod']['edit_config'], $board_config)) {
            error($config['error']['noaccess']);
        }

        $config_file = $board_config ? $board['dir'] . 'config.php' : '../../instance-config.php';

        if ($config['mod']['config_editor_php']) {
            $readonly = !(is_file($config_file) ? is_writable($config_file) : is_writable(dirname($config_file)));

            if (!$readonly && isset($_POST['code'])) {
                $code = $_POST['code'];
                file_put_contents($config_file, $code);
                header('Location: ?/config' . ($board_config ? '/' . $board_config : ''), true, $config['redirect_http']);
                return;
            }

            $instance_config = @file_get_contents($config_file);
            if ($instance_config === false) {
                $instance_config = "<?php\n\n// This file does not exist yet. You are creating it.";
            }
            $instance_config = str_replace("\n", '&#010;', StringFormatter::utf8tohtml($instance_config));

            mod_page(_('Config editor'), 'mod/config-editor-php.html', [
                'php' => $instance_config,
                'readonly' => $readonly,
                'boards' => BoardService::listBoards(),
                'board' => $board_config,
                'file' => $config_file,
                'token' => Token::make_secure_link_token('config' . ($board_config ? '/' . $board_config : '')),
            ]);
            return;
        }

        $conf = self::config_vars();

        foreach ($conf as &$var) {
            if (is_array($var['name'])) {
                $c = &$config;
                foreach ($var['name'] as $n) {
                    $c = &$c[$n];
                }
            } else {
                $c = @$config[$var['name']];
            }

            $var['value'] = $c;
        }
        unset($var);

        if (isset($_POST['save'])) {
            $config_append = '';

            foreach ($conf as $var) {
                $field_name = 'cf_' . (is_array($var['name']) ? implode('/', $var['name']) : $var['name']);

                if ($var['type'] == 'boolean') {
                    $value = isset($_POST[$field_name]);
                } elseif (isset($_POST[$field_name])) {
                    $value = $_POST[$field_name];
                } else {
                    continue;
                } // ???

                if (!settype($value, $var['type'])) {
                    continue;
                } // invalid

                if ($value != $var['value']) {
                    // This value has been changed.

                    $config_append .= '$config';

                    if (is_array($var['name'])) {
                        foreach ($var['name'] as $name) {
                            $config_append .= '[' . var_export($name, true) . ']';
                        }
                    } else {
                        $config_append .= '[' . var_export($var['name'], true) . ']';
                    }


                    $config_append .= ' = ';
                    if (@$var['permissions'] && isset($config['mod']['groups'][$value])) {
                        $config_append .= $config['mod']['groups'][$value];
                    } else {
                        $config_append .= var_export($value, true);
                    }
                    $config_append .= ";\n";
                }
            }

            if (!empty($config_append)) {
                $config_append = "\n// Changes made via web editor by \"" . $mod['username'] . "\" @ " . date('r') . ":\n" . $config_append . "\n";
                if (!is_file($config_file)) {
                    $config_append = "<?php\n\n$config_append";
                }
                if (!@file_put_contents($config_file, $config_append, FILE_APPEND)) {
                    $config_append = htmlentities($config_append);

                    if ($config['minify_html']) {
                        $config_append = str_replace("\n", '&#010;', $config_append);
                    }
                    $page = [];
                    $page['title'] = 'Cannot write to file!';
                    $page['config'] = $config;
                    $page['body'] = '
                        <p style="text-align:center">Sudochan could not write to <strong>' . $config_file . '</strong> with the ammended configuration, probably due to a permissions error.</p>
                        <p style="text-align:center">You may proceed with these changes manually by copying and pasting the following code to the end of <strong>' . $config_file . '</strong>:</p>
                        <textarea style="width:700px;height:370px;margin:auto;display:block;background:white;color:black" readonly>' . $config_append . '</textarea>
                    ';
                    echo element('page.html', $page);
                    exit;
                }
            }

            header('Location: ?/config' . ($board_config ? '/' . $board_config : ''), true, $config['redirect_http']);

            exit;
        }

        mod_page(
            _('Config editor') . ($board_config ? ': ' . sprintf($config['board_abbreviation'], $board_config) : ''),
            'mod/config-editor.html',
            [
                'boards' => BoardService::listBoards(),
                'board' => $board_config,
                'conf' => $conf,
                'file' => $config_file,
                'token' => Token::make_secure_link_token('config' . ($board_config ? '/' . $board_config : '')),
            ],
        );
    }

    /**
     * Parse editable config vars from config.php file.
     *
     * @return array<int,array<string,mixed>>
     */
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
                    isset($var['default']) && $var['default'] !== false
                    && !preg_match('/^array|\[\]|function/', $var['default'])
                    && !preg_match('/^\[/', trim($var['default']))
                    && !preg_match('/^Example: /', trim(implode(' ', $var['comment'])))
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
                        $var['type'] == 'integer'
                        && ((is_array($var['name']) && $var['name'][0] == 'mod') || $var['name'] == 'mod')
                        && (in_array($var['default'], ['JANITOR', 'MOD', 'ADMIN', 'DISABLED'])
                            || mb_strpos($var['default'], "\$config['mod']") === 0)
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
                            !$already_exists
                            && PermissionManager::permission_to_edit_config_var($var['name'])
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
