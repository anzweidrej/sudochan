<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Mod\Auth;

class DebugController
{
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
                $query = prepare(', DATE ``antispam`` SET `expires` = UNIX_TIMESTAMP() + :expires WHERE' . $where);
                $query->bindValue(':expires', $config['spam']['hidden_inputs_expire']);
                $query->execute() or error(db_error());
            }

            $args['board'] = $_POST['board'];
            $args['thread'] = $_POST['thread'];
        } else {
            $where = '';
        }

        $query = query('SELECT COUNT(*) FROM ``antispam``' . ($where ? " WHERE $where" : '')) or error(db_error());
        $args['total'] = number_format($query->fetchColumn());

        $query = query('SELECT COUNT(*) FROM ``antispam`` WHERE `expires` IS NOT NULL' . ($where ? " AND $where" : '')) or error(db_error());
        $args['expiring'] = number_format($query->fetchColumn());

        $query = query('SELECT * FROM ``antispam`` ' . ($where ? "WHERE $where" : '') . ' ORDER BY `passed` DESC LIMIT 40') or error(db_error());
        $args['top'] = $query->fetchAll(\PDO::FETCH_ASSOC);

        $query = query('SELECT * FROM ``antispam`` ' . ($where ? "WHERE $where" : '') . ' ORDER BY `created` DESC LIMIT 20') or error(db_error());
        $args['recent'] = $query->fetchAll(\PDO::FETCH_ASSOC);

        mod_page(_('Debug: Anti-spam'), 'mod/debug/antispam.html', $args);
    }

    public function mod_debug_recent_posts(): void
    {
        global $pdo, $config;

        $limit = 500;

        $boards = listBoards();

        // Manually build an SQL query
        $query = 'SELECT * FROM (';
        foreach ($boards as $board) {
            $query .= sprintf('SELECT *, %s AS `board` FROM ``posts_%s`` UNION ALL ', $pdo->quote($board['uri']), $board['uri']);
        }
        // Remove the last "UNION ALL" seperator and complete the query
        $query = preg_replace('/UNION ALL $/', ') AS `all_posts` ORDER BY `time` DESC LIMIT ' . $limit, $query);
        $query = query($query) or error(db_error());
        $posts = $query->fetchAll(\PDO::FETCH_ASSOC);

        // Fetch recent posts from flood prevention cache
        $query = query("SELECT * FROM ``flood`` ORDER BY `time` DESC") or error(db_error());
        $flood_posts = $query->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($posts as &$post) {
            $post['snippet'] = pm_snippet($post['body']);
            foreach ($flood_posts as $flood_post) {
                if ($flood_post['time'] == $post['time'] &&
                    $flood_post['posthash'] == make_comment_hex($post['body_nomarkup']) &&
                    $flood_post['filehash'] == $post['filehash']) {
                    $post['in_flood_table'] = true;
                }
            }
        }

        mod_page(_('Debug: Recent posts'), 'mod/debug/recent_posts.html', ['posts' => $posts, 'flood_posts' => $flood_posts]);
    }

    public function mod_debug_sql(): void
    {
        global $config;

        if (!hasPermission($config['mod']['debug_sql'])) {
            error($config['error']['noaccess']);
        }

        $args['security_token'] = Auth::make_secure_link_token('debug/sql');

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

    public function mod_debug_apc(): void
    {
        global $config;

        if (!hasPermission($config['mod']['debug_apc'])) {
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
