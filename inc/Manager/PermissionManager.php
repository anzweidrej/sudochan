<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Manager;

class PermissionManager
{
    /**
     * Check if a moderator has a permission.
     *
     * @param int|null    $action Minimum mod type required
     * @param string|null $board  Board name
     * @param array|null  $_mod   Optional mod data instead of global $mod
     * @return bool
     */
    public static function hasPermission(?int $action = null, ?string $board = null, ?array $_mod = null): bool
    {
        global $config;

        if (isset($_mod)) {
            $mod = &$_mod;
        } else {
            global $mod;
        }

        if (!is_array($mod)) {
            return false;
        }

        if (isset($action) && $mod['type'] < $action) {
            return false;
        }

        if (!isset($board) || $config['mod']['skip_per_board']) {
            return true;
        }

        if (!isset($mod['boards'])) {
            return false;
        }

        if (!in_array('*', $mod['boards']) && !in_array($board, $mod['boards'])) {
            return false;
        }

        return true;
    }

    /**
     * Define mod group constants and sort them.
     *
     * @global array $config
     * @return void
     */
    public static function define_groups()
    {
        global $config;

        foreach ($config['mod']['groups'] as $group_value => $group_name) {
            $group_name = strtoupper($group_name);
            if (!defined($group_name)) {
                define($group_name, $group_value);
            }
        }

        ksort($config['mod']['groups']);
    }
}
