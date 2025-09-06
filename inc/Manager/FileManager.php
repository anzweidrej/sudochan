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
                self::purge($uri);
            }
            self::purge($path);
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
        if (isset($config['purge']) && isset($path[0]) && $path[0] != '/' && isset($_SERVER['HTTP_HOST'])) {
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
                self::purge($uri);
            }
            self::purge($path);
        }

        EventDispatcher::event('unlink', $path);

        return $ret;
    }

    public static function rrmdir(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (filetype($dir . "/" . $object) == "dir") {
                        self::rrmdir($dir . "/" . $object);
                    } else {
                        self::file_unlink($dir . "/" . $object);
                    }
                }
            }
            reset($objects);
            rmdir($dir);
        }
    }

    public static function undoImage(array $post): void
    {
        if (!$post['has_file']) {
            return;
        }

        if (isset($post['file_path'])) {
            self::file_unlink($post['file_path']);
        }
        if (isset($post['thumb_path'])) {
            self::file_unlink($post['thumb_path']);
        }
    }

    public static function purge(string $uri): void
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
}
