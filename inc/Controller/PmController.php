<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Security\Authenticator;
use Sudochan\Manager\{CacheManager as Cache, PermissionManager};
use Sudochan\Service\MarkupService;
use Sudochan\Utils\{StringFormatter, TextFormatter, Token, Sanitize};
use Sudochan\Repository\PmRepository;

class PmController
{
    private PmRepository $repository;

    public function __construct(?PmRepository $repository = null)
    {
        $this->repository = $repository ?? new PmRepository();
    }

    /**
     * View a PM, optionally render a reply form, and handle deletion/read actions.
     *
     * @param int  $id    PM ID.
     * @param bool $reply If true, show reply form instead of viewing the PM.
     * @return void
     */
    public function mod_pm(int $id, bool $reply = false): void
    {
        global $mod, $config;

        if ($reply && !PermissionManager::hasPermission($config['mod']['create_pm'])) {
            error($config['error']['noaccess']);
        }

        $pm = $this->repository->getById($id);
        if ((!$pm) || ($pm['to'] != $mod['id'] && !PermissionManager::hasPermission($config['mod']['master_pm']))) {
            error($config['error']['404']);
        }

        if (isset($_POST['delete'])) {
            $this->repository->deleteById($id);

            if ($config['cache']['enabled']) {
                Cache::delete('pm_unread_' . $mod['id']);
                Cache::delete('pm_unreadcount_' . $mod['id']);
            }

            header('Location: ?/', true, $config['redirect_http']);
            return;
        }

        if ($pm['unread'] && $pm['to'] == $mod['id']) {
            $this->repository->markAsRead($id);

            if ($config['cache']['enabled']) {
                Cache::delete('pm_unread_' . $mod['id']);
                Cache::delete('pm_unreadcount_' . $mod['id']);
            }

            Authenticator::modLog('Read a PM');
        }

        if ($reply) {
            if (!$pm['to_username']) {
                error($config['error']['404']);
            } // deleted?

            mod_page(sprintf('%s %s', _('New PM for'), $pm['to_username']), 'mod/new_pm.html', [
                'username' => $pm['username'],
                'id' => $pm['sender'],
                'message' => TextFormatter::quote($pm['message']),
                'token' => Token::make_secure_link_token('new_PM/' . $pm['username']),
            ]);
        } else {
            mod_page(sprintf('%s &ndash; #%d', _('Private message'), $id), 'mod/pm.html', $pm);
        }
    }

    /**
     * Show the moderator's inbox with message snippets and unread count.
     *
     * @return void
     */
    public function mod_inbox(): void
    {
        global $config, $mod;

        $messages = $this->repository->getInboxForMod($mod['id']);
        $unread = $this->repository->countUnreadForMod($mod['id']);

        foreach ($messages as &$message) {
            $message['snippet'] = TextFormatter::pm_snippet($message['message']);
        }

        mod_page(sprintf('%s (%s)', _('PM inbox'), count($messages) > 0 ? $unread . ' unread' : 'empty'), 'mod/inbox.html', [
            'messages' => $messages,
            'unread' => $unread,
        ]);
    }


    /**
     * Create a new PM to another moderator and handle submission.
     *
     * @param string $username Recipient username.
     * @return void
     */
    public function mod_new_pm(string $username): void
    {
        global $config, $mod;

        if (!PermissionManager::hasPermission($config['mod']['create_pm'])) {
            error($config['error']['noaccess']);
        }

        $id = $this->repository->findModIdByUsername($username);
        if (!$id) {
            // Old style ?/PM: by user ID
            $username = $this->repository->findModUsernameById($username);
            if ($username) {
                header('Location: ?/new_PM/' . $username, true, $config['redirect_http']);
            } else {
                error($config['error']['404']);
            }
            return;
        }

        if (isset($_POST['message'])) {
            $_POST['message'] = Sanitize::escape_markup_modifiers($_POST['message']);
            MarkupService::markup($_POST['message']);

            $this->repository->insertPm($mod['id'], $id, $_POST['message'], time());

            if ($config['cache']['enabled']) {
                Cache::delete('pm_unread_' . $id);
                Cache::delete('pm_unreadcount_' . $id);
            }

            Authenticator::modLog('Sent a PM to ' . StringFormatter::utf8tohtml($username));

            header('Location: ?/', true, $config['redirect_http']);
        }

        mod_page(sprintf('%s %s', _('New PM for'), $username), 'mod/new_pm.html', [
            'username' => $username,
            'id' => $id,
            'token' => Token::make_secure_link_token('new_PM/' . $username),
        ]);
    }
}
