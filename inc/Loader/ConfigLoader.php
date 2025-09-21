<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Loader;

use Sudochan\Handler\ErrorHandler;
use Sudochan\Dispatcher\EventDispatcher;
use Sudochan\Cache;

$microtime_start = microtime(true);

// the user is not currently logged in as a moderator
$mod = false;

register_shutdown_function([ErrorHandler::class, 'fatal_error_handler']);
mb_internal_encoding('UTF-8');

class ConfigLoader
{
    /**
     * Load and initialize configuration.
     *
     * @global array|null $board
     * @global array $config
     * @global string $__ip
     * @global array|null $debug
     * @global float $microtime_start
     * @return void
     */
    public static function loadConfig(): void
    {
        global $board, $config, $__ip, $debug, $__version, $microtime_start;

        $error = function_exists('error') ? 'error' : [ErrorHandler::class, 'basic_error_function_because_the_other_isnt_loaded_yet'];

        EventDispatcher::reset_events();

        if (!isset($_SERVER['REMOTE_ADDR'])) {
            $_SERVER['REMOTE_ADDR'] = '0.0.0.0';
        }

        // Initialize config arrays
        $arrays = [
            'db', 'api', 'cache', 'cookies', 'error', 'dir', 'mod', 'spam', 'filters',
            'wordfilters', 'custom_capcode', 'custom_tripcode', 'dnsbl', 'dnsbl_exceptions',
            'remote', 'allowed_ext', 'allowed_ext_files', 'file_icons', 'footer',
            'stylesheets', 'additional_javascript', 'markup', 'custom_pages', 'dashboard_links',
        ];
        $config = [];
        foreach ($arrays as $key) {
            $config[$key] = [];
        }

        require dirname(__DIR__, 2) . '/etc/config.php';
        if (!file_exists(dirname(__DIR__, 2) . '/instance-config.php')) {
            $error('Sudochan is not configured! Create instance-config.php in root dir.');
            return;
        }
        require dirname(__DIR__, 2) . '/instance-config.php';

        if (isset($board['dir']) && file_exists($board['dir'] . '/config.php')) {
            require $board['dir'] . '/config.php';
        }

        if (!isset($__version)) {
            $__version = file_exists('.installed') ? trim(file_get_contents('.installed')) : false;
        }
        $config['version'] = $__version;

        date_default_timezone_set($config['timezone']);

        // Set defaults if not set
        $config['global_message'] = $config['global_message'] ?? false;
        $config['post_url'] = $config['post_url'] ?? $config['root'] . $config['file_post'];

        // Referer match regex
        if (!isset($config['referer_match'])) {
            if (isset($_SERVER['HTTP_HOST'])) {
                $config['referer_match'] = '/^'
                    . (preg_match('@^https?://@', $config['root']) ? '' : 'https?:\/\/' . $_SERVER['HTTP_HOST'])
                    . preg_quote($config['root'], '/')
                    . '('
                        . str_replace('%s', $config['board_regex'], preg_quote($config['board_path'], '/'))
                        . '('
                            . preg_quote($config['file_index'], '/') . '|'
                            . str_replace('%d', '\d+', preg_quote($config['file_page']))
                        . ')?'
                    . '|'
                        . str_replace('%s', $config['board_regex'], preg_quote($config['board_path'], '/'))
                        . preg_quote($config['dir']['res'], '/')
                        . str_replace('%d', '\d+', preg_quote($config['file_page'], '/'))
                    . '|'
                        . preg_quote($config['file_mod'], '/') . '\?\/.+'
                    . ')([#?](.+)?)?$/ui';
            } else {
                $config['referer_match'] = '//'; // CLI mode
            }
        }

        $config['cookies']['path'] = $config['cookies']['path'] ?? $config['root'];
        $config['dir']['static'] = $config['dir']['static'] ?? $config['root'] . 'static/';

        $config['image_sticky'] = $config['image_sticky'] ?? $config['dir']['static'] . 'sticky.gif';
        $config['image_locked'] = $config['image_locked'] ?? $config['dir']['static'] . 'locked.gif';
        $config['image_bumplocked'] = $config['image_bumplocked'] ?? $config['dir']['static'] . 'sage.gif';
        $config['image_deleted'] = $config['image_deleted'] ?? $config['dir']['static'] . 'deleted.png';

        // Board-specific URIs
        if (isset($board)) {
            if (!isset($config['uri_thumb'])) {
                $config['uri_thumb'] = $config['root'] . $board['dir'] . $config['dir']['thumb'];
            } elseif (isset($board['dir'])) {
                $config['uri_thumb'] = sprintf($config['uri_thumb'], $board['dir']);
            }

            if (!isset($config['uri_img'])) {
                $config['uri_img'] = $config['root'] . $board['dir'] . $config['dir']['img'];
            } elseif (isset($board['dir'])) {
                $config['uri_img'] = sprintf($config['uri_img'], $board['dir']);
            }
        }

        $config['uri_stylesheets'] = $config['uri_stylesheets'] ?? $config['root'] . 'stylesheets/';
        $config['url_stylesheet'] = $config['url_stylesheet'] ?? $config['uri_stylesheets'] . 'style.css';
        $config['url_javascript'] = $config['url_javascript'] ?? $config['root'] . $config['file_script'];
        $config['additional_javascript_url'] = $config['additional_javascript_url'] ?? $config['root'];
        $config['uri_flags'] = $config['uri_flags'] ?? $config['root'] . 'static/flags/%s.png';

        // Change working directory if needed
        if (!empty($config['root_file'])) {
            chdir($config['root_file']);
        }

        // Verbose error handling
        if (!empty($config['verbose_errors'])) {
            if (method_exists(ErrorHandler::class, 'verbose_error_handler')) {
                set_error_handler([ErrorHandler::class, 'verbose_error_handler']);
            }
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
            ini_set('html_errors', '0');
        } else {
            ini_set('display_errors', '0');
        }

        // Keep the original address to properly comply with other board configurations
        if (!isset($__ip)) {
            $__ip = $_SERVER['REMOTE_ADDR'];
        }

        // Handle IPv6-mapped IPv4 addresses ::ffff:0.0.0.0
        if (preg_match('/^\:\:(ffff\:)?(\d+\.\d+\.\d+\.\d+)$/', $__ip, $m)) {
            $_SERVER['REMOTE_ADDR'] = $m[2];
        }

        // Load locale translations if not English
        if ($config['locale'] != 'en') {
            if (setlocale(LC_ALL, $config['locale']) === false) {
                $error('The specified locale (' . $config['locale'] . ') does not exist on your platform!');
            }
            if (extension_loaded('gettext')) {
                $locales_dir = dirname(__DIR__, 2) . '/locales';
                bindtextdomain('sudochan', $locales_dir);
                bind_textdomain_codeset('sudochan', 'UTF-8');
                textdomain('sudochan');
            }
        }

        // Syslog
        if (!empty($config['syslog'])) {
            openlog('sudochan', LOG_ODELAY, LOG_SYSLOG); // open a connection to system logger
        }

        if (!empty($config['cache']['enabled'])) {
            Cache::init();
        }

        EventDispatcher::event('load-config');

        // Debug
        if (!empty($config['debug']) && !isset($debug)) {
            $debug = [
                'sql' => [],
                'exec' => [],
                'purge' => [],
                'cached' => [],
                'write' => [],
                'time' => [
                    'db_queries' => 0,
                    'exec' => 0,
                ],
                'start' => $microtime_start,
                'start_debug' => microtime(true),
            ];
            $debug['start'] = $microtime_start;
        }
    }
}
