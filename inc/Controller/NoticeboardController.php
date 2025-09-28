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
use Sudochan\Repository\NoticeboardRepository;

class NoticeboardController
{
    private NoticeboardRepository $repository;

    public function __construct(?NoticeboardRepository $repository = null)
    {
        $this->repository = $repository ?? new NoticeboardRepository();
    }

    /**
     * Display and handle the moderator noticeboard page.
     *
     * @param int $page_no Page number.
     * @return void
     */
    public function mod_noticeboard(int $page_no = 1): void
    {
        global $config, $mod;

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

            $id = $this->repository->insertNotice($mod['id'], time(), $_POST['subject'], $_POST['body']);

            if ($config['cache']['enabled']) {
                Cache::delete('noticeboard_preview');
            }

            AuthManager::modLog('Posted a noticeboard entry');

            header('Location: ?/noticeboard#' . $id, true, $config['redirect_http']);
        }

        $offset = ($page_no - 1) * $config['mod']['noticeboard_page'];
        $noticeboard = $this->repository->fetchNoticeboard($offset, $config['mod']['noticeboard_page']);

        if (empty($noticeboard) && $page_no > 1) {
            error($config['error']['404']);
        }

        foreach ($noticeboard as &$entry) {
            $entry['delete_token'] = Token::make_secure_link_token('noticeboard/delete/' . $entry['id']);
        }

        $count = $this->repository->countNoticeboard();

        mod_page(_('Noticeboard'), 'mod/noticeboard.html', [
            'noticeboard' => $noticeboard,
            'count' => $count,
            'token' => Token::make_secure_link_token('noticeboard'),
        ]);
    }

    /**
     * Delete a noticeboard entry.
     *
     * @param int $id Notice ID to delete.
     * @return void
     */
    public function mod_noticeboard_delete(int $id): void
    {
        global $config;

        if (!PermissionManager::hasPermission($config['mod']['noticeboard_delete'])) {
            error($config['error']['noaccess']);
        }

        $this->repository->deleteNotice($id);

        AuthManager::modLog('Deleted a noticeboard entry');

        if ($config['cache']['enabled']) {
            Cache::delete('noticeboard_preview');
        }

        header('Location: ?/noticeboard', true, $config['redirect_http']);
    }
}
