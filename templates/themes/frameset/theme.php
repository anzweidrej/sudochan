<?php

use Sudochan\Manager\FileManager;
use Sudochan\Service\BoardService;

require 'info.php';

function frameset_build(string $action, array $settings, string|array|false $board): void
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
    public static function build(string $action, array $settings): void
    {
        global $config;

        if ($action == 'all') {
            FileManager::file_write($config['dir']['home'] . $settings['file_main'], Frameset::homepage($settings));
        }
        if ($action == 'all' || $action == 'boards') {
            FileManager::file_write($config['dir']['home'] . $settings['file_sidebar'], Frameset::sidebar($settings));
        }
        if ($action == 'all') {
            copy('templates/themes/frameset/' . $settings['css'], $config['dir']['home'] . $settings['css']);
        }
    }

    // Build homepage
    public static function homepage(array $settings): string
    {
        global $config;

        // Use the already existing index.html at the site root instead of building one
        $settings['file_index'] = rtrim($config['root'], '/') . '/index.html';

        return element('themes/frameset/frames.html', ['config' => $config, 'settings' => $settings]);
    }

    // Build sidebar
    public static function sidebar(array $settings): string
    {
        global $config, $board;

        return element('themes/frameset/sidebar.html', [
            'settings' => $settings,
            'config' => $config,
            'boards' => BoardService::listBoards(),
        ]);
    }
}
