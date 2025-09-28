<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Repository;

class NoticeboardRepository
{
    /**
     * Insert a new noticeboard entry.
     *
     * @param int $mod      Moderator ID creating the entry.
     * @param int $time     Timestamp of creation.
     * @param string $subject  Entry subject.
     * @param string $body     Preformatted/marked-up body.
     * @return int Inserted entry ID.
     */
    public function insertNotice(int $mod, int $time, string $subject, string $body): int
    {
        global $pdo;

        $query = prepare('INSERT INTO ``noticeboard`` VALUES (NULL, :mod, :time, :subject, :body)');
        $query->bindValue(':mod', $mod);
        $query->bindValue(':time', $time);
        $query->bindValue(':subject', $subject);
        $query->bindValue(':body', $body);
        $query->execute() or error(db_error($query));

        return (int) $pdo->lastInsertId();
    }

    /**
     * Fetch a page of noticeboard entries.
     *
     * @param int $offset Result offset.
     * @param int $limit  Number of entries to fetch.
     * @return array Array of noticeboard rows.
     */
    public function fetchNoticeboard(int $offset, int $limit): array
    {
        $query = prepare("SELECT ``noticeboard``.*, `username` FROM ``noticeboard`` LEFT JOIN ``mods`` ON ``mods``.`id` = `mod` ORDER BY `id` DESC LIMIT :offset, :limit");
        $query->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $query->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Count total noticeboard entries.
     *
     * @return int Total number of entries.
     */
    public function countNoticeboard(): int
    {
        $query = prepare("SELECT COUNT(*) FROM ``noticeboard``");
        $query->execute() or error(db_error($query));
        return (int) $query->fetchColumn();
    }

    /**
     * Delete a noticeboard entry by ID.
     *
     * @param int $id Entry ID to delete.
     * @return void
     */
    public function deleteNotice(int $id): void
    {
        $query = prepare('DELETE FROM ``noticeboard`` WHERE `id` = :id');
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));
    }
}
