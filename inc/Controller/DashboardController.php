<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Manager\AuthManager;
use Sudochan\Cache;
use Sudochan\Service\BoardService;
use Sudochan\Service\PageService;
use Sudochan\Service\PostService;
use Sudochan\Manager\ThemeManager;
use Sudochan\Manager\PermissionManager;
use Sudochan\Utils\Token;

class DashboardController
{
    public function mod_dashboard(): void
    {
        global $config, $mod;

        $args = [];

        $args['boards'] = BoardService::listBoards();

        if (PermissionManager::hasPermission($config['mod']['noticeboard'])) {
            if (!$config['cache']['enabled'] || !$args['noticeboard'] = Cache::get('noticeboard_preview')) {
                $query = prepare("SELECT ``noticeboard``.*, `username` FROM ``noticeboard`` LEFT JOIN ``mods`` ON ``mods``.`id` = `mod` ORDER BY `id` DESC LIMIT :limit");
                $query->bindValue(':limit', $config['mod']['noticeboard_dashboard'], \PDO::PARAM_INT);
                $query->execute() or error(db_error($query));
                $args['noticeboard'] = $query->fetchAll(\PDO::FETCH_ASSOC);

                if ($config['cache']['enabled']) {
                    Cache::set('noticeboard_preview', $args['noticeboard']);
                }
            }
        }

        if (!$config['cache']['enabled'] || ($args['unread_pms'] = Cache::get('pm_unreadcount_' . $mod['id'])) === false) {
            $query = prepare('SELECT COUNT(*) FROM ``pms`` WHERE `to` = :id AND `unread` = 1');
            $query->bindValue(':id', $mod['id']);
            $query->execute() or error(db_error($query));
            $args['unread_pms'] = $query->fetchColumn();

            if ($config['cache']['enabled']) {
                Cache::set('pm_unreadcount_' . $mod['id'], $args['unread_pms']);
            }
        }

        $query = query('SELECT COUNT(*) FROM ``reports``') or error(db_error($query));
        $args['reports'] = $query->fetchColumn();

        if ($mod['type'] >= ADMIN && $config['check_updates']) {
            if (!$config['version']) {
                error(_('Could not find current version! (Check .installed)'));
            }

            if (isset($_COOKIE['update'])) {
                $latest = unserialize($_COOKIE['update']);
            } else {
                $ctx = stream_context_create(['http' => ['timeout' => 5]]);
                if ($code = @file_get_contents(__DIR__ . '/../../version.txt', 0, $ctx)) {
                    $ver = strtok($code, "\n");

                    if (preg_match('@^// v(\d+)\.(\d+)\.(\d+)\s*?$@', $ver, $matches)) {
                        $latest = [
                            'massive' => $matches[1],
                            'major' => $matches[2],
                            'minor' => $matches[3],
                        ];
                        if (preg_match('/v(\d+)\.(\d)\.(\d+)(-dev.+)?$/', $config['version'], $matches)) {
                            $current = [
                                'massive' => (int) $matches[1],
                                'major' => (int) $matches[2],
                                'minor' => (int) $matches[3],
                            ];
                            if (isset($m[4])) {
                                // Development versions are always ahead in the versioning numbers
                                $current['minor']--;
                            }
                            // Check if it's newer
                            if (!($latest['massive'] > $current['massive'] ||
                                $latest['major'] > $current['major'] ||
                                    (
                                        $latest['massive'] == $current['massive'] &&
                                        $latest['major'] == $current['major'] &&
                                        $latest['minor'] > $current['minor']
                                    ))) {
                                $latest = false;
                            }
                        } else {
                            $latest = false;
                        }
                    } else {
                        // Couldn't get latest version
                        $latest = false;
                    }
                } else {
                    // Couldn't get latest version
                    $latest = false;
                }

                setcookie('update', serialize($latest), time() + $config['check_updates_time'], $config['cookies']['jail'] ? $config['cookies']['path'] : '/', '', false, true);
            }

            if ($latest) {
                $args['newer_release'] = $latest;
            }
        }

        $args['logout_token'] = Token::make_secure_link_token('logout');

        mod_page(_('Dashboard'), 'mod/dashboard.html', $args);
    }

    public function mod_rebuild(): void
    {
        global $config, $twig;

        if (!PermissionManager::hasPermission($config['mod']['rebuild'])) {
            error($config['error']['noaccess']);
        }

        if (isset($_POST['rebuild'])) {
            @set_time_limit($config['mod']['rebuild_timelimit']);

            $log = [];
            $boards = BoardService::listBoards();
            $rebuilt_scripts = [];

            if (isset($_POST['rebuild_cache'])) {
                if ($config['cache']['enabled']) {
                    $log[] = 'Flushing cache';
                    Cache::flush();
                }

                $log[] = 'Clearing template cache';
                load_twig();
                // Recursively clear Twig template cache directory
                $cache = $twig->getCache();
                if (is_string($cache) && is_dir($cache)) {
                    $it = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($cache, \FilesystemIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::CHILD_FIRST,
                    );
                    foreach ($it as $fs) {
                        $fs->isDir() ? @rmdir($fs->getPathname()) : @unlink($fs->getPathname());
                    }
                }
            }

            if (isset($_POST['rebuild_themes'])) {
                $log[] = 'Regenerating theme files';
                ThemeManager::rebuildThemes('all');
            }

            if (isset($_POST['rebuild_javascript'])) {
                $log[] = 'Rebuilding <strong>' . $config['file_script'] . '</strong>';
                PageService::buildJavascript();
                $rebuilt_scripts[] = $config['file_script'];
            }

            foreach ($boards as $board) {
                if (!(isset($_POST['boards_all']) || isset($_POST['board_' . $board['uri']]))) {
                    continue;
                }

                BoardService::openBoard($board['uri']);
                $config['try_smarter'] = false;

                if (isset($_POST['rebuild_index'])) {
                    PageService::buildIndex();
                    $log[] = '<strong>' . sprintf($config['board_abbreviation'], $board['uri']) . '</strong>: Creating index pages';
                }

                if (isset($_POST['rebuild_javascript']) && !in_array($config['file_script'], $rebuilt_scripts)) {
                    $log[] = '<strong>' . sprintf($config['board_abbreviation'], $board['uri']) . '</strong>: Rebuilding <strong>' . $config['file_script'] . '</strong>';
                    PageService::buildJavascript();
                    $rebuilt_scripts[] = $config['file_script'];
                }

                if (isset($_POST['rebuild_thread'])) {
                    $query = query(sprintf("SELECT `id` FROM ``posts_%s`` WHERE `thread` IS NULL", $board['uri'])) or error(db_error());
                    while ($post = $query->fetch(\PDO::FETCH_ASSOC)) {
                        $log[] = '<strong>' . sprintf($config['board_abbreviation'], $board['uri']) . '</strong>: Rebuilding thread #' . $post['id'];
                        PostService::buildThread($post['id']);
                    }
                }
            }

            mod_page(_('Rebuild'), 'mod/rebuilt.html', ['logs' => $log]);
            return;
        }

        mod_page(_('Rebuild'), 'mod/rebuild.html', [
            'boards' => BoardService::listBoards(),
            'token' => Token::make_secure_link_token('rebuild'),
        ]);
    }
}
