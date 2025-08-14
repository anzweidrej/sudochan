<?php

require 'info.php';

/**
 * @param 'all'|'news'|'boards' $action
 * @param array<string, mixed> $settings
 * @param mixed $board
 */
function frameset_build(string $action, array $settings, mixed $board): void
{
    // Possible values for $action:
    //	- all (rebuild everything, initialization)
    //	- news (news has been updated)
    //	- boards (board list changed)

    Frameset::build($action, $settings);
}

// Wrap functions in a class so they don't interfere with normal Tinyboard operations
class Frameset
{
    /**
     * @param 'all'|'news'|'boards' $action
     * @param array<string, mixed> $settings
     */
    public static function build(string $action, array $settings): void
    {
        global $config;

        if ($action == 'all') {
            file_write($config['dir']['home'] . $settings['file_main'], Frameset::homepage($settings));
        }

        if ($action == 'all' || $action == 'boards') {
            file_write($config['dir']['home'] . $settings['file_sidebar'], Frameset::sidebar($settings));
        }

        if ($action == 'all' || $action == 'news') {
            file_write($config['dir']['home'] . $settings['file_news'], Frameset::news($settings));
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

        return element('themes/frameset/frames.html', ['config' => $config, 'settings' => $settings]);
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

        return element('themes/frameset/news.html', [
            'settings' => $settings,
            'config' => $config,
            'news' => $news,
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

        return element('themes/frameset/sidebar.html', [
            'settings' => $settings,
            'config' => $config,
            'boards' => listBoards(),
        ]);
    }
}
