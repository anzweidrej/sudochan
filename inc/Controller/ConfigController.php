<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Mod\Auth;
use Sudochan\Mod\ConfigEditor;

class ConfigController
{
    public function mod_config(string|false $board_config = false): void
    {
        global $config, $mod, $board;

        if ($board_config && !openBoard($board_config)) {
            error($config['error']['noboard']);
        }

        if (!hasPermission($config['mod']['edit_config'], $board_config)) {
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
            $instance_config = str_replace("\n", '&#010;', utf8tohtml($instance_config));

            mod_page(_('Config editor'), 'mod/config-editor-php.html', [
                'php' => $instance_config,
                'readonly' => $readonly,
                'boards' => listBoards(),
                'board' => $board_config,
                'file' => $config_file,
                'token' => Auth::make_secure_link_token('config' . ($board_config ? '/' . $board_config : '')),
            ]);
            return;
        }

        require_once 'inc/mod/ConfigEditor.php';

        $conf = (new ConfigEditor())->config_vars();

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
                'boards' => listBoards(),
                'board' => $board_config,
                'conf' => $conf,
                'file' => $config_file,
                'token' => Auth::make_secure_link_token('config' . ($board_config ? '/' . $board_config : '')),
            ],
        );
    }
}
