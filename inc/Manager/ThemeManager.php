<?php

namespace Sudochan\Manager;

class ThemeManager
{
    public static function rebuildThemes(string $action, string|false $board = false): void
    {
        // List themes
        $query = query("SELECT `theme` FROM ``theme_settings`` WHERE `name` IS NULL AND `value` IS NULL") or error(db_error());

        while ($theme = $query->fetch(\PDO::FETCH_ASSOC)) {
            self::rebuildTheme($theme['theme'], $action, $board);
        }
    }

    public static function loadThemeConfig(string $_theme): array|false
    {
        global $config;

        if (!file_exists($config['dir']['themes'] . '/' . $_theme . '/info.php')) {
            return false;
        }

        // Load theme information into $theme
        include $config['dir']['themes'] . '/' . $_theme . '/info.php';

        return $theme;
    }

    public static function rebuildTheme(string $theme, string $action, string|false $board = false): void
    {
        global $config, $_theme;
        $_theme = $theme;

        $theme = self::loadThemeConfig($_theme);

        if (file_exists($config['dir']['themes'] . '/' . $_theme . '/theme.php')) {
            require_once $config['dir']['themes'] . '/' . $_theme . '/theme.php';

            $theme['build_function']($action, self::themeSettings($_theme), $board);
        }
    }

    public static function themeSettings(string $theme): array
    {
        $query = prepare("SELECT `name`, `value` FROM ``theme_settings`` WHERE `theme` = :theme AND `name` IS NOT NULL");
        $query->bindValue(':theme', $theme);
        $query->execute() or error(db_error($query));

        $settings = [];
        while ($s = $query->fetch(\PDO::FETCH_ASSOC)) {
            $settings[$s['name']] = $s['value'];
        }

        return $settings;
    }
}
