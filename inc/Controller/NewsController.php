<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Security\Authenticator;
use Sudochan\Manager\{ThemeManager, PermissionManager};
use Sudochan\Service\MarkupService;
use Sudochan\Utils\{Token, TextFormatter, Sanitize};
use Sudochan\Repository\NewsRepository;

class NewsController
{
    private NewsRepository $repository;

    public function __construct(?NewsRepository $repository = null)
    {
        $this->repository = $repository ?? new NewsRepository();
    }

    /**
     * Display and handle the moderator news page.
     *
     * @param int $page_no Page number.
     * @return void
     */
    public function mod_news(int $page_no = 1): void
    {
        global $config, $mod;

        if ($page_no < 1) {
            error($config['error']['404']);
        }

        if (isset($_POST['subject'], $_POST['body'])) {
            if (!PermissionManager::hasPermission($config['mod']['news'])) {
                error($config['error']['noaccess']);
            }

            $_POST['body'] = Sanitize::escape_markup_modifiers($_POST['body']);
            MarkupService::markup($_POST['body']);

            $name = isset($_POST['name']) && PermissionManager::hasPermission($config['mod']['news_custom'])
                ? $_POST['name']
                : $mod['username'];

            $id = $this->repository->insert($name, time(), $_POST['subject'], $_POST['body']);

            Authenticator::modLog('Posted a news entry');

            ThemeManager::rebuildThemes('news');

            header('Location: ?/news#' . $id, true, $config['redirect_http']);
        }

        $offset = ($page_no - 1) * $config['mod']['news_page'];
        $news = $this->repository->fetchPage($offset, $config['mod']['news_page']);

        if (empty($news) && $page_no > 1) {
            error($config['error']['404']);
        }

        foreach ($news as &$entry) {
            $entry['delete_token'] = Token::make_secure_link_token('news/delete/' . $entry['id']);
        }

        $count = $this->repository->count();

        mod_page(_('News'), 'mod/news.html', ['news' => $news, 'count' => $count, 'token' => Token::make_secure_link_token('news')]);
    }

    /**
     * Delete a news entry.
     *
     * @param int $id News entry ID to delete.
     * @return void
     */
    public function mod_news_delete(int $id): void
    {
        global $config;

        if (!PermissionManager::hasPermission($config['mod']['news_delete'])) {
            error($config['error']['noaccess']);
        }

        $this->repository->deleteById($id);

        Authenticator::modLog('Deleted a news entry');

        header('Location: ?/news', true, $config['redirect_http']);
    }
}
