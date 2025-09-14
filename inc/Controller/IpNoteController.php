<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Manager\AuthManager;
use Sudochan\Entity\Thread;
use Sudochan\Entity\Post;
use Sudochan\Bans;
use Sudochan\Service\BoardService;
use Sudochan\Service\MarkupService;
use Sudochan\Resolver\DNSResolver;
use Sudochan\Manager\PermissionManager;

class IpNoteController
{
    public function mod_ip_remove_note(string $ip, int $id): void
    {
        global $config, $mod;

        if (!PermissionManager::hasPermission($config['mod']['remove_notes'])) {
            error($config['error']['noaccess']);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            error("Invalid IP address.");
        }

        $query = prepare('DELETE FROM ``ip_notes`` WHERE `ip` = :ip AND `id` = :id');
        $query->bindValue(':ip', $ip);
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));

        AuthManager::modLog("Removed a note for <a href=\"?/IP/{$ip}\">{$ip}</a>");

        header('Location: ?/IP/' . $ip . '#notes', true, $config['redirect_http']);
    }

    public function mod_page_ip(string $ip): void
    {
        global $config, $mod;

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            error("Invalid IP address.");
        }

        if (isset($_POST['ban_id'], $_POST['unban'])) {
            if (!PermissionManager::hasPermission($config['mod']['unban'])) {
                error($config['error']['noaccess']);
            }

            Bans::delete($_POST['ban_id'], true);

            header('Location: ?/IP/' . $ip . '#bans', true, $config['redirect_http']);
            return;
        }

        if (isset($_POST['note'])) {
            if (!PermissionManager::hasPermission($config['mod']['create_notes'])) {
                error($config['error']['noaccess']);
            }

            $_POST['note'] = escape_markup_modifiers($_POST['note']);
            MarkupService::markup($_POST['note']);
            $query = prepare('INSERT INTO ``ip_notes`` VALUES (NULL, :ip, :mod, :time, :body)');
            $query->bindValue(':ip', $ip);
            $query->bindValue(':mod', $mod['id']);
            $query->bindValue(':time', time());
            $query->bindValue(':body', $_POST['note']);
            $query->execute() or error(db_error($query));

            AuthManager::modLog("Added a note for <a href=\"?/IP/{$ip}\">{$ip}</a>");

            header('Location: ?/IP/' . $ip . '#notes', true, $config['redirect_http']);
            return;
        }

        $args = [];
        $args['ip'] = $ip;
        $args['posts'] = [];

        if ($config['mod']['dns_lookup']) {
            $args['hostname'] = DNSResolver::rDNS($ip);
        }

        $boards = BoardService::listBoards();
        foreach ($boards as $board) {
            BoardService::openBoard($board['uri']);
            if (!PermissionManager::hasPermission($config['mod']['show_ip'], $board['uri'])) {
                continue;
            }
            $query = prepare(sprintf('SELECT * FROM ``posts_%s`` WHERE `ip` = :ip ORDER BY `sticky` DESC, `id` DESC LIMIT :limit', $board['uri']));
            $query->bindValue(':ip', $ip);
            $query->bindValue(':limit', $config['mod']['ip_recentposts'], \PDO::PARAM_INT);
            $query->execute() or error(db_error($query));

            while ($post = $query->fetch(\PDO::FETCH_ASSOC)) {
                if (!$post['thread']) {
                    $po = new Thread($post, '?/', $mod, false);
                } else {
                    $po = new Post($post, '?/', $mod);
                }

                if (!isset($args['posts'][$board['uri']])) {
                    $args['posts'][$board['uri']] = ['board' => $board, 'posts' => []];
                }
                $args['posts'][$board['uri']]['posts'][] = $po->build(true);
            }
        }

        $args['boards'] = $boards;
        $args['token'] = AuthManager::make_secure_link_token('ban');

        if (PermissionManager::hasPermission($config['mod']['view_ban'])) {
            $args['bans'] = Bans::find($ip, false, true);
        }

        if (PermissionManager::hasPermission($config['mod']['view_notes'])) {
            $query = prepare("SELECT ``ip_notes``.*, `username` FROM ``ip_notes`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE `ip` = :ip ORDER BY `time` DESC");
            $query->bindValue(':ip', $ip);
            $query->execute() or error(db_error($query));
            $args['notes'] = $query->fetchAll(\PDO::FETCH_ASSOC);
        }

        if (PermissionManager::hasPermission($config['mod']['modlog_ip'])) {
            $query = prepare("SELECT `username`, `mod`, `ip`, `board`, `time`, `text` FROM ``modlogs`` LEFT JOIN ``mods`` ON `mod` = ``mods``.`id` WHERE `text` LIKE :search ORDER BY `time` DESC LIMIT 50");
            $query->bindValue(':search', '%' . $ip . '%');
            $query->execute() or error(db_error($query));
            $args['logs'] = $query->fetchAll(\PDO::FETCH_ASSOC);
        } else {
            $args['logs'] = [];
        }

        $args['security_token'] = AuthManager::make_secure_link_token('IP/' . $ip);

        mod_page(sprintf('%s: %s', _('IP'), $ip), 'mod/view_ip.html', $args, $args['hostname']);
    }
}
