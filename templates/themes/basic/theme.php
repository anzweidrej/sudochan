<?php

require 'info.php';

/**
 * @param 'all'|'news'|'boards' $action
 * @param array<string, mixed> $settings
 * @param mixed $board
 */
function basic_build(string $action, array $settings, mixed $board): void
{
    // Possible values for $action:
    //	- all (rebuild everything, initialization)
    //	- news (news has been updated)
    //	- boards (board list changed)

    Basic::build($action, $settings);
}

// Wrap functions in a class so they don't interfere with normal Tinyboard operations
class Basic
{
    /**
     * @param 'all'|'news'|'boards' $action
     * @param array<string, mixed> $settings
     */
    public static function build(string $action, array $settings): void
    {
        global $config;

        if ($action == 'all' || $action == 'news') {
            file_write($config['dir']['home'] . $settings['file'], Basic::homepage($settings));
        }
    }

    /**
     * Build news page
     *
     * @param array<string, mixed> $settings
     */
    public static function homepage(array $settings): string
    {
        global $config;

        $settings['no_recent'] = (int) $settings['no_recent'];

        $query = query("SELECT * FROM ``news`` ORDER BY `time` DESC" . ($settings['no_recent'] ? ' LIMIT ' . $settings['no_recent'] : '')) or error(db_error());
        $news = $query->fetchAll(PDO::FETCH_ASSOC);

        return element('themes/basic/index.html', [
            'settings' => $settings,
            'config' => $config,
            'boardlist' => createBoardlist(),
            'news' => $news,
        ]);
    }
}
