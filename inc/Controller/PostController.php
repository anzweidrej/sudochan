<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Mod\Auth;
use Sudochan\EventDispatcher;
use Sudochan\Bans;

class PostController
{
    public function mod_lock(string $board, bool $unlock, int $post): void
    {
        global $config;

        if (!openBoard($board)) {
            error($config['error']['noboard']);
        }

        if (!hasPermission($config['mod']['lock'], $board)) {
            error($config['error']['noaccess']);
        }

        $query = prepare(sprintf('UPDATE ``posts_%s`` SET `locked` = :locked WHERE `id` = :id AND `thread` IS NULL', $board));
        $query->bindValue(':id', $post);
        $query->bindValue(':locked', $unlock ? 0 : 1);
        $query->execute() or error(db_error($query));
        if ($query->rowCount()) {
            Auth::modLog(($unlock ? 'Unlocked' : 'Locked') . " thread #{$post}");
            buildThread($post);
            buildIndex();
        }

        if ($config['mod']['dismiss_reports_on_lock']) {
            $query = prepare('DELETE FROM ``reports`` WHERE `board` = :board AND `post` = :id');
            $query->bindValue(':board', $board);
            $query->bindValue(':id', $post);
            $query->execute() or error(db_error($query));
        }

        header('Location: ?/' . sprintf($config['board_path'], $board) . $config['file_index'], true, $config['redirect_http']);

        if ($unlock) {
            EventDispatcher::event('unlock', $post);
        } else {
            EventDispatcher::event('lock', $post);
        }
    }

    public function mod_sticky(string $board, bool $unsticky, int $post): void
    {
        global $config;

        if (!openBoard($board)) {
            error($config['error']['noboard']);
        }

        if (!hasPermission($config['mod']['sticky'], $board)) {
            error($config['error']['noaccess']);
        }

        $query = prepare(sprintf('UPDATE ``posts_%s`` SET `sticky` = :sticky WHERE `id` = :id AND `thread` IS NULL', $board));
        $query->bindValue(':id', $post);
        $query->bindValue(':sticky', $unsticky ? 0 : 1);
        $query->execute() or error(db_error($query));
        if ($query->rowCount()) {
            Auth::modLog(($unsticky ? 'Unstickied' : 'Stickied') . " thread #{$post}");
            buildThread($post);
            buildIndex();
        }

        header('Location: ?/' . sprintf($config['board_path'], $board) . $config['file_index'], true, $config['redirect_http']);
    }

    public function mod_bumplock(string $board, bool $unbumplock, int $post): void
    {
        global $config;

        if (!openBoard($board)) {
            error($config['error']['noboard']);
        }

        if (!hasPermission($config['mod']['bumplock'], $board)) {
            error($config['error']['noaccess']);
        }

        $query = prepare(sprintf('UPDATE ``posts_%s`` SET `sage` = :bumplock WHERE `id` = :id AND `thread` IS NULL', $board));
        $query->bindValue(':id', $post);
        $query->bindValue(':bumplock', $unbumplock ? 0 : 1);
        $query->execute() or error(db_error($query));
        if ($query->rowCount()) {
            Auth::modLog(($unbumplock ? 'Unbumplocked' : 'Bumplocked') . " thread #{$post}");
            buildThread($post);
            buildIndex();
        }

        header('Location: ?/' . sprintf($config['board_path'], $board) . $config['file_index'], true, $config['redirect_http']);
    }

    public function mod_move(string $originBoard, int $postID): void
    {
        global $board, $config, $mod, $pdo;

        if (!openBoard($originBoard)) {
            error($config['error']['noboard']);
        }

        if (!hasPermission($config['mod']['move'], $originBoard)) {
            error($config['error']['noaccess']);
        }

        $query = prepare(sprintf('SELECT * FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL', $originBoard));
        $query->bindValue(':id', $postID);
        $query->execute() or error(db_error($query));
        if (!$post = $query->fetch(\PDO::FETCH_ASSOC)) {
            error($config['error']['404']);
        }

        if (isset($_POST['board'])) {
            $targetBoard = $_POST['board'];
            $shadow = isset($_POST['shadow']);

            if ($targetBoard === $originBoard) {
                error(_('Target and source board are the same.'));
            }

            // copy() if leaving a shadow thread behind; else, rename().
            $clone = $shadow ? 'copy' : 'rename';

            // indicate that the post is a thread
            $post['op'] = true;

            if ($post['file']) {
                $post['has_file'] = true;
                $post['width'] = &$post['filewidth'];
                $post['height'] = &$post['fileheight'];

                $file_src = sprintf($config['board_path'], $board['uri']) . $config['dir']['img'] . $post['file'];
                $file_thumb = sprintf($config['board_path'], $board['uri']) . $config['dir']['thumb'] . $post['thumb'];
            } else {
                $post['has_file'] = false;
            }

            // allow thread to keep its same traits (stickied, locked, etc.)
            $post['mod'] = true;

            if (!openBoard($targetBoard)) {
                error($config['error']['noboard']);
            }

            // create the new thread
            $newID = post($post);

            if ($post['has_file']) {
                // copy image
                $clone($file_src, sprintf($config['board_path'], $board['uri']) . $config['dir']['img'] . $post['file']);
                if (!in_array($post['thumb'], ['spoiler', 'deleted', 'file'])) {
                    $clone($file_thumb, sprintf($config['board_path'], $board['uri']) . $config['dir']['thumb'] . $post['thumb']);
                }
            }

            // go back to the original board to fetch replies
            openBoard($originBoard);

            $query = prepare(sprintf('SELECT * FROM ``posts_%s`` WHERE `thread` = :id ORDER BY `id`', $originBoard));
            $query->bindValue(':id', $postID, \PDO::PARAM_INT);
            $query->execute() or error(db_error($query));

            $replies = [];

            while ($post = $query->fetch(\PDO::FETCH_ASSOC)) {
                $post['mod'] = true;
                $post['thread'] = $newID;

                if ($post['file']) {
                    $post['has_file'] = true;
                    $post['width'] = &$post['filewidth'];
                    $post['height'] = &$post['fileheight'];

                    $post['file_src'] = sprintf($config['board_path'], $board['uri']) . $config['dir']['img'] . $post['file'];
                    $post['file_thumb'] = sprintf($config['board_path'], $board['uri']) . $config['dir']['thumb'] . $post['thumb'];
                } else {
                    $post['has_file'] = false;
                }

                $replies[] = $post;
            }

            $newIDs = [$postID => $newID];

            openBoard($targetBoard);

            foreach ($replies as &$post) {
                $query = prepare('SELECT `target` FROM ``cites`` WHERE `target_board` = :board AND `board` = :board AND `post` = :post');
                $query->bindValue(':board', $originBoard);
                $query->bindValue(':post', $post['id'], \PDO::PARAM_INT);
                $query->execute() or error(db_error($qurey));

                // correct >>X links
                while ($cite = $query->fetch(\PDO::FETCH_ASSOC)) {
                    if (isset($newIDs[$cite['target']])) {
                        $post['body_nomarkup'] = preg_replace(
                            '/(>>(>\/' . preg_quote($originBoard, '/') . '\/)?)' . preg_quote($cite['target'], '/') . '/',
                            '>>' . $newIDs[$cite['target']],
                            $post['body_nomarkup'],
                        );

                        $post['body'] = $post['body_nomarkup'];
                    }
                }

                $post['body'] = $post['body_nomarkup'];

                $post['op'] = false;
                $post['tracked_cites'] = markup($post['body'], true);

                // insert reply
                $newIDs[$post['id']] = $newPostID = post($post);

                if ($post['has_file']) {
                    // copy image
                    $clone($post['file_src'], sprintf($config['board_path'], $board['uri']) . $config['dir']['img'] . $post['file']);
                    $clone($post['file_thumb'], sprintf($config['board_path'], $board['uri']) . $config['dir']['thumb'] . $post['thumb']);
                }

                if (!empty($post['tracked_cites'])) {
                    $insert_rows = [];
                    foreach ($post['tracked_cites'] as $cite) {
                        $insert_rows[] = '(' .
                            $pdo->quote($board['uri']) . ', ' . $newPostID . ', ' .
                            $pdo->quote($cite[0]) . ', ' . (int) $cite[1] . ')';
                    }
                    query('INSERT INTO ``cites`` VALUES ' . implode(', ', $insert_rows)) or error(db_error());
                }
            }

            Auth::modLog("Moved thread #{$postID} to " . sprintf($config['board_abbreviation'], $targetBoard) . " (#{$newID})", $originBoard);

            // build new thread
            buildThread($newID);

            clean();
            buildIndex();

            // trigger themes
            rebuildThemes('post', $targetBoard);

            // return to original board
            openBoard($originBoard);

            if ($shadow) {
                // lock old thread
                $query = prepare(sprintf('UPDATE ``posts_%s`` SET `locked` = 1 WHERE `id` = :id', $originBoard));
                $query->bindValue(':id', $postID, \PDO::PARAM_INT);
                $query->execute() or error(db_error($query));

                // leave a reply, linking to the new thread
                $post = [
                    'mod' => true,
                    'subject' => '',
                    'email' => '',
                    'name' => (!$config['mod']['shadow_name'] ? $config['anonymous'] : $config['mod']['shadow_name']),
                    'capcode' => $config['mod']['shadow_capcode'],
                    'trip' => '',
                    'password' => '',
                    'has_file' => false,
                    // attach to original thread
                    'thread' => $postID,
                    'op' => false,
                ];

                $post['body'] = $post['body_nomarkup'] =  sprintf($config['mod']['shadow_mesage'], '>>>/' . $targetBoard . '/' . $newID);

                markup($post['body']);

                $botID = post($post);
                buildThread($postID);

                buildIndex();

                header('Location: ?/' . sprintf($config['board_path'], $originBoard) . $config['dir']['res'] . sprintf($config['file_page'], $postID) .
                    '#' . $botID, true, $config['redirect_http']);
            } else {
                deletePost($postID);
                buildIndex();

                openBoard($targetBoard);
                header('Location: ?/' . sprintf($config['board_path'], $board['uri']) . $config['dir']['res'] . sprintf($config['file_page'], $newID), true, $config['redirect_http']);
            }
        }

        $boards = listBoards();
        if (count($boards) <= 1) {
            error(_('Impossible to move thread; there is only one board.'));
        }

        $security_token = Auth::make_secure_link_token($originBoard . '/move/' . $postID);

        mod_page(_('Move thread'), 'mod/move.html', ['post' => $postID, 'board' => $originBoard, 'boards' => $boards, 'token' => $security_token]);
    }

    public function mod_ban_post(string $board, bool $delete, int $post, string|false $token = false): void
    {
        global $config, $mod;

        if (!openBoard($board)) {
            error($config['error']['noboard']);
        }

        if (!hasPermission($config['mod']['delete'], $board)) {
            error($config['error']['noaccess']);
        }

        $security_token = Auth::make_secure_link_token($board . '/ban/' . $post);

        $query = prepare(sprintf('SELECT ' . ($config['ban_show_post'] ? '*' : '`ip`, `thread`') .
            ' FROM ``posts_%s`` WHERE `id` = :id', $board));
        $query->bindValue(':id', $post);
        $query->execute() or error(db_error($query));
        if (!$_post = $query->fetch(\PDO::FETCH_ASSOC)) {
            error($config['error']['404']);
        }

        $thread = $_post['thread'];
        $ip = $_post['ip'];

        if (isset($_POST['new_ban'], $_POST['reason'], $_POST['length'], $_POST['board'])) {
            if (isset($_POST['ip'])) {
                $ip = $_POST['ip'];
            }

            Bans::new_ban(
                $_POST['ip'],
                $_POST['reason'],
                $_POST['length'],
                $_POST['board'] == '*' ? false : $_POST['board'],
                false,
                $config['ban_show_post'] ? $_post : false,
            );

            if (isset($_POST['public_message'], $_POST['message'])) {
                // public ban message
                $length_english = Bans::parse_time($_POST['length']) ? 'for ' . until(Bans::parse_time($_POST['length'])) : 'permanently';
                $_POST['message'] = preg_replace('/[\r\n]/', '', $_POST['message']);
                $_POST['message'] = str_replace('%length%', $length_english, $_POST['message']);
                $_POST['message'] = str_replace('%LENGTH%', strtoupper($length_english), $_POST['message']);
                $query = prepare(sprintf('UPDATE ``posts_%s`` SET `body_nomarkup` = CONCAT(`body_nomarkup`, :body_nomarkup) WHERE `id` = :id', $board));
                $query->bindValue(':id', $post);
                $query->bindValue(':body_nomarkup', sprintf("\n<tinyboard ban message>%s</tinyboard>", utf8tohtml($_POST['message'])));
                $query->execute() or error(db_error($query));
                rebuildPost($post);

                Auth::modLog("Attached a public ban message to post #{$post}: " . utf8tohtml($_POST['message']));
                buildThread($thread ? $thread : $post);
                buildIndex();
            } elseif (isset($_POST['delete']) && (int) $_POST['delete']) {
                // Delete post
                deletePost($post);
                Auth::modLog("Deleted post #{$post}");
                // Rebuild board
                buildIndex();
                // Rebuild themes
                rebuildThemes('post-delete', $board);
            }

            header('Location: ?/' . sprintf($config['board_path'], $board) . $config['file_index'], true, $config['redirect_http']);
        }

        $args = [
            'ip' => $ip,
            'hide_ip' => !hasPermission($config['mod']['show_ip'], $board),
            'post' => $post,
            'board' => $board,
            'delete' => (bool) $delete,
            'boards' => listBoards(),
            'token' => $security_token,
        ];

        mod_page(_('New ban'), 'mod/ban_form.html', $args);
    }

    public function mod_edit_post(string $board, bool $edit_raw_html, int $postID): void
    {
        global $config, $mod;

        if (!openBoard($board)) {
            error($config['error']['noboard']);
        }

        if (!hasPermission($config['mod']['editpost'], $board)) {
            error($config['error']['noaccess']);
        }

        if ($edit_raw_html && !hasPermission($config['mod']['rawhtml'], $board)) {
            error($config['error']['noaccess']);
        }

        $security_token = Auth::make_secure_link_token($board . '/edit' . ($edit_raw_html ? '_raw' : '') . '/' . $postID);

        $query = prepare(sprintf('SELECT * FROM ``posts_%s`` WHERE `id` = :id', $board));
        $query->bindValue(':id', $postID);
        $query->execute() or error(db_error($query));

        if (!$post = $query->fetch(\PDO::FETCH_ASSOC)) {
            error($config['error']['404']);
        }

        if (isset($_POST['name'], $_POST['email'], $_POST['subject'], $_POST['body'])) {
            if ($edit_raw_html) {
                $query = prepare(sprintf('UPDATE ``posts_%s`` SET `name` = :name, `email` = :email, `subject` = :subject, `body` = :body, `body_nomarkup` = :body_nomarkup WHERE `id` = :id', $board));
            } else {
                $query = prepare(sprintf('UPDATE ``posts_%s`` SET `name` = :name, `email` = :email, `subject` = :subject, `body_nomarkup` = :body WHERE `id` = :id', $board));
            }
            $query->bindValue(':id', $postID);
            $query->bindValue('name', $_POST['name']);
            $query->bindValue(':email', $_POST['email']);
            $query->bindValue(':subject', $_POST['subject']);
            $query->bindValue(':body', $_POST['body']);
            if ($edit_raw_html) {
                $body_nomarkup = $_POST['body'] . "\n<tinyboard raw html>1</tinyboard>";
                $query->bindValue(':body_nomarkup', $body_nomarkup);
            }
            $query->execute() or error(db_error($query));

            if ($edit_raw_html) {
                Auth::modLog("Edited raw HTML of post #{$postID}");
            } else {
                Auth::modLog("Edited post #{$postID}");
                rebuildPost($postID);
            }

            buildIndex();

            rebuildThemes('post', $board);

            header('Location: ?/' . sprintf($config['board_path'], $board) . $config['dir']['res'] . sprintf($config['file_page'], $post['thread'] ? $post['thread'] : $postID) . '#' . $postID, true, $config['redirect_http']);
        } else {
            if ($config['minify_html']) {
                $post['body_nomarkup'] = str_replace("\n", '&#010;', utf8tohtml($post['body_nomarkup']));
                $post['body'] = str_replace("\n", '&#010;', utf8tohtml($post['body']));
                $post['body_nomarkup'] = str_replace("\r", '', $post['body_nomarkup']);
                $post['body'] = str_replace("\r", '', $post['body']);
                $post['body_nomarkup'] = str_replace("\t", '&#09;', $post['body_nomarkup']);
                $post['body'] = str_replace("\t", '&#09;', $post['body']);
            }

            mod_page(_('Edit post'), 'mod/edit_post_form.html', ['token' => $security_token, 'board' => $board, 'raw' => $edit_raw_html, 'post' => $post]);
        }
    }

    public function mod_delete(string $board, int $post): void
    {
        global $config, $mod;

        if (!openBoard($board)) {
            error($config['error']['noboard']);
        }

        if (!hasPermission($config['mod']['delete'], $board)) {
            error($config['error']['noaccess']);
        }

        // Delete post
        deletePost($post);
        // Record the action
        Auth::modLog("Deleted post #{$post}");
        // Rebuild board
        buildIndex();
        // Rebuild themes
        rebuildThemes('post-delete', $board);
        // Redirect
        header('Location: ?/' . sprintf($config['board_path'], $board) . $config['file_index'], true, $config['redirect_http']);
    }

    public function mod_deletefile(string $board, int $post): void
    {
        global $config, $mod;

        if (!openBoard($board)) {
            error($config['error']['noboard']);
        }

        if (!hasPermission($config['mod']['deletefile'], $board)) {
            error($config['error']['noaccess']);
        }

        // Delete file
        deleteFile($post);
        // Record the action
        Auth::modLog("Deleted file from post #{$post}");

        // Rebuild board
        buildIndex();
        // Rebuild themes
        rebuildThemes('post-delete', $board);

        // Redirect
        header('Location: ?/' . sprintf($config['board_path'], $board) . $config['file_index'], true, $config['redirect_http']);
    }

    public function mod_spoiler_image(string $board, int $post): void
    {
        global $config, $mod;

        if (!openBoard($board)) {
            error($config['error']['noboard']);
        }

        if (!hasPermission($config['mod']['spoilerimage'], $board)) {
            error($config['error']['noaccess']);
        }

        // Delete file
        $query = prepare(sprintf("SELECT `thumb`, `thread` FROM ``posts_%s`` WHERE id = :id", $board));
        $query->bindValue(':id', $post, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        $result = $query->fetch(\PDO::FETCH_ASSOC);

        file_unlink($board . '/' . $config['dir']['thumb'] . $result['thumb']);

        // Make thumbnail spoiler
        $query = prepare(sprintf("UPDATE ``posts_%s`` SET `thumb` = :thumb, `thumbwidth` = :thumbwidth, `thumbheight` = :thumbheight WHERE `id` = :id", $board));
        $query->bindValue(':thumb', "spoiler");
        $query->bindValue(':thumbwidth', 128, \PDO::PARAM_INT);
        $query->bindValue(':thumbheight', 128, \PDO::PARAM_INT);
        $query->bindValue(':id', $post, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        // Record the action
        Auth::modLog("Spoilered file from post #{$post}");

        // Rebuild thread
        buildThread($result['thread'] ? $result['thread'] : $post);

        // Rebuild board
        buildIndex();

        // Rebuild themes
        rebuildThemes('post-delete', $board);

        // Redirect
        header('Location: ?/' . sprintf($config['board_path'], $board) . $config['file_index'], true, $config['redirect_http']);
    }

    public function mod_deletebyip(string $boardName, int $post, bool $global = false): void
    {
        global $config, $mod, $board;

        $global = (bool) $global;

        if (!openBoard($boardName)) {
            error($config['error']['noboard']);
        }

        if (!$global && !hasPermission($config['mod']['deletebyip'], $boardName)) {
            error($config['error']['noaccess']);
        }

        if ($global && !hasPermission($config['mod']['deletebyip_global'], $boardName)) {
            error($config['error']['noaccess']);
        }

        // Find IP address
        $query = prepare(sprintf('SELECT `ip` FROM ``posts_%s`` WHERE `id` = :id', $boardName));
        $query->bindValue(':id', $post);
        $query->execute() or error(db_error($query));
        if (!$ip = $query->fetchColumn()) {
            error($config['error']['invalidpost']);
        }

        $boards = $global ? listBoards() : [['uri' => $boardName]];

        $query = '';
        foreach ($boards as $_board) {
            $query .= sprintf("SELECT `thread`, `id`, '%s' AS `board` FROM ``posts_%s`` WHERE `ip` = :ip UNION ALL ", $_board['uri'], $_board['uri']);
        }
        $query = preg_replace('/UNION ALL $/', '', $query);

        $query = prepare($query);
        $query->bindValue(':ip', $ip);
        $query->execute() or error(db_error($query));

        if ($query->rowCount() < 1) {
            error($config['error']['invalidpost']);
        }

        @set_time_limit($config['mod']['rebuild_timelimit']);

        $threads_to_rebuild = [];
        $threads_deleted = [];
        while ($post = $query->fetch(\PDO::FETCH_ASSOC)) {
            openBoard($post['board']);

            deletePost($post['id'], false, false);

            rebuildThemes('post-delete', $board['uri']);

            if ($post['thread']) {
                $threads_to_rebuild[$post['board']][$post['thread']] = true;
            } else {
                $threads_deleted[$post['board']][$post['id']] = true;
            }
        }

        foreach ($threads_to_rebuild as $_board => $_threads) {
            openBoard($_board);
            foreach ($_threads as $_thread => $_dummy) {
                if ($_dummy && !isset($threads_deleted[$_board][$_thread])) {
                    buildThread($_thread);
                }
            }
            buildIndex();
        }

        if ($global) {
            $board = false;
        }

        // Record the action
        Auth::modLog("Deleted all posts by IP address: <a href=\"?/IP/$ip\">$ip</a>");

        // Redirect
        header('Location: ?/' . sprintf($config['board_path'], $boardName) . $config['file_index'], true, $config['redirect_http']);
    }
}
