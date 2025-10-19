<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Service;

use Sudochan\Manager\CacheManager as Cache;

class BoardService
{
    /**
     * Get the title for a board.
     *
     * @param string $uri Board URI.
     * @return string|false Board title or false if not found.
     */
    public static function boardTitle(string $uri): string|false
    {
        $board = self::getBoardInfo($uri);
        if ($board) {
            return $board['title'];
        }
        return false;
    }

    /**
     * Build a part of the board list HTML.
     *
     * @param array $list Board list or nested board list.
     * @param string $root Root URL or prefix.
     * @return string HTML fragment for this part of the board list.
     */
    public static function doBoardListPart(array $list, string $root): string
    {
        global $config;

        $body = '';
        foreach ($list as $board) {
            if (is_array($board)) {
                $body .= ' <span class="sub">[' . self::doBoardListPart($board, $root) . ']</span> ';
            } else {
                if (($key = array_search($board, $list)) && is_string($key)) {
                    $body .= ' <a href="' . $board . '">' . $key . '</a> /';
                } else {
                    $body .= ' <a href="' . $root . $board . '/' . $config['file_index'] . '">' . $board . '</a> /';
                }
            }
        }
        $body = preg_replace('/\/$/', '', $body);

        return $body;
    }

    /**
     * Create the boardlist HTML for top and bottom placements.
     *
     * @param bool|array $mod If truthy/array, use mod view prefix.
     * @return array Associative array with 'top' and 'bottom' HTML strings.
     */
    public static function createBoardlist(bool|array $mod = false): array
    {
        global $config;

        if (!isset($config['boards']) || !is_array($config['boards'])) {
            $config['boards'] = [];
        }

        // Ensure boards from the database are included in the config
        foreach (self::listBoards() as $val) {
            if (!in_array($val['uri'], $config['boards'], true)) {
                $config['boards'][] = $val['uri'];
            }
        }

        $body = self::doBoardListPart($config['boards'], $mod ? '?/' : $config['root']);
        if ($config['boardlist_wrap_bracket'] && !preg_match('/\] $/', $body)) {
            $body = '[' . $body . ']';
        }

        $body = trim($body);

        return [
            'top' => '<div class="boardlist">' . $body . '</div>',
            'bottom' => '<div class="boardlist bottom">' . $body . '</div>',
        ];
    }

    /**
     * Initialize global $board state and ensure board directories exist.
     *
     * @param array $array Board data.
     * @return void
     */
    public static function setupBoard(array $array): void
    {
        global $board, $config;

        $board = [
            'uri' => $array['uri'],
            'title' => $array['title'],
            'subtitle' => $array['subtitle'],
        ];

        // older versions
        $board['name'] = &$board['title'];

        $board['dir'] = sprintf($config['board_path'], $board['uri']);
        $board['url'] = sprintf($config['board_abbreviation'], $board['uri']);

        if (!file_exists($board['dir'])) {
            @mkdir($board['dir'], 0777) or error("Couldn't create " . $board['dir'] . ". Check permissions.", true);
        }
        if (!file_exists($board['dir'] . $config['dir']['img'])) {
            @mkdir($board['dir'] . $config['dir']['img'], 0777)
                or error("Couldn't create " . $board['dir'] . $config['dir']['img'] . ". Check permissions.", true);
        }
        if (!file_exists($board['dir'] . $config['dir']['thumb'])) {
            @mkdir($board['dir'] . $config['dir']['thumb'], 0777)
                or error("Couldn't create " . $board['dir'] . $config['dir']['img'] . ". Check permissions.", true);
        }
        if (!file_exists($board['dir'] . $config['dir']['res'])) {
            @mkdir($board['dir'] . $config['dir']['res'], 0777)
                or error("Couldn't create " . $board['dir'] . $config['dir']['img'] . ". Check permissions.", true);
        }
    }

    /**
     * Open a board by URI and set it up.
     *
     * @param string $uri Board URI.
     * @return bool True if board opened and setup successfully, false otherwise.
     */
    public static function openBoard(string $uri): bool
    {
        global $config, $build_pages;

        if ($config['try_smarter']) {
            $build_pages = [];
        }

        $board = self::getBoardInfo($uri);
        if ($board) {
            self::setupBoard($board);
            return true;
        }
        return false;
    }

    /**
     * Retrieve all boards from the database.
     *
     * @return array List of boards.
     */
    public static function listBoards(): array
    {
        global $config;

        if ($config['cache']['enabled'] && ($boards = Cache::get('all_boards'))) {
            return $boards;
        }

        $query = query("SELECT * FROM ``boards`` ORDER BY `uri`") or error(db_error());
        $boards = $query->fetchAll();

        if ($config['cache']['enabled']) {
            Cache::set('all_boards', $boards);
        }

        return $boards;
    }

    /**
     * Get information for a single board by URI.
     *
     * @param string $uri Board URI.
     * @return array|false Board data array or false if not found.
     */
    public static function getBoardInfo(string $uri): array|false
    {
        global $config;

        if ($config['cache']['enabled'] && ($board = Cache::get('board_' . $uri))) {
            return $board;
        }

        $query = prepare("SELECT * FROM ``boards`` WHERE `uri` = :uri LIMIT 1");
        $query->bindValue(':uri', $uri);
        $query->execute() or error(db_error($query));

        if ($board = $query->fetch(\PDO::FETCH_ASSOC)) {
            if ($config['cache']['enabled']) {
                Cache::set('board_' . $uri, $board);
            }
            return $board;
        }

        return false;
    }
}
