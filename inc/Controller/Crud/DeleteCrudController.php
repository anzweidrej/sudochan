<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller\Crud;

use Sudochan\Manager\{BanManager as Bans, ThemeManager};
use Sudochan\Resolver\DNSResolver;
use Sudochan\Service\{BoardService, PageService, PostService};
use Sudochan\Utils\DateRange;
use Sudochan\Handler\ErrorHandler;

class DeleteCrudController
{
    public function executeDelete(): void
    {
        global $config, $board;

        // Delete
        if (!isset($_POST['board'], $_POST['password'])) {
            error($config['error']['bot']);
        }

        $password = &$_POST['password'];

        if ($password == '') {
            error($config['error']['invalidpassword']);
        }

        $delete = [];
        foreach ($_POST as $post => $value) {
            if (preg_match('/^delete_(\d+)$/', $post, $m)) {
                $delete[] = (int) $m[1];
            }
        }

        DNSResolver::checkDNSBL();

        // Check if board exists
        if (!BoardService::openBoard($_POST['board'])) {
            error($config['error']['noboard']);
        }

        // Check if banned
        Bans::checkBan($board['uri']);

        if (empty($delete)) {
            error($config['error']['nodelete']);
        }

        foreach ($delete as &$id) {
            $query = prepare(sprintf("SELECT `thread`, `time`,`password` FROM ``posts_%s`` WHERE `id` = :id", $board['uri']));
            $query->bindValue(':id', $id, \PDO::PARAM_INT);
            $query->execute() or error(db_error($query));

            if ($post = $query->fetch(\PDO::FETCH_ASSOC)) {
                if ($password != '' && $post['password'] != $password) {
                    error($config['error']['invalidpassword']);
                }

                if ($post['time'] > time() - $config['delete_time']) {
                    error(sprintf($config['error']['delete_too_soon'], DateRange::until($post['time'] + $config['delete_time'])));
                }

                if (isset($_POST['file'])) {
                    // Delete just the file
                    PostService::deleteFile($id);
                } else {
                    // Delete entire post
                    PostService::deletePost($id);
                }

                ErrorHandler::_syslog(
                    LOG_INFO,
                    'Deleted post: '
                    . '/' . $board['dir'] . $config['dir']['res'] . sprintf($config['file_page'], $post['thread'] ? $post['thread'] : $id) . ($post['thread'] ? '#' . $id : ''),
                );
            }
        }

        PageService::buildIndex();

        ThemeManager::rebuildThemes('post-delete', $board['uri']);

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
