#!/usr/bin/php
<?php

/*
 *  rebuild.php - rebuilds all static files
 *
 *  Command line arguments:
 *     -q, --quiet
 *          Suppress output.
 *
 *     --quick
 *          Do not rebuild posts.
 *
 *     -b, --board <string>
 *          Rebuild only the specified board.
 *
 *     -f, --full
 *          Rebuild replies as well as threads (re-markup).
 *
 */

use Sudochan\Manager\ThemeManager;
use Sudochan\Service\{BoardService, PageService, PostService};
use Sudochan\Security\Authenticator;

require dirname(__FILE__) . '/cli.php';

if (!is_writable($config['file_script'])) {
    get_httpd_privileges();
}

$start = microtime(true);

// parse command line
$opts = getopt('qfb:', ['board:', 'quick', 'full', 'quiet']);
$options = [];

$options['board'] = isset($opts['board']) ? $opts['board'] : (isset($opts['b']) ? $opts['b'] : false);
$options['quiet'] = isset($opts['q']) || isset($opts['quiet']);
$options['quick'] = isset($opts['quick']);
$options['full'] = isset($opts['full']) || isset($opts['f']);

if (!$options['quiet']) {
    echo "== Tinyboard {$config['version']} ==\n";
}

if (!$options['quiet']) {
    echo "Clearing template cache...\n";
}

load_twig();
$twig->clearCacheFiles();

if (!$options['quiet']) {
    echo "Regenerating theme files...\n";
}
ThemeManager::rebuildThemes('all');

if (!$options['quiet']) {
    echo "Generating Javascript file...\n";
}
PageService::buildJavascript();

$main_js = $config['file_script'];

$boards = BoardService::listBoards();

foreach ($boards as &$board) {
    if ($options['board'] && $board['uri'] != $options['board']) {
        continue;
    }

    if (!$options['quiet']) {
        echo "Opening board /{$board['uri']}/...\n";
    }
    BoardService::openBoard($board['uri']);
    $config['try_smarter'] = false;

    if ($config['file_script'] != $main_js) {
        // different javascript file
        if (!$options['quiet']) {
            echo "Generating Javascript file...\n";
        }
        PageService::buildJavascript();
    }

    if (!$options['quiet']) {
        echo "Creating index pages...\n";
    }
    PageService::buildIndex();

    if ($options['quick']) {
        continue;
    } // do no more

    if ($options['full']) {
        $query = query(sprintf("SELECT `id` FROM ``posts_%s``", $board['uri'])) or error(db_error());
        while ($post = $query->fetch()) {
            if (!$options['quiet']) {
                echo "Rebuilding #{$post['id']}...\n";
            }
            PostService::rebuildPost($post['id']);
        }
    }

    $query = query(sprintf("SELECT `id` FROM ``posts_%s`` WHERE `thread` IS NULL", $board['uri'])) or error(db_error());
    while ($post = $query->fetch()) {
        if (!$options['quiet']) {
            echo "Rebuilding #{$post['id']}...\n";
        }
        PostService::buildThread($post['id']);
    }
}

if (!$options['quiet']) {
    printf("Complete! Took %g seconds\n", microtime(true) - $start);
}

unset($board);
Authenticator::modLog('Rebuilt everything using tools/rebuild.php');
