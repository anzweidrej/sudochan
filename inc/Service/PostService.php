<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Service;

use Sudochan\Dispatcher\EventDispatcher;
use Sudochan\Manager\FileManager;
use Sudochan\Cache;
use Sudochan\Api;
use Sudochan\Entity\Thread;
use Sudochan\Entity\Post;
use Sudochan\Service\MarkupService;

class PostService
{
    public static function threadLocked(int $id): bool
    {
        global $board;

        if (EventDispatcher::event('check-locked', $id)) {
            return true;
        }

        $query = prepare(sprintf("SELECT `locked` FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL LIMIT 1", $board['uri']));
        $query->bindValue(':id', $id, \PDO::PARAM_INT);
        $query->execute() or error(db_error());

        if (($locked = $query->fetchColumn()) === false) {
            // Non-existant, so it can't be locked...
            return false;
        }

        return (bool) $locked;
    }

    public static function threadSageLocked(int $id): bool
    {
        global $board;

        if (EventDispatcher::event('check-sage-locked', $id)) {
            return true;
        }

        $query = prepare(sprintf("SELECT `sage` FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL LIMIT 1", $board['uri']));
        $query->bindValue(':id', $id, \PDO::PARAM_INT);
        $query->execute() or error(db_error());

        if (($sagelocked = $query->fetchColumn()) === false) {
            // Non-existant, so it can't be locked...
            return false;
        }

        return (bool) $sagelocked;
    }

    public static function threadExists(int $id): bool
    {
        global $board;

        $query = prepare(sprintf("SELECT 1 FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL LIMIT 1", $board['uri']));
        $query->bindValue(':id', $id, \PDO::PARAM_INT);
        $query->execute() or error(db_error());

        if ($query->rowCount()) {
            return true;
        }

        return false;
    }

    public static function insertFloodPost(array $post): void
    {
        global $board;

        $query = prepare("INSERT INTO ``flood`` VALUES (NULL, :ip, :board, :time, :posthash, :filehash, :isreply)");
        $query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
        $query->bindValue(':board', $board['uri']);
        $query->bindValue(':time', time());
        $query->bindValue(':posthash', make_comment_hex($post['body_nomarkup']));
        if ($post['has_file']) {
            $query->bindValue(':filehash', $post['filehash']);
        } else {
            $query->bindValue(':filehash', null, \PDO::PARAM_NULL);
        }
        $query->bindValue(':isreply', !$post['op'], \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
    }

    public static function post(array $post): string
    {
        global $pdo, $board;
        $query = prepare(sprintf("INSERT INTO ``posts_%s`` VALUES ( NULL, :thread, :subject, :email, :name, :trip, :capcode, :body, :body_nomarkup, :time, :time, :thumb, :thumbwidth, :thumbheight, :file, :width, :height, :filesize, :filename, :filehash, :password, :ip, :sticky, :locked, 0, :embed)", $board['uri']));

        // Basic stuff
        if (!empty($post['subject'])) {
            $query->bindValue(':subject', $post['subject']);
        } else {
            $query->bindValue(':subject', null, \PDO::PARAM_NULL);
        }

        if (!empty($post['email'])) {
            $query->bindValue(':email', $post['email']);
        } else {
            $query->bindValue(':email', null, \PDO::PARAM_NULL);
        }

        if (!empty($post['trip'])) {
            $query->bindValue(':trip', $post['trip']);
        } else {
            $query->bindValue(':trip', null, \PDO::PARAM_NULL);
        }

        $query->bindValue(':name', $post['name']);
        $query->bindValue(':body', $post['body']);
        $query->bindValue(':body_nomarkup', $post['body_nomarkup']);
        $query->bindValue(':time', isset($post['time']) ? $post['time'] : time(), \PDO::PARAM_INT);
        $query->bindValue(':password', $post['password']);
        $query->bindValue(':ip', isset($post['ip']) ? $post['ip'] : $_SERVER['REMOTE_ADDR']);

        if ($post['op'] && $post['mod'] && isset($post['sticky']) && $post['sticky']) {
            $query->bindValue(':sticky', true, \PDO::PARAM_INT);
        } else {
            $query->bindValue(':sticky', false, \PDO::PARAM_INT);
        }

        if ($post['op'] && $post['mod'] && isset($post['locked']) && $post['locked']) {
            $query->bindValue(':locked', true, \PDO::PARAM_INT);
        } else {
            $query->bindValue(':locked', false, \PDO::PARAM_INT);
        }

        if ($post['mod'] && isset($post['capcode']) && $post['capcode']) {
            $query->bindValue(':capcode', $post['capcode'], \PDO::PARAM_INT);
        } else {
            $query->bindValue(':capcode', null, \PDO::PARAM_NULL);
        }

        if (!empty($post['embed'])) {
            $query->bindValue(':embed', $post['embed']);
        } else {
            $query->bindValue(':embed', null, \PDO::PARAM_NULL);
        }

        if ($post['op']) {
            // No parent thread, image
            $query->bindValue(':thread', null, \PDO::PARAM_NULL);
        } else {
            $query->bindValue(':thread', $post['thread'], \PDO::PARAM_INT);
        }

        if ($post['has_file']) {
            $query->bindValue(':thumb', $post['thumb']);
            $query->bindValue(':thumbwidth', $post['thumbwidth'], \PDO::PARAM_INT);
            $query->bindValue(':thumbheight', $post['thumbheight'], \PDO::PARAM_INT);
            $query->bindValue(':file', $post['file']);

            if (isset($post['width'], $post['height'])) {
                $query->bindValue(':width', $post['width'], \PDO::PARAM_INT);
                $query->bindValue(':height', $post['height'], \PDO::PARAM_INT);
            } else {
                $query->bindValue(':width', null, \PDO::PARAM_NULL);
                $query->bindValue(':height', null, \PDO::PARAM_NULL);
            }

            $query->bindValue(':filesize', $post['filesize'], \PDO::PARAM_INT);
            $query->bindValue(':filename', $post['filename']);
            $query->bindValue(':filehash', $post['filehash']);
        } else {
            $query->bindValue(':thumb', null, \PDO::PARAM_NULL);
            $query->bindValue(':thumbwidth', null, \PDO::PARAM_NULL);
            $query->bindValue(':thumbheight', null, \PDO::PARAM_NULL);
            $query->bindValue(':file', null, \PDO::PARAM_NULL);
            $query->bindValue(':width', null, \PDO::PARAM_NULL);
            $query->bindValue(':height', null, \PDO::PARAM_NULL);
            $query->bindValue(':filesize', null, \PDO::PARAM_NULL);
            $query->bindValue(':filename', null, \PDO::PARAM_NULL);
            $query->bindValue(':filehash', null, \PDO::PARAM_NULL);
        }

        if (!$query->execute()) {
            FileManager::undoImage($post);
            error(db_error($query));
        }

        return $pdo->lastInsertId();
    }

    public static function bumpThread(int $id): bool
    {
        global $config, $board, $build_pages;

        if (EventDispatcher::event('bump', $id)) {
            return true;
        }

        if ($config['try_smarter']) {
            $build_pages[] = self::thread_find_page($id);
        }

        $query = prepare(sprintf("UPDATE ``posts_%s`` SET `bump` = :time WHERE `id` = :id AND `thread` IS NULL", $board['uri']));
        $query->bindValue(':time', time(), \PDO::PARAM_INT);
        $query->bindValue(':id', $id, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        return true;
    }

    // Remove file from post
    public static function deleteFile(int $id, bool $remove_entirely_if_already = true): void
    {
        global $board, $config;

        $query = prepare(sprintf("SELECT `thread`,`thumb`,`file` FROM ``posts_%s`` WHERE `id` = :id LIMIT 1", $board['uri']));
        $query->bindValue(':id', $id, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        if (!$post = $query->fetch(\PDO::FETCH_ASSOC)) {
            error($config['error']['invalidpost']);
        }

        if ($post['file'] == 'deleted' && !$post['thread']) {
            return;
        } // Can't delete OP's image completely.

        $query = prepare(sprintf("UPDATE ``posts_%s`` SET `thumb` = NULL, `thumbwidth` = NULL, `thumbheight` = NULL, `filewidth` = NULL, `fileheight` = NULL, `filesize` = NULL, `filename` = NULL, `filehash` = NULL, `file` = :file WHERE `id` = :id", $board['uri']));
        if ($post['file'] == 'deleted' && $remove_entirely_if_already) {
            // Already deleted; remove file fully
            $query->bindValue(':file', null, \PDO::PARAM_NULL);
        } else {
            // Delete thumbnail
            FileManager::file_unlink($board['dir'] . $config['dir']['thumb'] . $post['thumb']);

            // Delete file
            FileManager::file_unlink($board['dir'] . $config['dir']['img'] . $post['file']);

            // Set file to 'deleted'
            $query->bindValue(':file', 'deleted', \PDO::PARAM_INT);
        }

        $query->bindValue(':id', $id, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        if ($post['thread']) {
            self::buildThread($post['thread']);
        } else {
            self::buildThread($id);
        }
    }

    // rebuild post (markup)
    public static function rebuildPost(int $id): bool
    {
        global $board;

        $query = prepare(sprintf("SELECT `body_nomarkup`, `thread` FROM ``posts_%s`` WHERE `id` = :id", $board['uri']));
        $query->bindValue(':id', $id, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        if ((!$post = $query->fetch(\PDO::FETCH_ASSOC)) || !$post['body_nomarkup']) {
            return false;
        }

        MarkupService::markup($body = &$post['body_nomarkup']);

        $query = prepare(sprintf("UPDATE ``posts_%s`` SET `body` = :body WHERE `id` = :id", $board['uri']));
        $query->bindValue(':body', $body);
        $query->bindValue(':id', $id, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        self::buildThread($post['thread'] ? $post['thread'] : $id);

        return true;
    }

    // Delete a post (reply or thread)
    public static function deletePost(int $id, bool $error_if_doesnt_exist = true, bool $rebuild_after = true): bool
    {
        global $board, $config;

        // Select post and replies (if thread) in one query
        $query = prepare(sprintf("SELECT `id`,`thread`,`thumb`,`file` FROM ``posts_%s`` WHERE `id` = :id OR `thread` = :id", $board['uri']));
        $query->bindValue(':id', $id, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        if ($query->rowCount() < 1) {
            if ($error_if_doesnt_exist) {
                error($config['error']['invalidpost']);
            } else {
                return false;
            }
        }

        $ids = [];

        // Delete posts and maybe replies
        while ($post = $query->fetch(\PDO::FETCH_ASSOC)) {
            EventDispatcher::event('delete', $post);

            if (!$post['thread']) {
                // Delete thread HTML page
                FileManager::file_unlink($board['dir'] . $config['dir']['res'] . sprintf($config['file_page'], $post['id']));

                $antispam_query = prepare('DELETE FROM ``antispam`` WHERE `board` = :board AND `thread` = :thread');
                $antispam_query->bindValue(':board', $board['uri']);
                $antispam_query->bindValue(':thread', $post['id']);
                $antispam_query->execute() or error(db_error($antispam_query));
            } elseif ($query->rowCount() == 1) {
                // Rebuild thread
                $rebuild = &$post['thread'];
            }
            if ($post['thumb']) {
                // Delete thumbnail
                FileManager::file_unlink($board['dir'] . $config['dir']['thumb'] . $post['thumb']);
            }
            if ($post['file']) {
                // Delete file
                FileManager::file_unlink($board['dir'] . $config['dir']['img'] . $post['file']);
            }

            $ids[] = (int) $post['id'];

        }

        $query = prepare(sprintf("DELETE FROM ``posts_%s`` WHERE `id` = :id OR `thread` = :id", $board['uri']));
        $query->bindValue(':id', $id, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        $query = prepare("SELECT `board`, `post` FROM ``cites`` WHERE `target_board` = :board AND (`target` = " . implode(' OR `target` = ', $ids) . ") ORDER BY `board`");
        $query->bindValue(':board', $board['uri']);
        $query->execute() or error(db_error($query));
        while ($cite = $query->fetch(\PDO::FETCH_ASSOC)) {
            if ($board['uri'] != $cite['board']) {
                if (!isset($tmp_board)) {
                    $tmp_board = $board['uri'];
                }
                BoardService::openBoard($cite['board']);
            }
            self::rebuildPost($cite['post']);
        }

        if (isset($tmp_board)) {
            BoardService::openBoard($tmp_board);
        }

        $query = prepare("DELETE FROM ``cites`` WHERE (`target_board` = :board AND (`target` = " . implode(' OR `target` = ', $ids) . ")) OR (`board` = :board AND (`post` = " . implode(' OR `post` = ', $ids) . "))");
        $query->bindValue(':board', $board['uri']);
        $query->execute() or error(db_error($query));

        if (isset($rebuild) && $rebuild_after) {
            self::buildThread($rebuild);
        }

        return true;
    }

    public static function clean(): void
    {
        global $board, $config;
        $offset = round($config['max_pages'] * $config['threads_per_page']);

        // I too wish there was an easier way of doing this...
        $query = prepare(sprintf("SELECT `id` FROM ``posts_%s`` WHERE `thread` IS NULL ORDER BY `sticky` DESC, `bump` DESC LIMIT :offset, 9001", $board['uri']));
        $query->bindValue(':offset', $offset, \PDO::PARAM_INT);

        $query->execute() or error(db_error($query));
        while ($post = $query->fetch(\PDO::FETCH_ASSOC)) {
            self::deletePost($post['id']);
        }
    }

    public static function thread_find_page(int $thread): int|false
    {
        global $config, $board;

        $query = query(sprintf("SELECT `id` FROM ``posts_%s`` WHERE `thread` IS NULL ORDER BY `sticky` DESC, `bump` DESC", $board['uri'])) or error(db_error($query));
        $threads = $query->fetchAll(\PDO::FETCH_COLUMN);
        if (($index = array_search($thread, $threads)) === false) {
            return false;
        }
        return floor(($config['threads_per_page'] + $index) / $config['threads_per_page']);
    }

    public static function buildThread(int $id, bool $return = false, array|bool $mod = false): ?string
    {
        global $board, $config, $build_pages;
        $id = round($id);

        if (EventDispatcher::event('build-thread', $id)) {
            return null;
        }

        if ($config['cache']['enabled'] && !$mod) {
            // Clear cache
            Cache::delete("thread_index_{$board['uri']}_{$id}");
            Cache::delete("thread_{$board['uri']}_{$id}");
        }

        $query = prepare(sprintf("SELECT * FROM ``posts_%s`` WHERE (`thread` IS NULL AND `id` = :id) OR `thread` = :id ORDER BY `thread`,`id`", $board['uri']));
        $query->bindValue(':id', $id, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        while ($post = $query->fetch(\PDO::FETCH_ASSOC)) {
            if (!isset($thread)) {
                $thread = new Thread($post, $mod ? '?/' : $config['root'], $mod);
            } else {
                $thread->add(new Post($post, $mod ? '?/' : $config['root'], $mod));
            }
        }

        // Check if any posts were found
        if (!isset($thread)) {
            error($config['error']['nonexistant']);
        }

        $body = element('thread.html', [
            'board' => $board,
            'thread' => $thread,
            'body' => $thread->build(),
            'config' => $config,
            'id' => $id,
            'mod' => $mod,
            'antibot' => $mod || $return ? false : create_antibot($board['uri'], $id),
            'boardlist' => BoardService::createBoardlist($mod),
            'return' => ($mod ? '?' . $board['url'] . $config['file_index'] : $config['root'] . $board['dir'] . $config['file_index']),
        ]);

        if ($config['try_smarter'] && !$mod) {
            $build_pages[] = self::thread_find_page($id);
        }

        if ($return) {
            return $body;
        }

        FileManager::file_write($board['dir'] . $config['dir']['res'] . sprintf($config['file_page'], $id), $body);

        // json api
        if ($config['api']['enabled']) {
            $api = new Api();
            $json = json_encode($api->translateThread($thread));
            $jsonFilename = $board['dir'] . $config['dir']['res'] . $id . '.json';
            FileManager::file_write($jsonFilename, $json);
        }

        return null;
    }
}
