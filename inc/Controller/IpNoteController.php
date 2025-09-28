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
use Sudochan\Utils\TextFormatter;
use Sudochan\Utils\Token;
use Sudochan\Utils\Sanitize;
use Sudochan\Repository\IpNoteRepository;

class IpNoteController
{
    private IpNoteRepository $repository;

    public function __construct(IpNoteRepository $repository = null)
    {
        $this->repository = $repository ?: new IpNoteRepository();
    }

    /**
     * Remove a moderator note for a given IP.
     *
     * @param string $ip  IP address.
     * @param int    $id  Note ID to remove.
     * @return void
     */
    public function mod_ip_remove_note(string $ip, int $id): void
    {
        global $config, $mod;

        if (!PermissionManager::hasPermission($config['mod']['remove_notes'])) {
            error($config['error']['noaccess']);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            error("Invalid IP address.");
        }

        $this->repository->removeNote($ip, $id);

        AuthManager::modLog("Removed a note for <a href=\"?/IP/{$ip}\">{$ip}</a>");

        header('Location: ?/IP/' . $ip . '#notes', true, $config['redirect_http']);
    }

    /**
     * Display the IP page for moderators and handle note/ban actions.
     *
     * @param string $ip IP address to view.
     * @return void
     */
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

            $_POST['note'] = Sanitize::escape_markup_modifiers($_POST['note']);
            MarkupService::markup($_POST['note']);

            $this->repository->insertNote($ip, $mod['id'], $_POST['note']);

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

            $posts = $this->repository->getPostsQuery($board, $ip);

            foreach ($posts as $post) {
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
        $args['token'] = Token::make_secure_link_token('ban');

        if (PermissionManager::hasPermission($config['mod']['view_ban'])) {
            $args['bans'] = Bans::find($ip, false, true);
        }

        if (PermissionManager::hasPermission($config['mod']['view_notes'])) {
            $args['notes'] = $this->repository->getIpNotesQuery($ip);
        }

        if (PermissionManager::hasPermission($config['mod']['modlog_ip'])) {
            $args['logs'] = $this->repository->getModLogsByIpQuery($ip);
        } else {
            $args['logs'] = [];
        }

        $args['security_token'] = Token::make_secure_link_token('IP/' . $ip);

        mod_page(sprintf('%s: %s', _('IP'), $ip), 'mod/view_ip.html', $args, $args['hostname']);
    }
}
