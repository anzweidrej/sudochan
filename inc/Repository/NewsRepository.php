<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Repository;

class NewsRepository
{
    /**
     * Insert a news entry.
     *
     * @param string $name    Display name for the news entry.
     * @param int    $time    Timestamp of the news entry.
     * @param string $subject Subject/title of the news entry.
     * @param string $body    Marked-up body of the news entry.
     *
     * @return int Inserted news id.
     */
    public function insert(string $name, int $time, string $subject, string $body): int
    {
        global $pdo;

        $query = prepare('INSERT INTO ``news`` VALUES (NULL, :name, :time, :subject, :body)');
        $query->bindValue(':name', $name);
        $query->bindValue(':time', $time);
        $query->bindValue(':subject', $subject);
        $query->bindValue(':body', $body);
        $query->execute() or error(db_error($query));

        return (int) $pdo->lastInsertId();
    }

    /**
     * Fetch a page of news entries.
     *
     * @param int $offset Row offset.
     * @param int $limit  Number of rows to fetch.
     *
     * @return array Associative array of news rows.
     */
    public function fetchPage(int $offset, int $limit): array
    {
        $query = prepare("SELECT * FROM ``news`` ORDER BY `id` DESC LIMIT :offset, :limit");
        $query->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $query->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Count total news entries.
     *
     * @return int Total number of news rows.
     */
    public function count(): int
    {
        $query = prepare("SELECT COUNT(*) FROM ``news``");
        $query->execute() or error(db_error($query));
        return (int) $query->fetchColumn();
    }

    /**
     * Delete a news entry by id.
     *
     * @param int $id News entry id.
     *
     * @return void
     */
    public function deleteById(int $id): void
    {
        $query = prepare('DELETE FROM ``news`` WHERE `id` = :id');
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));
    }
}
