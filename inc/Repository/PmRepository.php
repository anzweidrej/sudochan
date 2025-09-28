<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Repository;

class PmRepository
{
    /**
     * Get PM by id, including sender and recipient usernames.
     *
     * @param int $id PM id
     * @return array|false Associative array of PM data or false if not found
     */
    public function getById(int $id)
    {
        $query = prepare("SELECT ``mods``.`username`, `mods_to`.`username` AS `to_username`, ``pms``.* FROM ``pms`` LEFT JOIN ``mods`` ON ``mods``.`id` = `sender` LEFT JOIN ``mods`` AS `mods_to` ON `mods_to`.`id` = `to` WHERE ``pms``.`id` = :id");
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));

        return $query->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Delete a PM by id.
     *
     * @param int $id PM id
     * @return void
     */
    public function deleteById(int $id): void
    {
        $query = prepare("DELETE FROM ``pms`` WHERE `id` = :id");
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));
    }

    /**
     * Mark a PM as read.
     *
     * @param int $id PM id
     * @return void
     */
    public function markAsRead(int $id): void
    {
        $query = prepare("UPDATE ``pms`` SET `unread` = 0 WHERE `id` = :id");
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));
    }

    /**
     * Get inbox messages for a moderator.
     *
     * @param int $modId Moderator id
     * @return array List of messages as associative arrays
     */
    public function getInboxForMod(int $modId): array
    {
        $query = prepare('SELECT `unread`,``pms``.`id`, `time`, `sender`, `to`, `message`, `username` FROM ``pms`` LEFT JOIN ``mods`` ON ``mods``.`id` = `sender` WHERE `to` = :mod ORDER BY `unread` DESC, `time` DESC');
        $query->bindValue(':mod', $modId);
        $query->execute() or error(db_error($query));
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Count unread PMs for a moderator.
     *
     * @param int $modId Moderator id
     * @return int Unread count
     */
    public function countUnreadForMod(int $modId): int
    {
        $query = prepare('SELECT COUNT(*) FROM ``pms`` WHERE `to` = :mod AND `unread` = 1');
        $query->bindValue(':mod', $modId);
        $query->execute() or error(db_error($query));
        return (int) $query->fetchColumn();
    }

    /**
     * Find a moderator id by username.
     *
     * @param string $username Moderator username
     * @return mixed Moderator id or false if not found
     */
    public function findModIdByUsername(string $username)
    {
        $query = prepare("SELECT `id` FROM ``mods`` WHERE `username` = :username");
        $query->bindValue(':username', $username);
        $query->execute() or error(db_error($query));
        return $query->fetchColumn();
    }

    /**
     * Find a moderator username by id.
     *
     * @param mixed $id Moderator id
     * @return mixed Username or false if not found
     */
    public function findModUsernameById($id)
    {
        $query = prepare("SELECT `username` FROM ``mods`` WHERE `id` = :username");
        $query->bindValue(':username', $id);
        $query->execute() or error(db_error($query));
        return $query->fetchColumn();
    }

    /**
     * Insert a new PM.
     *
     * @param mixed $me Sender moderator id
     * @param mixed $id Recipient moderator id
     * @param string $message Message content (already processed)
     * @param int $time Timestamp
     * @return void
     */
    public function insertPm($me, $id, $message, $time): void
    {
        $query = prepare("INSERT INTO ``pms`` VALUES (NULL, :me, :id, :message, :time, 1)");
        $query->bindValue(':me', $me);
        $query->bindValue(':id', $id);
        $query->bindValue(':message', $message);
        $query->bindValue(':time', $time);
        $query->execute() or error(db_error($query));
    }
}
