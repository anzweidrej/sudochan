<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Repository\BanAppealsRepository;
use Sudochan\Security\Authenticator;
use Sudochan\Manager\{PermissionManager, BanManager as Bans};
use Sudochan\Entity\{Post, Thread};
use Sudochan\Service\BoardService;
use Sudochan\Utils\Token;

class BanAppealsController
{
    protected BanAppealsRepository $repository;

    public function __construct(BanAppealsRepository $repository)
    {
        $this->repository = $repository;
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
                Authenticator::modLog('Accepted ban appeal #' . $ban['id'] . ' for ' . $ban['mask']);
                Bans::delete($ban['ban_id'], true);
                $this->repository->deleteAppealById($ban['id']);
            } else {
                Authenticator::modLog('Denied ban appeal #' . $ban['id'] . ' for ' . $ban['mask']);
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
