<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Manager\AuthManager;
use Sudochan\Service\BoardService;
use Sudochan\Manager\PermissionManager;
use Sudochan\Utils\Obfuscation;
use Sudochan\Utils\Token;
use Sudochan\Utils\TextFormatter;
use Sudochan\Repository\DebugRepository;

class DebugController
{
    private DebugRepository $repository;

    public function __construct(DebugRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Show and manage antispam entries.
     *
     * @return void
     */
    public function mod_debug_antispam(): void
    {
        global $pdo, $config;

        $args = [];

        if (isset($_POST['board'], $_POST['thread'])) {
            $where = '`board` = ' . $pdo->quote($_POST['board']);
            if ($_POST['thread'] != '') {
                $where .= ' AND `thread` = ' . $pdo->quote($_POST['thread']);
            }

            if (isset($_POST['purge'])) {
                $this->repository->purgeAntispam($where, $config['spam']['hidden_inputs_expire']);
            }

            $args['board'] = $_POST['board'];
            $args['thread'] = $_POST['thread'];
        } else {
            $where = '';
        }

        $args['total'] = number_format($this->repository->countAntispam($where));
        $args['expiring'] = number_format($this->repository->countExpiringAntispam($where));
        $args['top'] = $this->repository->getTopAntispam($where);
        $args['recent'] = $this->repository->getRecentAntispam($where);

        mod_page(_('Debug: Anti-spam'), 'mod/debug/antispam.html', $args);
    }

    /**
     * Display recent posts across boards for debugging.
     *
     * @return void
     */
    public function mod_debug_recent_posts(): void
    {
        global $pdo, $config;

        $limit = 500;

        $boards = BoardService::listBoards();

        $posts = $this->repository->getRecentPosts($limit);

        // Fetch recent posts from flood prevention cache
        $flood_posts = $this->repository->getFloodPosts();

        foreach ($posts as &$post) {
            $post['snippet'] = TextFormatter::pm_snippet($post['body']);
            foreach ($flood_posts as $flood_post) {
                if ($flood_post['time'] == $post['time']
                    && $flood_post['posthash'] == Obfuscation::make_comment_hex($post['body_nomarkup'])
                    && $flood_post['filehash'] == $post['filehash']) {
                    $post['in_flood_table'] = true;
                }
            }
        }

        mod_page(_('Debug: Recent posts'), 'mod/debug/recent_posts.html', ['posts' => $posts, 'flood_posts' => $flood_posts]);
    }

    /**
     * Execute arbitrary SQL for debugging.
     *
     * @return void
     */
    public function mod_debug_sql(): void
    {
        global $config;

        if (!PermissionManager::hasPermission($config['mod']['debug_sql'])) {
            error($config['error']['noaccess']);
        }

        $args['security_token'] = Token::make_secure_link_token('debug/sql');

        if (isset($_POST['query'])) {
            $args['query'] = $_POST['query'];
            if ($query = query($_POST['query'])) {
                $args['result'] = $query->fetchAll(\PDO::FETCH_ASSOC);
                if (!empty($args['result'])) {
                    $args['keys'] = array_keys($args['result'][0]);
                } else {
                    $args['result'] = 'empty';
                }
            } else {
                $args['error'] = db_error();
            }
        }

        mod_page(_('Debug: SQL'), 'mod/debug/sql.html', $args);
    }

    /**
     * Inspect APC user cache entries.
     *
     * @return void
     */
    public function mod_debug_apc(): void
    {
        global $config;

        if (!PermissionManager::hasPermission($config['mod']['debug_apc'])) {
            error($config['error']['noaccess']);
        }

        if ($config['cache']['enabled'] != 'apc') {
            error('APC is not enabled.');
        }

        $cache_info = apc_cache_info('user');

        // $cached_vars = new APCIterator('user', '/^' . $config['cache']['prefix'] . '/');
        $cached_vars = [];
        foreach ($cache_info['cache_list'] as $var) {
            if ($config['cache']['prefix'] != '' && strpos(isset($var['key']) ? $var['key'] : $var['info'], $config['cache']['prefix']) !== 0) {
                continue;
            }
            $cached_vars[] = $var;
        }

        mod_page(_('Debug: APC'), 'mod/debug/apc.html', ['cached_vars' => $cached_vars]);
    }
}
