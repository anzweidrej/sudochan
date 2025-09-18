<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Manager\AuthManager;
use Sudochan\Bans;
use Sudochan\Entity\Thread;
use Sudochan\Entity\Post;
use Sudochan\Service\BoardService;
use Sudochan\Manager\PermissionManager;
use Sudochan\Utils\TextFormatter;
use Sudochan\Utils\StringFormatter;
use Sudochan\Utils\Math;
use Sudochan\Utils\Token;

class BanController
{
    public function mod_ban(): void
    {
        global $config;

        if (!PermissionManager::hasPermission($config['mod']['ban'])) {
            error($config['error']['noaccess']);
        }

        if (!isset($_POST['ip'], $_POST['reason'], $_POST['length'], $_POST['board'])) {
            mod_page(_('New ban'), 'mod/ban_form.html', ['token' => Token::make_secure_link_token('ban')]);
            return;
        }

        Bans::new_ban($_POST['ip'], $_POST['reason'], $_POST['length'], $_POST['board'] == '*' ? false : $_POST['board']);

        if (isset($_POST['redirect'])) {
            header('Location: ' . $_POST['redirect'], true, $config['redirect_http']);
        } else {
            header('Location: ?/', true, $config['redirect_http']);
        }
    }

    public function mod_bans(int $page_no = 1): void
    {
        global $config;

        if ($page_no < 1) {
            error($config['error']['404']);
        }

        if (!PermissionManager::hasPermission($config['mod']['view_banlist'])) {
            error($config['error']['noaccess']);
        }

        if (isset($_POST['unban'])) {
            if (!PermissionManager::hasPermission($config['mod']['unban'])) {
                error($config['error']['noaccess']);
            }

            $unban = [];
            foreach ($_POST as $name => $unused) {
                if (preg_match('/^ban_(\d+)$/', $name, $match)) {
                    $unban[] = $match[1];
                }
            }
            if (isset($config['mod']['unban_limit']) && $config['mod']['unban_limit'] && count($unban) > $config['mod']['unban_limit']) {
                error(sprintf($config['error']['toomanyunban'], $config['mod']['unban_limit'], count($unban)));
            }

            foreach ($unban as $id) {
                Bans::delete($id, true);
            }
            header('Location: ?/bans', true, $config['redirect_http']);
            return;
        }

        $bans = Bans::list_all(($page_no - 1) * $config['mod']['banlist_page'], $config['mod']['banlist_page']);

        if (empty($bans) && $page_no > 1) {
            error($config['error']['404']);
        }

        foreach ($bans as &$ban) {
            if (filter_var($ban['mask'], FILTER_VALIDATE_IP) !== false) {
                $ban['single_addr'] = true;
            }
        }

        mod_page(_('Ban list'), 'mod/ban_list.html', [
            'bans' => $bans,
            'count' => Bans::count(),
            'token' => Token::make_secure_link_token('bans'),
        ]);
    }

    public function mod_ban_appeals(): void
    {
        global $config, $board;

        if (!PermissionManager::hasPermission($config['mod']['view_ban_appeals'])) {
            error($config['error']['noaccess']);
        }

        // Remove stale ban appeals
        query("DELETE FROM ``ban_appeals`` WHERE NOT EXISTS (SELECT 1 FROM ``bans`` WHERE `ban_id` = ``bans``.`id`)")
            or error(db_error());

        if (isset($_POST['appeal_id']) && (isset($_POST['unban']) || isset($_POST['deny']))) {
            if (!PermissionManager::hasPermission($config['mod']['ban_appeals'])) {
                error($config['error']['noaccess']);
            }

            $query = query("SELECT *, ``ban_appeals``.`id` AS `id` FROM ``ban_appeals``
                LEFT JOIN ``bans`` ON `ban_id` = ``bans``.`id`
                WHERE ``ban_appeals``.`id` = " . (int) $_POST['appeal_id']) or error(db_error());
            if (!$ban = $query->fetch(\PDO::FETCH_ASSOC)) {
                error(_('Ban appeal not found!'));
            }

            $ban['mask'] = Bans::range_to_string([$ban['ipstart'], $ban['ipend']]);

            if (isset($_POST['unban'])) {
                AuthManager::modLog('Accepted ban appeal #' . $ban['id'] . ' for ' . $ban['mask']);
                Bans::delete($ban['ban_id'], true);
                query("DELETE FROM ``ban_appeals`` WHERE `id` = " . $ban['id']) or error(db_error());
            } else {
                AuthManager::modLog('Denied ban appeal #' . $ban['id'] . ' for ' . $ban['mask']);
                query("UPDATE ``ban_appeals`` SET `denied` = 1 WHERE `id` = " . $ban['id']) or error(db_error());
            }

            header('Location: ?/ban-appeals', true, $config['redirect_http']);
            return;
        }

        $query = query("SELECT *, ``ban_appeals``.`id` AS `id` FROM ``ban_appeals``
            LEFT JOIN ``bans`` ON `ban_id` = ``bans``.`id`
            WHERE `denied` != 1 ORDER BY `time`") or error(db_error());
        $ban_appeals = $query->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($ban_appeals as &$ban) {
            if ($ban['post']) {
                $ban['post'] = json_decode($ban['post'], true);
            }
            $ban['mask'] = Bans::range_to_string([$ban['ipstart'], $ban['ipend']]);

            if ($ban['post'] && isset($ban['post']['board'], $ban['post']['id'])) {
                if (BoardService::openBoard($ban['post']['board'])) {
                    $query = query(sprintf("SELECT `thumb`, `file` FROM ``posts_%s`` WHERE `id` = " .
                        (int) $ban['post']['id'], $board['uri']));
                    if ($_post = $query->fetch(\PDO::FETCH_ASSOC)) {
                        $ban['post'] = array_merge($ban['post'], $_post);
                    } else {
                        $ban['post']['file'] = 'deleted';
                        $ban['post']['thumb'] = false;
                    }
                } else {
                    $ban['post']['file'] = 'deleted';
                    $ban['post']['thumb'] = false;
                }

                if ($ban['post']['thread']) {
                    $ban['post'] = new Post($ban['post']);
                } else {
                    $ban['post'] = new Thread($ban['post'], null, false, false);
                }
            }
        }

        mod_page(_('Ban appeals'), 'mod/ban_appeals.html', [
            'ban_appeals' => $ban_appeals,
            'token' => Token::make_secure_link_token('ban-appeals'),
        ]);
    }
}
