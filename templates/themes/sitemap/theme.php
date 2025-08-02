<?php

require 'info.php';

function sitemap_build($action, $settings, $board)
{
    global $config;

    // Possible values for $action:
    //	- all (rebuild everything, initialization)
    //	- news (news has been updated)
    //	- boards (board list changed)
    //	- post (a post has been made)
    //	- thread (a thread has been made)

    if ($action != 'post-thread' && $action != 'post-delete') {
        return;
    }

    if (isset($settings['regen_time']) && $settings['regen_time'] > 0) {
        if ($last_gen = @filemtime($settings['path'])) {
            if (time() - $last_gen < (int) $settings['regen_time']) {
                return;
            } // Too soon
        }
    }

    $boards = explode(' ', $settings['boards']);

    $threads = [];

    foreach ($boards as $board) {
        $query = query(sprintf("SELECT `id` AS `thread_id`, (SELECT `time` FROM ``posts_%s`` WHERE `thread` = `thread_id` OR `id` = `thread_id` ORDER BY `time` DESC LIMIT 1) AS `lastmod` FROM ``posts_%s`` WHERE `thread` IS NULL", $board, $board)) or error(db_error());
        $threads[$board] = $query->fetchAll(PDO::FETCH_ASSOC);
    }

    file_write($settings['path'], element('themes/sitemap/sitemap.xml', [
        'settings' => $settings,
        'config' => $config,
        'threads' => $threads,
        'boards' => $boards,
    ]));
}
