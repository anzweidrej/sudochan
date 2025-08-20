<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

if (realpath($_SERVER['SCRIPT_FILENAME']) == str_replace('\\', '/', __FILE__)) {
    // You cannot request this file directly.
    exit;
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

function doBoardListPart(array $list, string $root): string
{
    global $config;

    $body = '';
    // Build board list
    foreach ($list as $key => $board) {
        if (is_array($board)) {
            $body .= ' <span class="sub">[' . doBoardListPart($board, $root) . ']</span> ';
        } else {
            if (is_string($key)) {
                $body .= ' <a href="' . $board . '">' . $key . '</a> /';
            } else {
                $body .= ' <a href="' . $root . $board . '/' . $config['file_index'] . '">' . $board . '</a> /';
            }
        }
    }
    $body = preg_replace('/\/$/', '', $body);

    return $body;
}

function createBoardlist(bool|array $mod = false): array
{
    global $config;

    // Get boards from database
    $boards = listBoards();

    // Use config boards if set and not empty, else use database boards
    if (!empty($config['boards']) && is_array($config['boards'])) {
        $board_list = $config['boards'];
    } else {
        $board_list = [];
        foreach ($boards as $b) {
            $board_list[] = $b['uri'];
        }
    }

    // If no boards available, return empty strings
    if (empty($board_list)) {
        return ['top' => '', 'bottom' => ''];
    }

    $body = doBoardListPart($board_list, $mod ? '?/' : $config['root']);
    if ($config['boardlist_wrap_bracket'] && !preg_match('/\] $/', $body)) {
        $body = '[' . $body . ']';
    }

    $body = trim($body);

    return [
        'top' => '<div class="boardlist">' . $body . '</div>',
        'bottom' => '<div class="boardlist bottom">' . $body . '</div>',
    ];
}

function error(string $message, bool|int $priority = true, mixed $debug_stuff = false): never
{
    global $board, $mod, $config, $db_error;

    if ($config['syslog'] && $priority !== false) {
        // Use LOG_NOTICE instead of LOG_ERR or LOG_WARNING because most error message are not significant.
        _syslog($priority !== true ? $priority : LOG_NOTICE, $message);
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

function loginForm(string|false $error = false, string|false $username = false, string|false $redirect = false): never
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

    return '<a onclick="if (event.which==2) return true;if (confirm(\'' . htmlentities(addslashes($confirm_message)) . '\')) document.location=\'?/' . htmlspecialchars(addslashes($href . '/' . make_secure_link_token($href))) . '\';return false;" title="' . htmlentities($title) . '" href="?/' . $href . '">' . $text . '</a>';
}

function secure_link(string $href): string
{
    return $href . '/' . make_secure_link_token($href);
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
