<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Repository;

class BanAppealsRepository
{
    /**
     * Remove ban appeals that reference non-existent bans.
     *
     * @return void
     */
    public function removeStaleBanAppeals()
    {
        query("DELETE FROM ``ban_appeals`` WHERE NOT EXISTS (SELECT 1 FROM ``bans`` WHERE `ban_id` = ``bans``.`id`)")
            or error(db_error());
    }

    /**
     * Select a single ban appeal by id.
     *
     * @param int $id Appeal id.
     * @return array|false Associative array for the appeal or false if not found.
     */
    public function selectAppealById(int $id)
    {
        $query = query("SELECT *, ``ban_appeals``.`id` AS `id` FROM ``ban_appeals``
                LEFT JOIN ``bans`` ON `ban_id` = ``bans``.`id`
                WHERE ``ban_appeals``.`id` = " . (int) $id) or error(db_error());

        return $query->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Delete a ban appeal by id.
     *
     * @param int $id Appeal id.
     * @return void
     */
    public function deleteAppealById(int $id)
    {
        query("DELETE FROM ``ban_appeals`` WHERE `id` = " . $id) or error(db_error());
    }

    /**
     * Mark a ban appeal as denied by id.
     *
     * @param int $id Appeal id.
     * @return void
     */
    public function denyAppealById(int $id)
    {
        query("UPDATE ``ban_appeals`` SET `denied` = 1 WHERE `id` = " . $id) or error(db_error());
    }

    /**
     * Select active ban appeals.
     *
     * @return array List of associative arrays for active appeals.
     */
    public function selectActiveBanAppeals()
    {
        $query = query("SELECT *, ``ban_appeals``.`id` AS `id` FROM ``ban_appeals``
            LEFT JOIN ``bans`` ON `ban_id` = ``bans``.`id`
            WHERE `denied` != 1 ORDER BY `time`") or error(db_error());

        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Select thumb and file fields from a posts table for a given board uri and post id.
     *
     * @param string $boardUri Board URI used to build table name.
     * @param int $postId Post id.
     * @return array|false Associative array with 'thumb' and 'file' or false if not found.
     */
    public function selectPostThumbFile(string $boardUri, int $postId)
    {
        $query = query(sprintf("SELECT `thumb`, `file` FROM ``posts_%s`` WHERE `id` = " . (int) $postId, $boardUri));
        return $query->fetch(\PDO::FETCH_ASSOC);
    }
}
