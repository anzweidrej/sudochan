<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Repository;

class LogRepository
{
    /**
     * Fetch moderation logs with pagination.
     *
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function getLogs(int $offset, int $limit): array
    {
        global $config;

        $query = prepare("SELECT `username`, `mod`, `ip`, `board`, `time`, `text` FROM ``modlogs`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` ORDER BY `time` DESC LIMIT :offset, :limit");
        $query->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $query->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Count total moderation logs.
     *
     * @return int
     */
    public function countLogs(): int
    {
        $query = prepare("SELECT COUNT(*) FROM ``modlogs``");
        $query->execute() or error(db_error($query));
        return (int) $query->fetchColumn();
    }

    /**
     * Fetch moderation logs for a specific username with pagination.
     *
     * @param string $username
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function getUserLogs(string $username, int $offset, int $limit): array
    {
        global $config;

        $query = prepare("SELECT `username`, `mod`, `ip`, `board`, `time`, `text` FROM ``modlogs`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE `username` = :username ORDER BY `time` DESC LIMIT :offset, :limit");
        $query->bindValue(':username', $username, \PDO::PARAM_STR);
        $query->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $query->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Count moderation logs for a specific username.
     *
     * @param string $username
     * @return int
     */
    public function countUserLogs(string $username): int
    {
        $query = prepare("SELECT COUNT(*) FROM ``modlogs`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE `username` = :username");
        $query->bindValue(':username', $username);
        $query->execute() or error(db_error($query));
        return (int) $query->fetchColumn();
    }
}
