<?php

require 'info.php';

function categories_build($action, $settings, $board)
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
    public static function build($action, $settings)
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

    // Build homepage
    public static function homepage($settings)
    {
        global $config;

        return element('themes/categories/frames.html', ['config' => $config, 'settings' => $settings]);
    }

    // Build news page
    public static function news($settings)
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

    // Build sidebar
    public static function sidebar($settings)
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
};
