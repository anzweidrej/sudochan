<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Security\Authenticator;
use Sudochan\Dispatcher\EventDispatcher;
use Sudochan\Manager\{BanManager as Bans, FileManager, ThemeManager, PermissionManager};
use Sudochan\Service\{BoardService, PageService, PostService, MarkupService};
use Sudochan\Utils\{DateRange, StringFormatter, Token};
use Sudochan\Repository\PostRepository;

class PostController
{
    private PostRepository $repository;

    public function __construct(PostRepository $repository)
    {
        $this->repository = $repository;
    }

    public function mod_lock(string $board, bool $unlock, int $post): void
    {
        global $config;

        if (!BoardService::openBoard($board)) {
            error($config['error']['noboard']);
        }

        if (!PermissionManager::hasPermission($config['mod']['lock'], $board)) {
            error($config['error']['noaccess']);
        }

        $query = $this->repository->updateLock($board, $post, $unlock ? 0 : 1);
        if ($query->rowCount()) {
            Authenticator::modLog(($unlock ? 'Unlocked' : 'Locked') . " thread #{$post}");
            PostService::buildThread($post);
            PageService::buildIndex();
        }

        if ($config['mod']['dismiss_reports_on_lock']) {
            $this->repository->deleteReportsForPost($board, $post);
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

        if (!BoardService::openBoard($board)) {
            error($config['error']['noboard']);
        }

        if (!PermissionManager::hasPermission($config['mod']['sticky'], $board)) {
            error($config['error']['noaccess']);
        }

        $query = $this->repository->updateSticky($board, $post, $unsticky ? 0 : 1);
        if ($query->rowCount()) {
            Authenticator::modLog(($unsticky ? 'Unstickied' : 'Stickied') . " thread #{$post}");
            PostService::buildThread($post);
            PageService::buildIndex();
        }

        header('Location: ?/' . sprintf($config['board_path'], $board) . $config['file_index'], true, $config['redirect_http']);
    }

    public function mod_bumplock(string $board, bool $unbumplock, int $post): void
    {
        global $config;

        if (!BoardService::openBoard($board)) {
            error($config['error']['noboard']);
        }

        if (!PermissionManager::hasPermission($config['mod']['bumplock'], $board)) {
            error($config['error']['noaccess']);
        }

        $query = $this->repository->updateBumplock($board, $post, $unbumplock ? 0 : 1);
        if ($query->rowCount()) {
            Authenticator::modLog(($unbumplock ? 'Unbumplocked' : 'Bumplocked') . " thread #{$post}");
            PostService::buildThread($post);
            PageService::buildIndex();
        }

        header('Location: ?/' . sprintf($config['board_path'], $board) . $config['file_index'], true, $config['redirect_http']);
    }

    public function mod_move(string $originBoard, int $postID): void
    {
        global $board, $config, $mod, $pdo;

        if (!BoardService::openBoard($originBoard)) {
            error($config['error']['noboard']);
        }

        if (!PermissionManager::hasPermission($config['mod']['move'], $originBoard)) {
            error($config['error']['noaccess']);
        }

        $post = $this->repository->selectThreadById($originBoard, $postID);
        if (!$post) {
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

            if (!BoardService::openBoard($targetBoard)) {
                error($config['error']['noboard']);
            }

            // create the new thread
            $newID = PostService::post($post);

            if ($post['has_file']) {
                // copy image
                $clone($file_src, sprintf($config['board_path'], $board['uri']) . $config['dir']['img'] . $post['file']);
                if (!in_array($post['thumb'], ['spoiler', 'deleted', 'file'])) {
                    $clone($file_thumb, sprintf($config['board_path'], $board['uri']) . $config['dir']['thumb'] . $post['thumb']);
                }
            }

            // go back to the original board to fetch replies
            BoardService::openBoard($originBoard);

            $replies = $this->repository->selectRepliesByThread($originBoard, $postID);

            foreach ($replies as &$post) {
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
            }

            $newIDs = [$postID => $newID];

            BoardService::openBoard($targetBoard);

            foreach ($replies as &$post) {
                $cites = $this->repository->selectCitesTarget($originBoard, $post['id']);

                // correct >>X links
                foreach ($cites as $cite) {
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
                $post['tracked_cites'] = MarkupService::markup($post['body'], true);

                // insert reply
                $newIDs[$post['id']] = $newPostID = PostService::post($post);

                if ($post['has_file']) {
                    // copy image
                    $clone($post['file_src'], sprintf($config['board_path'], $board['uri']) . $config['dir']['img'] . $post['file']);
                    $clone($post['file_thumb'], sprintf($config['board_path'], $board['uri']) . $config['dir']['thumb'] . $post['thumb']);
                }

                if (!empty($post['tracked_cites'])) {
                    $insert_rows = [];
                    foreach ($post['tracked_cites'] as $cite) {
                        $insert_rows[] = '('
                            . $pdo->quote($board['uri']) . ', ' . $newPostID . ', '
                            . $pdo->quote($cite[0]) . ', ' . (int) $cite[1] . ')';
                    }
                    $this->repository->insertCitesValues(implode(', ', $insert_rows));
                }
            }

            Authenticator::modLog("Moved thread #{$postID} to " . sprintf($config['board_abbreviation'], $targetBoard) . " (#{$newID})", $originBoard);

            // build new thread
            PostService::buildThread($newID);

            PostService::clean();
            PageService::buildIndex();

            // trigger themes
            ThemeManager::rebuildThemes('post', $targetBoard);

            // return to original board
            BoardService::openBoard($originBoard);

            if ($shadow) {
                // lock old thread
                $query = $this->repository->updateLock($originBoard, $postID, 1);

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

                MarkupService::markup($post['body']);

                $botID = PostService::post($post);
                PostService::buildThread($postID);

                PageService::buildIndex();

                header('Location: ?/' . sprintf($config['board_path'], $originBoard) . $config['dir']['res'] . sprintf($config['file_page'], $postID)
                    . '#' . $botID, true, $config['redirect_http']);
            } else {
                PostService::deletePost($postID);
                PageService::buildIndex();

                BoardService::openBoard($targetBoard);
                header('Location: ?/' . sprintf($config['board_path'], $board['uri']) . $config['dir']['res'] . sprintf($config['file_page'], $newID), true, $config['redirect_http']);
            }
        }

        $boards = BoardService::listBoards();
        if (count($boards) <= 1) {
            error(_('Impossible to move thread; there is only one board.'));
        }

        $security_token = Token::make_secure_link_token($originBoard . '/move/' . $postID);

        mod_page(_('Move thread'), 'mod/move.html', ['post' => $postID, 'board' => $originBoard, 'boards' => $boards, 'token' => $security_token]);
    }

    public function mod_ban_post(string $board, bool $delete, int $post, string|false $token = false): void
    {
        global $config, $mod;

        if (!BoardService::openBoard($board)) {
            error($config['error']['noboard']);
        }

        if (!PermissionManager::hasPermission($config['mod']['delete'], $board)) {
            error($config['error']['noaccess']);
        }

        $security_token = Token::make_secure_link_token($board . '/ban/' . $post);

        $_post = $this->repository->selectPostByIdForBan($board, $post);
        if (!$_post) {
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
                $length_english = Bans::parse_time($_POST['length']) ? 'for ' . DateRange::until(Bans::parse_time($_POST['length'])) : 'permanently';
                $_POST['message'] = preg_replace('/[\r\n]/', '', $_POST['message']);
                $_POST['message'] = str_replace('%length%', $length_english, $_POST['message']);
                $_POST['message'] = str_replace('%LENGTH%', strtoupper($length_english), $_POST['message']);
                $body_nomarkup = sprintf("\n<tinyboard ban message>%s</tinyboard>", StringFormatter::utf8tohtml($_POST['message']));
                $this->repository->updateBodyAppendForBan($board, $post, $body_nomarkup);
                PostService::rebuildPost($post);

                Authenticator::modLog("Attached a public ban message to post #{$post}: " . StringFormatter::utf8tohtml($_POST['message']));
                PostService::buildThread($thread ? $thread : $post);
                PageService::buildIndex();
            } elseif (isset($_POST['delete']) && (int) $_POST['delete']) {
                // Delete post
                PostService::deletePost($post);
                Authenticator::modLog("Deleted post #{$post}");
                // Rebuild board
                PageService::buildIndex();
                // Rebuild themes
                ThemeManager::rebuildThemes('post-delete', $board);
            }

            header('Location: ?/' . sprintf($config['board_path'], $board) . $config['file_index'], true, $config['redirect_http']);
        }

        $args = [
            'ip' => $ip,
            'hide_ip' => !PermissionManager::hasPermission($config['mod']['show_ip'], $board),
            'post' => $post,
            'board' => $board,
            'delete' => (bool) $delete,
            'boards' => BoardService::listBoards(),
            'token' => $security_token,
        ];

        mod_page(_('New ban'), 'mod/ban_form.html', $args);
    }

    public function mod_edit_post(string $board, bool $edit_raw_html, int $postID): void
    {
        global $config, $mod;

        if (!BoardService::openBoard($board)) {
            error($config['error']['noboard']);
        }

        if (!PermissionManager::hasPermission($config['mod']['editpost'], $board)) {
            error($config['error']['noaccess']);
        }

        if ($edit_raw_html && !PermissionManager::hasPermission($config['mod']['rawhtml'], $board)) {
            error($config['error']['noaccess']);
        }

        $security_token = Token::make_secure_link_token($board . '/edit' . ($edit_raw_html ? '_raw' : '') . '/' . $postID);

        $post = $this->repository->selectPostById($board, $postID);
        if (!$post) {
            error($config['error']['404']);
        }

        if (isset($_POST['name'], $_POST['email'], $_POST['subject'], $_POST['body'])) {
            if ($edit_raw_html) {
                $body_nomarkup = $_POST['body'] . "\n<tinyboard raw html>1</tinyboard>";
                $this->repository->updatePostRaw($board, $postID, $_POST['name'], $_POST['email'], $_POST['subject'], $_POST['body'], $body_nomarkup);
            } else {
                $this->repository->updatePost($board, $postID, $_POST['name'], $_POST['email'], $_POST['subject'], $_POST['body']);
            }

            if ($edit_raw_html) {
                Authenticator::modLog("Edited raw HTML of post #{$postID}");
            } else {
                Authenticator::modLog("Edited post #{$postID}");
                PostService::rebuildPost($postID);
            }

            PageService::buildIndex();

            ThemeManager::rebuildThemes('post', $board);

            header('Location: ?/' . sprintf($config['board_path'], $board) . $config['dir']['res'] . sprintf($config['file_page'], $post['thread'] ? $post['thread'] : $postID) . '#' . $postID, true, $config['redirect_http']);
        } else {
            if ($config['minify_html']) {
                $post['body_nomarkup'] = str_replace("\n", '&#010;', StringFormatter::utf8tohtml($post['body_nomarkup']));
                $post['body'] = str_replace("\n", '&#010;', StringFormatter::utf8tohtml($post['body']));
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

        if (!BoardService::openBoard($board)) {
            error($config['error']['noboard']);
        }

        if (!PermissionManager::hasPermission($config['mod']['delete'], $board)) {
            error($config['error']['noaccess']);
        }

        // Delete post
        PostService::deletePost($post);
        // Record the action
        Authenticator::modLog("Deleted post #{$post}");
        // Rebuild board
        PageService::buildIndex();
        // Rebuild themes
        ThemeManager::rebuildThemes('post-delete', $board);
        // Redirect
        header('Location: ?/' . sprintf($config['board_path'], $board) . $config['file_index'], true, $config['redirect_http']);
    }

    public function mod_deletefile(string $board, int $post): void
    {
        global $config, $mod;

        if (!BoardService::openBoard($board)) {
            error($config['error']['noboard']);
        }

        if (!PermissionManager::hasPermission($config['mod']['deletefile'], $board)) {
            error($config['error']['noaccess']);
        }

        // Delete file
        PostService::deleteFile($post);
        // Record the action
        Authenticator::modLog("Deleted file from post #{$post}");

        // Rebuild board
        PageService::buildIndex();
        // Rebuild themes
        ThemeManager::rebuildThemes('post-delete', $board);

        // Redirect
        header('Location: ?/' . sprintf($config['board_path'], $board) . $config['file_index'], true, $config['redirect_http']);
    }

    public function mod_spoiler_image(string $board, int $post): void
    {
        global $config, $mod;

        if (!BoardService::openBoard($board)) {
            error($config['error']['noboard']);
        }

        if (!PermissionManager::hasPermission($config['mod']['spoilerimage'], $board)) {
            error($config['error']['noaccess']);
        }

        // Delete file
        $result = $this->repository->selectThumbAndThread($board, $post);

        FileManager::file_unlink($board . '/' . $config['dir']['thumb'] . $result['thumb']);

        // Make thumbnail spoiler
        $this->repository->updateThumb($board, $post, "spoiler", 128, 128);

        // Record the action
        Authenticator::modLog("Spoilered file from post #{$post}");

        // Rebuild thread
        PostService::buildThread($result['thread'] ? $result['thread'] : $post);

        // Rebuild board
        PageService::buildIndex();

        // Rebuild themes
        ThemeManager::rebuildThemes('post-delete', $board);

        // Redirect
        header('Location: ?/' . sprintf($config['board_path'], $board) . $config['file_index'], true, $config['redirect_http']);
    }

    public function mod_deletebyip(string $boardName, int $post, bool $global = false): void
    {
        global $config, $mod, $board;

        $global = (bool) $global;

        if (!BoardService::openBoard($boardName)) {
            error($config['error']['noboard']);
        }

        if (!$global && !PermissionManager::hasPermission($config['mod']['deletebyip'], $boardName)) {
            error($config['error']['noaccess']);
        }

        if ($global && !PermissionManager::hasPermission($config['mod']['deletebyip_global'], $boardName)) {
            error($config['error']['noaccess']);
        }

        // Find IP address
        $ip = $this->repository->selectIpById($boardName, $post);
        if (!$ip) {
            error($config['error']['invalidpost']);
        }

        $boards = $global ? BoardService::listBoards() : [['uri' => $boardName]];

        $found = $this->repository->selectPostsByIp($boards, $ip);

        if (count($found) < 1) {
            error($config['error']['invalidpost']);
        }

        @set_time_limit($config['mod']['rebuild_timelimit']);

        $threads_to_rebuild = [];
        $threads_deleted = [];
        foreach ($found as $post) {
            BoardService::openBoard($post['board']);

            PostService::deletePost($post['id'], false, false);

            ThemeManager::rebuildThemes('post-delete', $board['uri']);

            if ($post['thread']) {
                $threads_to_rebuild[$post['board']][$post['thread']] = true;
            } else {
                $threads_deleted[$post['board']][$post['id']] = true;
            }
        }

        foreach ($threads_to_rebuild as $_board => $_threads) {
            BoardService::openBoard($_board);
            foreach ($_threads as $_thread => $_dummy) {
                if ($_dummy && !isset($threads_deleted[$_board][$_thread])) {
                    PostService::buildThread($_thread);
                }
            }
            PageService::buildIndex();
        }

        if ($global) {
            $board = false;
        }

        // Record the action
        Authenticator::modLog("Deleted all posts by IP address: <a href=\"?/IP/$ip\">$ip</a>");

        // Redirect
        header('Location: ?/' . sprintf($config['board_path'], $boardName) . $config['file_index'], true, $config['redirect_http']);
    }
}
