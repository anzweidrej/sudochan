<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Repository;

class IpNoteRepository
{
    /**
     * Delete an IP note by IP and id.
     *
     * @param string $ip
     * @param int    $id
     * @return void
     */
    public function removeNote(string $ip, int $id): void
    {
        $query = prepare('DELETE FROM ``ip_notes`` WHERE `ip` = :ip AND `id` = :id');
        $query->bindValue(':ip', $ip);
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));
    }

    /**
     * Insert a new IP note.
     *
     * @param string $ip
     * @param int    $modId
     * @param string $body
     * @return void
     */
    public function insertNote(string $ip, int $modId, string $body): void
    {
        $query = prepare('INSERT INTO ``ip_notes`` VALUES (NULL, :ip, :mod, :time, :body)');
        $query->bindValue(':ip', $ip, \PDO::PARAM_STR);
        $query->bindValue(':mod', $modId, \PDO::PARAM_INT);
        $query->bindValue(':time', time(), \PDO::PARAM_INT);
        $query->bindValue(':body', $body, \PDO::PARAM_STR);
        $query->execute() or error(db_error($query));
    }

    /**
     * Prepare and execute a query to fetch recent posts for a board by IP.
     *
     * @param array  $board
     * @param string $ip
     * @return array
     */
    public function getPostsQuery(array $board, string $ip): array
    {
        global $config;

        $query = prepare(sprintf('SELECT * FROM ``posts_%s`` WHERE `ip` = :ip ORDER BY `sticky` DESC, `id` DESC LIMIT :limit', $board['uri']));
        $query->bindValue(':ip', $ip, \PDO::PARAM_STR);
        $query->bindValue(':limit', $config['mod']['ip_recentposts'], \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Fetch IP notes joined with moderator usernames.
     *
     * @param string $ip
     * @return mixed PDO statement
     */
    public function getIpNotesQuery(string $ip)
    {
        $query = prepare("SELECT ``ip_notes``.*, `username` FROM ``ip_notes`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE `ip` = :ip ORDER BY `time` DESC");
        $query->bindValue(':ip', $ip);
        $query->execute() or error(db_error($query));
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get modlog entries containing an IP fragment.
     *
     * @param string $ip
     * @return void
     */
    public function getModLogsByIpQuery(string $ip)
    {
        $query = prepare("SELECT `username`, `mod`, `ip`, `board`, `time`, `text` FROM ``modlogs`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE `text` LIKE :search ORDER BY `time` DESC LIMIT 50");
        $query->bindValue(':search', '%' . $ip . '%');
        $query->execute() or error(db_error($query));
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }
}
