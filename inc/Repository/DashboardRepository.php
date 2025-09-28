<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Repository;

class DashboardRepository
{
    /**
     * Get recent noticeboard entries for dashboard preview.
     *
     * @param int $limit Number of entries to fetch.
     * @return array Array of noticeboard rows.
     */
    public function getNoticeboardPreview(int $limit)
    {
        $query = prepare("SELECT ``noticeboard``.*, `username` FROM ``noticeboard`` LEFT JOIN ``mods`` ON ``mods``.`id` = `mod` ORDER BY `id` DESC LIMIT :limit");
        $query->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Count unread private messages for a moderator.
     *
     * @param int $modId Moderator ID.
     * @return int Number of unread PMs.
     */
    public function getUnreadPmCount(int $modId)
    {
        $query = prepare('SELECT COUNT(*) FROM ``pms`` WHERE `to` = :id AND `unread` = 1');
        $query->bindValue(':id', $modId);
        $query->execute() or error(db_error($query));
        return $query->fetchColumn();
    }

    /**
     * Get total number of reports.
     *
     * @return int Number of reports.
     */
    public function getReportsCount()
    {
        $query = query('SELECT COUNT(*) FROM ``reports``') or error(db_error($query));
        return $query->fetchColumn();
    }

    /**
     * Get posts that are not part of any thread for a board.
     *
     * @param string $boardUri Board URI.
     * @return array Array of posts (each as assoc array).
     */
    public function getPostsWithoutThread(string $boardUri): array
    {
        $query = query(sprintf("SELECT `id` FROM ``posts_%s`` WHERE `thread` IS NULL", $boardUri)) or error(db_error());
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }
}
