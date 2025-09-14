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
use Sudochan\Dispatcher\EventDispatcher;
use Sudochan\Filter;
use Sudochan\Manager\AuthManager;
use Sudochan\Remote;
use Sudochan\Entity\Post;
use Sudochan\Entity\Thread;
use Sudochan\Handler\ErrorHandler;
use Sudochan\Manager\FileManager;

function create_antibot(string $board, ?int $thread = null): object
{
    require_once __DIR__ . '/../inc/AntiBot.php';

    return \Sudochan\_create_antibot($board, $thread);
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

// Returns an associative array with 'replies' and 'images' keys
function numPosts(int $id): array
{
    global $board;
    $query = prepare(sprintf("SELECT COUNT(*) AS `replies`, COUNT(NULLIF(`file`, 0)) AS `images` FROM ``posts_%s`` WHERE `thread` = :thread", $board['uri']));
    $query->bindValue(':thread', $id, \PDO::PARAM_INT);
    $query->execute() or error(db_error($query));

    return $query->fetch(\PDO::FETCH_ASSOC);
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

/**
 * joaoptm78@gmail.com
 * http://www.php.net/manual/en/function.filesize.php#100097
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

    return '<a onclick="if (event.which==2) return true;if (confirm(\'' . htmlentities(addslashes($confirm_message)) . '\')) document.location=\'?/' . htmlspecialchars(addslashes($href . '/' . AuthManager::make_secure_link_token($href))) . '\';return false;" title="' . htmlentities($title) . '" href="?/' . $href . '">' . $text . '</a>';
}

function secure_link(string $href): string
{
    return $href . '/' . AuthManager::make_secure_link_token($href);
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
    mod_page(_('Confirm action'), 'mod/confirm.html', ['request' => $request, 'token' => AuthManager::make_secure_link_token($request)]);
}
