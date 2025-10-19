<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Manager;

use Lifo\IP\CIDR;
use Sudochan\Security\Authenticator;
use Sudochan\Service\{MarkupService, BoardService};
use Sudochan\Utils\{DateRange, StringFormatter, TextFormatter, Sanitize};
use Sudochan\Entity\{Post, Thread};
use Sudochan\Dispatcher\EventDispatcher;
use Sudochan\Manager\CacheManager as Cache;

class BanManager
{
    /**
     * Convert an IP range to a human-readable string.
     *
     * @param array $mask [ipstart, ipend]
     * @return string Human readable mask (single IP, CIDR or '???')
     */
    public static function range_to_string(array $mask): string
    {
        list($ipstart, $ipend) = $mask;

        if (!isset($ipend) || $ipend === false) {
            // Not a range. Single IP address.
            $ipstr = inet_ntop($ipstart);
            return $ipstr;
        }

        if (strlen($ipstart) != strlen($ipend)) {
            return '???';
        } // What the fuck are you doing, son?

        $range = CIDR::range_to_cidr(inet_ntop($ipstart), inet_ntop($ipend));
        if ($range !== false) {
            return $range;
        }

        return '???';
    }

    /**
     * Calculate binary range (inet_pton) for a CIDR string.
     *
     * @param string $mask CIDR notation.
     * @return array [ipstart, ipend] as binary strings.
     */
    private static function calc_cidr(string $mask): array
    {
        $cidr = new CIDR($mask);
        $range = $cidr->getRange();

        return [inet_pton($range[0]), inet_pton($range[1])];
    }

    /**
     * Parse a human time string to a UNIX timestamp or false.
     *
     * @param string $str
     * @return int|false Timestamp when the ban expires, or false on failure.
     */
    public static function parse_time(string $str): int|false
    {
        if (empty($str)) {
            return false;
        }

        if (($time = @strtotime($str)) !== false) {
            return $time;
        }

        if (!preg_match('/^((\d+)\s?ye?a?r?s?)?\s?+((\d+)\s?mon?t?h?s?)?\s?+((\d+)\s?we?e?k?s?)?\s?+((\d+)\s?da?y?s?)?((\d+)\s?ho?u?r?s?)?\s?+((\d+)\s?mi?n?u?t?e?s?)?\s?+((\d+)\s?se?c?o?n?d?s?)?$/', $str, $matches)) {
            return false;
        }

        $expire = 0;

        if (isset($matches[2])) {
            // Years
            $expire += $matches[2] * 60 * 60 * 24 * 365;
        }
        if (isset($matches[4])) {
            // Months
            $expire += $matches[4] * 60 * 60 * 24 * 30;
        }
        if (isset($matches[6])) {
            // Weeks
            $expire += $matches[6] * 60 * 60 * 24 * 7;
        }
        if (isset($matches[8])) {
            // Days
            $expire += $matches[8] * 60 * 60 * 24;
        }
        if (isset($matches[10])) {
            // Hours
            $expire += $matches[10] * 60 * 60;
        }
        if (isset($matches[12])) {
            // Minutes
            $expire += $matches[12] * 60;
        }
        if (isset($matches[14])) {
            // Seconds
            $expire += $matches[14];
        }

        return time() + $expire;
    }

    /**
     * Parse a mask string into binary start/end addresses.
     *
     * @param string $mask
     * @return array|false [ipstart, ipend] (binary via inet_pton) or false on invalid mask.
     */
    public static function parse_range(string $mask): array|false
    {
        $ipstart = false;
        $ipend = false;

        if (preg_match('@^(\d{1,3}\.){1,3}([\d*]{1,3})?$@', $mask) && substr_count($mask, '*') == 1) {
            // IPv4 wildcard mask
            $parts = explode('.', $mask);
            $ipv4 = '';
            foreach ($parts as $part) {
                if ($part == '*') {
                    $ipstart = inet_pton($ipv4 . '0' . str_repeat('.0', 3 - substr_count($ipv4, '.')));
                    $ipend = inet_pton($ipv4 . '255' . str_repeat('.255', 3 - substr_count($ipv4, '.')));
                    break;
                } elseif (($wc = strpos($part, '*')) !== false) {
                    $ipstart = inet_pton($ipv4 . substr($part, 0, $wc) . '0' . str_repeat('.0', 3 - substr_count($ipv4, '.')));
                    $ipend = inet_pton($ipv4 . substr($part, 0, $wc) . '9' . str_repeat('.255', 3 - substr_count($ipv4, '.')));
                    break;
                }
                $ipv4 .= "$part.";
            }
        } elseif (preg_match('@^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/\d+$@', $mask)) {
            list($ipv4, $bits) = explode('/', $mask);
            if ($bits > 32) {
                return false;
            }

            list($ipstart, $ipend) = self::calc_cidr($mask);
        } elseif (preg_match('@^[:a-z\d]+/\d+$@i', $mask)) {
            list($ipv6, $bits) = explode('/', $mask);
            if ($bits > 128) {
                return false;
            }

            list($ipstart, $ipend) = self::calc_cidr($mask);
        } else {
            if (($ipstart = @inet_pton($mask)) === false) {
                return false;
            }
        }

        return [$ipstart, $ipend];
    }

    /**
     * Find bans that match a given IP and optional board.
     *
     * @param string $ip IP address in text form.
     * @param string|false $board Board URI or false for all boards.
     * @param bool $get_mod_info Include moderator username if true.
     * @return array List of bans.
     */
    public static function find(string $ip, string|false $board = false, bool $get_mod_info = false): array
    {
        global $config;

        $query = prepare('SELECT ``bans``.*' . ($get_mod_info ? ', `username`' : '') . ' FROM ``bans``
        ' . ($get_mod_info ? 'LEFT JOIN ``mods`` ON ``mods``.`id` = `creator`' : '') . '
        WHERE
            (' . ($board ? '(`board` IS NULL OR `board` = :board) AND' : '') . '
            (`ipstart` = :ip OR (:ip >= `ipstart` AND :ip <= `ipend`)))
        ORDER BY `expires` IS NULL, `expires` DESC');

        if ($board) {
            $query->bindValue(':board', $board);
        }

        $query->bindValue(':ip', inet_pton($ip));
        $query->execute() or error(db_error($query));

        $ban_list = [];

        while ($ban = $query->fetch(\PDO::FETCH_ASSOC)) {
            if ($ban['expires'] && ($ban['seen'] || !$config['require_ban_view']) && $ban['expires'] < time()) {
                self::delete($ban['id']);
            } else {
                if ($ban['post']) {
                    $ban['post'] = json_decode($ban['post'], true);
                }
                $ban['mask'] = self::range_to_string([$ban['ipstart'], $ban['ipend']]);
                $ban_list[] = $ban;
            }
        }

        return $ban_list;
    }

    /**
     * List all bans with optional pagination.
     *
     * @param int $offset
     * @param int $limit
     * @return array List of bans
     */
    public static function list_all(int $offset = 0, int $limit = 9001): array
    {
        $offset = (int) $offset;
        $limit = (int) $limit;

        $query = query("SELECT ``bans``.*, `username` FROM ``bans``
            LEFT JOIN ``mods`` ON ``mods``.`id` = `creator`
            ORDER BY `created` DESC LIMIT $offset, $limit") or error(db_error());
        $bans = $query->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($bans as &$ban) {
            $ban['mask'] = self::range_to_string([$ban['ipstart'], $ban['ipend']]);
        }

        return $bans;
    }

    /**
     * Count total bans.
     *
     * @return int
     */
    public static function count(): int
    {
        $query = query("SELECT COUNT(*) FROM ``bans``") or error(db_error());
        return (int) $query->fetchColumn();
    }

    /**
     * Mark a ban as seen.
     *
     * @param int|string $ban_id
     * @return void
     */
    public static function seen(int|string $ban_id): void
    {
        $query = query("UPDATE ``bans`` SET `seen` = 1 WHERE `id` = " . (int) $ban_id) or error(db_error());
    }

    /**
     * Purge expired and seen bans from the database.
     *
     * @return void
     */
    public static function purge(): void
    {
        $query = query("DELETE FROM ``bans`` WHERE `expires` IS NOT NULL AND `expires` < " . time() . " AND `seen` = 1") or error(db_error());
    }

    /**
     * Delete a ban by id, optionally logging the action.
     *
     * @param int|string $ban_id
     * @param bool $modlog Log removal to modlog when true.
     * @return bool True if deletion executed.
     */
    public static function delete(int|string $ban_id, bool $modlog = false): bool
    {
        if ($modlog) {
            $query = query("SELECT `ipstart`, `ipend` FROM ``bans`` WHERE `id` = " . (int) $ban_id) or error(db_error());
            if (!$ban = $query->fetch(\PDO::FETCH_ASSOC)) {
                // Ban doesn't exist
                return false;
            }

            $mask = self::range_to_string([$ban['ipstart'], $ban['ipend']]);

            Authenticator::modLog("Removed ban #{$ban_id} for "
                . (filter_var($mask, FILTER_VALIDATE_IP) !== false ? "<a href=\"?/IP/$mask\">$mask</a>" : $mask));
        }

        query("DELETE FROM ``bans`` WHERE `id` = " . (int) $ban_id) or error(db_error());

        return true;
    }

    /**
     * Create a new ban.
     *
     * @param string $mask IP/CIDR/wildcard/single IP.
     * @param string $reason Reason text.
     * @param int|string|false $length Seconds or parseable time string, or false for permanent.
     * @param string|false $ban_board Board URI or false for global.
     * @param int|false $mod_id Moderator id or false to use current mod.
     * @param array|false $post Optional post info to attach.
     * @return string Inserted ban id.
     */
    public static function new_ban(
        string $mask,
        string $reason,
        int|string|false $length = false,
        string|false $ban_board = false,
        int|false $mod_id = false,
        array|false $post = false,
    ): string {
        global $mod, $pdo, $board;

        if ($mod_id === false) {
            $mod_id = isset($mod['id']) ? $mod['id'] : -1;
        }

        $range = self::parse_range($mask);
        $mask = self::range_to_string($range);

        $query = prepare("INSERT INTO ``bans`` VALUES (NULL, :ipstart, :ipend, :time, :expires, :board, :mod, :reason, 0, :post)");

        $query->bindValue(':ipstart', $range[0]);
        if ($range[1] !== false && $range[1] != $range[0]) {
            $query->bindValue(':ipend', $range[1]);
        } else {
            $query->bindValue(':ipend', null, \PDO::PARAM_NULL);
        }

        $query->bindValue(':mod', $mod_id);
        $query->bindValue(':time', time());

        if ($reason !== '') {
            $reason = Sanitize::escape_markup_modifiers($reason);
            MarkupService::markup($reason);
            $query->bindValue(':reason', $reason);
        } else {
            $query->bindValue(':reason', null, \PDO::PARAM_NULL);
        }

        if ($length) {
            if (is_int($length) || ctype_digit($length)) {
                $length = time() + $length;
            } else {
                $length = self::parse_time($length);
            }
            $query->bindValue(':expires', $length);
        } else {
            $query->bindValue(':expires', null, \PDO::PARAM_NULL);
        }

        if ($ban_board) {
            $query->bindValue(':board', $ban_board);
        } else {
            $query->bindValue(':board', null, \PDO::PARAM_NULL);
        }

        if ($post) {
            $post['board'] = $board['uri'];
            $query->bindValue(':post', json_encode($post));
        } else {
            $query->bindValue(':post', null, \PDO::PARAM_NULL);
        }

        $query->execute() or error(db_error($query));

        if (isset($mod['id']) && $mod['id'] == $mod_id) {
            Authenticator::modLog('Created a new '
                . ($length > 0 ? preg_replace('/^(\d+) (\w+?)s?$/', '$1-$2', DateRange::until($length)) : 'permanent')
                . ' ban on '
                . ($ban_board ? '/' . $ban_board . '/' : 'all boards')
                . ' for '
                . (filter_var($mask, FILTER_VALIDATE_IP) !== false ? "<a href=\"?/IP/$mask\">$mask</a>" : $mask)
                . ' (<small>#' . $pdo->lastInsertId() . '</small>)'
                . ' with ' . ($reason ? 'reason: ' . StringFormatter::utf8tohtml($reason) . '' : 'no reason'));
        }
        return $pdo->lastInsertId();
    }

    /**
     * Check whether the current request IP is banned.
     *
     * @param string|false $board Optional board URI to restrict the check.
     * @return bool|null True if an event handler handled the check, null otherwise.
     */
    public static function checkBan(string|false $board = false): ?bool
    {
        global $config;

        if (!isset($_SERVER['REMOTE_ADDR'])) {
            // Server misconfiguration
            return null;
        }

        if (EventDispatcher::event('check-ban', $board)) {
            return true;
        }

        $bans = self::find($_SERVER['REMOTE_ADDR'], $board);

        foreach ($bans as &$ban) {
            if ($ban['expires'] && $ban['expires'] < time()) {
                self::delete($ban['id']);
                if ($config['require_ban_view'] && !$ban['seen']) {
                    if (!isset($_POST['json_response'])) {
                        self::displayBan($ban);
                    } else {
                        header('Content-Type: text/json');
                        die(json_encode(['error' => true, 'banned' => true]));
                    }
                }
            } else {
                if (!isset($_POST['json_response'])) {
                    self::displayBan($ban);
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

        self::purge();

        if ($config['cache']['enabled']) {
            Cache::set('purged_bans_last', time());
        }

        return null;
    }

    /**
     * Show the banned page for a given ban and terminate execution.
     *
     * @param array $ban Ban record data.
     * @return void Exits via die() after rendering.
     */
    public static function displayBan(array $ban): void
    {
        global $config, $board;

        if (!$ban['seen']) {
            self::seen($ban['id']);
        }

        $ban['ip'] = $_SERVER['REMOTE_ADDR'];
        if ($ban['post'] && isset($ban['post']['board'], $ban['post']['id'])) {
            if (BoardService::openBoard($ban['post']['board'])) {

                $query = query(sprintf("SELECT `thumb`, `file` FROM ``posts_%s`` WHERE `id` = "
                    . (int) $ban['post']['id'], $board['uri']));
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
                    ),
                ],
            )
        );
    }
}
