<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Repository;

class UserRepository
{
    /**
     * Fetch a user by ID.
     *
     * @param int $id User ID.
     * @return array|false Associative array of user data or false if not found.
     */
    public function getById(int $id)
    {
        $query = prepare('SELECT * FROM ``mods`` WHERE `id` = :id');
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));
        return $query->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Delete a user by ID.
     *
     * @param int $id User ID.
     * @return void
     */
    public function deleteById(int $id): void
    {
        $query = prepare('DELETE FROM ``mods`` WHERE `id` = :id');
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));
    }

    /**
     * Update username and boards for a user.
     *
     * @param int $id User ID.
     * @param string $username New username.
     * @param string $boards Comma-separated board list.
     * @return void
     */
    public function updateUsernameBoards(int $id, string $username, string $boards): void
    {
        $query = prepare('UPDATE ``mods`` SET `username` = :username, `boards` = :boards WHERE `id` = :id');
        $query->bindValue(':id', $id);
        $query->bindValue(':username', $username);
        $query->bindValue(':boards', $boards);
        $query->execute() or error(db_error($query));
    }

    /**
     * Update password hash and salt for a user.
     *
     * @param int $id User ID.
     * @param string $password Hashed password.
     * @param string $salt Salt used for hashing.
     * @return void
     */
    public function updatePasswordAndSalt(int $id, string $password, string $salt): void
    {
        $query = prepare('UPDATE ``mods`` SET `password` = :password, `salt` = :salt WHERE `id` = :id');
        $query->bindValue(':id', $id);
        $query->bindValue(':password', $password);
        $query->bindValue(':salt', $salt);
        $query->execute() or error(db_error($query));
    }

    /**
     * Get recent mod log entries for a user.
     *
     * @param int $id User ID.
     * @param int $limit Number of log entries to return.
     * @return array List of mod log entries.
     */
    public function getModLogs(int $id, int $limit = 5): array
    {
        $query = prepare('SELECT * FROM ``modlogs`` WHERE `mod` = :id ORDER BY `time` DESC LIMIT ' . (int) $limit);
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Insert a new user.
     *
     * @param string $username Username.
     * @param string $password Hashed password.
     * @param string $salt Salt.
     * @param int $type User group/type.
     * @param string $boards Comma-separated boards.
     * @return string Last insert ID.
     */
    public function insertUser(string $username, string $password, string $salt, int $type, string $boards)
    {
        global $pdo;
        $query = prepare('INSERT INTO ``mods`` VALUES (NULL, :username, :password, :salt, :type, :boards)');
        $query->bindValue(':username', $username);
        $query->bindValue(':password', $password);
        $query->bindValue(':salt', $salt);
        $query->bindValue(':type', $type);
        $query->bindValue(':boards', $boards);
        $query->execute() or error(db_error($query));

        return $pdo->lastInsertId();
    }

    /**
     * Retrieve all users with last action info.
     *
     * @return array List of users.
     */
    public function getAllUsers(): array
    {
        $query = query("SELECT
            *,
            (SELECT `time` FROM ``modlogs`` WHERE `mod` = `id` ORDER BY `time` DESC LIMIT 1) AS `last`,
            (SELECT `text` FROM ``modlogs`` WHERE `mod` = `id` ORDER BY `time` DESC LIMIT 1) AS `action`
            FROM ``mods`` ORDER BY `type` DESC,`id`") or error(db_error());
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get type and username for a given user ID.
     *
     * @param int $id User ID.
     * @return array|false Associative array with keys 'type' and 'username', or false if not found.
     */
    public function getTypeAndUsername(int $id)
    {
        $query = prepare("SELECT `type`, `username` FROM ``mods`` WHERE `id` = :id");
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));
        return $query->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Update the group/type of a user.
     *
     * @param int $id User ID.
     * @param int $group_value New group value.
     * @return void
     */
    public function updateType(int $id, int $group_value): void
    {
        $query = prepare("UPDATE ``mods`` SET `type` = :group_value WHERE `id` = :id");
        $query->bindValue(':id', $id);
        $query->bindValue(':group_value', $group_value);
        $query->execute() or error(db_error($query));
    }
}
