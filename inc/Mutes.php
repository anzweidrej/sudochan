<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan;

use Sudochan\Cache;
use Sudochan\Dispatcher\EventDispatcher;

class Mutes
{
    public static function makerobot(string $body): string
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

    public static function checkRobot(string $body): bool
    {
        if (empty($body) || EventDispatcher::event('check-robot', $body)) {
            return true;
        }

        $body = self::makerobot($body);
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

    public static function muteTime(): int
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

    public static function mute(): int
    {
        // Insert mute
        $query = prepare("INSERT INTO ``mutes`` VALUES (:ip, :time)");
        $query->bindValue(':time', time(), \PDO::PARAM_INT);
        $query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
        $query->execute() or error(db_error($query));

        return self::muteTime();
    }

    public static function checkMute(): void
    {
        global $config, $debug;

        if ($config['cache']['enabled']) {
            // Cached mute?
            if (($mute = Cache::get("mute_{$_SERVER['REMOTE_ADDR']}")) && ($mutetime = Cache::get("mutetime_{$_SERVER['REMOTE_ADDR']}"))) {
                error(sprintf($config['error']['youaremuted'], $mute['time'] + $mutetime - time()));
            }
        }

        $mutetime = self::muteTime();
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
}
