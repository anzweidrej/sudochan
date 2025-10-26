<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Action;

use Sudochan\Manager\{BanManager as Bans};
use Sudochan\Resolver\DNSResolver;
use Sudochan\Service\{BoardService, MarkupService};
use Sudochan\Handler\ErrorHandler;
use Sudochan\Utils\Sanitize;

class ReportAction
{
    public function executeReport(): void
    {
        global $config, $board;

        if (!isset($_POST['board'], $_POST['password'], $_POST['reason'])) {
            error($config['error']['bot']);
        }

        $report = [];
        foreach ($_POST as $post => $value) {
            if (preg_match('/^delete_(\d+)$/', $post, $m)) {
                $report[] = (int) $m[1];
            }
        }

        DNSResolver::checkDNSBL();

        // Check if board exists
        if (!BoardService::openBoard($_POST['board'])) {
            error($config['error']['noboard']);
        }

        // Check if banned
        Bans::checkBan($board['uri']);

        if (empty($report)) {
            error($config['error']['noreport']);
        }

        if (count($report) > $config['report_limit']) {
            error($config['error']['toomanyreports']);
        }

        $reason = Sanitize::escape_markup_modifiers($_POST['reason']);
        MarkupService::markup($reason);

        foreach ($report as &$id) {
            $query = prepare(sprintf("SELECT `thread` FROM ``posts_%s`` WHERE `id` = :id", $board['uri']));
            $query->bindValue(':id', $id, \PDO::PARAM_INT);
            $query->execute() or error(db_error($query));

            $thread = $query->fetchColumn();

            if ($config['syslog']) {
                ErrorHandler::_syslog(
                    LOG_INFO,
                    'Reported post: '
                    . '/' . $board['dir'] . $config['dir']['res'] . sprintf($config['file_page'], $thread ? $thread : $id) . ($thread ? '#' . $id : '')
                    . ' for "' . $reason . '"',
                );
            }
            $query = prepare("INSERT INTO ``reports`` VALUES (NULL, :time, :ip, :board, :post, :reason)");
            $query->bindValue(':time', time(), \PDO::PARAM_INT);
            $query->bindValue(':ip', $_SERVER['REMOTE_ADDR'], \PDO::PARAM_STR);
            $query->bindValue(':board', $board['uri'], \PDO::PARAM_STR);
            $query->bindValue(':post', $id, \PDO::PARAM_INT);
            $query->bindValue(':reason', $reason, \PDO::PARAM_STR);
            $query->execute() or error(db_error($query));
        }

        $is_mod = isset($_POST['mod']) && $_POST['mod'];
        $root = $is_mod ? $config['root'] . $config['file_mod'] . '?/' : $config['root'];

        if (!isset($_POST['json_response'])) {
            header('Location: ' . $root . $board['dir'] . $config['file_index'], true, $config['redirect_http']);
        } else {
            header('Content-Type: text/json');
            echo json_encode(['success' => true]);
        }
    }
}
