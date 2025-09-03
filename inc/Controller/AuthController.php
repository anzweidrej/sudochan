<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller;

use Sudochan\Mod\Auth;
use Sudochan\Handler\ErrorHandler;

class AuthController
{
    public function mod_login(string|false $redirect = false): void
    {
        global $config;

        $args = [];

        if (isset($_POST['login'])) {
            // Check if inputs are set and not empty
            if (!isset($_POST['username'], $_POST['password']) || $_POST['username'] == '' || $_POST['password'] == '') {
                $args['error'] = $config['error']['invalid'];
            } elseif (!Auth::login($_POST['username'], $_POST['password'])) {
                if ($config['syslog']) {
                    ErrorHandler::_syslog(LOG_WARNING, 'Unauthorized login attempt!');
                }

                $args['error'] = $config['error']['invalid'];
            } else {
                Auth::modLog('Logged in');

                // Login successful
                // Set cookies
                Auth::setCookies();

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

    public function mod_logout(): void
    {
        global $config;
        Auth::destroyCookies();

        header('Location: ?/', true, $config['redirect_http']);
    }

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
                    'username' => utf8tohtml($username),
                    'redirect' => $redirect,
                ],
            ),
        ]));
    }
}
