<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Repository;

class ReportRepository
{
    /**
     * Get recent reports ordered by time descending.
     *
     * @param int $limit Number of reports to fetch.
     * @return array Array of report rows.
     */
    public function getRecentReports(int $limit): array
    {
        $query = prepare("SELECT * FROM ``reports`` ORDER BY `time` DESC LIMIT :limit");
        $query->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Fetch posts for a specific board by their IDs.
     *
     * @param array $posts List of post IDs.
     * @param string $board Board short name.
     * @return array Associative array of posts keyed by post id.
     */
    public function getPostsForBoard(array $posts, string $board): array
    {
        if (empty($posts)) {
            return [];
        }

        $query = query(sprintf('SELECT * FROM ``posts_%s`` WHERE `id` = ' . implode(' OR `id` = ', $posts), $board)) or error(db_error());
        $result = [];
        while ($post = $query->fetch(\PDO::FETCH_ASSOC)) {
            $result[$post['id']] = $post;
        }
        return $result;
    }

    /**
     * Delete a report by post ID and board.
     *
     * @param int $postId Post ID.
     * @param string $board Board short name.
     * @return void
     */
    public function deleteReportByPostAndBoard(int $postId, string $board): void
    {
        $query = prepare("DELETE FROM ``reports`` WHERE `post` = :id AND `board` = :board");
        $query->bindValue(':id', $postId, \PDO::PARAM_INT);
        $query->bindValue(':board', $board);
        $query->execute() or error(db_error($query));
    }

    /**
     * Get a single report row by its ID.
     *
     * @param int $id Report ID.
     * @return array|false|null Report row or false/null if not found.
     */
    public function getReportById(int $id)
    {
        $query = prepare("SELECT `post`, `board`, `ip` FROM ``reports`` WHERE `id` = :id");
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));
        return $query->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Delete all reports originating from a given IP.
     *
     * @param string $ip IP address.
     * @return void
     */
    public function deleteReportsByIp(string $ip): void
    {
        $query = prepare("DELETE FROM ``reports`` WHERE `ip` = :ip");
        $query->bindValue(':ip', $ip);
        $query->execute() or error(db_error($query));
    }

    /**
     * Delete a report by its ID.
     *
     * @param int $id Report ID.
     * @return void
     */
    public function deleteReportById(int $id): void
    {
        $query = prepare("DELETE FROM ``reports`` WHERE `id` = :id");
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));
    }
}
