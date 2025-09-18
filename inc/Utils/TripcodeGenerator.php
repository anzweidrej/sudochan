<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Utils;

use Sudochan\Dispatcher\EventDispatcher;

class TripcodeGenerator
{
    /**
     * Generate a tripcode from a name containing # or ##.
     *
     * @param string $name Input name
     * @return array [name, trip?]
     */
    public static function generate_tripcode(string $name): array
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
}
