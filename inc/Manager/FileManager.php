<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Manager;

use Sudochan\Remote;
use Sudochan\Dispatcher\EventDispatcher;

class FileManager
{
    public static function file_write(string $path, string $data, bool $simple = false, bool $skip_purge = false): void
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

    public static function file_unlink(string $path): bool
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
}
