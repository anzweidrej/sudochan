<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Manager;

class PermissionManager
{
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
}
