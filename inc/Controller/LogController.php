<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Manager\PermissionManager;

class LogController
{
    public function mod_log(int $page_no = 1): void
    {
        global $config;

        if ($page_no < 1) {
            error($config['error']['404']);
        }

        if (!PermissionManager::hasPermission($config['mod']['modlog'])) {
            error($config['error']['noaccess']);
        }

        $query = prepare("SELECT `username`, `mod`, `ip`, `board`, `time`, `text` FROM ``modlogs`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` ORDER BY `time` DESC LIMIT :offset, :limit");
        $query->bindValue(':limit', $config['mod']['modlog_page'], \PDO::PARAM_INT);
        $query->bindValue(':offset', ($page_no - 1) * $config['mod']['modlog_page'], \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        $logs = $query->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($logs) && $page_no > 1) {
            error($config['error']['404']);
        }

        $query = prepare("SELECT COUNT(*) FROM ``modlogs``");
        $query->execute() or error(db_error($query));
        $count = $query->fetchColumn();

        mod_page(_('Moderation log'), 'mod/log.html', ['logs' => $logs, 'count' => $count]);
    }

    public function mod_user_log(string $username, int $page_no = 1): void
    {
        global $config;

        if ($page_no < 1) {
            error($config['error']['404']);
        }

        if (!PermissionManager::hasPermission($config['mod']['modlog'])) {
            error($config['error']['noaccess']);
        }

        $query = prepare("SELECT `username`, `mod`, `ip`, `board`, `time`, `text` FROM ``modlogs`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE `username` = :username ORDER BY `time` DESC LIMIT :offset, :limit");
        $query->bindValue(':username', $username);
        $query->bindValue(':limit', $config['mod']['modlog_page'], \PDO::PARAM_INT);
        $query->bindValue(':offset', ($page_no - 1) * $config['mod']['modlog_page'], \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        $logs = $query->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($logs) && $page_no > 1) {
            error($config['error']['404']);
        }

        $query = prepare("SELECT COUNT(*) FROM ``modlogs`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE `username` = :username");
        $query->bindValue(':username', $username);
        $query->execute() or error(db_error($query));
        $count = $query->fetchColumn();

        mod_page(_('Moderation log'), 'mod/log.html', ['logs' => $logs, 'count' => $count, 'username' => $username]);
    }
}
