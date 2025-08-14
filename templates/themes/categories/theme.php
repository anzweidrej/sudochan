<?php

require 'info.php';

/**
 * @param 'all'|'news'|'boards' $action
 * @param array<string, mixed> $settings
 * @param mixed $board
 */
function categories_build(string $action, array $settings, mixed $board): void
{
    // Possible values for $action:
    //	- all (rebuild everything, initialization)
    //	- news (news has been updated)
    //	- boards (board list changed)

    Categories::build($action, $settings);
}

// Wrap functions in a class so they don't interfere with normal Tinyboard operations
class Categories
{
    /**
     * @param 'all'|'news'|'boards' $action
     * @param array<string, mixed> $settings
     */
    public static function build(string $action, array $settings): void
    {
        global $config;

        if ($action == 'all') {
            file_write($config['dir']['home'] . $settings['file_main'], Categories::homepage($settings));
        }

        if ($action == 'all' || $action == 'boards') {
            file_write($config['dir']['home'] . $settings['file_sidebar'], Categories::sidebar($settings));
        }

        if ($action == 'all' || $action == 'news') {
            file_write($config['dir']['home'] . $settings['file_news'], Categories::news($settings));
        }
    }

    /**
     * Build homepage
     *
     * @param array<string, mixed> $settings
     */
    public static function homepage(array $settings): string
    {
        global $config;

        return element('themes/categories/frames.html', ['config' => $config, 'settings' => $settings]);
    }

    /**
     * Build news page
     *
     * @param array<string, mixed> $settings
     */
    public static function news(array $settings): string
    {
        global $config;

        $query = query("SELECT * FROM ``news`` ORDER BY `time` DESC") or error(db_error());
        $news = $query->fetchAll(PDO::FETCH_ASSOC);

        return element('themes/categories/news.html', [
            'settings' => $settings,
            'config' => $config,
            'news' => $news,
            'boardlist' => createBoardlist(false),
        ]);
    }

    /**
     * Build sidebar
     *
     * @param array<string, mixed> $settings
     */
    public static function sidebar(array $settings): string
    {
        global $config, $board;

        $categories = $config['categories'];

        foreach ($categories as &$boards) {
            foreach ($boards as &$board) {
                $title = boardTitle($board);
                if (!$title) {
                    $title = $board;
                } // board doesn't exist, but for some reason you want to display it anyway
                $board = ['title' => $title, 'uri' => sprintf($config['board_path'], $board)];
            }
        }

        return element('themes/categories/sidebar.html', [
            'settings' => $settings,
            'config' => $config,
            'categories' => $categories,
        ]);
    }
}
