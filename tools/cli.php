<?php

/*
 *  This script will look for Tinyboard in the following places (in order):
 *    - $TINYBOARD_PATH environment variable
 *    - ./
 *    - ./Tinyboard/
 *    - ../
 *
 *  This script is based on code from vichan-devel/vichan
 *  Copyright (c) 2012-2018 vichan-devel
 *  Copyright (c) 2010-2014 Tinyboard Development Group (tinyboard.org)
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);
$shell_path = getcwd();

if (php_sapi_name() != 'cli') {
    die("This script is executable only from Command Line Interface.");
}

if (getenv('TINYBOARD_PATH') !== false) {
    $dir = getenv('TINYBOARD_PATH');
} elseif (file_exists('etc/functions.php')) {
    $dir = false;
} elseif (file_exists('Tinyboard') && is_dir('Tinyboard') && file_exists('Tinyboard/etc/functions.php')) {
    $dir = 'Tinyboard';
} elseif (file_exists('../etc/functions.php')) {
    $dir = '..';
} else {
    die("Could not locate Tinyboard directory!\n");
}

if ($dir && !chdir($dir)) {
    die("Could not change directory to {$dir}\n");
}

if (!getenv('TINYBOARD_PATH')) {
    // follow symlink
    chdir(realpath('etc') . '/..');
}

putenv('TINYBOARD_PATH=' . getcwd());

require dirname(__DIR__) . '/bootstrap.php';

$mod = [
    'id' => -1,
    'type' => ADMIN,
    'username' => '?',
    'boards' => ['*'],
];
