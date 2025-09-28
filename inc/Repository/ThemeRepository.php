<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Repository;

class ThemeRepository
{
    /**
     * Get themes currently in use.
     *
     * @return string[] Array of theme names
     */
    public function getThemesInUse(): array
    {
        $query = query('SELECT `theme` FROM ``theme_settings`` WHERE `name` IS NULL AND `value` IS NULL') or error(db_error());
        return $query->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Delete all settings for a given theme.
     *
     * @param string $theme Theme directory/name
     * @return void
     */
    public function clearThemeSettings(string $theme): void
    {
        $query = prepare("DELETE FROM ``theme_settings`` WHERE `theme` = :theme");
        $query->bindValue(':theme', $theme);
        $query->execute() or error(db_error($query));
    }

    /**
     * Insert a theme setting.
     *
     * @param string      $theme Theme directory/name
     * @param string|null $name  Setting name (NULL for marker row)
     * @param mixed       $value Setting value
     * @return void
     */
    public function insertThemeSetting(string $theme, ?string $name, $value): void
    {
        $query = prepare("INSERT INTO ``theme_settings`` VALUES(:theme, :name, :value)");
        $query->bindValue(':theme', $theme);
        $query->bindValue(':name', $name);
        $query->bindValue(':value', $value);
        $query->execute() or error(db_error($query));
    }
}
