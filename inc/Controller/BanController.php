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
use Sudochan\Repository\BanRepository;

class BanController
{
    private BanRepository $repository;

    public function __construct(?BanRepository $repository = null)
    {
        $this->repository = $repository ?? new BanRepository();
    }

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

    /**
     * Manage ban appeals.
     *
     * @return void
     */
    public function mod_ban_appeals(): void
    {
        global $config, $board;

        if (!PermissionManager::hasPermission($config['mod']['view_ban_appeals'])) {
            error($config['error']['noaccess']);
        }

        // Remove stale ban appeals
        $this->repository->removeStaleBanAppeals();

        if (isset($_POST['appeal_id']) && (isset($_POST['unban']) || isset($_POST['deny']))) {
            if (!PermissionManager::hasPermission($config['mod']['ban_appeals'])) {
                error($config['error']['noaccess']);
            }

            $ban = $this->repository->selectAppealById((int) $_POST['appeal_id']);
            if (!$ban) {
                error(_('Ban appeal not found!'));
            }

            $ban['mask'] = Bans::range_to_string([$ban['ipstart'], $ban['ipend']]);

            if (isset($_POST['unban'])) {
                AuthManager::modLog('Accepted ban appeal #' . $ban['id'] . ' for ' . $ban['mask']);
                Bans::delete($ban['ban_id'], true);
                $this->repository->deleteAppealById($ban['id']);
            } else {
                AuthManager::modLog('Denied ban appeal #' . $ban['id'] . ' for ' . $ban['mask']);
                $this->repository->denyAppealById($ban['id']);
            }

            header('Location: ?/ban-appeals', true, $config['redirect_http']);
            return;
        }

        $ban_appeals = $this->repository->selectActiveBanAppeals();
        foreach ($ban_appeals as &$ban) {
            if ($ban['post']) {
                $ban['post'] = json_decode($ban['post'], true);
            }
            $ban['mask'] = Bans::range_to_string([$ban['ipstart'], $ban['ipend']]);

            if ($ban['post'] && isset($ban['post']['board'], $ban['post']['id'])) {
                if (BoardService::openBoard($ban['post']['board'])) {
                    $_post = $this->repository->selectPostThumbFile($board['uri'], (int) $ban['post']['id']);
                    if ($_post) {
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
