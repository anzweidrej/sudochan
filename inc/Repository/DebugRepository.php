<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Repository;

use Sudochan\Service\BoardService;

class DebugRepository
{
    /**
     * Count antispam entries.
     *
     * @param string $where Optional WHERE clause (without the 'WHERE' keyword).
     * @return int Number of matching antispam rows.
     */
    public function countAntispam(string $where = ''): int
    {
        $query = query('SELECT COUNT(*) FROM ``antispam``' . ($where ? " WHERE $where" : '')) or error(db_error());
        return (int) $query->fetchColumn();
    }

    /**
     * Count antispam entries that have an expiry.
     *
     * @param string $where Optional additional WHERE condition (without the 'WHERE' keyword).
     * @return int Number of expiring antispam rows.
     */
    public function countExpiringAntispam(string $where = ''): int
    {
        $query = query('SELECT COUNT(*) FROM ``antispam`` WHERE `expires` IS NOT NULL' . ($where ? " AND $where" : '')) or error(db_error());
        return (int) $query->fetchColumn();
    }

    /**
     * Get top antispam entries ordered by passed count.
     *
     * @param string $where Optional WHERE clause (without the 'WHERE' keyword).
     * @return array Associative array of antispam rows.
     */
    public function getTopAntispam(string $where = ''): array
    {
        $query = query('SELECT * FROM ``antispam`` ' . ($where ? "WHERE $where" : '') . ' ORDER BY `passed` DESC LIMIT 40') or error(db_error());
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get most recent antispam entries.
     *
     * @param string $where Optional WHERE clause (without the 'WHERE' keyword).
     * @return array Associative array of recent antispam rows.
     */
    public function getRecentAntispam(string $where = ''): array
    {
        $query = query('SELECT * FROM ``antispam`` ' . ($where ? "WHERE $where" : '') . ' ORDER BY `created` DESC LIMIT 20') or error(db_error());
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get flood log posts.
     *
     * @return array Associative array of flood rows ordered by time desc.
     */
    public function getFloodPosts(): array
    {
        $query = query("SELECT * FROM ``flood`` ORDER BY `time` DESC") or error(db_error());
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get recent posts from all boards.
     *
     * @param int $limit Maximum number of posts to return.
     * @return array Associative array of recent posts including a `board` field.
     */
    public function getRecentPosts(int $limit = 500): array
    {
        global $pdo;

        $boards = BoardService::listBoards();

        $query = 'SELECT * FROM (';
        foreach ($boards as $board) {
            $query .= sprintf('SELECT *, %s AS `board` FROM ``posts_%s`` UNION ALL ', $pdo->quote($board['uri']), $board['uri']);
        }
        $query = preg_replace('/UNION ALL $/', ') AS `all_posts` ORDER BY `time` DESC LIMIT ' . $limit, $query);
        $query = query($query) or error(db_error());
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Purge / update antispam entries matching WHERE clause.
     *
     * @param string $where
     * @param int $expires seconds to add to UNIX_TIMESTAMP()
     * @return void
     */
    public function purgeAntispam(string $where, int $expires): void
    {
        $sql = 'UPDATE ``antispam`` SET `expires` = UNIX_TIMESTAMP() + :expires' . ($where ? " WHERE $where" : '');
        $query = prepare($sql);
        $query->bindValue(':expires', $expires, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
    }
}
