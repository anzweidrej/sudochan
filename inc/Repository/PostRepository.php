<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Repository;

class PostRepository
{
    /**
     * Update the locked state of a thread.
     *
     * @param string $board Board identifier/uri.
     * @param int    $id    Post id.
     * @param int    $locked 0 = unlocked, 1 = locked.
     * @return \PDOStatement
     */
    public function updateLock(string $board, int $id, int $locked)
    {
        $query = prepare(sprintf('UPDATE ``posts_%s`` SET `locked` = :locked WHERE `id` = :id AND `thread` IS NULL', $board));
        $query->bindValue(':id', $id);
        $query->bindValue(':locked', $locked);
        $query->execute() or error(db_error($query));
        return $query;
    }

    /**
     * Update the sticky state of a thread.
     *
     * @param string $board  Board identifier/uri.
     * @param int    $id     Post id.
     * @param int    $sticky 0 = unsticky, 1 = sticky.
     * @return \PDOStatement
     */
    public function updateSticky(string $board, int $id, int $sticky)
    {
        $query = prepare(sprintf('UPDATE ``posts_%s`` SET `sticky` = :sticky WHERE `id` = :id AND `thread` IS NULL', $board));
        $query->bindValue(':id', $id);
        $query->bindValue(':sticky', $sticky);
        $query->execute() or error(db_error($query));
        return $query;
    }

    /**
     * Update the bumplock state of a thread.
     *
     * @param string $board   Board identifier/uri.
     * @param int    $id      Post id.
     * @param int    $bumplock 0 = unbumplock, 1 = bumplock.
     * @return \PDOStatement
     */
    public function updateBumplock(string $board, int $id, int $bumplock)
    {
        $query = prepare(sprintf('UPDATE ``posts_%s`` SET `sage` = :bumplock WHERE `id` = :id AND `thread` IS NULL', $board));
        $query->bindValue(':id', $id);
        $query->bindValue(':bumplock', $bumplock);
        $query->execute() or error(db_error($query));
        return $query;
    }

    /**
     * Select a thread by id and return the fetched assoc row.
     *
     * @param string $board Board identifier/uri.
     * @param int    $id    Thread id.
     * @return array|false
     */
    public function selectThreadById(string $board, int $id)
    {
        $query = prepare(sprintf('SELECT * FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL', $board));
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));
        return $query->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Select replies belonging to a thread and return all as array of assoc rows.
     *
     * @param string $board    Board identifier/uri.
     * @param int    $threadId Thread id.
     * @return array
     */
    public function selectRepliesByThread(string $board, int $threadId): array
    {
        $query = prepare(sprintf('SELECT * FROM ``posts_%s`` WHERE `thread` = :id ORDER BY `id`', $board));
        $query->bindValue(':id', $threadId, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Select cites targets for a given post on the origin board and return all rows.
     *
     * @param string $originBoard Origin board identifier/uri.
     * @param int    $post        Post id.
     * @return array
     */
    public function selectCitesTarget(string $originBoard, int $post): array
    {
        $query = prepare('SELECT `target` FROM ``cites`` WHERE `target_board` = :board AND `board` = :board AND `post` = :post');
        $query->bindValue(':board', $originBoard);
        $query->bindValue(':post', $post, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Insert multiple cites rows using a pre-built values SQL fragment.
     *
     * @param string $values_sql SQL values fragment (e.g. "(...),(...)" ).
     * @return mixed
     */
    public function insertCitesValues(string $values_sql)
    {
        return query('INSERT INTO ``cites`` VALUES ' . $values_sql) or error(db_error());
    }

    /**
     * Select a post used for ban operations and return the fetched assoc row.
     *
     * @param string $board Board identifier/uri.
     * @param int    $id    Post id.
     * @return array|false
     */
    public function selectPostByIdForBan(string $board, int $id)
    {
        global $config;
        $query = prepare(sprintf('SELECT ' . ($config['ban_show_post'] ? '*' : '`ip`, `thread`') . ' FROM ``posts_%s`` WHERE `id` = :id', $board));
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));
        return $query->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Append a public ban message to a post's body_nomarkup.
     *
     * @param string $board         Board identifier/uri.
     * @param int    $id            Post id.
     * @param string $body_nomarkup Text to append.
     * @return \PDOStatement
     */
    public function updateBodyAppendForBan(string $board, int $id, string $body_nomarkup)
    {
        $query = prepare(sprintf('UPDATE ``posts_%s`` SET `body_nomarkup` = CONCAT(`body_nomarkup`, :body_nomarkup) WHERE `id` = :id', $board));
        $query->bindValue(':id', $id);
        $query->bindValue(':body_nomarkup', $body_nomarkup);
        $query->execute() or error(db_error($query));
        return $query;
    }

    /**
     * Select a post by id and return the fetched assoc row.
     *
     * @param string $board Board identifier/uri.
     * @param int    $id    Post id.
     * @return array|false
     */
    public function selectPostById(string $board, int $id)
    {
        $query = prepare(sprintf('SELECT * FROM ``posts_%s`` WHERE `id` = :id', $board));
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));
        return $query->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Update a post's full/raw fields.
     *
     * @param string $board         Board identifier/uri.
     * @param int    $id            Post id.
     * @param string $name
     * @param string $email
     * @param string $subject
     * @param string $body
     * @param string $body_nomarkup
     * @return \PDOStatement
     */
    public function updatePostRaw(string $board, int $id, string $name, string $email, string $subject, string $body, string $body_nomarkup)
    {
        $query = prepare(sprintf('UPDATE ``posts_%s`` SET `name` = :name, `email` = :email, `subject` = :subject, `body` = :body, `body_nomarkup` = :body_nomarkup WHERE `id` = :id', $board));
        $query->bindValue(':id', $id);
        $query->bindValue('name', $name);
        $query->bindValue(':email', $email);
        $query->bindValue(':subject', $subject);
        $query->bindValue(':body', $body);
        $query->bindValue(':body_nomarkup', $body_nomarkup);
        $query->execute() or error(db_error($query));
        return $query;
    }

    /**
     * Update a post's editable fields.
     *
     * @param string $board  Board identifier/uri.
     * @param int    $id     Post id.
     * @param string $name
     * @param string $email
     * @param string $subject
     * @param string $body   Body markup.
     * @return \PDOStatement
     */
    public function updatePost(string $board, int $id, string $name, string $email, string $subject, string $body)
    {
        $query = prepare(sprintf('UPDATE ``posts_%s`` SET `name` = :name, `email` = :email, `subject` = :subject, `body_nomarkup` = :body WHERE `id` = :id', $board));
        $query->bindValue(':id', $id);
        $query->bindValue('name', $name);
        $query->bindValue(':email', $email);
        $query->bindValue(':subject', $subject);
        $query->bindValue(':body', $body);
        $query->execute() or error(db_error($query));
        return $query;
    }

    /**
     * Select thumbnail filename and thread id for a post and return the fetched assoc row.
     *
     * @param string $board Board identifier/uri.
     * @param int    $id    Post id.
     * @return array|false
     */
    public function selectThumbAndThread(string $board, int $id)
    {
        $query = prepare(sprintf("SELECT `thumb`, `thread` FROM ``posts_%s`` WHERE id = :id", $board));
        $query->bindValue(':id', $id, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        return $query->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Update thumbnail metadata for a post.
     *
     * @param string $board       Board identifier/uri.
     * @param int    $id          Post id.
     * @param string $thumb       Thumbnail marker/filename.
     * @param int    $thumbwidth
     * @param int    $thumbheight
     * @return \PDOStatement
     */
    public function updateThumb(string $board, int $id, string $thumb, int $thumbwidth, int $thumbheight)
    {
        $query = prepare(sprintf("UPDATE ``posts_%s`` SET `thumb` = :thumb, `thumbwidth` = :thumbwidth, `thumbheight` = :thumbheight WHERE `id` = :id", $board));
        $query->bindValue(':thumb', $thumb);
        $query->bindValue(':thumbwidth', $thumbwidth, \PDO::PARAM_INT);
        $query->bindValue(':thumbheight', $thumbheight, \PDO::PARAM_INT);
        $query->bindValue(':id', $id, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        return $query;
    }

    /**
     * Select IP address of a post by id and return the column value.
     *
     * @param string $board Board identifier/uri.
     * @param int    $id    Post id.
     * @return string|false
     */
    public function selectIpById(string $board, int $id)
    {
        $query = prepare(sprintf('SELECT `ip` FROM ``posts_%s`` WHERE `id` = :id', $board));
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));
        return $query->fetchColumn();
    }

    /**
     * Delete reports for a specific post on a board.
     *
     * @param string $board Board identifier/uri.
     * @param int    $post  Post id.
     * @return \PDOStatement
     */
    public function deleteReportsForPost(string $board, int $post)
    {
        $query = prepare('DELETE FROM ``reports`` WHERE `board` = :board AND `post` = :id');
        $query->bindValue(':board', $board);
        $query->bindValue(':id', $post);
        $query->execute() or error(db_error($query));
        return $query;
    }

    /**
     * Select posts by IP for one or more boards using the original UNION ALL logic.
     *
     * @param string|array $boards Board identifier/uri or array of boards.
     * @param string $ip    IP address to search for.
     * @return array
     */
    public function selectPostsByIp($boards, string $ip): array
    {
        if (is_string($boards)) {
            $boards = [['uri' => $boards]];
        }

        $query_sql = '';
        foreach ($boards as $_board) {
            $uri = is_array($_board) && isset($_board['uri']) ? $_board['uri'] : $_board;
            $query_sql .= sprintf("SELECT `thread`, `id`, '%s' AS `board` FROM ``posts_%s`` WHERE `ip` = :ip UNION ALL ", $uri, $uri);
        }

        $query_sql = preg_replace('/UNION ALL $/', '', $query_sql);

        $query = prepare($query_sql);
        $query->bindValue(':ip', $ip);
        $query->execute() or error(db_error($query));
        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }
}
