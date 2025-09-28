<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Manager\AuthManager;
use Sudochan\Handler\ErrorHandler;
use Sudochan\Utils\StringFormatter;
use Sudochan\Utils\Token;

class AuthController
{
    /**
     * Handle moderator login page and submission.
     *
     * @param string|false $redirect Optional redirect query string on success.
     * @return void
     */
    public function mod_login(string|false $redirect = false): void
    {
        global $config;

        $args = [];

        if (isset($_POST['login'])) {
            // Check if inputs are set and not empty
            if (!isset($_POST['username'], $_POST['password']) || $_POST['username'] == '' || $_POST['password'] == '') {
                $args['error'] = $config['error']['invalid'];
            } elseif (!AuthManager::login($_POST['username'], $_POST['password'])) {
                if ($config['syslog']) {
                    ErrorHandler::_syslog(LOG_WARNING, 'Unauthorized login attempt!');
                }

                $args['error'] = $config['error']['invalid'];
            } else {
                AuthManager::modLog('Logged in');

                // Login successful
                // Set cookies
                AuthManager::setCookies();

                if ($redirect) {
                    header('Location: ?' . $redirect, true, $config['redirect_http']);
                } else {
                    header('Location: ?/', true, $config['redirect_http']);
                }
            }
        }

        if (isset($_POST['username'])) {
            $args['username'] = $_POST['username'];
        }

        mod_page(_('Login'), 'mod/login.html', $args);
    }

    /**
     * Log out the current moderator by destroying auth cookies and redirecting.
     *
     * @return void
     */
    public function mod_logout(): void
    {
        global $config;
        AuthManager::destroyCookies();

        header('Location: ?/', true, $config['redirect_http']);
    }

    /**
     * Render the login form.
     *
     * @param string|false $error    Optional error message to display.
     * @param string|false $username Optional username to pre-fill.
     * @param string|false $redirect Optional redirect query string.
     * @return never This function terminates execution with die().
     */
    public function loginForm(string|false $error = false, string|false $username = false, string|false $redirect = false): never
    {
        global $config;

        die(element('page.html', [
            'index' => $config['root'],
            'title' => _('Login'),
            'config' => $config,
            'body' => element(
                'login.html',
                [
                    'config' => $config,
                    'error' => $error,
                    'username' => StringFormatter::utf8tohtml($username),
                    'redirect' => $redirect,
                ],
            ),
        ]));
    }

    /**
     * Render a confirmation page for a moderator action.
     *
     * @param string $request Action identifier to confirm.
     * @return void
     */
    public static function mod_confirm(string $request): void
    {
        mod_page(_('Confirm action'), 'mod/confirm.html', ['request' => $request, 'token' => Token::make_secure_link_token($request)]);
    }
}
