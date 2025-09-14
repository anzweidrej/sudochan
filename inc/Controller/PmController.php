<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Manager\AuthManager;
use Sudochan\Cache;
use Sudochan\Manager\PermissionManager;
use Sudochan\Service\MarkupService;

class PmController
{
    public function mod_pm(int $id, bool $reply = false): void
    {
        global $mod, $config;

        if ($reply && !PermissionManager::hasPermission($config['mod']['create_pm'])) {
            error($config['error']['noaccess']);
        }

        $query = prepare("SELECT ``mods``.`username`, `mods_to`.`username` AS `to_username`, ``pms``.* FROM ``pms`` LEFT JOIN ``mods`` ON ``mods``.`id` = `sender` LEFT JOIN ``mods`` AS `mods_to` ON `mods_to`.`id` = `to` WHERE ``pms``.`id` = :id");
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));

        if ((!$pm = $query->fetch(\PDO::FETCH_ASSOC)) || ($pm['to'] != $mod['id'] && !PermissionManager::hasPermission($config['mod']['master_pm']))) {
            error($config['error']['404']);
        }

        if (isset($_POST['delete'])) {
            $query = prepare("DELETE FROM ``pms`` WHERE `id` = :id");
            $query->bindValue(':id', $id);
            $query->execute() or error(db_error($query));

            if ($config['cache']['enabled']) {
                Cache::delete('pm_unread_' . $mod['id']);
                Cache::delete('pm_unreadcount_' . $mod['id']);
            }

            header('Location: ?/', true, $config['redirect_http']);
            return;
        }

        if ($pm['unread'] && $pm['to'] == $mod['id']) {
            $query = prepare("UPDATE ``pms`` SET `unread` = 0 WHERE `id` = :id");
            $query->bindValue(':id', $id);
            $query->execute() or error(db_error($query));

            if ($config['cache']['enabled']) {
                Cache::delete('pm_unread_' . $mod['id']);
                Cache::delete('pm_unreadcount_' . $mod['id']);
            }

            AuthManager::modLog('Read a PM');
        }

        if ($reply) {
            if (!$pm['to_username']) {
                error($config['error']['404']);
            } // deleted?

            mod_page(sprintf('%s %s', _('New PM for'), $pm['to_username']), 'mod/new_pm.html', [
                'username' => $pm['username'],
                'id' => $pm['sender'],
                'message' => quote($pm['message']),
                'token' => AuthManager::make_secure_link_token('new_PM/' . $pm['username']),
            ]);
        } else {
            mod_page(sprintf('%s &ndash; #%d', _('Private message'), $id), 'mod/pm.html', $pm);
        }
    }

    public function mod_inbox(): void
    {
        global $config, $mod;

        $query = prepare('SELECT `unread`,``pms``.`id`, `time`, `sender`, `to`, `message`, `username` FROM ``pms`` LEFT JOIN ``mods`` ON ``mods``.`id` = `sender` WHERE `to` = :mod ORDER BY `unread` DESC, `time` DESC');
        $query->bindValue(':mod', $mod['id']);
        $query->execute() or error(db_error($query));
        $messages = $query->fetchAll(\PDO::FETCH_ASSOC);

        $query = prepare('SELECT COUNT(*) FROM ``pms`` WHERE `to` = :mod AND `unread` = 1');
        $query->bindValue(':mod', $mod['id']);
        $query->execute() or error(db_error($query));
        $unread = $query->fetchColumn();

        foreach ($messages as &$message) {
            $message['snippet'] = pm_snippet($message['message']);
        }

        mod_page(sprintf('%s (%s)', _('PM inbox'), count($messages) > 0 ? $unread . ' unread' : 'empty'), 'mod/inbox.html', [
            'messages' => $messages,
            'unread' => $unread,
        ]);
    }


    public function mod_new_pm(string $username): void
    {
        global $config, $mod;

        if (!PermissionManager::hasPermission($config['mod']['create_pm'])) {
            error($config['error']['noaccess']);
        }

        $query = prepare("SELECT `id` FROM ``mods`` WHERE `username` = :username");
        $query->bindValue(':username', $username);
        $query->execute() or error(db_error($query));
        if (!$id = $query->fetchColumn()) {
            // Old style ?/PM: by user ID
            $query = prepare("SELECT `username` FROM ``mods`` WHERE `id` = :username");
            $query->bindValue(':username', $username);
            $query->execute() or error(db_error($query));
            if ($username = $query->fetchColumn()) {
                header('Location: ?/new_PM/' . $username, true, $config['redirect_http']);
            } else {
                error($config['error']['404']);
            }
        }

        if (isset($_POST['message'])) {
            $_POST['message'] = escape_markup_modifiers($_POST['message']);
            MarkupService::markup($_POST['message']);

            $query = prepare("INSERT INTO ``pms`` VALUES (NULL, :me, :id, :message, :time, 1)");
            $query->bindValue(':me', $mod['id']);
            $query->bindValue(':id', $id);
            $query->bindValue(':message', $_POST['message']);
            $query->bindValue(':time', time());
            $query->execute() or error(db_error($query));

            if ($config['cache']['enabled']) {
                Cache::delete('pm_unread_' . $id);
                Cache::delete('pm_unreadcount_' . $id);
            }

            AuthManager::modLog('Sent a PM to ' . utf8tohtml($username));

            header('Location: ?/', true, $config['redirect_http']);
        }

        mod_page(sprintf('%s %s', _('New PM for'), $username), 'mod/new_pm.html', [
            'username' => $username,
            'id' => $id,
            'token' => AuthManager::make_secure_link_token('new_PM/' . $username),
        ]);
    }
}
