<?php

use Sudochan\Service\BoardService;
use Sudochan\Manager\FileManager;
use Sudochan\Utils\TextFormatter;

require 'info.php';

function index_build(string $action, array $settings, $board): void
{
    // Possible values for $action:
    //	- all (rebuild everything, initialization)
    //	- news (news has been updated)
    //	- boards (board list changed)

    Index::build($action, $settings);
}

// Wrap functions in a class so they don't interfere with normal Tinyboard operations
class Index
{
    public static function build(string $action, array $settings): void
    {
        global $config;

        $excluded = isset($settings['exclude']) ? explode(' ', $settings['exclude']) : [];

        if (
            $action == 'all'
            || $action == 'news'
            || $action == 'boards'
            || $action == 'post'
            || $action == 'post-thread'
            || $action == 'post-delete'
        ) {
            FileManager::file_write($config['dir']['home'] . $settings['html'], self::homepage($settings, $excluded));
        }
        if ($action == 'all') {
            copy('templates/themes/index/' . $settings['css'], $config['dir']['home'] . $settings['css']);
        }
    }

    public static function homepage(array $settings, array $excluded): string
    {
        global $config, $board;

        // Build news page
        $settings['no_recent'] = (int) $settings['no_recent'];

        $query = query("SELECT * FROM ``news`` ORDER BY `time` DESC" . ($settings['no_recent'] ? ' LIMIT ' . $settings['no_recent'] : '')) or error(db_error());
        $news = $query->fetchAll(\PDO::FETCH_ASSOC);

        // Build categories page
        $boards = BoardService::listBoards();
        $categories = [];
        foreach ($boards as $board) {
            $cat = $board['category'] ?: 'Uncategorized';
            if (!isset($categories[$cat])) {
                $categories[$cat] = [];
            }
            $categories[$cat][] = [
                'title' => BoardService::boardTitle($board['uri']),
                'uri' => sprintf($config['board_path'], $board['uri']),
            ];
        }

        // Build recent posts page
        $recent_images = [];
        $recent_posts = [];
        $stats = ['total_posts' => 0, 'unique_posters' => 0, 'active_content' => 0];

        $boards = BoardService::listBoards();

        $query = '';
        foreach ($boards as &$_board) {
            if (in_array($_board['uri'], $excluded)) {
                continue;
            }
            $query .= sprintf("SELECT *, '%s' AS `board` FROM ``posts_%s`` WHERE `file` IS NOT NULL AND `file` != 'deleted' AND `thumb` != 'spoiler' UNION ALL ", $_board['uri'], $_board['uri']);
        }
        $query = preg_replace('/UNION ALL $/', 'ORDER BY `time` DESC LIMIT ' . (int) $settings['limit_images'], $query);
        $query = query($query) or error(db_error());

        while ($post = $query->fetch(\PDO::FETCH_ASSOC)) {
            BoardService::openBoard($post['board']);

            // board settings won't be available in the template file, so generate links now
            $post['link'] = $config['root'] . $board['dir'] . $config['dir']['res'] . sprintf($config['file_page'], ($post['thread'] ? $post['thread'] : $post['id'])) . '#' . $post['id'];
            $post['src'] = $config['uri_thumb'] . $post['thumb'];

            $recent_images[] = $post;
        }

        $query = '';
        foreach ($boards as &$_board) {
            if (in_array($_board['uri'], $excluded)) {
                continue;
            }
            $query .= sprintf("SELECT *, '%s' AS `board` FROM ``posts_%s`` UNION ALL ", $_board['uri'], $_board['uri']);
        }
        $query = preg_replace('/UNION ALL $/', 'ORDER BY `time` DESC LIMIT ' . (int) $settings['limit_posts'], $query);
        $query = query($query) or error(db_error());

        while ($post = $query->fetch(\PDO::FETCH_ASSOC)) {
            BoardService::openBoard($post['board']);

            $post['link'] = $config['root'] . $board['dir'] . $config['dir']['res'] . sprintf($config['file_page'], ($post['thread'] ? $post['thread'] : $post['id'])) . '#' . $post['id'];
            $post['snippet'] = TextFormatter::pm_snippet($post['body'], 30);
            $post['board_name'] = $board['name'];

            $recent_posts[] = $post;
        }

        // Total posts
        $query = 'SELECT SUM(`top`) FROM (';
        foreach ($boards as &$_board) {
            if (in_array($_board['uri'], $excluded)) {
                continue;
            }
            $query .= sprintf("SELECT MAX(`id`) AS `top` FROM ``posts_%s`` UNION ALL ", $_board['uri']);
        }
        $query = preg_replace('/UNION ALL $/', ') AS `posts_all`', $query);
        $query = query($query) or error(db_error());
        $stats['total_posts'] = number_format($query->fetchColumn());

        // Build popular threads
        $popular_threads = [];
        $boards = BoardService::listBoards();
        foreach ($boards as &$_board) {
            if (in_array($_board['uri'], $excluded)) {
                continue;
            }
            // Get top 3 threads by reply count
            $query = sprintf(
                "SELECT t.*, '%s' AS `board`, 
                    (SELECT COUNT(*) FROM ``posts_%s`` WHERE `thread` = t.`id`) AS replies
                 FROM ``posts_%s`` t
                 WHERE t.`thread` IS NULL
                 ORDER BY replies DESC, t.`id` DESC
                 LIMIT 3",
                $_board['uri'],
                $_board['uri'],
                $_board['uri'],
            );
            $result = query($query) or error(db_error());
            while ($thread = $result->fetch(\PDO::FETCH_ASSOC)) {
                BoardService::openBoard($thread['board']);
                $thread['link'] = $config['root'] . $board['dir'] . $config['dir']['res'] . sprintf($config['file_page'], $thread['id']);
                $thread['src'] = $config['uri_thumb'] . $thread['thumb'];
                $thread['thumbwidth'] = $thread['thumbwidth'] ?? 150;
                $thread['thumbheight'] = $thread['thumbheight'] ?? 150;
                $thread['snippet'] = TextFormatter::pm_snippet($thread['body'], 30);
                $thread['board_name'] = $board['name'];

                $popular_threads[] = $thread;
            }
        }

        // Unique IPs
        $query = 'SELECT COUNT(DISTINCT(`ip`)) FROM (';
        foreach ($boards as &$_board) {
            if (in_array($_board['uri'], $excluded)) {
                continue;
            }
            $query .= sprintf("SELECT `ip` FROM ``posts_%s`` UNION ALL ", $_board['uri']);
        }
        $query = preg_replace('/UNION ALL $/', ') AS `posts_all`', $query);
        $query = query($query) or error(db_error());
        $stats['unique_posters'] = number_format($query->fetchColumn());

        // Active content
        $query = 'SELECT SUM(`filesize`) FROM (';
        foreach ($boards as &$_board) {
            if (in_array($_board['uri'], $excluded)) {
                continue;
            }
            $query .= sprintf("SELECT `filesize` FROM ``posts_%s`` UNION ALL ", $_board['uri']);
        }
        $query = preg_replace('/UNION ALL $/', ') AS `posts_all`', $query);
        $query = query($query) or error(db_error());
        $stats['active_content'] = $query->fetchColumn();

        return Element('themes/index/index.html', [
            'settings' => $settings,
            'config' => $config,
            'boardlist' => BoardService::createBoardlist(),
            'news' => $news,
            'categories' => $categories,
            'recent_images' => $recent_images,
            'recent_posts' => $recent_posts,
            'stats' => $stats,
            'popular_threads' => $popular_threads,
        ]);
    }
}
