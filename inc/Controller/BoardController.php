<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Security\Authenticator;
use Sudochan\Manager\{CacheManager as Cache, ThemeManager, PermissionManager, FileManager};
use Sudochan\Service\{BoardService, PageService, PostService};
use Sudochan\Utils\{Token, StringFormatter};
use Sudochan\Repository\BoardRepository;

class BoardController
{
    private BoardRepository $repository;

    public function __construct(?BoardRepository $repository = null)
    {
        $this->repository = $repository ?? new BoardRepository();
    }

    /**
     * Edit or delete a board.
     *
     * @param string $boardName Board URI to edit.
     * @return void
     */
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

                // Delete board row
                $this->repository->deleteBoardUri($board['uri']);

                if ($config['cache']['enabled']) {
                    Cache::delete('board_' . $board['uri']);
                    Cache::delete('all_boards');
                }

                Authenticator::modLog('Deleted board: ' . sprintf($config['board_abbreviation'], $board['uri']), false);

                // Delete posting table
                $this->repository->dropPostsTable($board['uri']);

                // Clear reports
                $this->repository->deleteReportsForBoard($board['uri']);

                // Delete from boards table
                $this->repository->deleteBoardsWhereUri($board['uri']);

                $cites = $this->repository->selectCitesByTargetBoard($board['uri']);
                foreach ($cites as $cite) {
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

                $this->repository->deleteCitesForBoard($board['uri']);
                $this->repository->deleteAntispamForBoard($board['uri']);

                // Remove board from users/permissions table
                $users = $this->repository->selectAllMods();
                foreach ($users as $user) {
                    $user_boards = explode(',', $user['boards']);
                    if (in_array($board['uri'], $user_boards)) {
                        unset($user_boards[array_search($board['uri'], $user_boards)]);
                        $this->repository->updateModBoards($user['id'], implode(',', $user_boards));
                    }
                }

                // Delete entire board directory
                FileManager::rrmdir($board['uri'] . '/');
            } else {
                $this->repository->updateBoardInfo($board['uri'], $_POST['title'], $_POST['subtitle'], $_POST['category']);

                Authenticator::modLog('Edited board information for ' . sprintf($config['board_abbreviation'], $board['uri']), false);
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
                'token' => Token::make_secure_link_token('edit/' . $board['uri']),
            ]);
        }
    }

    /**
     * Create a new board.
     *
     * @return void
     */
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
                $ord = StringFormatter::ordutf8($char, $o);
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

            $this->repository->insertBoard($_POST['uri'], $_POST['title'], $_POST['subtitle'], $_POST['category']);

            Authenticator::modLog('Created a new board: ' . sprintf($config['board_abbreviation'], $_POST['uri']));

            if (!BoardService::openBoard($_POST['uri'])) {
                error(_("Couldn't open board after creation."));
            }

            $query = element('posts.sql', ['board' => $board['uri']]);

            if (mysql_version() < 50503) {
                $query = preg_replace('/(CHARSET=|CHARACTER SET )utf8mb4/', '$1utf8', $query);
            }

            $this->repository->executeSql($query);

            if ($config['cache']['enabled']) {
                Cache::delete('all_boards');
            }

            // Build the board
            PageService::buildIndex();

            ThemeManager::rebuildThemes('boards');

            header('Location: ?/' . $board['uri'] . '/' . $config['file_index'], true, $config['redirect_http']);
        }

        mod_page(_('New board'), 'mod/board.html', ['new' => true, 'token' => Token::make_secure_link_token('new-board')]);
    }

    /**
     * View a board index.
     *
     * @param string $boardName Board URI to view.
     * @param int $page_no Page number (1-based).
     * @return void
     */
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

    /**
     * View a thread.
     *
     * @param string $boardName Board URI containing the thread.
     * @param int $thread Thread ID.
     * @return void
     */
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
