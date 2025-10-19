<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Security\Authenticator;
use Sudochan\Manager\{BanManager as Bans, PermissionManager};
use Sudochan\Entity\{Thread, Post};
use Sudochan\Service\BoardService;
use Sudochan\Utils\{TextFormatter, StringFormatter, Math, Token};

class BanController
{
    /**
     * Handle creating a new ban via mod form.
     *
     * @return void
     */
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

    /**
     * Display and process the ban list.
     *
     * @param int $page_no Page number.
     * @return void
     */
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
}
