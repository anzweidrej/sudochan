<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Manager\AuthManager;
use Sudochan\Entity\Post;
use Sudochan\Entity\Thread;
use Sudochan\Service\BoardService;
use Sudochan\Manager\PermissionManager;
use Sudochan\Utils\TextFormatter;
use Sudochan\Utils\Token;
use Sudochan\Repository\ReportRepository;

class ReportController
{
    private ReportRepository $repository;

    public function __construct(?ReportRepository $repository = null)
    {
        $this->repository = $repository ?? new ReportRepository();
    }

    /**
     * Display the report queue.
     *
     * @return void
     */
    public function mod_reports(): void
    {
        global $config, $mod;

        if (!PermissionManager::hasPermission($config['mod']['reports'])) {
            error($config['error']['noaccess']);
        }

        $reports = $this->repository->getRecentReports($config['mod']['recent_reports']);

        $report_queries = [];
        foreach ($reports as $report) {
            if (!isset($report_queries[$report['board']])) {
                $report_queries[$report['board']] = [];
            }
            $report_queries[$report['board']][] = $report['post'];
        }

        $report_posts = [];
        foreach ($report_queries as $board => $posts) {
            $report_posts[$board] = $this->repository->getPostsForBoard($posts, $board);
        }

        $count = 0;
        $body = '';
        foreach ($reports as $report) {
            if (!isset($report_posts[$report['board']][$report['post']])) {
                // // Invalid report (post has since been deleted)
                $this->repository->deleteReportByPostAndBoard((int) $report['post'], $report['board']);
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
                'token' => Token::make_secure_link_token('reports/' . $report['id'] . '/dismiss'),
                'token_all' => Token::make_secure_link_token('reports/' . $report['id'] . '/dismissall'),
            ]);

            // Bug fix for https://github.com/savetheinternet/Tinyboard/issues/21
            $po->body = TextFormatter::truncate($po->body, $po->link(), $config['body_truncate'] - substr_count($append_html, '<br>'));

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

    /**
     * Dismiss a report or dismiss all reports from the same IP.
     *
     * @param int  $id  Report ID to dismiss.
     * @param bool $all True to dismiss all reports by the same IP.
     * @return void
     */
    public function mod_report_dismiss(int $id, bool $all = false): void
    {
        global $config;

        $report = $this->repository->getReportById($id);
        if ($report) {
            $ip = $report['ip'];
            $board = $report['board'];
            $post = $report['post'];
        } else {
            error($config['error']['404']);
        }

        if (!$all && !PermissionManager::hasPermission($config['mod']['report_dismiss'], $board)) {
            error($config['error']['noaccess']);
        }

        if ($all && !PermissionManager::hasPermission($config['mod']['report_dismiss_ip'], $board)) {
            error($config['error']['noaccess']);
        }

        if ($all) {
            $this->repository->deleteReportsByIp($ip);
        } else {
            $this->repository->deleteReportById($id);
        }

        if ($all) {
            AuthManager::modLog("Dismissed all reports by <a href=\"?/IP/$ip\">$ip</a>");
        } else {
            AuthManager::modLog("Dismissed a report for post #{$id}", $board);
        }

        header('Location: ?/reports', true, $config['redirect_http']);
    }
}
