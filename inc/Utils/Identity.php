<?php

namespace Sudochan\Utils;

use Sudochan\Dispatcher\EventDispatcher;

class Identity
{
    /**
     * Generate a poster identifier from IP and thread id.
     *
     * @param string $ip Poster IP address
     * @param int $thread Thread ID
     * @return string Poster identifier
     */
    public static function poster_id(string $ip, int $thread): string
    {
        global $config;

        if ($id = EventDispatcher::event('poster-id', $ip, $thread)) {
            return $id;
        }

        // Confusing, hard to brute-force, but simple algorithm
        return substr(sha1(sha1($ip . $config['secure_trip_salt'] . $thread) . $config['secure_trip_salt']), 0, $config['poster_id_length']);
    }

    /**
     * Build capcode data from a cap identifier using config.
     *
     * @param string|false $cap Cap identifier or false
     * @return array<string,string>|false Capcode array or false when none
     */
    public static function capcode(string|false $cap): array|false
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
}
