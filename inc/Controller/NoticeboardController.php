<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Manager\AuthManager;
use Sudochan\Cache;
use Sudochan\Manager\PermissionManager;
use Sudochan\Service\MarkupService;
use Sudochan\Utils\Token;
use Sudochan\Utils\TextFormatter;
use Sudochan\Utils\Sanitize;

class NoticeboardController
{
    public function mod_noticeboard(int $page_no = 1): void
    {
        global $config, $pdo, $mod;

        if ($page_no < 1) {
            error($config['error']['404']);
        }

        if (!PermissionManager::hasPermission($config['mod']['noticeboard'])) {
            error($config['error']['noaccess']);
        }

        if (isset($_POST['subject'], $_POST['body'])) {
            if (!PermissionManager::hasPermission($config['mod']['noticeboard_post'])) {
                error($config['error']['noaccess']);
            }

            $_POST['body'] = Sanitize::escape_markup_modifiers($_POST['body']);
            MarkupService::markup($_POST['body']);

            $query = prepare('INSERT INTO ``noticeboard`` VALUES (NULL, :mod, :time, :subject, :body)');
            $query->bindValue(':mod', $mod['id']);
            $query->bindValue(':time', time());
            $query->bindValue(':subject', $_POST['subject']);
            $query->bindValue(':body', $_POST['body']);
            $query->execute() or error(db_error($query));

            if ($config['cache']['enabled']) {
                Cache::delete('noticeboard_preview');
            }

            AuthManager::modLog('Posted a noticeboard entry');

            header('Location: ?/noticeboard#' . $pdo->lastInsertId(), true, $config['redirect_http']);
        }

        $query = prepare("SELECT ``noticeboard``.*, `username` FROM ``noticeboard`` LEFT JOIN ``mods`` ON ``mods``.`id` = `mod` ORDER BY `id` DESC LIMIT :offset, :limit");
        $query->bindValue(':limit', $config['mod']['noticeboard_page'], \PDO::PARAM_INT);
        $query->bindValue(':offset', ($page_no - 1) * $config['mod']['noticeboard_page'], \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        $noticeboard = $query->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($noticeboard) && $page_no > 1) {
            error($config['error']['404']);
        }

        foreach ($noticeboard as &$entry) {
            $entry['delete_token'] = Token::make_secure_link_token('noticeboard/delete/' . $entry['id']);
        }

        $query = prepare("SELECT COUNT(*) FROM ``noticeboard``");
        $query->execute() or error(db_error($query));
        $count = $query->fetchColumn();

        mod_page(_('Noticeboard'), 'mod/noticeboard.html', [
            'noticeboard' => $noticeboard,
            'count' => $count,
            'token' => Token::make_secure_link_token('noticeboard'),
        ]);
    }

    public function mod_noticeboard_delete(int $id): void
    {
        global $config;

        if (!PermissionManager::hasPermission($config['mod']['noticeboard_delete'])) {
            error($config['error']['noaccess']);
        }

        $query = prepare('DELETE FROM ``noticeboard`` WHERE `id` = :id');
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));

        AuthManager::modLog('Deleted a noticeboard entry');

        if ($config['cache']['enabled']) {
            Cache::delete('noticeboard_preview');
        }

        header('Location: ?/noticeboard', true, $config['redirect_http']);
    }
}
