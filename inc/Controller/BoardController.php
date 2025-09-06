<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Mod\Auth;
use Sudochan\Cache;
use Sudochan\Service\BoardService;
use Sudochan\Service\PageService;
use Sudochan\Service\PostService;
use Sudochan\Manager\ThemeManager;
use Sudochan\Manager\PermissionManager;
use Sudochan\Manager\FileManager;

class BoardController
{
    public function mod_edit_board(string $boardName): void
    {
        global $board, $config;

        if (!BoardService::openBoard($boardName)) {
            error($config['error']['noboard']);
        }

        if (!PermissionManager::hasPermission($config['mod']['manageboards'], $board['uri'])) {
            error($config['error']['noaccess']);
        }

        if (isset($_POST['title'], $_POST['subtitle'])) {
            if (isset($_POST['delete'])) {
                if (!PermissionManager::hasPermission($config['mod']['manageboards'], $board['uri'])) {
                    error($config['error']['deleteboard']);
                }

                $query = prepare('DELETE FROM ``boards`` WHERE `uri` = :uri');
                $query->bindValue(':uri', $board['uri']);
                $query->execute() or error(db_error($query));

                if ($config['cache']['enabled']) {
                    Cache::delete('board_' . $board['uri']);
                    Cache::delete('all_boards');
                }

                Auth::modLog('Deleted board: ' . sprintf($config['board_abbreviation'], $board['uri']), false);

                // Delete posting table
                $query = query(sprintf('DROP TABLE IF EXISTS ``posts_%s``', $board['uri'])) or error(db_error());

                // Clear reports
                $query = prepare('DELETE FROM ``reports`` WHERE `board` = :id');
                $query->bindValue(':id', $board['uri'], \PDO::PARAM_INT);
                $query->execute() or error(db_error($query));

                // Delete from table
                $query = prepare('DELETE FROM ``boards`` WHERE `uri` = :uri');
                $query->bindValue(':uri', $board['uri'], \PDO::PARAM_STR);
                $query->execute() or error(db_error($query));

                $query = prepare("SELECT `board`, `post` FROM ``cites`` WHERE `target_board` = :board ORDER BY `board`");
                $query->bindValue(':board', $board['uri']);
                $query->execute() or error(db_error($query));
                while ($cite = $query->fetch(\PDO::FETCH_ASSOC)) {
                    if ($board['uri'] != $cite['board']) {
                        if (!isset($tmp_board)) {
                            $tmp_board = $board;
                        }
                        BoardService::openBoard($cite['board']);
                        PostService::rebuildPost($cite['post']);
                    }
                }

                if (isset($tmp_board)) {
                    $board = $tmp_board;
                }

                $query = prepare('DELETE FROM ``cites`` WHERE `board` = :board OR `target_board` = :board');
                $query->bindValue(':board', $board['uri']);
                $query->execute() or error(db_error($query));

                $query = prepare('DELETE FROM ``antispam`` WHERE `board` = :board');
                $query->bindValue(':board', $board['uri']);
                $query->execute() or error(db_error($query));

                // Remove board from users/permissions table
                $query = query('SELECT `id`,`boards` FROM ``mods``') or error(db_error());
                while ($user = $query->fetch(\PDO::FETCH_ASSOC)) {
                    $user_boards = explode(',', $user['boards']);
                    if (in_array($board['uri'], $user_boards)) {
                        unset($user_boards[array_search($board['uri'], $user_boards)]);
                        $_query = prepare('UPDATE ``mods`` SET `boards` = :boards WHERE `id` = :id');
                        $_query->bindValue(':boards', implode(',', $user_boards));
                        $_query->bindValue(':id', $user['id']);
                        $_query->execute() or error(db_error($_query));
                    }
                }

                // Delete entire board directory
                FileManager::rrmdir($board['uri'] . '/');
            } else {
                $query = prepare('UPDATE ``boards`` SET `title` = :title, `subtitle` = :subtitle, `category` = :category WHERE `uri` = :uri');
                $query->bindValue(':uri', $board['uri']);
                $query->bindValue(':title', $_POST['title']);
                $query->bindValue(':subtitle', $_POST['subtitle']);
                $query->bindValue(':category', $_POST['category']);
                $query->execute() or error(db_error($query));

                Auth::modLog('Edited board information for ' . sprintf($config['board_abbreviation'], $board['uri']), false);
            }

            if ($config['cache']['enabled']) {
                Cache::delete('board_' . $board['uri']);
                Cache::delete('all_boards');
            }

            ThemeManager::rebuildThemes('boards');

            header('Location: ?/', true, $config['redirect_http']);
        } else {
            mod_page(sprintf('%s: ' . $config['board_abbreviation'], _('Edit board'), $board['uri']), 'mod/board.html', [
                'board' => $board,
                'token' => Auth::make_secure_link_token('edit/' . $board['uri']),
            ]);
        }
    }

    public function mod_new_board(): void
    {
        global $config, $board;

        if (!PermissionManager::hasPermission($config['mod']['newboard'])) {
            error($config['error']['noaccess']);
        }

        if (isset($_POST['uri'], $_POST['title'], $_POST['subtitle'], $_POST['category'])) {
            if ($_POST['uri'] == '') {
                error(sprintf($config['error']['required'], 'URI'));
            }

            if ($_POST['title'] == '') {
                error(sprintf($config['error']['required'], 'title'));
            }

            if (!preg_match('/^' . $config['board_regex'] . '$/u', $_POST['uri'])) {
                error(sprintf($config['error']['invalidfield'], 'URI'));
            }

            $bytes = 0;
            $chars = preg_split('//u', $_POST['uri'], -1, PREG_SPLIT_NO_EMPTY);
            foreach ($chars as $char) {
                $o = 0;
                $ord = ordutf8($char, $o);
                if ($ord > 0x0080) {
                    $bytes += 5;
                } // @01ff
                else {
                    $bytes++;
                }
            }
            $bytes + strlen('posts_.frm');

            if ($bytes > 255) {
                error('Your filesystem cannot handle a board URI of that length (' . $bytes . '/255 bytes)');
                exit;
            }

            if (BoardService::openBoard($_POST['uri'])) {
                error(sprintf($config['error']['boardexists'], $board['url']));
            }

            $query = prepare('INSERT INTO ``boards`` VALUES (:uri, :title, :subtitle, :category)');
            $query->bindValue(':uri', $_POST['uri']);
            $query->bindValue(':title', $_POST['title']);
            $query->bindValue(':subtitle', $_POST['subtitle']);
            $query->bindValue(':category', $_POST['category']);
            $query->execute() or error(db_error($query));

            Auth::modLog('Created a new board: ' . sprintf($config['board_abbreviation'], $_POST['uri']));

            if (!BoardService::openBoard($_POST['uri'])) {
                error(_("Couldn't open board after creation."));
            }

            $query = element('posts.sql', ['board' => $board['uri']]);

            if (mysql_version() < 50503) {
                $query = preg_replace('/(CHARSET=|CHARACTER SET )utf8mb4/', '$1utf8', $query);
            }

            query($query) or error(db_error());

            if ($config['cache']['enabled']) {
                Cache::delete('all_boards');
            }

            // Build the board
            PageService::buildIndex();

            ThemeManager::rebuildThemes('boards');

            header('Location: ?/' . $board['uri'] . '/' . $config['file_index'], true, $config['redirect_http']);
        }

        mod_page(_('New board'), 'mod/board.html', ['new' => true, 'token' => Auth::make_secure_link_token('new-board')]);
    }

    public function mod_view_board(string $boardName, int $page_no = 1): void
    {
        global $config, $mod;

        if (!BoardService::openBoard($boardName)) {
            error($config['error']['noboard']);
        }

        if (!$page = PageService::index($page_no, $mod)) {
            error($config['error']['404']);
        }

        $page['pages'] = PageService::getPages(true);
        $page['pages'][$page_no - 1]['selected'] = true;
        $page['btn'] = PageService::getPageButtons($page['pages'], true);
        $page['mod'] = true;
        $page['config'] = $config;

        echo element('index.html', $page);
    }

    public function mod_view_thread(string $boardName, int $thread): void
    {
        global $config, $mod;

        if (!BoardService::openBoard($boardName)) {
            error($config['error']['noboard']);
        }

        $page = PostService::buildThread($thread, true, $mod);
        echo $page;
    }
}
