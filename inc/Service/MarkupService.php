<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Service;

use Sudochan\Dispatcher\EventDispatcher;
use Sudochan\Utils\TextFormatter;
use Sudochan\Utils\StringFormatter;
use Sudochan\Utils\Sanitize;

class MarkupService
{
    public static function markup_url(array $matches): string
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

    public static function markup(string &$body, bool $track_cites = false): array
    {
        global $board, $config, $markup_urls;

        $modifiers = Sanitize::extract_modifiers($body);

        $body = preg_replace('@<tinyboard (?!escape )([\w\s]+)>(.+?)</tinyboard>@us', '', $body);
        $body = preg_replace('@<(tinyboard) escape ([\w\s]+)>@i', '<$1 $2>', $body);

        if (isset($modifiers['raw html']) && $modifiers['raw html'] == '1') {
            return [];
        }

        $body = str_replace("\r", '', $body);
        $body = StringFormatter::utf8tohtml($body);

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
                [self::class, 'markup_url'],
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
            $body = StringFormatter::unicodify($body);

            if ($config['markup_urls']) {
                foreach ($markup_urls as &$url) {
                    $body = str_replace(StringFormatter::unicodify($url), $url, $body);
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

            $query = query(sprintf('SELECT `thread`, `id` FROM ``posts_%s`` WHERE '
                . implode(' OR ', $search_cites), $board['uri'])) or error(db_error());

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
                    $replacement = '<a onclick="highlightReply(\'' . $cite . '\');" href="'
                        . $config['root'] . $board['dir'] . $config['dir']['res']
                        . ($cited_posts[$cite] ? $cited_posts[$cite] : $cite) . '.html#' . $cite . '">'
                        . '&gt;&gt;' . $cite
                        . '</a>';

                    $body = self::mb_substr_replace($body, $matches[1][0] . $replacement . $matches[3][0], $matches[0][1] + $skip_chars, mb_strlen($matches[0][0]));
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
                    $cited_posts[$cite] = $config['root'] . $board['dir'] . $config['dir']['res']
                        . ($thread ? $thread : $cite) . '.html#' . $cite;
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

                    $query = query(sprintf('SELECT `thread`, `id` FROM ``posts_%s`` WHERE '
                        . implode(' OR ', $clauses), $board['uri'])) or error(db_error());

                    while ($cite = $query->fetch(\PDO::FETCH_ASSOC)) {
                        $cited_posts[$_board][$cite['id']] = $config['root'] . $board['dir'] . $config['dir']['res']
                            . ($cite['thread'] ? $cite['thread'] : $cite['id']) . '.html#' . $cite['id'];
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

                        $replacement = '<a '
                            . ($_board == $board['uri']
                                ? 'onclick="highlightReply(\'' . $cite . '\');" '
                            : '') . 'href="' . $link . '">'
                            . '&gt;&gt;&gt;/' . $_board . '/' . $cite
                            . '</a>';

                        $body = self::mb_substr_replace($body, $matches[1][0] . $replacement . $matches[4][0], $matches[0][1] + $skip_chars, mb_strlen($matches[0][0]));
                        $skip_chars += mb_strlen($matches[1][0] . $replacement . $matches[4][0]) - mb_strlen($matches[0][0]);

                        if ($track_cites && $config['track_cites']) {
                            $tracked_cites[] = [$_board, $cite];
                        }
                    }
                } elseif (isset($crossboard_indexes[$_board])) {
                    $replacement = '<a href="' . $crossboard_indexes[$_board] . '">'
                            . '&gt;&gt;&gt;/' . $_board . '/'
                            . '</a>';
                    $body = self::mb_substr_replace($body, $matches[1][0] . $replacement . $matches[4][0], $matches[0][1] + $skip_chars, mb_strlen($matches[0][0]));
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
            $tidy = new \tidy();
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

    public static function mb_substr_replace(string $string, string $replacement, int $start, int $length): string
    {
        return mb_substr($string, 0, $start) . $replacement . mb_substr($string, $start + $length);
    }
}
