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
     * @param string|null $board  Board name (or null)
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

    /**
     * Check if the current moderator is permitted to edit a config variable.
     *
     * @param array|string $varname Variable name or path segments.
     * @return bool True if editing is allowed.
     */
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
                        (count($perm_var_name) === 1 && $varname == $perm_var_name[0])
                        || (is_array($varname) && array_slice($varname, 0, count($perm_var_name)) == $perm_var_name)
                    ) {
                        return $allow_only ? true : false;
                    }
                }
            }
        }

        return !$allow_only;
    }
}
