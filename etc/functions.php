<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

if (realpath($_SERVER['SCRIPT_FILENAME']) == str_replace('\\', '/', __FILE__)) {
    // You cannot request this file directly.
    exit;
}

use Sudochan\Api;
use Sudochan\Bans;
use Sudochan\Service\BoardService;
use Sudochan\Cache;
use Sudochan\EventDispatcher;
use Sudochan\Filter;
use Sudochan\Mod\Auth;
use Sudochan\Remote;
use Sudochan\Entity\Post;
use Sudochan\Entity\Thread;
use Sudochan\Handler\ErrorHandler;

$microtime_start = microtime(true);

// the user is not currently logged in as a moderator
$mod = false;

register_shutdown_function([ErrorHandler::class, 'fatal_error_handler']);
mb_internal_encoding('UTF-8');
loadConfig();

// Ensure fallback translation function is always defined
if (!function_exists('_')) {
    function _($str)
    {
        return $str;
    }
}

function loadConfig(): void
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

    require 'etc/config.php';
    if (!file_exists(__DIR__ . '/../instance-config.php')) {
        $error('Sudochan is not configured! Create instance-config.php in root dir.');
        return;
    }
    require __DIR__ . '/../instance-config.php';

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
            $config['referer_match'] = '/^' .
                (preg_match('@^https?://@', $config['root']) ? '' : 'https?:\/\/' . $_SERVER['HTTP_HOST']) .
                preg_quote($config['root'], '/') .
                '(' .
                    str_replace('%s', $config['board_regex'], preg_quote($config['board_path'], '/')) .
                    '(' .
                        preg_quote($config['file_index'], '/') . '|' .
                        str_replace('%d', '\d+', preg_quote($config['file_page'])) .
                    ')?' .
                '|' .
                    str_replace('%s', $config['board_regex'], preg_quote($config['board_path'], '/')) .
                    preg_quote($config['dir']['res'], '/') .
                    str_replace('%d', '\d+', preg_quote($config['file_page'], '/')) .
                '|' .
                    preg_quote($config['file_mod'], '/') . '\?\/.+' .
                ')([#?](.+)?)?$/ui';
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
        // @phpstan-ignore-next-line
        if (_setlocale(LC_ALL, $config['locale']) === false) {
            $error('The specified locale (' . $config['locale'] . ') does not exist on your platform!');
        }
        if (extension_loaded('gettext')) {
            bindtextdomain('sudochan', './locales/');
            bind_textdomain_codeset('sudochan', 'UTF-8');
            textdomain('sudochan');
        } else {
            // @phpstan-ignore-next-line
            _bindtextdomain('sudochan', './locales/');
            // @phpstan-ignore-next-line
            _bind_textdomain_codeset('sudochan', 'UTF-8');
            // @phpstan-ignore-next-line
            _textdomain('sudochan');
        }
    }

    // Syslog
    if (!empty($config['syslog'])) {
        openlog('tinyboard', LOG_ODELAY, LOG_SYSLOG); // open a connection to system logger
    }

    if (!empty($config['cache']['enabled'])) {
        require_once 'inc/Cache.php';
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

function define_groups(): void
{
    global $config;

    foreach ($config['mod']['groups'] as $group_value => $group_name) {
        $group_name = strtoupper($group_name);
        if (!defined($group_name)) {
            define($group_name, $group_value);
        }
    }

    ksort($config['mod']['groups']);
}

function create_antibot(string $board, ?int $thread = null): object
{
    require_once __DIR__ . '/../inc/AntiBot.php';

    return \Sudochan\_create_antibot($board, $thread);
}

function rebuildThemes(string $action, string|false $board = false): void
{
    // List themes
    $query = query("SELECT `theme` FROM ``theme_settings`` WHERE `name` IS NULL AND `value` IS NULL") or error(db_error());

    while ($theme = $query->fetch(\PDO::FETCH_ASSOC)) {
        rebuildTheme($theme['theme'], $action, $board);
    }
}

function loadThemeConfig(string $_theme): array|false
{
    global $config;

    if (!file_exists($config['dir']['themes'] . '/' . $_theme . '/info.php')) {
        return false;
    }

    // Load theme information into $theme
    include $config['dir']['themes'] . '/' . $_theme . '/info.php';

    return $theme;
}

function rebuildTheme(string $theme, string $action, string|false $board = false): void
{
    global $config, $_theme;
    $_theme = $theme;

    $theme = loadThemeConfig($_theme);

    if (file_exists($config['dir']['themes'] . '/' . $_theme . '/theme.php')) {
        require_once $config['dir']['themes'] . '/' . $_theme . '/theme.php';

        $theme['build_function']($action, themeSettings($_theme), $board);
    }
}

function themeSettings(string $theme): array
{
    $query = prepare("SELECT `name`, `value` FROM ``theme_settings`` WHERE `theme` = :theme AND `name` IS NOT NULL");
    $query->bindValue(':theme', $theme);
    $query->execute() or error(db_error($query));

    $settings = [];
    while ($s = $query->fetch(\PDO::FETCH_ASSOC)) {
        $settings[$s['name']] = $s['value'];
    }

    return $settings;
}

function sprintf3(string $str, array $vars, string $delim = '%'): string
{
    $replaces = [];
    foreach ($vars as $k => $v) {
        $replaces[$delim . $k . $delim] = $v;
    }
    return str_replace(
        array_keys($replaces),
        array_values($replaces),
        $str,
    );
}

function mb_substr_replace(string $string, string $replacement, int $start, int $length): string
{
    return mb_substr($string, 0, $start) . $replacement . mb_substr($string, $start + $length);
}

function purge(string $uri): void
{
    global $config, $debug;

    // Fix for Unicode
    $uri = rawurlencode($uri);

    $noescape = "/!~*()+:";
    $noescape = preg_split('//', $noescape);
    $noescape_url = array_map("rawurlencode", $noescape);
    $uri = str_replace($noescape_url, $noescape, $uri);

    if (preg_match($config['referer_match'], $config['root']) && isset($_SERVER['REQUEST_URI'])) {
        $uri = (str_replace('\\', '/', dirname($_SERVER['REQUEST_URI'])) == '/' ? '/' : str_replace('\\', '/', dirname($_SERVER['REQUEST_URI'])) . '/') . $uri;
    } else {
        $uri = $config['root'] . $uri;
    }

    if ($config['debug']) {
        $debug['purge'][] = $uri;
    }

    foreach ($config['purge'] as &$purge) {
        $host = &$purge[0];
        $port = &$purge[1];
        $http_host = isset($purge[2]) ? $purge[2] : (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');
        $request = "PURGE {$uri} HTTP/1.1\r\nHost: {$http_host}\r\nUser-Agent: Tinyboard\r\nConnection: Close\r\n\r\n";
        if ($fp = fsockopen($host, $port, $errno, $errstr, $config['purge_timeout'])) {
            fwrite($fp, $request);
            fclose($fp);
        } else {
            // Cannot connect?
            error('Could not PURGE for ' . $host);
        }
    }
}

function file_write(string $path, string $data, bool $simple = false, bool $skip_purge = false): void
{
    global $config, $debug;

    if (preg_match('/^remote:\/\/(.+)\:(.+)$/', $path, $m)) {
        if (isset($config['remote'][$m[1]])) {

            $remote = new Remote($config['remote'][$m[1]]);
            $remote->write($data, $m[2]);
            return;
        } else {
            error('Invalid remote server: ' . $m[1]);
        }
    }

    if (!$fp = fopen($path, $simple ? 'w' : 'c')) {
        error('Unable to open file for writing: ' . $path);
    }

    // File locking
    if (!$simple && !flock($fp, LOCK_EX)) {
        error('Unable to lock file: ' . $path);
    }

    // Truncate file
    if (!$simple && !ftruncate($fp, 0)) {
        error('Unable to truncate file: ' . $path);
    }

    // Write data
    if (($bytes = fwrite($fp, $data)) === false) {
        error('Unable to write to file: ' . $path);
    }

    // Unlock
    if (!$simple) {
        flock($fp, LOCK_UN);
    }

    // Close
    if (!fclose($fp)) {
        error('Unable to close file: ' . $path);
    }

    if (!$skip_purge && isset($config['purge'])) {
        // Purge cache
        if (basename($path) == $config['file_index']) {
            // Index file (/index.html); purge "/" as well
            $uri = dirname($path);
            // root
            if ($uri == '.') {
                $uri = '';
            } else {
                $uri .= '/';
            }
            purge($uri);
        }
        purge($path);
    }

    if ($config['debug']) {
        $debug['write'][] = $path . ': ' . $bytes . ' bytes';
    }

    EventDispatcher::event('write', $path);
}

function file_unlink(string $path): bool
{
    global $config, $debug;

    if ($config['debug']) {
        if (!isset($debug['unlink'])) {
            $debug['unlink'] = [];
        }
        $debug['unlink'][] = $path;
    }

    $ret = @unlink($path);
    if (isset($config['purge']) && $path[0] != '/' && isset($_SERVER['HTTP_HOST'])) {
        // Purge cache
        if (basename($path) == $config['file_index']) {
            // Index file (/index.html); purge "/" as well
            $uri = dirname($path);
            // root
            if ($uri == '.') {
                $uri = '';
            } else {
                $uri .= '/';
            }
            purge($uri);
        }
        purge($path);
    }

    EventDispatcher::event('unlink', $path);

    return $ret;
}

function hasPermission(?int $action = null, ?string $board = null, ?array $_mod = null): bool
{
    global $config;

    if (isset($_mod)) {
        $mod = &$_mod;
    } else {
        global $mod;
    }

    if (!is_array($mod)) {
        return false;
    }

    if (isset($action) && $mod['type'] < $action) {
        return false;
    }

    if (!isset($board) || $config['mod']['skip_per_board']) {
        return true;
    }

    if (!isset($mod['boards'])) {
        return false;
    }

    if (!in_array('*', $mod['boards']) && !in_array($board, $mod['boards'])) {
        return false;
    }

    return true;
}

function until(int $timestamp): string
{
    $difference = $timestamp - time();
    if ($difference < 60) {
        return $difference . ' ' . ngettext('second', 'seconds', $difference);
    } elseif ($difference < 60 * 60) {
        return ($num = round($difference / (60))) . ' ' . ngettext('minute', 'minutes', $num);
    } elseif ($difference < 60 * 60 * 24) {
        return ($num = round($difference / (60 * 60))) . ' ' . ngettext('hour', 'hours', $num);
    } elseif ($difference < 60 * 60 * 24 * 7) {
        return ($num = round($difference / (60 * 60 * 24))) . ' ' . ngettext('day', 'days', $num);
    } elseif ($difference < 60 * 60 * 24 * 365) {
        return ($num = round($difference / (60 * 60 * 24 * 7))) . ' ' . ngettext('week', 'weeks', $num);
    }

    return ($num = round($difference / (60 * 60 * 24 * 365))) . ' ' . ngettext('year', 'years', $num);
}

function ago(int $timestamp): string
{
    $difference = time() - $timestamp;
    if ($difference < 60) {
        return $difference . ' ' . ngettext('second', 'seconds', $difference);
    } elseif ($difference < 60 * 60) {
        return ($num = round($difference / (60))) . ' ' . ngettext('minute', 'minutes', $num);
    } elseif ($difference < 60 * 60 * 24) {
        return ($num = round($difference / (60 * 60))) . ' ' . ngettext('hour', 'hours', $num);
    } elseif ($difference < 60 * 60 * 24 * 7) {
        return ($num = round($difference / (60 * 60 * 24))) . ' ' . ngettext('day', 'days', $num);
    } elseif ($difference < 60 * 60 * 24 * 365) {
        return ($num = round($difference / (60 * 60 * 24 * 7))) . ' ' . ngettext('week', 'weeks', $num);
    }

    return ($num = round($difference / (60 * 60 * 24 * 365))) . ' ' . ngettext('year', 'years', $num);
}

function displayBan(array $ban): void
{
    global $config, $board;

    if (!$ban['seen']) {
        Bans::seen($ban['id']);
    }

    $ban['ip'] = $_SERVER['REMOTE_ADDR'];
    if ($ban['post'] && isset($ban['post']['board'], $ban['post']['id'])) {
        if (BoardService::openBoard($ban['post']['board'])) {

            $query = query(sprintf("SELECT `thumb`, `file` FROM ``posts_%s`` WHERE `id` = " .
                (int) $ban['post']['id'], $board['uri']));
            if ($_post = $query->fetch(\PDO::FETCH_ASSOC)) {
                $ban['post'] = array_merge($ban['post'], $_post);
            } else {
                $ban['post']['file'] = 'deleted';
                $ban['post']['thumb'] = false;
            }
        } else {
            $ban['post']['file'] = 'deleted';
            $ban['post']['thumb'] = false;
        }

        if ($ban['post']['thread']) {
            $post = new Post($ban['post']);
        } else {
            $post = new Thread($ban['post'], null, false, false);
        }
    }

    $denied_appeals = [];
    $pending_appeal = false;

    if ($config['ban_appeals']) {
        $query = query("SELECT `time`, `denied` FROM `ban_appeals` WHERE `ban_id` = " . (int) $ban['id']) or error(db_error());
        while ($ban_appeal = $query->fetch(\PDO::FETCH_ASSOC)) {
            if ($ban_appeal['denied']) {
                $denied_appeals[] = $ban_appeal['time'];
            } else {
                $pending_appeal = $ban_appeal['time'];
            }
        }
    }

    // Show banned page and exit
    die(
        element(
            'page.html',
            [
                'title' => _('Banned!'),
                'config' => $config,
                'nojavascript' => true,
                'body' => element(
                    'banned.html',
                    [
                        'config' => $config,
                        'ban' => $ban,
                        'board' => $board,
                        'post' => isset($post) ? $post->build(true) : false,
                        'denied_appeals' => $denied_appeals,
                        'pending_appeal' => $pending_appeal,
                    ],
                )],
        ));
}

function checkBan(string|false $board = false): ?bool
{
    global $config;

    if (!isset($_SERVER['REMOTE_ADDR'])) {
        // Server misconfiguration
        return null;
    }

    if (EventDispatcher::event('check-ban', $board)) {
        return true;
    }

    $bans = Bans::find($_SERVER['REMOTE_ADDR'], $board);

    foreach ($bans as &$ban) {
        if ($ban['expires'] && $ban['expires'] < time()) {
            Bans::delete($ban['id']);
            if ($config['require_ban_view'] && !$ban['seen']) {
                if (!isset($_POST['json_response'])) {
                    displayBan($ban);
                } else {
                    header('Content-Type: text/json');
                    die(json_encode(['error' => true, 'banned' => true]));
                }
            }
        } else {
            if (!isset($_POST['json_response'])) {
                displayBan($ban);
            } else {
                header('Content-Type: text/json');
                die(json_encode(['error' => true, 'banned' => true]));
            }
        }
    }

    // I'm not sure where else to put this. It doesn't really matter where; it just needs to be called every
    // now and then to keep the ban list tidy.
    if ($config['cache']['enabled'] && $last_time_purged = Cache::get('purged_bans_last')) {
        if (time() - $last_time_purged < $config['purge_bans']) {
            return null;
        }
    }

    Bans::purge();

    if ($config['cache']['enabled']) {
        Cache::set('purged_bans_last', time());
    }

    return null;
}

function threadLocked(int $id): bool
{
    global $board;

    if (EventDispatcher::event('check-locked', $id)) {
        return true;
    }

    $query = prepare(sprintf("SELECT `locked` FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL LIMIT 1", $board['uri']));
    $query->bindValue(':id', $id, \PDO::PARAM_INT);
    $query->execute() or error(db_error());

    if (($locked = $query->fetchColumn()) === false) {
        // Non-existant, so it can't be locked...
        return false;
    }

    return (bool) $locked;
}

function threadSageLocked(int $id): bool
{
    global $board;

    if (EventDispatcher::event('check-sage-locked', $id)) {
        return true;
    }

    $query = prepare(sprintf("SELECT `sage` FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL LIMIT 1", $board['uri']));
    $query->bindValue(':id', $id, \PDO::PARAM_INT);
    $query->execute() or error(db_error());

    if (($sagelocked = $query->fetchColumn()) === false) {
        // Non-existant, so it can't be locked...
        return false;
    }

    return (bool) $sagelocked;
}

function threadExists(int $id): bool
{
    global $board;

    $query = prepare(sprintf("SELECT 1 FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL LIMIT 1", $board['uri']));
    $query->bindValue(':id', $id, \PDO::PARAM_INT);
    $query->execute() or error(db_error());

    if ($query->rowCount()) {
        return true;
    }

    return false;
}

function insertFloodPost(array $post): void
{
    global $board;

    $query = prepare("INSERT INTO ``flood`` VALUES (NULL, :ip, :board, :time, :posthash, :filehash, :isreply)");
    $query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
    $query->bindValue(':board', $board['uri']);
    $query->bindValue(':time', time());
    $query->bindValue(':posthash', make_comment_hex($post['body_nomarkup']));
    if ($post['has_file']) {
        $query->bindValue(':filehash', $post['filehash']);
    } else {
        $query->bindValue(':filehash', null, \PDO::PARAM_NULL);
    }
    $query->bindValue(':isreply', !$post['op'], \PDO::PARAM_INT);
    $query->execute() or error(db_error($query));
}

function post(array $post): string
{
    global $pdo, $board;
    $query = prepare(sprintf("INSERT INTO ``posts_%s`` VALUES ( NULL, :thread, :subject, :email, :name, :trip, :capcode, :body, :body_nomarkup, :time, :time, :thumb, :thumbwidth, :thumbheight, :file, :width, :height, :filesize, :filename, :filehash, :password, :ip, :sticky, :locked, 0, :embed)", $board['uri']));

    // Basic stuff
    if (!empty($post['subject'])) {
        $query->bindValue(':subject', $post['subject']);
    } else {
        $query->bindValue(':subject', null, \PDO::PARAM_NULL);
    }

    if (!empty($post['email'])) {
        $query->bindValue(':email', $post['email']);
    } else {
        $query->bindValue(':email', null, \PDO::PARAM_NULL);
    }

    if (!empty($post['trip'])) {
        $query->bindValue(':trip', $post['trip']);
    } else {
        $query->bindValue(':trip', null, \PDO::PARAM_NULL);
    }

    $query->bindValue(':name', $post['name']);
    $query->bindValue(':body', $post['body']);
    $query->bindValue(':body_nomarkup', $post['body_nomarkup']);
    $query->bindValue(':time', isset($post['time']) ? $post['time'] : time(), \PDO::PARAM_INT);
    $query->bindValue(':password', $post['password']);
    $query->bindValue(':ip', isset($post['ip']) ? $post['ip'] : $_SERVER['REMOTE_ADDR']);

    if ($post['op'] && $post['mod'] && isset($post['sticky']) && $post['sticky']) {
        $query->bindValue(':sticky', true, \PDO::PARAM_INT);
    } else {
        $query->bindValue(':sticky', false, \PDO::PARAM_INT);
    }

    if ($post['op'] && $post['mod'] && isset($post['locked']) && $post['locked']) {
        $query->bindValue(':locked', true, \PDO::PARAM_INT);
    } else {
        $query->bindValue(':locked', false, \PDO::PARAM_INT);
    }

    if ($post['mod'] && isset($post['capcode']) && $post['capcode']) {
        $query->bindValue(':capcode', $post['capcode'], \PDO::PARAM_INT);
    } else {
        $query->bindValue(':capcode', null, \PDO::PARAM_NULL);
    }

    if (!empty($post['embed'])) {
        $query->bindValue(':embed', $post['embed']);
    } else {
        $query->bindValue(':embed', null, \PDO::PARAM_NULL);
    }

    if ($post['op']) {
        // No parent thread, image
        $query->bindValue(':thread', null, \PDO::PARAM_NULL);
    } else {
        $query->bindValue(':thread', $post['thread'], \PDO::PARAM_INT);
    }

    if ($post['has_file']) {
        $query->bindValue(':thumb', $post['thumb']);
        $query->bindValue(':thumbwidth', $post['thumbwidth'], \PDO::PARAM_INT);
        $query->bindValue(':thumbheight', $post['thumbheight'], \PDO::PARAM_INT);
        $query->bindValue(':file', $post['file']);

        if (isset($post['width'], $post['height'])) {
            $query->bindValue(':width', $post['width'], \PDO::PARAM_INT);
            $query->bindValue(':height', $post['height'], \PDO::PARAM_INT);
        } else {
            $query->bindValue(':width', null, \PDO::PARAM_NULL);
            $query->bindValue(':height', null, \PDO::PARAM_NULL);
        }

        $query->bindValue(':filesize', $post['filesize'], \PDO::PARAM_INT);
        $query->bindValue(':filename', $post['filename']);
        $query->bindValue(':filehash', $post['filehash']);
    } else {
        $query->bindValue(':thumb', null, \PDO::PARAM_NULL);
        $query->bindValue(':thumbwidth', null, \PDO::PARAM_NULL);
        $query->bindValue(':thumbheight', null, \PDO::PARAM_NULL);
        $query->bindValue(':file', null, \PDO::PARAM_NULL);
        $query->bindValue(':width', null, \PDO::PARAM_NULL);
        $query->bindValue(':height', null, \PDO::PARAM_NULL);
        $query->bindValue(':filesize', null, \PDO::PARAM_NULL);
        $query->bindValue(':filename', null, \PDO::PARAM_NULL);
        $query->bindValue(':filehash', null, \PDO::PARAM_NULL);
    }

    if (!$query->execute()) {
        undoImage($post);
        error(db_error($query));
    }

    return $pdo->lastInsertId();
}

function bumpThread(int $id): bool
{
    global $config, $board, $build_pages;

    if (EventDispatcher::event('bump', $id)) {
        return true;
    }

    if ($config['try_smarter']) {
        $build_pages[] = thread_find_page($id);
    }

    $query = prepare(sprintf("UPDATE ``posts_%s`` SET `bump` = :time WHERE `id` = :id AND `thread` IS NULL", $board['uri']));
    $query->bindValue(':time', time(), \PDO::PARAM_INT);
    $query->bindValue(':id', $id, \PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    return true;
}

// Remove file from post
function deleteFile(int $id, bool $remove_entirely_if_already = true): void
{
    global $board, $config;

    $query = prepare(sprintf("SELECT `thread`,`thumb`,`file` FROM ``posts_%s`` WHERE `id` = :id LIMIT 1", $board['uri']));
    $query->bindValue(':id', $id, \PDO::PARAM_INT);
    $query->execute() or error(db_error($query));
    if (!$post = $query->fetch(\PDO::FETCH_ASSOC)) {
        error($config['error']['invalidpost']);
    }

    if ($post['file'] == 'deleted' && !$post['thread']) {
        return;
    } // Can't delete OP's image completely.

    $query = prepare(sprintf("UPDATE ``posts_%s`` SET `thumb` = NULL, `thumbwidth` = NULL, `thumbheight` = NULL, `filewidth` = NULL, `fileheight` = NULL, `filesize` = NULL, `filename` = NULL, `filehash` = NULL, `file` = :file WHERE `id` = :id", $board['uri']));
    if ($post['file'] == 'deleted' && $remove_entirely_if_already) {
        // Already deleted; remove file fully
        $query->bindValue(':file', null, \PDO::PARAM_NULL);
    } else {
        // Delete thumbnail
        file_unlink($board['dir'] . $config['dir']['thumb'] . $post['thumb']);

        // Delete file
        file_unlink($board['dir'] . $config['dir']['img'] . $post['file']);

        // Set file to 'deleted'
        $query->bindValue(':file', 'deleted', \PDO::PARAM_INT);
    }

    $query->bindValue(':id', $id, \PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    if ($post['thread']) {
        buildThread($post['thread']);
    } else {
        buildThread($id);
    }
}

// rebuild post (markup)
function rebuildPost(int $id): bool
{
    global $board;

    $query = prepare(sprintf("SELECT `body_nomarkup`, `thread` FROM ``posts_%s`` WHERE `id` = :id", $board['uri']));
    $query->bindValue(':id', $id, \PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    if ((!$post = $query->fetch(\PDO::FETCH_ASSOC)) || !$post['body_nomarkup']) {
        return false;
    }

    markup($body = &$post['body_nomarkup']);

    $query = prepare(sprintf("UPDATE ``posts_%s`` SET `body` = :body WHERE `id` = :id", $board['uri']));
    $query->bindValue(':body', $body);
    $query->bindValue(':id', $id, \PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    buildThread($post['thread'] ? $post['thread'] : $id);

    return true;
}

// Delete a post (reply or thread)
function deletePost(int $id, bool $error_if_doesnt_exist = true, bool $rebuild_after = true): bool
{
    global $board, $config;

    // Select post and replies (if thread) in one query
    $query = prepare(sprintf("SELECT `id`,`thread`,`thumb`,`file` FROM ``posts_%s`` WHERE `id` = :id OR `thread` = :id", $board['uri']));
    $query->bindValue(':id', $id, \PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    if ($query->rowCount() < 1) {
        if ($error_if_doesnt_exist) {
            error($config['error']['invalidpost']);
        } else {
            return false;
        }
    }

    $ids = [];

    // Delete posts and maybe replies
    while ($post = $query->fetch(\PDO::FETCH_ASSOC)) {
        EventDispatcher::event('delete', $post);

        if (!$post['thread']) {
            // Delete thread HTML page
            file_unlink($board['dir'] . $config['dir']['res'] . sprintf($config['file_page'], $post['id']));

            $antispam_query = prepare('DELETE FROM ``antispam`` WHERE `board` = :board AND `thread` = :thread');
            $antispam_query->bindValue(':board', $board['uri']);
            $antispam_query->bindValue(':thread', $post['id']);
            $antispam_query->execute() or error(db_error($antispam_query));
        } elseif ($query->rowCount() == 1) {
            // Rebuild thread
            $rebuild = &$post['thread'];
        }
        if ($post['thumb']) {
            // Delete thumbnail
            file_unlink($board['dir'] . $config['dir']['thumb'] . $post['thumb']);
        }
        if ($post['file']) {
            // Delete file
            file_unlink($board['dir'] . $config['dir']['img'] . $post['file']);
        }

        $ids[] = (int) $post['id'];

    }

    $query = prepare(sprintf("DELETE FROM ``posts_%s`` WHERE `id` = :id OR `thread` = :id", $board['uri']));
    $query->bindValue(':id', $id, \PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    $query = prepare("SELECT `board`, `post` FROM ``cites`` WHERE `target_board` = :board AND (`target` = " . implode(' OR `target` = ', $ids) . ") ORDER BY `board`");
    $query->bindValue(':board', $board['uri']);
    $query->execute() or error(db_error($query));
    while ($cite = $query->fetch(\PDO::FETCH_ASSOC)) {
        if ($board['uri'] != $cite['board']) {
            if (!isset($tmp_board)) {
                $tmp_board = $board['uri'];
            }
            BoardService::openBoard($cite['board']);
        }
        rebuildPost($cite['post']);
    }

    if (isset($tmp_board)) {
        BoardService::openBoard($tmp_board);
    }

    $query = prepare("DELETE FROM ``cites`` WHERE (`target_board` = :board AND (`target` = " . implode(' OR `target` = ', $ids) . ")) OR (`board` = :board AND (`post` = " . implode(' OR `post` = ', $ids) . "))");
    $query->bindValue(':board', $board['uri']);
    $query->execute() or error(db_error($query));

    if (isset($rebuild) && $rebuild_after) {
        buildThread($rebuild);
    }

    return true;
}

function clean(): void
{
    global $board, $config;
    $offset = round($config['max_pages'] * $config['threads_per_page']);

    // I too wish there was an easier way of doing this...
    $query = prepare(sprintf("SELECT `id` FROM ``posts_%s`` WHERE `thread` IS NULL ORDER BY `sticky` DESC, `bump` DESC LIMIT :offset, 9001", $board['uri']));
    $query->bindValue(':offset', $offset, \PDO::PARAM_INT);

    $query->execute() or error(db_error($query));
    while ($post = $query->fetch(\PDO::FETCH_ASSOC)) {
        deletePost($post['id']);
    }
}

function thread_find_page(int $thread): int|false
{
    global $config, $board;

    $query = query(sprintf("SELECT `id` FROM ``posts_%s`` WHERE `thread` IS NULL ORDER BY `sticky` DESC, `bump` DESC", $board['uri'])) or error(db_error($query));
    $threads = $query->fetchAll(\PDO::FETCH_COLUMN);
    if (($index = array_search($thread, $threads)) === false) {
        return false;
    }
    return floor(($config['threads_per_page'] + $index) / $config['threads_per_page']);
}

function index(int $page, bool|array $mod = false): array|false
{
    global $board, $config, $debug;

    $body = '';
    $offset = round($page * $config['threads_per_page'] - $config['threads_per_page']);

    $query = prepare(sprintf("SELECT * FROM ``posts_%s`` WHERE `thread` IS NULL ORDER BY `sticky` DESC, `bump` DESC LIMIT :offset,:threads_per_page", $board['uri']));
    $query->bindValue(':offset', $offset, \PDO::PARAM_INT);
    $query->bindValue(':threads_per_page', $config['threads_per_page'], \PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    if ($page == 1 && $query->rowCount() < $config['threads_per_page']) {
        $board['thread_count'] = $query->rowCount();
    }

    if ($query->rowCount() < 1 && $page > 1) {
        return false;
    }

    $threads = [];

    while ($th = $query->fetch(\PDO::FETCH_ASSOC)) {
        $thread = new Thread($th, $mod ? '?/' : $config['root'], $mod);

        if ($config['cache']['enabled']) {
            $cached = Cache::get("thread_index_{$board['uri']}_{$th['id']}");
            if (isset($cached['replies'], $cached['omitted'])) {
                $replies = $cached['replies'];
                $omitted = $cached['omitted'];
            } else {
                unset($cached);
            }
        }
        if (!isset($cached)) {
            $posts = prepare(sprintf("SELECT * FROM ``posts_%s`` WHERE `thread` = :id ORDER BY `id` DESC LIMIT :limit", $board['uri']));
            $posts->bindValue(':id', $th['id']);
            $posts->bindValue(':limit', ($th['sticky'] ? $config['threads_preview_sticky'] : $config['threads_preview']), \PDO::PARAM_INT);
            $posts->execute() or error(db_error($posts));

            $replies = array_reverse($posts->fetchAll(\PDO::FETCH_ASSOC));

            if (count($replies) == ($th['sticky'] ? $config['threads_preview_sticky'] : $config['threads_preview'])) {
                $count = numPosts($th['id']);
                $omitted = ['post_count' => $count['replies'], 'image_count' => $count['images']];
            } else {
                $omitted = false;
            }

            if ($config['cache']['enabled']) {
                Cache::set("thread_index_{$board['uri']}_{$th['id']}", [
                    'replies' => $replies,
                    'omitted' => $omitted,
                ]);
            }
        }

        $num_images = 0;
        foreach ($replies as $po) {
            if ($po['file']) {
                $num_images++;
            }

            $thread->add(new Post($po, $mod ? '?/' : $config['root'], $mod));
        }

        if ($omitted) {
            $thread->omitted = $omitted['post_count'] - ($th['sticky'] ? $config['threads_preview_sticky'] : $config['threads_preview']);
            $thread->omitted_images = $omitted['image_count'] - $num_images;
        }

        $threads[] = $thread;
        $body .= $thread->build(true);
    }

    return [
        'board' => $board,
        'body' => $body,
        'post_url' => $config['post_url'],
        'config' => $config,
        'boardlist' => BoardService::createBoardlist($mod),
        'threads' => $threads,
    ];
}

function getPageButtons(array $pages, bool $mod = false): array
{
    global $config, $board;

    $btn = [];
    $root = ($mod ? '?/' : $config['root']) . $board['dir'];

    foreach ($pages as $num => $page) {
        if (isset($page['selected'])) {
            // Previous button
            if ($num == 0) {
                // There is no previous page.
                $btn['prev'] = _('Previous');
            } else {
                $loc = ($mod ? '?/' . $board['uri'] . '/' : '') .
                    (
                        $num == 1 ?
                        $config['file_index']
                    :
                        sprintf($config['file_page'], $num)
                    );

                $btn['prev'] = '<form action="' . ($mod ? '' : $root . $loc) . '" method="get">' .
                    ($mod ?
                        '<input type="hidden" name="status" value="301" />' .
                        '<input type="hidden" name="r" value="' . htmlentities($loc) . '" />'
                    : '') .
                '<input type="submit" value="' . _('Previous') . '" /></form>';
            }

            if ($num == count($pages) - 1) {
                // There is no next page.
                $btn['next'] = _('Next');
            } else {
                $loc = ($mod ? '?/' . $board['uri'] . '/' : '') . sprintf($config['file_page'], $num + 2);

                $btn['next'] = '<form action="' . ($mod ? '' : $root . $loc) . '" method="get">' .
                    ($mod ?
                        '<input type="hidden" name="status" value="301" />' .
                        '<input type="hidden" name="r" value="' . htmlentities($loc) . '" />'
                    : '') .
                '<input type="submit" value="' . _('Next') . '" /></form>';
            }
        }
    }

    return $btn;
}

function getPages(bool $mod = false): array
{
    global $board, $config;

    if (isset($board['thread_count'])) {
        $count = $board['thread_count'];
    } else {
        // Count threads
        $query = query(sprintf("SELECT COUNT(*) FROM ``posts_%s`` WHERE `thread` IS NULL", $board['uri'])) or error(db_error());
        $count = $query->fetchColumn();
    }
    $count = floor(($config['threads_per_page'] + $count - 1) / $config['threads_per_page']);

    if ($count < 1) {
        $count = 1;
    }

    $pages = [];
    for ($x = 0;$x < $count && $x < $config['max_pages'];$x++) {
        $pages[] = [
            'num' => $x + 1,
            'link' => $x == 0 ? ($mod ? '?/' : $config['root']) . $board['dir'] . $config['file_index'] : ($mod ? '?/' : $config['root']) . $board['dir'] . sprintf($config['file_page'], $x + 1),
        ];
    }

    return $pages;
}

// Stolen with permission from PlainIB (by Frank Usrs)
function make_comment_hex(string $str)
{
    // remove cross-board citations
    // the numbers don't matter
    $str = preg_replace('!>>>/[A-Za-z0-9]+/!', '', $str);

    if (function_exists('iconv')) {
        // remove diacritics and other noise
        // FIXME: this removes cyrillic entirely
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
    }

    $str = strtolower($str);

    // strip all non-alphabet characters
    $str = preg_replace('/[^a-z]/', '', $str);

    return md5($str);
}

function makerobot(string $body): string
{
    global $config;
    $body = strtolower($body);

    // Leave only letters
    $body = preg_replace('/[^a-z]/i', '', $body);
    // Remove repeating characters
    if ($config['robot_strip_repeating']) {
        $body = preg_replace('/(.)\\1+/', '$1', $body);
    }

    return sha1($body);
}

function checkRobot(string $body): bool
{
    if (empty($body) || EventDispatcher::event('check-robot', $body)) {
        return true;
    }

    $body = makerobot($body);
    $query = prepare("SELECT 1 FROM ``robot`` WHERE `hash` = :hash LIMIT 1");
    $query->bindValue(':hash', $body);
    $query->execute() or error(db_error($query));

    if ($query->fetchColumn()) {
        return true;
    }

    // Insert new hash
    $query = prepare("INSERT INTO ``robot`` VALUES (:hash)");
    $query->bindValue(':hash', $body);
    $query->execute() or error(db_error($query));

    return false;
}

// Returns an associative array with 'replies' and 'images' keys
function numPosts(int $id): array
{
    global $board;
    $query = prepare(sprintf("SELECT COUNT(*) AS `replies`, COUNT(NULLIF(`file`, 0)) AS `images` FROM ``posts_%s`` WHERE `thread` = :thread", $board['uri']));
    $query->bindValue(':thread', $id, \PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    return $query->fetch(\PDO::FETCH_ASSOC);
}

function muteTime(): int
{
    global $config;

    if ($time = EventDispatcher::event('mute-time')) {
        return $time;
    }

    // Find number of mutes in the past X hours
    $query = prepare("SELECT COUNT(*) FROM ``mutes`` WHERE `time` >= :time AND `ip` = :ip");
    $query->bindValue(':time', time() - ($config['robot_mute_hour'] * 3600), \PDO::PARAM_INT);
    $query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
    $query->execute() or error(db_error($query));

    if (!$result = $query->fetchColumn()) {
        return 0;
    }
    return pow($config['robot_mute_multiplier'], $result);
}

function mute(): int
{
    // Insert mute
    $query = prepare("INSERT INTO ``mutes`` VALUES (:ip, :time)");
    $query->bindValue(':time', time(), \PDO::PARAM_INT);
    $query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
    $query->execute() or error(db_error($query));

    return muteTime();
}

function checkMute(): void
{
    global $config, $debug;

    if ($config['cache']['enabled']) {
        // Cached mute?
        if (($mute = Cache::get("mute_{$_SERVER['REMOTE_ADDR']}")) && ($mutetime = Cache::get("mutetime_{$_SERVER['REMOTE_ADDR']}"))) {
            error(sprintf($config['error']['youaremuted'], $mute['time'] + $mutetime - time()));
        }
    }

    $mutetime = muteTime();
    if ($mutetime > 0) {
        // Find last mute time
        $query = prepare("SELECT `time` FROM ``mutes`` WHERE `ip` = :ip ORDER BY `time` DESC LIMIT 1");
        $query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
        $query->execute() or error(db_error($query));

        if (!$mute = $query->fetch(\PDO::FETCH_ASSOC)) {
            // What!? He's muted but he's not muted...
            return;
        }

        if ($mute['time'] + $mutetime > time()) {
            if ($config['cache']['enabled']) {
                Cache::set("mute_{$_SERVER['REMOTE_ADDR']}", $mute, $mute['time'] + $mutetime - time());
                Cache::set("mutetime_{$_SERVER['REMOTE_ADDR']}", $mutetime, $mute['time'] + $mutetime - time());
            }
            // Not expired yet
            error(sprintf($config['error']['youaremuted'], $mute['time'] + $mutetime - time()));
        } else {
            // Already expired
            return;
        }
    }
}

function buildIndex(): void
{
    global $board, $config, $build_pages;

    $pages = getPages();
    if (!$config['try_smarter']) {
        $antibot = create_antibot($board['uri']);
    }

    if ($config['api']['enabled']) {
        $api = new Api();
        $catalog = [];
    }

    for ($page = 1; $page <= $config['max_pages']; $page++) {
        $filename = $board['dir'] . ($page == 1 ? $config['file_index'] : sprintf($config['file_page'], $page));

        if ($config['try_smarter'] && isset($build_pages) && !empty($build_pages)
            && !in_array($page, $build_pages) && is_file($filename)) {
            continue;
        }
        $content = index($page);
        if (!$content) {
            break;
        }

        if ($config['try_smarter']) {
            $antibot = create_antibot($board['uri'], 0 - $page);
            $content['current_page'] = $page;
        }
        $antibot->reset();
        $content['pages'] = $pages;
        $content['pages'][$page - 1]['selected'] = true;
        $content['btn'] = getPageButtons($content['pages']);
        $content['antibot'] = $antibot;

        file_write($filename, element('index.html', $content));

        // json api
        if ($config['api']['enabled']) {
            $threads = $content['threads'];
            $json = json_encode($api->translatePage($threads));
            $jsonFilename = $board['dir'] . ($page - 1) . '.json'; // pages should start from 0
            file_write($jsonFilename, $json);

            $catalog[$page - 1] = $threads;
        }
    }

    if ($page < $config['max_pages']) {
        for (;$page <= $config['max_pages'];$page++) {
            $filename = $board['dir'] . ($page == 1 ? $config['file_index'] : sprintf($config['file_page'], $page));
            file_unlink($filename);

            $jsonFilename = $board['dir'] . ($page - 1) . '.json';
            file_unlink($jsonFilename);
        }
    }

    // json api catalog
    if ($config['api']['enabled']) {
        $json = json_encode($api->translateCatalog($catalog));
        $jsonFilename = $board['dir'] . 'catalog.json';
        file_write($jsonFilename, $json);
    }

    if ($config['try_smarter']) {
        $build_pages = [];
    }
}

function buildJavascript(): void
{
    global $config;

    $stylesheets = [];
    foreach ($config['stylesheets'] as $name => $uri) {
        $stylesheets[] = [
            'name' => addslashes($name),
            'uri' => addslashes((!empty($uri) ? $config['uri_stylesheets'] : '') . $uri)];
    }

    $script = element('main.js', [
        'config' => $config,
        'stylesheets' => $stylesheets,
    ]);

    // Check if we have translation for the javascripts; if yes, we add it to additional javascripts
    list($pure_locale) = explode(".", $config['locale']);
    if (file_exists($jsloc = "./locales/$pure_locale/LC_MESSAGES/javascript.js")) {
        $script = file_get_contents($jsloc) . "\n\n" . $script;
    }

    if ($config['additional_javascript_compile']) {
        foreach ($config['additional_javascript'] as $file) {
            $script .= file_get_contents($file);
        }
    }

    if ($config['minify_js']) {
        $script = \JSMin\JSMin::minify($script);
    }

    file_write($config['file_script'], $script);
}

function checkDNSBL(): void
{
    global $config;


    if (isIPv6()) {
        return;
    } // No IPv6 support yet.

    if (!isset($_SERVER['REMOTE_ADDR'])) {
        return;
    } // Fix your web server configuration

    if (in_array($_SERVER['REMOTE_ADDR'], $config['dnsbl_exceptions'])) {
        return;
    }

    $ipaddr = ReverseIPOctets($_SERVER['REMOTE_ADDR']);

    foreach ($config['dnsbl'] as $blacklist) {
        if (!is_array($blacklist)) {
            $blacklist = [$blacklist];
        }

        if (($lookup = str_replace('%', $ipaddr, $blacklist[0])) == $blacklist[0]) {
            $lookup = $ipaddr . '.' . $blacklist[0];
        }

        if (!$ip = DNS($lookup)) {
            continue;
        } // not in list

        $blacklist_name = isset($blacklist[2]) ? $blacklist[2] : $blacklist[0];

        if (!isset($blacklist[1])) {
            // If you're listed at all, you're blocked.
            error(sprintf($config['error']['dnsbl'], $blacklist_name));
        } elseif (is_array($blacklist[1])) {
            foreach ($blacklist[1] as $octet) {
                if ($ip == $octet || $ip == '127.0.0.' . $octet) {
                    error(sprintf($config['error']['dnsbl'], $blacklist_name));
                }
            }
        } elseif (is_callable($blacklist[1])) {
            if ($blacklist[1]($ip)) {
                error(sprintf($config['error']['dnsbl'], $blacklist_name));
            }
        } else {
            if ($ip == $blacklist[1] || $ip == '127.0.0.' . $blacklist[1]) {
                error(sprintf($config['error']['dnsbl'], $blacklist_name));
            }
        }
    }
}

function isIPv6(): bool
{
    return strstr($_SERVER['REMOTE_ADDR'], ':') !== false;
}

function ReverseIPOctets(string $ip): string
{
    return implode('.', array_reverse(explode('.', $ip)));
}

function wordfilters(string &$body): void
{
    global $config;

    foreach ($config['wordfilters'] as $filter) {
        if (isset($filter[2]) && $filter[2]) {
            if (is_callable($filter[1])) {
                $body = preg_replace_callback($filter[0], $filter[1], $body);
            } else {
                $body = preg_replace($filter[0], $filter[1], $body);
            }
        } else {
            $body = str_ireplace($filter[0], $filter[1], $body);
        }
    }
}

function quote(string $body, bool $quote = true): string
{
    global $config;

    $body = str_replace('<br/>', "\n", $body);

    $body = strip_tags($body);

    $body = preg_replace("/(^|\n)/", '$1&gt;', $body);

    $body .= "\n";

    if ($config['minify_html']) {
        $body = str_replace("\n", '&#010;', $body);
    }

    return $body;
}

function markup_url(array $matches): string
{
    global $config, $markup_urls;

    $url = $matches[1];
    $after = $matches[2];

    $markup_urls[] = $url;

    $link = (object) [
        'href' => $url,
        'text' => $url,
        'rel' => 'nofollow',
        'target' => '_blank',
    ];

    EventDispatcher::event('markup-url', $link);
    $link = (array) $link;

    $parts = [];
    foreach ($link as $attr => $value) {
        if ($attr == 'text' || $attr == 'after') {
            continue;
        }
        $parts[] = $attr . '="' . $value . '"';
    }
    if (isset($link['after'])) {
        $after = $link['after'] . $after;
    }
    return '<a ' . implode(' ', $parts) . '>' . $link['text'] . '</a>' . $after;
}

function unicodify(string $body): string
{
    $body = str_replace('...', '&hellip;', $body);
    $body = str_replace('&lt;--', '&larr;', $body);
    $body = str_replace('--&gt;', '&rarr;', $body);

    // En and em- dashes are rendered exactly the same in
    // most monospace fonts (they look the same in code
    // editors).
    $body = str_replace('---', '&mdash;', $body); // em dash
    $body = str_replace('--', '&ndash;', $body); // en dash

    return $body;
}

function extract_modifiers(string $body): array
{
    $modifiers = [];

    if (preg_match_all('@<tinyboard ([\w\s]+)>(.+?)</tinyboard>@us', $body, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            if (preg_match('/^escape /', $match[1])) {
                continue;
            }
            $modifiers[$match[1]] = html_entity_decode($match[2]);
        }
    }

    return $modifiers;
}

function markup(string &$body, bool $track_cites = false): array
{
    global $board, $config, $markup_urls;

    $modifiers = extract_modifiers($body);

    $body = preg_replace('@<tinyboard (?!escape )([\w\s]+)>(.+?)</tinyboard>@us', '', $body);
    $body = preg_replace('@<(tinyboard) escape ([\w\s]+)>@i', '<$1 $2>', $body);

    if (isset($modifiers['raw html']) && $modifiers['raw html'] == '1') {
        return [];
    }

    $body = str_replace("\r", '', $body);
    $body = utf8tohtml($body);

    if (mysql_version() < 50503) {
        $body = mb_encode_numericentity($body, [0x010000, 0xffffff, 0, 0xffffff], 'UTF-8');
    }

    foreach ($config['markup'] as $markup) {
        if (is_string($markup[1])) {
            $body = preg_replace($markup[0], $markup[1], $body);
        } elseif (is_callable($markup[1])) {
            $body = preg_replace_callback($markup[0], $markup[1], $body);
        }
    }

    if ($config['markup_urls']) {
        $markup_urls = [];

        $body = preg_replace_callback(
            '/((?:https?:\/\/|ftp:\/\/|irc:\/\/)[^\s<>()"]+?(?:\([^\s<>()"]*?\)[^\s<>()"]*?)*)((?:\s|<|>|"|\.||\]|!|\?|,|&#44;|&quot;)*(?:[\s<>()"]|$))/',
            'markup_url',
            $body,
            -1,
            $num_links,
        );

        if ($num_links > $config['max_links']) {
            error($config['error']['toomanylinks']);
        }
    }

    if ($config['markup_repair_tidy']) {
        $body = str_replace('  ', ' &nbsp;', $body);
    }

    if ($config['auto_unicode']) {
        $body = unicodify($body);

        if ($config['markup_urls']) {
            foreach ($markup_urls as &$url) {
                $body = str_replace(unicodify($url), $url, $body);
            }
        }
    }

    $tracked_cites = [];

    // Cites
    if (isset($board) && preg_match_all('/(^|\s)&gt;&gt;(\d+?)([\s,.)?]|$)/m', $body, $cites, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        if (count($cites[0]) > $config['max_cites']) {
            error($config['error']['toomanycites']);
        }

        $skip_chars = 0;
        $body_tmp = $body;

        $search_cites = [];
        foreach ($cites as $matches) {
            $search_cites[] = '`id` = ' . $matches[2][0];
        }
        $search_cites = array_unique($search_cites);

        $query = query(sprintf('SELECT `thread`, `id` FROM ``posts_%s`` WHERE ' .
            implode(' OR ', $search_cites), $board['uri'])) or error(db_error());

        $cited_posts = [];
        while ($cited = $query->fetch(\PDO::FETCH_ASSOC)) {
            $cited_posts[$cited['id']] = $cited['thread'] ? $cited['thread'] : false;
        }

        foreach ($cites as $matches) {
            $cite = $matches[2][0];

            // preg_match_all is not multibyte-safe
            foreach ($matches as &$match) {
                $match[1] = mb_strlen(substr($body_tmp, 0, $match[1]));
            }

            if (isset($cited_posts[$cite])) {
                $replacement = '<a onclick="highlightReply(\'' . $cite . '\');" href="' .
                    $config['root'] . $board['dir'] . $config['dir']['res'] .
                    ($cited_posts[$cite] ? $cited_posts[$cite] : $cite) . '.html#' . $cite . '">' .
                    '&gt;&gt;' . $cite .
                    '</a>';

                $body = mb_substr_replace($body, $matches[1][0] . $replacement . $matches[3][0], $matches[0][1] + $skip_chars, mb_strlen($matches[0][0]));
                $skip_chars += mb_strlen($matches[1][0] . $replacement . $matches[3][0]) - mb_strlen($matches[0][0]);

                if ($track_cites && $config['track_cites']) {
                    $tracked_cites[] = [$board['uri'], $cite];
                }
            }
        }
    }

    // Cross-board linking
    if (preg_match_all('/(^|\s)&gt;&gt;&gt;\/(' . $config['board_regex'] . 'f?)\/(\d+)?([\s,.)?]|$)/um', $body, $cites, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        if (count($cites[0]) > $config['max_cites']) {
            error($config['error']['toomanycross']);
        }

        $skip_chars = 0;
        $body_tmp = $body;

        if (isset($cited_posts)) {
            // Carry found posts from local board >>X links
            foreach ($cited_posts as $cite => $thread) {
                $cited_posts[$cite] = $config['root'] . $board['dir'] . $config['dir']['res'] .
                    ($thread ? $thread : $cite) . '.html#' . $cite;
            }

            $cited_posts = [
                $board['uri'] => $cited_posts,
            ];
        } else {
            $cited_posts = [];
        }

        $crossboard_indexes = [];
        $search_cites_boards = [];

        foreach ($cites as $matches) {
            $_board = $matches[2][0];
            $cite = @$matches[3][0];

            if (!isset($search_cites_boards[$_board])) {
                $search_cites_boards[$_board] = [];
            }
            $search_cites_boards[$_board][] = $cite;
        }

        $tmp_board = $board['uri'];

        foreach ($search_cites_boards as $_board => $search_cites) {
            $clauses = [];
            foreach ($search_cites as $cite) {
                if (!$cite || isset($cited_posts[$_board][$cite])) {
                    continue;
                }
                $clauses[] = '`id` = ' . $cite;
            }
            $clauses = array_unique($clauses);

            if ($board['uri'] != $_board) {
                if (!BoardService::openBoard($_board)) {
                    continue;
                } // Unknown board
            }

            if (!empty($clauses)) {
                $cited_posts[$_board] = [];

                $query = query(sprintf('SELECT `thread`, `id` FROM ``posts_%s`` WHERE ' .
                    implode(' OR ', $clauses), $board['uri'])) or error(db_error());

                while ($cite = $query->fetch(\PDO::FETCH_ASSOC)) {
                    $cited_posts[$_board][$cite['id']] = $config['root'] . $board['dir'] . $config['dir']['res'] .
                        ($cite['thread'] ? $cite['thread'] : $cite['id']) . '.html#' . $cite['id'];
                }
            }

            $crossboard_indexes[$_board] = $config['root'] . $board['dir'] . $config['file_index'];
        }

        // Restore old board
        if ($board['uri'] != $tmp_board) {
            BoardService::openBoard($tmp_board);
        }

        foreach ($cites as $matches) {
            $_board = $matches[2][0];
            $cite = @$matches[3][0];

            // preg_match_all is not multibyte-safe
            foreach ($matches as &$match) {
                $match[1] = mb_strlen(substr($body_tmp, 0, $match[1]));
            }

            if ($cite) {
                if (isset($cited_posts[$_board][$cite])) {
                    $link = $cited_posts[$_board][$cite];

                    $replacement = '<a ' .
                        ($_board == $board['uri'] ?
                            'onclick="highlightReply(\'' . $cite . '\');" '
                        : '') . 'href="' . $link . '">' .
                        '&gt;&gt;&gt;/' . $_board . '/' . $cite .
                        '</a>';

                    $body = mb_substr_replace($body, $matches[1][0] . $replacement . $matches[4][0], $matches[0][1] + $skip_chars, mb_strlen($matches[0][0]));
                    $skip_chars += mb_strlen($matches[1][0] . $replacement . $matches[4][0]) - mb_strlen($matches[0][0]);

                    if ($track_cites && $config['track_cites']) {
                        $tracked_cites[] = [$_board, $cite];
                    }
                }
            } elseif (isset($crossboard_indexes[$_board])) {
                $replacement = '<a href="' . $crossboard_indexes[$_board] . '">' .
                        '&gt;&gt;&gt;/' . $_board . '/' .
                        '</a>';
                $body = mb_substr_replace($body, $matches[1][0] . $replacement . $matches[4][0], $matches[0][1] + $skip_chars, mb_strlen($matches[0][0]));
                $skip_chars += mb_strlen($matches[1][0] . $replacement . $matches[4][0]) - mb_strlen($matches[0][0]);
            }
        }
    }

    $tracked_cites = array_unique($tracked_cites, SORT_REGULAR);

    $body = preg_replace("/^\s*&gt;.*$/m", '<span class="quote">$0</span>', $body);

    if ($config['strip_superfluous_returns']) {
        $body = preg_replace('/\s+$/', '', $body);
    }

    $body = preg_replace("/\n/", '<br/>', $body);

    if ($config['markup_repair_tidy']) {
        $tidy = new tidy();
        $body = str_replace("\t", '&#09;', $body);
        $body = $tidy->repairString($body, [
            'doctype' => 'omit',
            'bare' => true,
            'literal-attributes' => true,
            'indent' => false,
            'show-body-only' => true,
            'wrap' => 0,
            'output-bom' => false,
            'output-html' => true,
            'newline' => 'LF',
            'quiet' => true,
        ], 'utf8');
        $body = str_replace("\n", '', $body);
    }

    // replace tabs with 8 spaces
    $body = str_replace("\t", '        ', $body);

    return $tracked_cites;
}

function escape_markup_modifiers(string $string): string
{
    return preg_replace('@<(tinyboard) ([\w\s]+)>@mi', '<$1 escape $2>', $string);
}

function utf8tohtml(string $utf8): string
{
    return htmlspecialchars($utf8, ENT_NOQUOTES, 'UTF-8');
}

function ordutf8(string $string, int &$offset): int
{
    $code = ord(substr($string, $offset, 1));
    if ($code >= 128) { // otherwise 0xxxxxxx
        if ($code < 224) {
            $bytesnumber = 2;
        } // 110xxxxx
        elseif ($code < 240) {
            $bytesnumber = 3;
        } // 1110xxxx
        elseif ($code < 248) {
            $bytesnumber = 4;
        } // 11110xxx
        $codetemp = $code - 192 - ($bytesnumber > 2 ? 32 : 0) - ($bytesnumber > 3 ? 16 : 0);
        for ($i = 2; $i <= $bytesnumber; $i++) {
            $offset++;
            $code2 = ord(substr($string, $offset, 1)) - 128; //10xxxxxx
            $codetemp = $codetemp * 64 + $code2;
        }
        $code = $codetemp;
    }
    $offset += 1;
    if ($offset >= strlen($string)) {
        $offset = -1;
    }
    return $code;
}

function strip_combining_chars(string $str): string
{
    $chars = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);
    $str = '';
    foreach ($chars as $char) {
        $o = 0;
        $ord = ordutf8($char, $o);

        if ($ord >= 768 && $ord <= 879) {
            continue;
        }

        if ($ord >= 7616 && $ord <= 7679) {
            continue;
        }

        if ($ord >= 8400 && $ord <= 8447) {
            continue;
        }

        if ($ord >= 65056 && $ord <= 65071) {
            continue;
        }

        $str .= $char;
    }
    return $str;
}

function buildThread(int $id, bool $return = false, array|bool $mod = false): ?string
{
    global $board, $config, $build_pages;
    $id = round($id);

    if (EventDispatcher::event('build-thread', $id)) {
        return null;
    }

    if ($config['cache']['enabled'] && !$mod) {
        // Clear cache
        Cache::delete("thread_index_{$board['uri']}_{$id}");
        Cache::delete("thread_{$board['uri']}_{$id}");
    }

    $query = prepare(sprintf("SELECT * FROM ``posts_%s`` WHERE (`thread` IS NULL AND `id` = :id) OR `thread` = :id ORDER BY `thread`,`id`", $board['uri']));
    $query->bindValue(':id', $id, \PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    while ($post = $query->fetch(\PDO::FETCH_ASSOC)) {
        if (!isset($thread)) {
            $thread = new Thread($post, $mod ? '?/' : $config['root'], $mod);
        } else {
            $thread->add(new Post($post, $mod ? '?/' : $config['root'], $mod));
        }
    }

    // Check if any posts were found
    if (!isset($thread)) {
        error($config['error']['nonexistant']);
    }

    $body = element('thread.html', [
        'board' => $board,
        'thread' => $thread,
        'body' => $thread->build(),
        'config' => $config,
        'id' => $id,
        'mod' => $mod,
        'antibot' => $mod || $return ? false : create_antibot($board['uri'], $id),
        'boardlist' => BoardService::createBoardlist($mod),
        'return' => ($mod ? '?' . $board['url'] . $config['file_index'] : $config['root'] . $board['dir'] . $config['file_index']),
    ]);

    if ($config['try_smarter'] && !$mod) {
        $build_pages[] = thread_find_page($id);
    }

    if ($return) {
        return $body;
    }

    file_write($board['dir'] . $config['dir']['res'] . sprintf($config['file_page'], $id), $body);

    // json api
    if ($config['api']['enabled']) {
        $api = new Api();
        $json = json_encode($api->translateThread($thread));
        $jsonFilename = $board['dir'] . $config['dir']['res'] . $id . '.json';
        file_write($jsonFilename, $json);
    }

    return null;
}

function rrmdir(string $dir): void
{
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (filetype($dir . "/" . $object) == "dir") {
                    rrmdir($dir . "/" . $object);
                } else {
                    file_unlink($dir . "/" . $object);
                }
            }
        }
        reset($objects);
        rmdir($dir);
    }
}

function poster_id(string $ip, int $thread): string
{
    global $config;

    if ($id = EventDispatcher::event('poster-id', $ip, $thread)) {
        return $id;
    }

    // Confusing, hard to brute-force, but simple algorithm
    return substr(sha1(sha1($ip . $config['secure_trip_salt'] . $thread) . $config['secure_trip_salt']), 0, $config['poster_id_length']);
}

function generate_tripcode(string $name): array
{
    global $config;

    if ($trip = EventDispatcher::event('tripcode', $name)) {
        return $trip;
    }

    if (!preg_match('/^([^#]+)?(##|#)(.+)$/', $name, $match)) {
        return [$name];
    }

    $name = $match[1];
    $secure = $match[2] == '##';
    $trip = $match[3];

    // convert to SHIT_JIS encoding
    $trip = mb_convert_encoding($trip, 'Shift_JIS', 'UTF-8');

    // generate salt
    $salt = substr($trip . 'H..', 1, 2);
    $salt = preg_replace('/[^.-z]/', '.', $salt);
    $salt = strtr($salt, ':;<=>?@[\]^_`', 'ABCDEFGabcdef');

    if ($secure) {
        if (isset($config['custom_tripcode']["##{$trip}"])) {
            $trip = $config['custom_tripcode']["##{$trip}"];
        } else {
            $trip = '!!' . substr(crypt($trip, '_..A.' . substr(base64_encode(sha1($trip . $config['secure_trip_salt'], true)), 0, 4)), -10);
        }
    } else {
        if (isset($config['custom_tripcode']["#{$trip}"])) {
            $trip = $config['custom_tripcode']["#{$trip}"];
        } else {
            $trip = '!' . substr(crypt($trip, $salt), -10);
        }
    }

    return [$name, $trip];
}

// Highest common factor
function hcf(int $a, int $b): int
{
    $gcd = 1;
    if ($a > $b) {
        $a = $a + $b;
        $b = $a - $b;
        $a = $a - $b;
    }
    if ($b == (round($b / $a)) * $a) {
        $gcd = $a;
    } else {
        for ($i = round($a / 2);$i;$i--) {
            if ($a == round($a / $i) * $i && $b == round($b / $i) * $i) {
                $gcd = $i;
                $i = false;
            }
        }
    }
    return $gcd;
}

function fraction(int $numerator, int $denominator, string $sep): string
{
    $gcf = hcf($numerator, $denominator);
    $numerator = $numerator / $gcf;
    $denominator = $denominator / $gcf;

    return "{$numerator}{$sep}{$denominator}";
}

function getPostByHash(string $hash): array|false
{
    global $board;
    $query = prepare(sprintf("SELECT `id`,`thread` FROM ``posts_%s`` WHERE `filehash` = :hash", $board['uri']));
    $query->bindValue(':hash', $hash, \PDO::PARAM_STR);
    $query->execute() or error(db_error($query));

    if ($post = $query->fetch(\PDO::FETCH_ASSOC)) {
        return $post;
    }

    return false;
}

function getPostByHashInThread(string $hash, int $thread): array|false
{
    global $board;
    $query = prepare(sprintf("SELECT `id`,`thread` FROM ``posts_%s`` WHERE `filehash` = :hash AND ( `thread` = :thread OR `id` = :thread )", $board['uri']));
    $query->bindValue(':hash', $hash, \PDO::PARAM_STR);
    $query->bindValue(':thread', $thread, \PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    if ($post = $query->fetch(\PDO::FETCH_ASSOC)) {
        return $post;
    }

    return false;
}

function undoImage(array $post): void
{
    if (!$post['has_file']) {
        return;
    }

    if (isset($post['file_path'])) {
        file_unlink($post['file_path']);
    }
    if (isset($post['thumb_path'])) {
        file_unlink($post['thumb_path']);
    }
}

function rDNS(string $ip_addr): string
{
    global $config;

    if ($config['cache']['enabled'] && ($host = Cache::get('rdns_' . $ip_addr))) {
        return $host;
    }

    if (!$config['dns_system']) {
        $host = gethostbyaddr($ip_addr);
    } else {
        $resp = shell_exec_error('host -W 1 ' . $ip_addr);
        if (preg_match('/domain name pointer ([^\s]+)$/', $resp, $m)) {
            $host = $m[1];
        } else {
            $host = $ip_addr;
        }
    }

    if ($config['cache']['enabled']) {
        Cache::set('rdns_' . $ip_addr, $host);
    }

    return $host;
}

function DNS(string $host): string|false
{
    global $config;

    if ($config['cache']['enabled'] && ($ip_addr = Cache::get('dns_' . $host))) {
        return $ip_addr != '?' ? $ip_addr : false;
    }

    if (!$config['dns_system']) {
        $ip_addr = gethostbyname($host);
        if ($ip_addr == $host) {
            $ip_addr = false;
        }
    } else {
        $resp = shell_exec_error('host -W 1 ' . $host);
        if (preg_match('/has address ([^\s]+)$/', $resp, $m)) {
            $ip_addr = $m[1];
        } else {
            $ip_addr = false;
        }
    }

    if ($config['cache']['enabled']) {
        Cache::set('dns_' . $host, $ip_addr !== false ? $ip_addr : '?');
    }

    return $ip_addr;
}

function shell_exec_error(string $command, bool $suppress_stdout = false): string|false
{
    global $config, $debug;

    if ($config['debug']) {
        $start = microtime(true);
    }

    $return = trim(shell_exec('PATH="' . escapeshellcmd($config['shell_path']) . ':$PATH";' .
        $command . ' 2>&1 ' . ($suppress_stdout ? '> /dev/null ' : '') . '&& echo "TB_SUCCESS"'));
    $return = preg_replace('/TB_SUCCESS$/', '', $return);

    if ($config['debug']) {
        $time = microtime(true) - $start;
        $debug['exec'][] = [
            'command' => $command,
            'time' => '~' . round($time * 1000, 2) . 'ms',
            'response' => $return ? $return : null,
        ];
        $debug['time']['exec'] += $time;
    }

    return $return === 'TB_SUCCESS' ? false : $return;
}

/*
    joaoptm78@gmail.com
    http://www.php.net/manual/en/function.filesize.php#100097
*/
function format_bytes(int|float $size): string
{
    $units = [' B', ' KB', ' MB', ' GB', ' TB'];
    for ($i = 0; $size >= 1024 && $i < 4; $i++) {
        $size /= 1024;
    }
    return round($size, 2) . $units[$i];
}

function error(string $message, bool|int $priority = true, mixed $debug_stuff = false): never
{
    global $board, $mod, $config, $db_error;

    if ($config['syslog'] && $priority !== false) {
        // Use LOG_NOTICE instead of LOG_ERR or LOG_WARNING because most error message are not significant.
        ErrorHandler::_syslog($priority !== true ? $priority : LOG_NOTICE, $message);
    }

    if (defined('STDIN')) {
        // Running from CLI
        die('Error: ' . $message . "\n");
    }

    if ($config['debug'] && isset($db_error)) {
        $debug_stuff = array_combine(['SQLSTATE', 'Error code', 'Error message'], $db_error);
    }

    // Is there a reason to disable this?
    if (isset($_POST['json_response'])) {
        header('Content-Type: text/json; charset=utf-8');
        die(json_encode([
            'error' => $message,
        ]));
    }

    die(element('page.html', [
        'config' => $config,
        'title' => _('Error'),
        'subtitle' => _('An error has occured.'),
        'body' => element('error.html', [
            'config' => $config,
            'message' => $message,
            'mod' => $mod,
            'board' => isset($board) ? $board : false,
            'debug' => is_array($debug_stuff) ? str_replace("\n", '&#10;', utf8tohtml(print_r($debug_stuff, true))) : utf8tohtml($debug_stuff),
        ]),
    ]));
}

function pm_snippet(string $body, ?int $len = null): string
{
    global $config;

    if (!isset($len)) {
        $len = &$config['mod']['snippet_length'];
    }

    // Replace line breaks with some whitespace
    $body = preg_replace('@<br/?>@i', '  ', $body);

    // Strip tags
    $body = strip_tags($body);

    // Unescape HTML characters, to avoid splitting them in half
    $body = html_entity_decode($body, ENT_COMPAT, 'UTF-8');

    // calculate strlen() so we can add "..." after if needed
    $strlen = mb_strlen($body);

    $body = mb_substr($body, 0, $len);

    // Re-escape the characters.
    return '<em>' . utf8tohtml($body) . ($strlen > $len ? '&hellip;' : '') . '</em>';
}

function capcode(string|false $cap): array|false
{
    global $config;

    if (!$cap) {
        return false;
    }

    $capcode = [];
    if (isset($config['custom_capcode'][$cap])) {
        if (is_array($config['custom_capcode'][$cap])) {
            $capcode['cap'] = sprintf($config['custom_capcode'][$cap][0], $cap);
            if (isset($config['custom_capcode'][$cap][1])) {
                $capcode['name'] = $config['custom_capcode'][$cap][1];
            }
            if (isset($config['custom_capcode'][$cap][2])) {
                $capcode['trip'] = $config['custom_capcode'][$cap][2];
            }
        } else {
            $capcode['cap'] = sprintf($config['custom_capcode'][$cap], $cap);
        }
    } else {
        $capcode['cap'] = sprintf($config['capcode'], $cap);
    }

    return $capcode;
}

function truncate(string $body, string $url, int|false $max_lines = false, int|false $max_chars = false): string
{
    global $config;

    if ($max_lines === false) {
        $max_lines = $config['body_truncate'];
    }
    if ($max_chars === false) {
        $max_chars = $config['body_truncate_char'];
    }

    // We don't want to risk truncating in the middle of an HTML comment.
    // It's easiest just to remove them all first.
    $body = preg_replace('/<!--.*?-->/s', '', $body);

    $original_body = $body;

    $lines = substr_count($body, '<br/>');

    // Limit line count
    if ($lines > $max_lines) {
        if (preg_match('/(((.*?)<br\/>){' . $max_lines . '})/', $body, $m)) {
            $body = $m[0];
        }
    }

    $body = mb_substr($body, 0, $max_chars);

    if ($body != $original_body) {
        // Remove any corrupt tags at the end
        $body = preg_replace('/<([\w]+)?([^>]*)?$/', '', $body);

        // Open tags
        if (preg_match_all('/<([\w]+)[^>]*>/', $body, $open_tags)) {

            $tags = [];
            for ($x = 0;$x < count($open_tags[0]);$x++) {
                if (!preg_match('/\/(\s+)?>$/', $open_tags[0][$x])) {
                    $tags[] = $open_tags[1][$x];
                }
            }

            // List successfully closed tags
            if (preg_match_all('/(<\/([\w]+))>/', $body, $closed_tags)) {
                for ($x = 0;$x < count($closed_tags[0]);$x++) {
                    unset($tags[array_search($closed_tags[2][$x], $tags)]);
                }
            }

            // remove broken HTML entity at the end (if existent)
            $body = preg_replace('/&[^;]+$/', '', $body);

            $tags_no_close_needed = ["colgroup", "dd", "dt", "li", "optgroup", "option", "p", "tbody", "td", "tfoot", "th", "thead", "tr", "br", "img"];

            // Close any open tags
            foreach ($tags as &$tag) {
                if (!in_array($tag, $tags_no_close_needed)) {
                    $body .= "</{$tag}>";
                }
            }
        } else {
            // remove broken HTML entity at the end (if existent)
            $body = preg_replace('/&[^;]*$/', '', $body);
        }

        $body .= '<span class="toolong">' . sprintf(_('Post too long. Click <a href="%s">here</a> to view the full text.'), $url) . '</span>';
    }

    return $body;
}

function bidi_cleanup(string $data): string
{
    // Closes all embedded RTL and LTR unicode formatting blocks in a string so that
    // it can be used inside another without controlling its direction.

    $explicits	= '\xE2\x80\xAA|\xE2\x80\xAB|\xE2\x80\xAD|\xE2\x80\xAE';
    $pdf		= '\xE2\x80\xAC';

    preg_match_all("!$explicits!", $data, $m1, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
    preg_match_all("!$pdf!", $data, $m2, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

    if (count($m1) || count($m2)) {

        $p = [];
        foreach ($m1 as $m) {
            $p[$m[0][1]] = 'push';
        }
        foreach ($m2 as $m) {
            $p[$m[0][1]] = 'pop';
        }
        ksort($p);

        $offset = 0;
        $stack = 0;
        foreach ($p as $pos => $type) {

            if ($type == 'push') {
                $stack++;
            } else {
                if ($stack) {
                    $stack--;
                } else {
                    # we have a pop without a push - remove it
                    $data = substr($data, 0, $pos - $offset)
                        . substr($data, $pos + 3 - $offset);
                    $offset += 3;
                }
            }
        }

        # now add some pops if your stack is bigger than 0
        for ($i = 0; $i < $stack; $i++) {
            $data .= "\xE2\x80\xAC";
        }

        return $data;
    }

    return $data;
}

function secure_link_confirm(string $text, string $title, string $confirm_message, string $href): string
{
    global $config;

    return '<a onclick="if (event.which==2) return true;if (confirm(\'' . htmlentities(addslashes($confirm_message)) . '\')) document.location=\'?/' . htmlspecialchars(addslashes($href . '/' . Auth::make_secure_link_token($href))) . '\';return false;" title="' . htmlentities($title) . '" href="?/' . $href . '">' . $text . '</a>';
}

function secure_link(string $href): string
{
    return $href . '/' . Auth::make_secure_link_token($href);
}

function embed_html(string $link): string
{
    global $config;

    foreach ($config['embedding'] as $embed) {
        if ($html = preg_replace($embed[0], $embed[1], $link)) {
            if ($html == $link) {
                continue;
            } // Nope

            $html = str_replace('%%tb_width%%', $config['embed_width'], $html);
            $html = str_replace('%%tb_height%%', $config['embed_height'], $html);

            return $html;
        }
    }

    if ($link[0] == '<') {
        // Prior to v0.9.6-dev-8, HTML code for embedding was stored in the database instead of the link.
        return $link;
    }

    return 'Embedding error.';
}

function mod_page(string $title, string $template, array $args, string|false $subtitle = false): void
{
    global $config, $mod;

    echo element(
        'page.html',
        [
            'config' => $config,
            'mod' => $mod,
            'hide_dashboard_link' => $template == 'mod/dashboard.html',
            'title' => $title,
            'subtitle' => $subtitle,
            'nojavascript' => true,
            'body' => element(
                $template,
                array_merge(
                    ['config' => $config, 'mod' => $mod],
                    $args,
                ),
            ),
        ],
    );
}

function checkSpam(array $extra_salt = []): bool|string
{
    global $config, $pdo;

    if (!isset($_POST['hash'])) {
        return true;
    }

    $hash = $_POST['hash'];

    if (!empty($extra_salt)) {
        // create a salted hash of the "extra salt"
        $extra_salt = implode(':', $extra_salt);
    } else {
        $extra_salt = '';
    }

    // Reconstruct the $inputs array
    $inputs = [];

    foreach ($_POST as $name => $value) {
        if (in_array($name, $config['spam']['valid_inputs'])) {
            continue;
        }
        $inputs[$name] = $value;
    }

    // Sort the inputs in alphabetical order (A-Z)
    ksort($inputs);

    $_hash = '';
    // Iterate through each input
    foreach ($inputs as $name => $value) {
        $_hash .= $name . '=' . $value;
    }

    // Add a salt to the hash
    $_hash .= $config['cookies']['salt'];
    // Use SHA1 for the hash
    $_hash = sha1($_hash . $extra_salt);

    if ($hash != $_hash) {
        return true;
    }

    $query = prepare('SELECT `passed` FROM ``antispam`` WHERE `hash` = :hash');
    $query->bindValue(':hash', $hash);
    $query->execute() or error(db_error($query));
    $passed = $query->fetchColumn(0);

    if ($passed === false || $passed > $config['spam']['hidden_inputs_max_pass']) {
        // there was no database entry for this hash. most likely expired.
        return true;
    }

    return $hash;
}

function incrementSpamHash(string $hash): void
{
    $query = prepare('UPDATE ``antispam`` SET `passed` = `passed` + 1 WHERE `hash` = :hash');
    $query->bindValue(':hash', $hash);
    $query->execute() or error(db_error($query));
}

function purge_flood_table(): void
{
    global $config;

    // Determine how long we need to keep a cache of posts for flood prevention. Unfortunately, it is not
    // aware of flood filters in other board configurations. You can solve this problem by settings the
    // config variable $config['flood_cache'] (seconds).

    if (isset($config['flood_cache'])) {
        $max_time = &$config['flood_cache'];
    } else {
        $max_time = 0;
        foreach ($config['filters'] as $filter) {
            if (isset($filter['condition']['flood-time'])) {
                $max_time = max($max_time, $filter['condition']['flood-time']);
            }
        }
    }

    $time = time() - $max_time;

    query("DELETE FROM ``flood`` WHERE `time` < $time") or error(db_error());
}

function do_filters(array $post): void
{
    global $config;

    if (!isset($config['filters']) || empty($config['filters'])) {
        return;
    }

    $has_flood = false;
    foreach ($config['filters'] as $filter) {
        if (isset($filter['condition']['flood-match'])) {
            $has_flood = true;
            break;
        }
    }

    if ($has_flood) {
        if ($post['has_file']) {
            $query = prepare("SELECT * FROM ``flood`` WHERE `ip` = :ip OR `posthash` = :posthash OR `filehash` = :filehash");
            $query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
            $query->bindValue(':posthash', make_comment_hex($post['body_nomarkup']));
            $query->bindValue(':filehash', $post['filehash']);
        } else {
            $query = prepare("SELECT * FROM ``flood`` WHERE `ip` = :ip OR `posthash` = :posthash");
            $query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
            $query->bindValue(':posthash', make_comment_hex($post['body_nomarkup']));
        }
        $query->execute() or error(db_error($query));
        $flood_check = $query->fetchAll(\PDO::FETCH_ASSOC);
    } else {
        $flood_check = false;
    }

    foreach ($config['filters'] as $filter_array) {
        $filter = new Filter($filter_array);
        $filter->flood_check = $flood_check;
        if ($filter->check($post)) {
            $filter->action();
        }
    }

    purge_flood_table();
}

function mod_confirm(string $request): void
{
    mod_page(_('Confirm action'), 'mod/confirm.html', ['request' => $request, 'token' => Auth::make_secure_link_token($request)]);
}
