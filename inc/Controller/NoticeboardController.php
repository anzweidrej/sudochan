<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Mod\Auth;
use Sudochan\Cache;

class NoticeboardController
{
    public function mod_noticeboard(int $page_no = 1): void
    {
        global $config, $pdo, $mod;

        if ($page_no < 1) {
            error($config['error']['404']);
        }

        if (!hasPermission($config['mod']['noticeboard'])) {
            error($config['error']['noaccess']);
        }

        if (isset($_POST['subject'], $_POST['body'])) {
            if (!hasPermission($config['mod']['noticeboard_post'])) {
                error($config['error']['noaccess']);
            }

            $_POST['body'] = escape_markup_modifiers($_POST['body']);
            markup($_POST['body']);

            $query = prepare('INSERT INTO ``noticeboard`` VALUES (NULL, :mod, :time, :subject, :body)');
            $query->bindValue(':mod', $mod['id']);
            $query->bindValue(':time', time());
            $query->bindValue(':subject', $_POST['subject']);
            $query->bindValue(':body', $_POST['body']);
            $query->execute() or error(db_error($query));

            if ($config['cache']['enabled']) {
                Cache::delete('noticeboard_preview');
            }

            Auth::modLog('Posted a noticeboard entry');

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
            $entry['delete_token'] = Auth::make_secure_link_token('noticeboard/delete/' . $entry['id']);
        }

        $query = prepare("SELECT COUNT(*) FROM ``noticeboard``");
        $query->execute() or error(db_error($query));
        $count = $query->fetchColumn();

        mod_page(_('Noticeboard'), 'mod/noticeboard.html', [
            'noticeboard' => $noticeboard,
            'count' => $count,
            'token' => Auth::make_secure_link_token('noticeboard'),
        ]);
    }

    public function mod_noticeboard_delete(int $id): void
    {
        global $config;

        if (!hasPermission($config['mod']['noticeboard_delete'])) {
            error($config['error']['noaccess']);
        }

        $query = prepare('DELETE FROM ``noticeboard`` WHERE `id` = :id');
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));

        Auth::modLog('Deleted a noticeboard entry');

        if ($config['cache']['enabled']) {
            Cache::delete('noticeboard_preview');
        }

        header('Location: ?/noticeboard', true, $config['redirect_http']);
    }
}
