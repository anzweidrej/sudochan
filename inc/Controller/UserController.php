<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Mod\Auth;
use Sudochan\Service\BoardService;

class UserController
{
    public function mod_user(int $uid): void
    {
        global $config, $mod;

        if (!hasPermission($config['mod']['editusers']) && !(hasPermission($config['mod']['change_password']) && $uid == $mod['id'])) {
            error($config['error']['noaccess']);
        }

        $query = prepare('SELECT * FROM ``mods`` WHERE `id` = :id');
        $query->bindValue(':id', $uid);
        $query->execute() or error(db_error($query));
        if (!$user = $query->fetch(\PDO::FETCH_ASSOC)) {
            error($config['error']['404']);
        }

        if (hasPermission($config['mod']['editusers']) && isset($_POST['username'], $_POST['password'])) {
            if (isset($_POST['allboards'])) {
                $boards = ['*'];
            } else {
                $_boards = BoardService::listBoards();
                foreach ($_boards as &$board) {
                    $board = $board['uri'];
                }

                $boards = [];
                foreach ($_POST as $name => $value) {
                    if (preg_match('/^board_(' . $config['board_regex'] . ')$/u', $name, $matches) && in_array($matches[1], $_boards)) {
                        $boards[] = $matches[1];
                    }
                }
            }

            if (isset($_POST['delete'])) {
                if (!hasPermission($config['mod']['deleteusers'])) {
                    error($config['error']['noaccess']);
                }

                $query = prepare('DELETE FROM ``mods`` WHERE `id` = :id');
                $query->bindValue(':id', $uid);
                $query->execute() or error(db_error($query));

                Auth::modLog('Deleted user ' . utf8tohtml($user['username']) . ' <small>(#' . $user['id'] . ')</small>');

                header('Location: ?/users', true, $config['redirect_http']);

                return;
            }

            if ($_POST['username'] == '') {
                error(sprintf($config['error']['required'], 'username'));
            }

            $query = prepare('UPDATE ``mods`` SET `username` = :username, `boards` = :boards WHERE `id` = :id');
            $query->bindValue(':id', $uid);
            $query->bindValue(':username', $_POST['username']);
            $query->bindValue(':boards', implode(',', $boards));
            $query->execute() or error(db_error($query));

            if ($user['username'] !== $_POST['username']) {
                // account was renamed
                Auth::modLog('Renamed user "' . utf8tohtml($user['username']) . '" <small>(#' . $user['id'] . ')</small> to "' . utf8tohtml($_POST['username']) . '"');
            }

            if ($_POST['password'] != '') {
                $salt = Auth::generate_salt();
                $password = hash('sha256', $salt . sha1($_POST['password']));

                $query = prepare('UPDATE ``mods`` SET `password` = :password, `salt` = :salt WHERE `id` = :id');
                $query->bindValue(':id', $uid);
                $query->bindValue(':password', $password);
                $query->bindValue(':salt', $salt);
                $query->execute() or error(db_error($query));

                Auth::modLog('Changed password for ' . utf8tohtml($_POST['username']) . ' <small>(#' . $user['id'] . ')</small>');

                if ($uid == $mod['id']) {
                    Auth::login($_POST['username'], $_POST['password']);
                    Auth::setCookies();
                }
            }

            if (hasPermission($config['mod']['manageusers'])) {
                header('Location: ?/users', true, $config['redirect_http']);
            } else {
                header('Location: ?/', true, $config['redirect_http']);
            }

            return;
        }

        if (hasPermission($config['mod']['change_password']) && $uid == $mod['id'] && isset($_POST['password'])) {
            if ($_POST['password'] != '') {
                $salt = Auth::generate_salt();
                $password = hash('sha256', $salt . sha1($_POST['password']));

                $query = prepare('UPDATE ``mods`` SET `password` = :password, `salt` = :salt WHERE `id` = :id');
                $query->bindValue(':id', $uid);
                $query->bindValue(':password', $password);
                $query->bindValue(':salt', $salt);
                $query->execute() or error(db_error($query));

                Auth::modLog('Changed own password');

                Auth::login($user['username'], $_POST['password']);
                Auth::setCookies();
            }

            if (hasPermission($config['mod']['manageusers'])) {
                header('Location: ?/users', true, $config['redirect_http']);
            } else {
                header('Location: ?/', true, $config['redirect_http']);
            }

            return;
        }

        if (hasPermission($config['mod']['modlog'])) {
            $query = prepare('SELECT * FROM ``modlogs`` WHERE `mod` = :id ORDER BY `time` DESC LIMIT 5');
            $query->bindValue(':id', $uid);
            $query->execute() or error(db_error($query));
            $log = $query->fetchAll(\PDO::FETCH_ASSOC);
        } else {
            $log = [];
        }

        $user['boards'] = explode(',', $user['boards']);

        mod_page(_('Edit user'), 'mod/user.html', [
            'user' => $user,
            'logs' => $log,
            'boards' => BoardService::listBoards(),
            'token' => Auth::make_secure_link_token('users/' . $user['id']),
        ]);
    }

    public function mod_user_new(): void
    {
        global $pdo, $config;

        if (!hasPermission($config['mod']['createusers'])) {
            error($config['error']['noaccess']);
        }

        if (isset($_POST['username'], $_POST['password'], $_POST['type'])) {
            if ($_POST['username'] == '') {
                error(sprintf($config['error']['required'], 'username'));
            }
            if ($_POST['password'] == '') {
                error(sprintf($config['error']['required'], 'password'));
            }

            if (isset($_POST['allboards'])) {
                $boards = ['*'];
            } else {
                $_boards = BoardService::listBoards();
                foreach ($_boards as &$board) {
                    $board = $board['uri'];
                }

                $boards = [];
                foreach ($_POST as $name => $value) {
                    if (preg_match('/^board_(' . $config['board_regex'] . ')$/u', $name, $matches) && in_array($matches[1], $_boards)) {
                        $boards[] = $matches[1];
                    }
                }
            }

            $type = (int) $_POST['type'];
            if (!isset($config['mod']['groups'][$type]) || $type == DISABLED) {
                error(sprintf($config['error']['invalidfield'], 'type'));
            }

            $salt = Auth::generate_salt();
            $password = hash('sha256', $salt . sha1($_POST['password']));

            $query = prepare('INSERT INTO ``mods`` VALUES (NULL, :username, :password, :salt, :type, :boards)');
            $query->bindValue(':username', $_POST['username']);
            $query->bindValue(':password', $password);
            $query->bindValue(':salt', $salt);
            $query->bindValue(':type', $type);
            $query->bindValue(':boards', implode(',', $boards));
            $query->execute() or error(db_error($query));

            $userID = $pdo->lastInsertId();

            Auth::modLog('Created a new user: ' . utf8tohtml($_POST['username']) . ' <small>(#' . $userID . ')</small>');

            header('Location: ?/users', true, $config['redirect_http']);
            return;
        }

        mod_page(_('New user'), 'mod/user.html', ['new' => true, 'boards' => BoardService::listBoards(), 'token' => Auth::make_secure_link_token('users/new')]);
    }

    public function mod_users(): void
    {
        global $config;

        if (!hasPermission($config['mod']['manageusers'])) {
            error($config['error']['noaccess']);
        }

        $query = query("SELECT
            *,
            (SELECT `time` FROM ``modlogs`` WHERE `mod` = `id` ORDER BY `time` DESC LIMIT 1) AS `last`,
            (SELECT `text` FROM ``modlogs`` WHERE `mod` = `id` ORDER BY `time` DESC LIMIT 1) AS `action`
            FROM ``mods`` ORDER BY `type` DESC,`id`") or error(db_error());
        $users = $query->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($users as &$user) {
            $user['promote_token'] = Auth::make_secure_link_token("users/{$user['id']}/promote");
            $user['demote_token'] = Auth::make_secure_link_token("users/{$user['id']}/demote");
        }

        mod_page(sprintf('%s (%d)', _('Manage users'), count($users)), 'mod/users.html', ['users' => $users]);
    }

    public function mod_user_promote(int $uid, string $action): void
    {
        global $config;

        if (!hasPermission($config['mod']['promoteusers'])) {
            error($config['error']['noaccess']);
        }

        $query = prepare("SELECT `type`, `username` FROM ``mods`` WHERE `id` = :id");
        $query->bindValue(':id', $uid);
        $query->execute() or error(db_error($query));

        if (!$mod = $query->fetch(\PDO::FETCH_ASSOC)) {
            error($config['error']['404']);
        }

        $new_group = false;

        $groups = $config['mod']['groups'];
        if ($action == 'demote') {
            $groups = array_reverse($groups, true);
        }

        foreach ($groups as $group_value => $group_name) {
            if ($action == 'promote' && $group_value > $mod['type']) {
                $new_group = $group_value;
                break;
            } elseif ($action == 'demote' && $group_value < $mod['type']) {
                $new_group = $group_value;
                break;
            }
        }

        if ($new_group === false || $new_group == DISABLED) {
            error(_('Impossible to promote/demote user.'));
        }

        $query = prepare("UPDATE ``mods`` SET `type` = :group_value WHERE `id` = :id");
        $query->bindValue(':id', $uid);
        $query->bindValue(':group_value', $new_group);
        $query->execute() or error(db_error($query));

        Auth::modLog(($action == 'promote' ? 'Promoted' : 'Demoted') . ' user "' .
            utf8tohtml($mod['username']) . '" to ' . $config['mod']['groups'][$new_group]);

        header('Location: ?/users', true, $config['redirect_http']);
    }

}
