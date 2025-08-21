<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Mod\Auth;

class NewsController
{
    public function mod_news(int $page_no = 1): void
    {
        global $config, $pdo, $mod;

        if ($page_no < 1) {
            error($config['error']['404']);
        }

        if (isset($_POST['subject'], $_POST['body'])) {
            if (!hasPermission($config['mod']['news'])) {
                error($config['error']['noaccess']);
            }

            $_POST['body'] = escape_markup_modifiers($_POST['body']);
            markup($_POST['body']);

            $query = prepare('INSERT INTO ``news`` VALUES (NULL, :name, :time, :subject, :body)');
            $query->bindValue(':name', isset($_POST['name']) && hasPermission($config['mod']['news_custom']) ? $_POST['name'] : $mod['username']);
            $query->bindValue(':time', time());
            $query->bindValue(':subject', $_POST['subject']);
            $query->bindValue(':body', $_POST['body']);
            $query->execute() or error(db_error($query));

            Auth::modLog('Posted a news entry');

            rebuildThemes('news');

            header('Location: ?/news#' . $pdo->lastInsertId(), true, $config['redirect_http']);
        }

        $query = prepare("SELECT * FROM ``news`` ORDER BY `id` DESC LIMIT :offset, :limit");
        $query->bindValue(':limit', $config['mod']['news_page'], \PDO::PARAM_INT);
        $query->bindValue(':offset', ($page_no - 1) * $config['mod']['news_page'], \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        $news = $query->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($news) && $page_no > 1) {
            error($config['error']['404']);
        }

        foreach ($news as &$entry) {
            $entry['delete_token'] = Auth::make_secure_link_token('news/delete/' . $entry['id']);
        }

        $query = prepare("SELECT COUNT(*) FROM ``news``");
        $query->execute() or error(db_error($query));
        $count = $query->fetchColumn();

        mod_page(_('News'), 'mod/news.html', ['news' => $news, 'count' => $count, 'token' => Auth::make_secure_link_token('news')]);
    }

    public function mod_news_delete(int $id): void
    {
        global $config;

        if (!hasPermission($config['mod']['news_delete'])) {
            error($config['error']['noaccess']);
        }

        $query = prepare('DELETE FROM ``news`` WHERE `id` = :id');
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));

        Auth::modLog('Deleted a news entry');

        header('Location: ?/news', true, $config['redirect_http']);
    }
}
