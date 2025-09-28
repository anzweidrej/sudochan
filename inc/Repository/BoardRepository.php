<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Repository;

class BoardRepository
{
    /**
     * Delete a board row by URI.
     *
     * @param string $uri Board URI.
     * @return \PDOStatement
     */
    public function deleteBoardUri(string $uri)
    {
        $query = prepare('DELETE FROM ``boards`` WHERE `uri` = :uri');
        $query->bindValue(':uri', $uri);
        $query->execute() or error(db_error($query));
        return $query;
    }

    /**
     * Drop the posts table for a board.
     *
     * @param string $boardUri Board URI.
     * @return mixed
     */
    public function dropPostsTable(string $boardUri)
    {
        return query(sprintf('DROP TABLE IF EXISTS ``posts_%s``', $boardUri)) or error(db_error());
    }

    /**
     * Delete reports for a specific board.
     *
     * @param string $boardUri Board URI.
     * @return \PDOStatement
     */
    public function deleteReportsForBoard(string $boardUri)
    {
        $query = prepare('DELETE FROM ``reports`` WHERE `board` = :id');
        $query->bindValue(':id', $boardUri, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        return $query;
    }

    /**
     * Delete boards where URI matches.
     *
     * @param string $uri Board URI.
     * @return \PDOStatement
     */
    public function deleteBoardsWhereUri(string $uri)
    {
        $query = prepare('DELETE FROM ``boards`` WHERE `uri` = :uri');
        $query->bindValue(':uri', $uri, \PDO::PARAM_STR);
        $query->execute() or error(db_error($query));
        return $query;
    }

    /**
     * Select cites referencing a target board.
     *
     * @param string $boardUri Target board URI.
     * @return array<int,array<string,mixed>>
     */
    public function selectCitesByTargetBoard(string $boardUri): array
    {
        $query = prepare("SELECT `board`, `post` FROM ``cites`` WHERE `target_board` = :board ORDER BY `board`");
        $query->bindValue(':board', $boardUri);
        $query->execute() or error(db_error($query));
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Delete cites for a board.
     *
     * @param string $boardUri Board URI.
     * @return \PDOStatement
     */
    public function deleteCitesForBoard(string $boardUri)
    {
        $query = prepare('DELETE FROM ``cites`` WHERE `board` = :board OR `target_board` = :board');
        $query->bindValue(':board', $boardUri);
        $query->execute() or error(db_error($query));
        return $query;
    }

    /**
     * Delete antispam entries for a board.
     *
     * @param string $boardUri Board URI.
     * @return \PDOStatement
     */
    public function deleteAntispamForBoard(string $boardUri)
    {
        $query = prepare('DELETE FROM ``antispam`` WHERE `board` = :board');
        $query->bindValue(':board', $boardUri);
        $query->execute() or error(db_error($query));
        return $query;
    }

    /**
     * Select all moderator rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public function selectAllMods(): array
    {
        $query = query('SELECT `id`,`boards` FROM ``mods``') or error(db_error());
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Update a moderator's boards.
     *
     * @param mixed $id Moderator id.
     * @param string $boards Comma-separated board URIs.
     * @return \PDOStatement
     */
    public function updateModBoards($id, $boards)
    {
        $_query = prepare('UPDATE ``mods`` SET `boards` = :boards WHERE `id` = :id');
        $_query->bindValue(':boards', $boards);
        $_query->bindValue(':id', $id);
        $_query->execute() or error(db_error($_query));
        return $_query;
    }

    /**
     * Update board information.
     *
     * @param string $uri Board URI.
     * @param string $title Board title.
     * @param string $subtitle Board subtitle.
     * @param string $category Board category.
     * @return \PDOStatement
     */
    public function updateBoardInfo(string $uri, string $title, string $subtitle, string $category)
    {
        $query = prepare('UPDATE ``boards`` SET `title` = :title, `subtitle` = :subtitle, `category` = :category WHERE `uri` = :uri');
        $query->bindValue(':uri', $uri);
        $query->bindValue(':title', $title);
        $query->bindValue(':subtitle', $subtitle);
        $query->bindValue(':category', $category);
        $query->execute() or error(db_error($query));
        return $query;
    }

    /**
     * Insert a new board row.
     *
     * @param string $uri Board URI.
     * @param string $title Board title.
     * @param string $subtitle Board subtitle.
     * @param string $category Board category.
     * @return \PDOStatement
     */
    public function insertBoard(string $uri, string $title, string $subtitle, string $category)
    {
        $query = prepare('INSERT INTO ``boards`` VALUES (:uri, :title, :subtitle, :category)');
        $query->bindValue(':uri', $uri);
        $query->bindValue(':title', $title);
        $query->bindValue(':subtitle', $subtitle);
        $query->bindValue(':category', $category);
        $query->execute() or error(db_error($query));
        return $query;
    }

    /**
     * Execute arbitrary SQL.
     *
     * @param string $sql SQL statement.
     * @return mixed
     */
    public function executeSql(string $sql)
    {
        return query($sql) or error(db_error());
    }
}
