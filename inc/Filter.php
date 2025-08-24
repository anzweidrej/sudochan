<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan;

defined('TINYBOARD') or exit;

use Sudochan\Bans;

class Filter
{
    public array|false|null $flood_check = null;
    private array $condition;
    public ?string $action = null;
    public ?string $reason = null;
    public int|false|null $expires = null;
    public bool $reject = true;
    public bool $all_boards = false;
    public ?string $message = null;

    public function __construct(array $arr)
    {
        foreach ($arr as $key => $value) {
            $this->$key = $value;
        }
    }

    public function match(array $post, string $condition, mixed $match): bool|int
    {
        $condition = strtolower($condition);

        switch ($condition) {
            case 'custom':
                if (!is_callable($match)) {
                    error('Custom condition for filter is not callable!');
                }
                return $match($post);

            case 'flood-match':
                if (!is_array($match)) {
                    error('Filter condition "flood-match" must be an array.');
                }

                // Filter out "flood" table entries which do not match this filter.

                $flood_check_matched = [];

                foreach ($this->flood_check as $flood_post) {
                    foreach ($match as $flood_match_arg) {
                        switch ($flood_match_arg) {
                            case 'ip':
                                if ($flood_post['ip'] != $_SERVER['REMOTE_ADDR']) {
                                    continue 3;
                                }
                                break;
                            case 'body':
                                if ($flood_post['posthash'] != make_comment_hex($post['body_nomarkup'])) {
                                    continue 3;
                                }
                                break;
                            case 'file':
                                if (!isset($post['filehash'])) {
                                    return false;
                                }
                                if ($flood_post['filehash'] != $post['filehash']) {
                                    continue 3;
                                }
                                break;
                            case 'board':
                                if ($flood_post['board'] != $post['board']) {
                                    continue 3;
                                }
                                break;
                            case 'isreply':
                                if ($flood_post['isreply'] == $post['op']) {
                                    continue 3;
                                }
                                break;
                            default:
                                error('Invalid filter flood condition: ' . $flood_match_arg);
                        }
                    }
                    $flood_check_matched[] = $flood_post;
                }

                $this->flood_check = $flood_check_matched;

                return !empty($this->flood_check);

            case 'flood-time':
                foreach ($this->flood_check as $flood_post) {
                    if (time() - $flood_post['time'] <= $match) {
                        return true;
                    }
                }
                return false;

            case 'flood-count':
                $count = 0;
                foreach ($this->flood_check as $flood_post) {
                    if (time() - $flood_post['time'] <= $this->condition['flood-time']) {
                        ++$count;
                    }
                }
                return $count >= $match;

            case 'name':
                return preg_match($match, $post['name']);

            case 'trip':
                return $match === $post['trip'];

            case 'email':
                return preg_match($match, $post['email']);

            case 'subject':
                return preg_match($match, $post['subject']);

            case 'body':
                return preg_match($match, $post['body_nomarkup']);

            case 'filehash':
                return $match === $post['filehash'];

            case 'filename':
                if (!$post['has_file']) {
                    return false;
                }
                return preg_match($match, $post['filename']);

            case 'extension':
                if (!$post['has_file']) {
                    return false;
                }
                return preg_match($match, $post['body']);

            case 'ip':
                return preg_match($match, $_SERVER['REMOTE_ADDR']);

            case 'op':
                return $post['op'] == $match;

            case 'has_file':
                return $post['has_file'] == $match;

            default:
                error('Unknown filter condition: ' . $condition);
        }
    }

    public function action(): void
    {
        global $board;

        switch ($this->action) {
            case 'reject':
                error(isset($this->message) ? $this->message : 'Posting throttled by filter.');
                // no break
            case 'ban':
                if (!isset($this->reason)) {
                    error('The ban action requires a reason.');
                }

                $this->expires = isset($this->expires) ? $this->expires : false;
                $this->reject = isset($this->reject) ? $this->reject : true;
                $this->all_boards = isset($this->all_boards) ? $this->all_boards : false;

                Bans::new_ban(
                    $_SERVER['REMOTE_ADDR'],
                    $this->reason,
                    $this->expires,
                    $this->all_boards ? false : $board['uri'],
                    -1,
                );

                if ($this->reject) {
                    if (isset($this->message)) {
                        error($this->message);
                    }
                    checkBan($board['uri']);
                    exit;
                }
                break;
            default:
                error('Unknown filter action: ' . $this->action);
        }
    }

    public function check(array $post): bool
    {
        foreach ($this->condition as $condition => $value) {
            $NOT = false;
            if ($condition[0] == '!') {
                $NOT = true;
                $condition = substr($condition, 1);
            }
            if ($this->match($post, $condition, $value) == $NOT) {
                return false;
            }
        }
        return true;
    }
}
