<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Manager\PermissionManager;
use Sudochan\Repository\LogRepository;

class LogController
{
    private LogRepository $repository;

    public function __construct(LogRepository $repository = null)
    {
        $this->repository = $repository ?? new LogRepository();
    }

    /**
     * Display the global moderation log with pagination.
     *
     * @param int $page_no Page number.
     * @return void
     */
    public function mod_log(int $page_no = 1): void
    {
        global $config;

        if ($page_no < 1) {
            error($config['error']['404']);
        }

        if (!PermissionManager::hasPermission($config['mod']['modlog'])) {
            error($config['error']['noaccess']);
        }

        $offset = ($page_no - 1) * $config['mod']['modlog_page'];
        $limit = $config['mod']['modlog_page'];

        $logs = $this->repository->getLogs($offset, $limit);

        if (empty($logs) && $page_no > 1) {
            error($config['error']['404']);
        }

        $count = $this->repository->countLogs();

        mod_page(_('Moderation log'), 'mod/log.html', ['logs' => $logs, 'count' => $count]);
    }

    /**
     * Display the moderation log for a specific user with pagination.
     *
     * @param string $username Username to filter logs by.
     * @param int    $page_no  Page number.
     * @return void
     */
    public function mod_user_log(string $username, int $page_no = 1): void
    {
        global $config;

        if ($page_no < 1) {
            error($config['error']['404']);
        }

        if (!PermissionManager::hasPermission($config['mod']['modlog'])) {
            error($config['error']['noaccess']);
        }

        $offset = ($page_no - 1) * $config['mod']['modlog_page'];
        $limit = $config['mod']['modlog_page'];

        $logs = $this->repository->getUserLogs($username, $offset, $limit);

        if (empty($logs) && $page_no > 1) {
            error($config['error']['404']);
        }

        $count = $this->repository->countUserLogs($username);

        mod_page(_('Moderation log'), 'mod/log.html', ['logs' => $logs, 'count' => $count, 'username' => $username]);
    }
}
