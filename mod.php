<?php

/*
 *  Copyright (c) 2010-2014 Tinyboard Development Group
 */

use Sudochan\Security\Authenticator;
use Sudochan\Controller\AuthController;
use Sudochan\Utils\Token;
use Sudochan\Dispatcher;

require_once 'bootstrap.php';

// Authenticate the mod user
Authenticator::authenticate();

if (!empty($config['debug'])) {
    $parse_start_time = microtime(true);
}

// Normalize query string
$query = isset($_SERVER['QUERY_STRING']) ? rawurldecode($_SERVER['QUERY_STRING']) : '';

$pages = require 'etc/routes.php';

$pages += [
    '/(\%b)/' => 'BoardController@mod_view_board',
    '/(\%b)/' . preg_quote($config['file_index'], '!') => 'BoardController@mod_view_board',
    '/(\%b)/' . str_replace('%d', '(\d+)', preg_quote($config['file_page'], '!')) => 'BoardController@mod_view_board',
    '/(\%b)/' . preg_quote($config['dir']['res'], '!')
        . str_replace('%d', '(\d+)', preg_quote($config['file_page'], '!')) => 'BoardController@mod_view_thread',
];

// If not logged in as mod, redirect to login
if (!$mod) {
    $pages = ['!^(.+)?$!' => 'AuthController@mod_login'];
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

// Ensure $debug variable exists
$debug = $debug ?? null;
if (!empty($config['debug'])) {
    $parse_start_time = $parse_start_time ?? microtime(true);
    if ($debug === null) {
        $debug = [];
    }
}

// Dispatch all routes
Dispatcher::dispatch($pages, $query, $config, $debug, $parse_start_time ?? null);
