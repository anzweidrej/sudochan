<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Security\Authenticator;
use Sudochan\Service\BoardService;
use Sudochan\Manager\PermissionManager;
use Sudochan\Utils\{StringFormatter, Token};
use Sudochan\Repository\UserRepository;

class UserController
{
    private UserRepository $repository;

    public function __construct(?UserRepository $repository = null)
    {
        $this->repository = $repository ?? new UserRepository();
    }

    /**
     * Edit a moderator account.
     *
     * @param int $uid User ID to edit.
     * @return void
     */
    public function mod_user(int $uid): void
    {
        global $config, $mod;

        if (!PermissionManager::hasPermission($config['mod']['editusers']) && !(PermissionManager::hasPermission($config['mod']['change_password']) && $uid == $mod['id'])) {
            error($config['error']['noaccess']);
        }

        $user = $this->repository->getById($uid);
        if (!$user) {
            error($config['error']['404']);
        }

        if (PermissionManager::hasPermission($config['mod']['editusers']) && isset($_POST['username'], $_POST['password'])) {
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
                if (!PermissionManager::hasPermission($config['mod']['deleteusers'])) {
                    error($config['error']['noaccess']);
                }

                $this->repository->deleteById($uid);

                Authenticator::modLog('Deleted user ' . StringFormatter::utf8tohtml($user['username']) . ' <small>(#' . $user['id'] . ')</small>');

                header('Location: ?/users', true, $config['redirect_http']);

                return;
            }

            if ($_POST['username'] == '') {
                error(sprintf($config['error']['required'], 'username'));
            }

            $this->repository->updateUsernameBoards($uid, $_POST['username'], implode(',', $boards));

            if ($user['username'] !== $_POST['username']) {
                // account was renamed
                Authenticator::modLog('Renamed user "' . StringFormatter::utf8tohtml($user['username']) . '" <small>(#' . $user['id'] . ')</small> to "' . StringFormatter::utf8tohtml($_POST['username']) . '"');
            }

            if ($_POST['password'] != '') {
                $salt = Authenticator::generate_salt();
                $password = hash('sha256', $salt . sha1($_POST['password']));

                $this->repository->updatePasswordAndSalt($uid, $password, $salt);

                Authenticator::modLog('Changed password for ' . StringFormatter::utf8tohtml($_POST['username']) . ' <small>(#' . $user['id'] . ')</small>');

                if ($uid == $mod['id']) {
                    Authenticator::login($_POST['username'], $_POST['password']);
                    Authenticator::setCookies();
                }
            }

            if (PermissionManager::hasPermission($config['mod']['manageusers'])) {
                header('Location: ?/users', true, $config['redirect_http']);
            } else {
                header('Location: ?/', true, $config['redirect_http']);
            }

            return;
        }

        if (PermissionManager::hasPermission($config['mod']['change_password']) && $uid == $mod['id'] && isset($_POST['password'])) {
            if ($_POST['password'] != '') {
                $salt = Authenticator::generate_salt();
                $password = hash('sha256', $salt . sha1($_POST['password']));

                $this->repository->updatePasswordAndSalt($uid, $password, $salt);

                Authenticator::modLog('Changed own password');

                Authenticator::login($user['username'], $_POST['password']);
                Authenticator::setCookies();
            }

            if (PermissionManager::hasPermission($config['mod']['manageusers'])) {
                header('Location: ?/users', true, $config['redirect_http']);
            } else {
                header('Location: ?/', true, $config['redirect_http']);
            }

            return;
        }

        if (PermissionManager::hasPermission($config['mod']['modlog'])) {
            $log = $this->repository->getModLogs($uid, 5);
        } else {
            $log = [];
        }

        $user['boards'] = explode(',', $user['boards']);

        mod_page(_('Edit user'), 'mod/user.html', [
            'user' => $user,
            'logs' => $log,
            'boards' => BoardService::listBoards(),
            'token' => Token::make_secure_link_token('users/' . $user['id']),
        ]);
    }

    /**
     * Create a new moderator account.
     *
     * @return void
     */
    public function mod_user_new(): void
    {
        global $config;

        if (!PermissionManager::hasPermission($config['mod']['createusers'])) {
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

            $salt = Authenticator::generate_salt();
            $password = hash('sha256', $salt . sha1($_POST['password']));

            $userID = (int) $this->repository->insertUser($_POST['username'], $password, $salt, $type, implode(',', $boards));

            Authenticator::modLog('Created a new user: ' . StringFormatter::utf8tohtml($_POST['username']) . ' <small>(#' . $userID . ')</small>');

            header('Location: ?/users', true, $config['redirect_http']);
            return;
        }

        mod_page(_('New user'), 'mod/user.html', ['new' => true, 'boards' => BoardService::listBoards(), 'token' => Token::make_secure_link_token('users/new')]);
    }

    /**
     * List all moderator accounts with promote/demote tokens.
     *
     * @return void
     */
    public function mod_users(): void
    {
        global $config;

        if (!PermissionManager::hasPermission($config['mod']['manageusers'])) {
            error($config['error']['noaccess']);
        }

        $users = $this->repository->getAllUsers();

        foreach ($users as &$user) {
            $user['promote_token'] = Token::make_secure_link_token("users/{$user['id']}/promote");
            $user['demote_token'] = Token::make_secure_link_token("users/{$user['id']}/demote");
        }

        mod_page(sprintf('%s (%d)', _('Manage users'), count($users)), 'mod/users.html', ['users' => $users]);
    }

    /**
     * Promote or demote a moderator account to the next/previous group.
     *
     * @param int    $uid    User ID to change.
     * @param string $action 'promote' or 'demote'.
     * @return void
     */
    public function mod_user_promote(int $uid, string $action): void
    {
        global $config;

        if (!PermissionManager::hasPermission($config['mod']['promoteusers'])) {
            error($config['error']['noaccess']);
        }

        $mod = $this->repository->getTypeAndUsername($uid);
        if (!$mod) {
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

        $this->repository->updateType($uid, $new_group);

        Authenticator::modLog(($action == 'promote' ? 'Promoted' : 'Demoted') . ' user "'
            . StringFormatter::utf8tohtml($mod['username']) . '" to ' . $config['mod']['groups'][$new_group]);

        header('Location: ?/users', true, $config['redirect_http']);
    }
}
