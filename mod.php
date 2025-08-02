<?php

/*
 *  Copyright (c) 2010-2014 Tinyboard Development Group
 */

require_once 'inc/bootstrap.php';
require_once 'inc/mod/pages.php';
require_once 'inc/mod/auth.php';

authenticate();

if ($config['debug']) {
    $parse_start_time = microtime(true);
}

$query = isset($_SERVER['QUERY_STRING']) ? rawurldecode($_SERVER['QUERY_STRING']) : '';

$pages = require 'inc/routes.php';

// If not logged in as mod, redirect to login
if (!$mod) {
    $pages = ['!^(.+)?$!' => 'login'];
} elseif (isset($_GET['status'], $_GET['r'])) {
    header('Location: ' . $_GET['r'], true, (int) $_GET['status']);
    exit;
}

// Merge in custom pages if set
if (isset($config['mod']['custom_pages'])) {
    $pages = array_merge($pages, $config['mod']['custom_pages']);
}

// Prepare routes with regex and tokens
$new_pages = [];
foreach ($pages as $key => $callback) {
    if (is_string($callback) && preg_match('/^secure /', $callback)) {
        $key .= '(/(?P<token>[a-f0-9]{8}))?';
    }
    $key = str_replace(
        '\%b',
        '?P<board>' . sprintf(substr($config['board_path'], 0, -1), $config['board_regex']),
        $key,
    );
    // Use explicit check for '!' at start
    $regex = (isset($key[0]) && $key[0] === '!') ? $key : '!^' . $key . '(?:&[^&=]+=[^&]*)*$!u';
    $new_pages[$regex] = $callback;
}
$pages = $new_pages;

foreach ($pages as $uri => $handler) {
    if (preg_match($uri, $query, $matches)) {
        $matches = array_slice($matches, 1);

        if (isset($matches['board'])) {
            $board_value = $matches['board'];
            unset($matches['board']);
            foreach ($matches as $k => $v) {
                if ($v === $board_value) {
                    if (preg_match('/^' . sprintf(substr($config['board_path'], 0, -1), '(' . $config['board_regex'] . ')') . '$/u', $v, $board_match)) {
                        $matches[$k] = $board_match[1];
                    }
                    break;
                }
            }
        }

        if (is_string($handler) && preg_match('/^secure(_POST)? /', $handler, $m)) {
            $secure_post_only = isset($m[1]);
            if (!$secure_post_only || $_SERVER['REQUEST_METHOD'] == 'POST') {
                $token = isset($matches['token']) ? $matches['token'] : (isset($_POST['token']) ? $_POST['token'] : false);

                if ($token === false) {
                    if ($secure_post_only) {
                        error($config['error']['csrf']);
                    } else {
                        mod_confirm(substr($query, 1));
                        exit;
                    }
                }

                // CSRF-protected page; validate security token
                $actual_query = preg_replace('!/([a-f0-9]{8})$!', '', $query);
                if ($token != make_secure_link_token(substr($actual_query, 1))) {
                    error($config['error']['csrf']);
                }
            }
            $handler = preg_replace('/^secure(_POST)? /', '', $handler);
        }

        if ($config['debug']) {
            $debug['mod_page'] = [
                'req' => $query,
                'match' => $uri,
                'handler' => $handler,
            ];
            $debug['time']['parse_mod_req'] = '~' . round((microtime(true) - $parse_start_time) * 1000, 2) . 'ms';
        }

        // Remove numeric keys from matches, as they are not needed
        $matches = array_values($matches);

        if (is_string($handler)) {
            if ($handler[0] == ':') {
                header('Location: ' . substr($handler, 1), true, $config['redirect_http']);
            } elseif (is_callable("mod_page_$handler")) {
                call_user_func_array("mod_page_$handler", $matches);
            } elseif (is_callable("mod_$handler")) {
                call_user_func_array("mod_$handler", $matches);
            } else {
                error("Mod page '$handler' not found!");
            }
        } elseif (is_callable($handler)) {
            call_user_func_array($handler, $matches);
        } else {
            error("Mod page '$handler' not a string, and not callable!");
        }

        exit;
    }
}

error($config['error']['404']);
