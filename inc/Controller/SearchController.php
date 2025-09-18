<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Bans;
use Sudochan\Service\BoardService;
use Sudochan\Manager\PermissionManager;
use Sudochan\Utils\TextFormatter;

class SearchController
{
    public function mod_search_redirect(): void
    {
        global $config;

        if (!PermissionManager::hasPermission($config['mod']['search'])) {
            error($config['error']['noaccess']);
        }

        if (isset($_POST['query'], $_POST['type']) && in_array($_POST['type'], ['posts', 'IP_notes', 'bans', 'log'])) {
            $query = $_POST['query'];
            $query = urlencode($query);
            $query = str_replace('_', '%5F', $query);
            $query = str_replace('+', '_', $query);

            if ($query === '') {
                header('Location: ?/', true, $config['redirect_http']);
                return;
            }

            header('Location: ?/search/' . $_POST['type'] . '/' . $query, true, $config['redirect_http']);
        } else {
            header('Location: ?/', true, $config['redirect_http']);
        }
    }

    public function mod_search(string $type, string $search_query_escaped, int $page_no = 1): void
    {
        global $pdo, $config;

        if (!PermissionManager::hasPermission($config['mod']['search'])) {
            error($config['error']['noaccess']);
        }

        // Unescape query
        $query = str_replace('_', ' ', $search_query_escaped);
        $query = urldecode($query);
        $search_query = $query;

        // Form a series of LIKE clauses for the query.
        // This gets a little complicated.

        // Escape "escape" character
        $query = str_replace('!', '!!', $query);

        // Escape SQL wildcard
        $query = str_replace('%', '!%', $query);

        // Use asterisk as wildcard instead
        $query = str_replace('*', '%', $query);

        $query = str_replace('`', '!`', $query);

        // Array of phrases to match
        $match = [];

        // Exact phrases ("like this")
        if (preg_match_all('/"(.+?)"/', $query, $exact_phrases)) {
            $exact_phrases = $exact_phrases[1];
            foreach ($exact_phrases as $phrase) {
                $query = str_replace("\"{$phrase}\"", '', $query);
                $match[] = $pdo->quote($phrase);
            }
        }

        // Non-exact phrases (ie. plain keywords)
        $keywords = explode(' ', $query);
        foreach ($keywords as $word) {
            if (empty($word)) {
                continue;
            }
            $match[] = $pdo->quote($word);
        }

        // Which `field` to search?
        if ($type == 'posts') {
            $sql_field = ['body_nomarkup', 'filename', 'file', 'subject', 'filehash', 'ip', 'name', 'trip'];
        }
        if ($type == 'IP_notes') {
            $sql_field = 'body';
        }
        if ($type == 'bans') {
            $sql_field = 'reason';
        }
        if ($type == 'log') {
            $sql_field = 'text';
        }

        // Build the "LIKE 'this' AND LIKE 'that'" etc. part of the SQL query
        $sql_like = '';
        foreach ($match as $phrase) {
            if (!empty($sql_like)) {
                $sql_like .= ' AND ';
            }
            $phrase = preg_replace('/^\'(.+)\'$/', '\'%$1%\'', $phrase);
            if (is_array($sql_field)) {
                foreach ($sql_field as $field) {
                    $sql_like .= '`' . $field . '` LIKE ' . $phrase . ' ESCAPE \'!\' OR';
                }
                $sql_like = preg_replace('/ OR$/', '', $sql_like);
            } else {
                $sql_like .= '`' . $sql_field . '` LIKE ' . $phrase . ' ESCAPE \'!\'';
            }
        }

        // Compile SQL query

        if ($type == 'posts') {
            $query = '';
            $boards = BoardService::listBoards();
            if (empty($boards)) {
                error(_('There are no boards to search!'));
            }

            foreach ($boards as $board) {
                BoardService::openBoard($board['uri']);
                if (!PermissionManager::hasPermission($config['mod']['search_posts'], $board['uri'])) {
                    continue;
                }

                if (!empty($query)) {
                    $query .= ' UNION ALL ';
                }
                $query .= sprintf("SELECT *, '%s' AS `board` FROM ``posts_%s`` WHERE %s", $board['uri'], $board['uri'], $sql_like);
            }

            // You weren't allowed to search any boards
            if (empty($query)) {
                error($config['error']['noaccess']);
            }

            $query .= ' ORDER BY `sticky` DESC, `id` DESC';
        }

        if ($type == 'IP_notes') {
            $query = 'SELECT * FROM ``ip_notes`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE ' . $sql_like . ' ORDER BY `time` DESC';
            $sql_table = 'ip_notes';
            if (!PermissionManager::hasPermission($config['mod']['view_notes']) || !PermissionManager::hasPermission($config['mod']['show_ip'])) {
                error($config['error']['noaccess']);
            }
        }

        if ($type == 'bans') {
            $query = 'SELECT ``bans``.*, `username` FROM ``bans`` LEFT JOIN ``mods`` ON `creator` = ``mods``.`id` WHERE ' . $sql_like . ' ORDER BY (`expires` IS NOT NULL AND `expires` < UNIX_TIMESTAMP()), `created` DESC';
            $sql_table = 'bans';
            if (!PermissionManager::hasPermission($config['mod']['view_banlist'])) {
                error($config['error']['noaccess']);
            }
        }

        if ($type == 'log') {
            $query = 'SELECT `username`, `mod`, `ip`, `board`, `time`, `text` FROM ``modlogs`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE ' . $sql_like . ' ORDER BY `time` DESC';
            $sql_table = 'modlogs';
            if (!PermissionManager::hasPermission($config['mod']['modlog'])) {
                error($config['error']['noaccess']);
            }
        }

        // Execute SQL query (with pages)
        $q = query($query . ' LIMIT ' . (($page_no - 1) * $config['mod']['search_page']) . ', ' . $config['mod']['search_page']) or error(db_error());
        $results = $q->fetchAll(\PDO::FETCH_ASSOC);

        // Get total result count
        if ($type == 'posts') {
            $q = query("SELECT COUNT(*) FROM ($query) AS `tmp_table`") or error(db_error());
            $result_count = $q->fetchColumn();
        } else {
            $q = query('SELECT COUNT(*) FROM `' . $sql_table . '` WHERE ' . $sql_like) or error(db_error());
            $result_count = $q->fetchColumn();
        }

        if ($type == 'bans') {
            foreach ($results as &$ban) {
                $ban['mask'] = Bans::range_to_string([$ban['ipstart'], $ban['ipend']]);
                if (filter_var($ban['mask'], FILTER_VALIDATE_IP) !== false) {
                    $ban['single_addr'] = true;
                }
            }
        }

        if ($type == 'posts') {
            foreach ($results as &$post) {
                $post['snippet'] = TextFormatter::pm_snippet($post['body']);
            }
        }

        // $results now contains the search results

        mod_page(_('Search results'), 'mod/search_results.html', [
            'search_type' => $type,
            'search_query' => $search_query,
            'search_query_escaped' => $search_query_escaped,
            'result_count' => $result_count,
            'results' => $results,
        ]);
    }
}
