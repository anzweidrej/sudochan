<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Service;

use Sudochan\Entity\Thread;
use Sudochan\Entity\Post;
use Sudochan\Cache;
use Sudochan\Api;
use Sudochan\Manager\FileManager;
use Sudochan\Service\BoardService;
use Sudochan\Factory\AntiBotFactory;

class PageService
{
    public static function index(int $page, bool|array $mod = false): array|false
    {
        global $board, $config, $debug;

        $body = '';
        $offset = round($page * $config['threads_per_page'] - $config['threads_per_page']);

        $query = prepare(sprintf("SELECT * FROM ``posts_%s`` WHERE `thread` IS NULL ORDER BY `sticky` DESC, `bump` DESC LIMIT :offset,:threads_per_page", $board['uri']));
        $query->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $query->bindValue(':threads_per_page', $config['threads_per_page'], \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        if ($page == 1 && $query->rowCount() < $config['threads_per_page']) {
            $board['thread_count'] = $query->rowCount();
        }

        if ($query->rowCount() < 1 && $page > 1) {
            return false;
        }

        $threads = [];

        while ($th = $query->fetch(\PDO::FETCH_ASSOC)) {
            $thread = new Thread($th, $mod ? '?/' : $config['root'], $mod);

            if ($config['cache']['enabled']) {
                $cached = Cache::get("thread_index_{$board['uri']}_{$th['id']}");
                if (isset($cached['replies'], $cached['omitted'])) {
                    $replies = $cached['replies'];
                    $omitted = $cached['omitted'];
                } else {
                    unset($cached);
                }
            }
            if (!isset($cached)) {
                $posts = prepare(sprintf("SELECT * FROM ``posts_%s`` WHERE `thread` = :id ORDER BY `id` DESC LIMIT :limit", $board['uri']));
                $posts->bindValue(':id', $th['id']);
                $posts->bindValue(':limit', ($th['sticky'] ? $config['threads_preview_sticky'] : $config['threads_preview']), \PDO::PARAM_INT);
                $posts->execute() or error(db_error($posts));

                $replies = array_reverse($posts->fetchAll(\PDO::FETCH_ASSOC));

                if (count($replies) == ($th['sticky'] ? $config['threads_preview_sticky'] : $config['threads_preview'])) {
                    $count = self::numPosts($th['id']);
                    $omitted = ['post_count' => $count['replies'], 'image_count' => $count['images']];
                } else {
                    $omitted = false;
                }

                if ($config['cache']['enabled']) {
                    Cache::set("thread_index_{$board['uri']}_{$th['id']}", [
                        'replies' => $replies,
                        'omitted' => $omitted,
                    ]);
                }
            }

            $num_images = 0;
            foreach ($replies as $po) {
                if ($po['file']) {
                    $num_images++;
                }

                $thread->add(new Post($po, $mod ? '?/' : $config['root'], $mod));
            }

            if ($omitted) {
                $thread->omitted = $omitted['post_count'] - ($th['sticky'] ? $config['threads_preview_sticky'] : $config['threads_preview']);
                $thread->omitted_images = $omitted['image_count'] - $num_images;
            }

            $threads[] = $thread;
            $body .= $thread->build(true);
        }

        return [
            'board' => $board,
            'body' => $body,
            'post_url' => $config['post_url'],
            'config' => $config,
            'boardlist' => BoardService::createBoardlist($mod),
            'threads' => $threads,
        ];
    }

    public static function getPageButtons(array $pages, bool $mod = false): array
    {
        global $config, $board;

        $btn = [];
        $root = ($mod ? '?/' : $config['root']) . $board['dir'];

        foreach ($pages as $num => $page) {
            if (isset($page['selected'])) {
                // Previous button
                if ($num == 0) {
                    // There is no previous page.
                    $btn['prev'] = _('Previous');
                } else {
                    $loc = ($mod ? '?/' . $board['uri'] . '/' : '') .
                        (
                            $num == 1 ?
                            $config['file_index']
                        :
                            sprintf($config['file_page'], $num)
                        );

                    $btn['prev'] = '<form action="' . ($mod ? '' : $root . $loc) . '" method="get">' .
                        ($mod ?
                            '<input type="hidden" name="status" value="301" />' .
                            '<input type="hidden" name="r" value="' . htmlentities($loc) . '" />'
                        : '') .
                    '<input type="submit" value="' . _('Previous') . '" /></form>';
                }

                if ($num == count($pages) - 1) {
                    // There is no next page.
                    $btn['next'] = _('Next');
                } else {
                    $loc = ($mod ? '?/' . $board['uri'] . '/' : '') . sprintf($config['file_page'], $num + 2);

                    $btn['next'] = '<form action="' . ($mod ? '' : $root . $loc) . '" method="get">' .
                        ($mod ?
                            '<input type="hidden" name="status" value="301" />' .
                            '<input type="hidden" name="r" value="' . htmlentities($loc) . '" />'
                        : '') .
                    '<input type="submit" value="' . _('Next') . '" /></form>';
                }
            }
        }

        return $btn;
    }

    public static function getPages(bool $mod = false): array
    {
        global $board, $config;

        if (isset($board['thread_count'])) {
            $count = $board['thread_count'];
        } else {
            // Count threads
            $query = query(sprintf("SELECT COUNT(*) FROM ``posts_%s`` WHERE `thread` IS NULL", $board['uri'])) or error(db_error());
            $count = $query->fetchColumn();
        }
        $count = floor(($config['threads_per_page'] + $count - 1) / $config['threads_per_page']);

        if ($count < 1) {
            $count = 1;
        }

        $pages = [];
        for ($x = 0;$x < $count && $x < $config['max_pages'];$x++) {
            $pages[] = [
                'num' => $x + 1,
                'link' => $x == 0 ? ($mod ? '?/' : $config['root']) . $board['dir'] . $config['file_index'] : ($mod ? '?/' : $config['root']) . $board['dir'] . sprintf($config['file_page'], $x + 1),
            ];
        }

        return $pages;
    }

    public static function buildIndex(): void
    {
        global $board, $config, $build_pages;

        $pages = self::getPages();
        if (!$config['try_smarter']) {
            $antibot = AntiBotFactory::create_antibot($board['uri']);
        }

        if ($config['api']['enabled']) {
            $api = new Api();
            $catalog = [];
        }

        for ($page = 1; $page <= $config['max_pages']; $page++) {
            $filename = $board['dir'] . ($page == 1 ? $config['file_index'] : sprintf($config['file_page'], $page));

            if ($config['try_smarter'] && isset($build_pages) && !empty($build_pages)
                && !in_array($page, $build_pages) && is_file($filename)) {
                continue;
            }
            $content = self::index($page);
            if (!$content) {
                break;
            }

            if ($config['try_smarter']) {
                $antibot = AntiBotFactory::create_antibot($board['uri'], 0 - $page);
                $content['current_page'] = $page;
            }
            $antibot->reset();
            $content['pages'] = $pages;
            $content['pages'][$page - 1]['selected'] = true;
            $content['btn'] = self::getPageButtons($content['pages']);
            $content['antibot'] = $antibot;

            FileManager::file_write($filename, element('index.html', $content));

            // json api
            if ($config['api']['enabled']) {
                $threads = $content['threads'];
                $json = json_encode($api->translatePage($threads));
                $jsonFilename = $board['dir'] . ($page - 1) . '.json'; // pages should start from 0
                FileManager::file_write($jsonFilename, $json);

                $catalog[$page - 1] = $threads;
            }
        }

        if ($page < $config['max_pages']) {
            for (;$page <= $config['max_pages'];$page++) {
                $filename = $board['dir'] . ($page == 1 ? $config['file_index'] : sprintf($config['file_page'], $page));
                FileManager::file_unlink($filename);

                $jsonFilename = $board['dir'] . ($page - 1) . '.json';
                FileManager::file_unlink($jsonFilename);
            }
        }

        // json api catalog
        if ($config['api']['enabled']) {
            $json = json_encode($api->translateCatalog($catalog));
            $jsonFilename = $board['dir'] . 'catalog.json';
            FileManager::file_write($jsonFilename, $json);
        }

        if ($config['try_smarter']) {
            $build_pages = [];
        }
    }

    public static function buildJavascript(): void
    {
        global $config;

        $stylesheets = [];
        foreach ($config['stylesheets'] as $name => $uri) {
            $stylesheets[] = [
                'name' => addslashes($name),
                'uri' => addslashes((!empty($uri) ? $config['uri_stylesheets'] : '') . $uri)];
        }

        $script = element('main.js', [
            'config' => $config,
            'stylesheets' => $stylesheets,
        ]);

        // Check if we have translation for the javascripts; if yes, we add it to additional javascripts
        list($pure_locale) = explode(".", $config['locale']);
        if (file_exists($jsloc = "./locales/$pure_locale/LC_MESSAGES/javascript.js")) {
            $script = file_get_contents($jsloc) . "\n\n" . $script;
        }

        if ($config['additional_javascript_compile']) {
            foreach ($config['additional_javascript'] as $file) {
                $script .= file_get_contents($file);
            }
        }

        if ($config['minify_js']) {
            $script = \JSMin\JSMin::minify($script);
        }

        FileManager::file_write($config['file_script'], $script);
    }

    // Returns an associative array with 'replies' and 'images' keys
    public static function numPosts(int $id): array
    {
        global $board;
        $query = prepare(sprintf("SELECT COUNT(*) AS `replies`, COUNT(NULLIF(`file`, 0)) AS `images` FROM ``posts_%s`` WHERE `thread` = :thread", $board['uri']));
        $query->bindValue(':thread', $id, \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        return $query->fetch(\PDO::FETCH_ASSOC);
    }
}
