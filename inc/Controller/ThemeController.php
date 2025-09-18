<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Manager\AuthManager;
use Sudochan\Manager\ThemeManager;
use Sudochan\Manager\PermissionManager;
use Sudochan\Utils\Token;

class ThemeController
{
    public function mod_themes_list(): void
    {
        global $config;

        if (!PermissionManager::hasPermission($config['mod']['themes'])) {
            error($config['error']['noaccess']);
        }

        if (!is_dir($config['dir']['themes'])) {
            error(_('Themes directory doesn\'t exist!'));
        }
        if (!$dir = opendir($config['dir']['themes'])) {
            error(_('Cannot open themes directory; check permissions.'));
        }

        $query = query('SELECT `theme` FROM ``theme_settings`` WHERE `name` IS NULL AND `value` IS NULL') or error(db_error());
        $themes_in_use = $query->fetchAll(\PDO::FETCH_COLUMN);

        // Scan directory for themes
        $themes = [];
        while ($file = readdir($dir)) {
            if ($file[0] != '.' && is_dir($config['dir']['themes'] . '/' . $file)) {
                $themes[$file] = ThemeManager::loadThemeConfig($file);
            }
        }
        closedir($dir);

        foreach ($themes as $theme_name => &$theme) {
            $theme['rebuild_token'] = Token::make_secure_link_token('themes/' . $theme_name . '/rebuild');
            $theme['uninstall_token'] = Token::make_secure_link_token('themes/' . $theme_name . '/uninstall');
        }

        mod_page(_('Manage themes'), 'mod/themes.html', [
            'themes' => $themes,
            'themes_in_use' => $themes_in_use,
        ]);
    }

    public function mod_theme_configure(string $theme_name): void
    {
        global $config;

        if (!PermissionManager::hasPermission($config['mod']['themes'])) {
            error($config['error']['noaccess']);
        }

        if (!$theme = ThemeManager::loadThemeConfig($theme_name)) {
            error($config['error']['invalidtheme']);
        }

        if (isset($_POST['install'])) {
            // Check if everything is submitted
            foreach ($theme['config'] as &$conf) {
                if (!isset($_POST[$conf['name']]) && $conf['type'] != 'checkbox') {
                    error(sprintf($config['error']['required'], $config['title']));
                }
            }

            // Clear previous settings
            $query = prepare("DELETE FROM ``theme_settings`` WHERE `theme` = :theme");
            $query->bindValue(':theme', $theme_name);
            $query->execute() or error(db_error($query));

            foreach ($theme['config'] as &$conf) {
                $query = prepare("INSERT INTO ``theme_settings`` VALUES(:theme, :name, :value)");
                $query->bindValue(':theme', $theme_name);
                $query->bindValue(':name', $conf['name']);
                if ($conf['type'] == 'checkbox') {
                    $query->bindValue(':value', isset($_POST[$conf['name']]) ? 1 : 0);
                } else {
                    $query->bindValue(':value', $_POST[$conf['name']]);
                }
                $query->execute() or error(db_error($query));
            }

            $query = prepare("INSERT INTO ``theme_settings`` VALUES(:theme, NULL, NULL)");
            $query->bindValue(':theme', $theme_name);
            $query->execute() or error(db_error($query));

            $result = true;
            $message = false;
            if (isset($theme['install_callback'])) {
                $ret = $theme['install_callback'](ThemeManager::themeSettings($theme_name));
                if ($ret && !empty($ret)) {
                    if (is_array($ret) && count($ret) == 2) {
                        $result = $ret[0];
                        $message = $ret[1];
                    }
                }
            }

            if (!$result) {
                // Install failed
                $query = prepare("DELETE FROM ``theme_settings`` WHERE `theme` = :theme");
                $query->bindValue(':theme', $theme_name);
                $query->execute() or error(db_error($query));
            }

            // Build themes
            ThemeManager::rebuildThemes('all');

            mod_page(sprintf(_($result ? 'Installed theme: %s' : 'Installation failed: %s'), $theme['name']), 'mod/theme_installed.html', [
                'theme_name' => $theme_name,
                'theme' => $theme,
                'result' => $result,
                'message' => $message,
            ]);
            return;
        }

        $settings = ThemeManager::themeSettings($theme_name);

        mod_page(sprintf(_('Configuring theme: %s'), $theme['name']), 'mod/theme_config.html', [
            'theme_name' => $theme_name,
            'theme' => $theme,
            'settings' => $settings,
            'token' => Token::make_secure_link_token('themes/' . $theme_name),
        ]);
    }

    public function mod_theme_uninstall(string $theme_name): void
    {
        global $config;

        if (!PermissionManager::hasPermission($config['mod']['themes'])) {
            error($config['error']['noaccess']);
        }

        $query = prepare("DELETE FROM ``theme_settings`` WHERE `theme` = :theme");
        $query->bindValue(':theme', $theme_name);
        $query->execute() or error(db_error($query));

        header('Location: ?/themes', true, $config['redirect_http']);
    }

    public function mod_theme_rebuild(string $theme_name): void
    {
        global $config;

        if (!PermissionManager::hasPermission($config['mod']['themes'])) {
            error($config['error']['noaccess']);
        }

        ThemeManager::rebuildTheme($theme_name, 'all');

        mod_page(sprintf(_('Rebuilt theme: %s'), $theme_name), 'mod/theme_rebuilt.html', [
            'theme_name' => $theme_name,
        ]);
    }
}
