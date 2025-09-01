<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Mod\Auth;
use Sudochan\Entity\Post;
use Sudochan\Entity\Thread;
use Sudochan\Service\BoardService;

class ReportController
{
    public function mod_reports(): void
    {
        global $config, $mod;

        if (!hasPermission($config['mod']['reports'])) {
            error($config['error']['noaccess']);
        }

        $query = prepare("SELECT * FROM ``reports`` ORDER BY `time` DESC LIMIT :limit");
        $query->bindValue(':limit', $config['mod']['recent_reports'], \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));
        $reports = $query->fetchAll(\PDO::FETCH_ASSOC);

        $report_queries = [];
        foreach ($reports as $report) {
            if (!isset($report_queries[$report['board']])) {
                $report_queries[$report['board']] = [];
            }
            $report_queries[$report['board']][] = $report['post'];
        }

        $report_posts = [];
        foreach ($report_queries as $board => $posts) {
            $report_posts[$board] = [];

            $query = query(sprintf('SELECT * FROM ``posts_%s`` WHERE `id` = ' . implode(' OR `id` = ', $posts), $board)) or error(db_error());
            while ($post = $query->fetch(\PDO::FETCH_ASSOC)) {
                $report_posts[$board][$post['id']] = $post;
            }
        }

        $count = 0;
        $body = '';
        foreach ($reports as $report) {
            if (!isset($report_posts[$report['board']][$report['post']])) {
                // // Invalid report (post has since been deleted)
                $query = prepare("DELETE FROM ``reports`` WHERE `post` = :id AND `board` = :board");
                $query->bindValue(':id', $report['post'], \PDO::PARAM_INT);
                $query->bindValue(':board', $report['board']);
                $query->execute() or error(db_error($query));
                continue;
            }

            BoardService::openBoard($report['board']);

            $post = &$report_posts[$report['board']][$report['post']];

            if (!$post['thread']) {
                // Still need to fix this:
                $po = new Thread($post, '?/', $mod, false);
            } else {
                $po = new Post($post, '?/', $mod);
            }

            // a little messy and inefficient
            $append_html = element('mod/report.html', [
                'report' => $report,
                'config' => $config,
                'mod' => $mod,
                'token' => Auth::make_secure_link_token('reports/' . $report['id'] . '/dismiss'),
                'token_all' => Auth::make_secure_link_token('reports/' . $report['id'] . '/dismissall'),
            ]);

            // Bug fix for https://github.com/savetheinternet/Tinyboard/issues/21
            $po->body = truncate($po->body, $po->link(), $config['body_truncate'] - substr_count($append_html, '<br>'));

            if (mb_strlen($po->body) + mb_strlen($append_html) > $config['body_truncate_char']) {
                // still too long; temporarily increase limit in the config
                $__old_body_truncate_char = $config['body_truncate_char'];
                $config['body_truncate_char'] = mb_strlen($po->body) + mb_strlen($append_html);
            }

            $po->body .= $append_html;

            $body .= $po->build(true) . '<hr>';

            if (isset($__old_body_truncate_char)) {
                $config['body_truncate_char'] = $__old_body_truncate_char;
            }

            $count++;
        }

        mod_page(sprintf('%s (%d)', _('Report queue'), $count), 'mod/reports.html', ['reports' => $body, 'count' => $count]);
    }

    public function mod_report_dismiss(int $id, bool $all = false): void
    {
        global $config;

        $query = prepare("SELECT `post`, `board`, `ip` FROM ``reports`` WHERE `id` = :id");
        $query->bindValue(':id', $id);
        $query->execute() or error(db_error($query));
        if ($report = $query->fetch(\PDO::FETCH_ASSOC)) {
            $ip = $report['ip'];
            $board = $report['board'];
            $post = $report['post'];
        } else {
            error($config['error']['404']);
        }

        if (!$all && !hasPermission($config['mod']['report_dismiss'], $board)) {
            error($config['error']['noaccess']);
        }

        if ($all && !hasPermission($config['mod']['report_dismiss_ip'], $board)) {
            error($config['error']['noaccess']);
        }

        if ($all) {
            $query = prepare("DELETE FROM ``reports`` WHERE `ip` = :ip");
            $query->bindValue(':ip', $ip);
        } else {
            $query = prepare("DELETE FROM ``reports`` WHERE `id` = :id");
            $query->bindValue(':id', $id);
        }
        $query->execute() or error(db_error($query));


        if ($all) {
            Auth::modLog("Dismissed all reports by <a href=\"?/IP/$ip\">$ip</a>");
        } else {
            Auth::modLog("Dismissed a report for post #{$id}", $board);
        }

        header('Location: ?/reports', true, $config['redirect_http']);
    }
}
