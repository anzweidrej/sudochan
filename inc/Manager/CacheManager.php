<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Manager;

class CacheManager
{
    private static mixed $cache = null;

    /**
     * Initialize cache backend from global $config.
     *
     * @return void
     */
    public static function init(): void
    {
        global $config;

        if (!isset($config['cache']['enabled'])) {
            return;
        }

        switch ($config['cache']['enabled']) {
            case 'memcached':
                self::$cache = new \Memcached();
                self::$cache->addServers($config['cache']['memcached']);
                break;
            case 'redis':
                self::$cache = new \Redis();
                self::$cache->connect($config['cache']['redis'][0], $config['cache']['redis'][1]);
                if (!empty($config['cache']['redis'][2])) {
                    self::$cache->auth($config['cache']['redis'][2]);
                }
                self::$cache->select($config['cache']['redis'][3]) or die('cache select failure');
                break;
            case 'php':
                self::$cache = [];
                break;
        }
    }

    /**
     * Retrieve a value from cache.
     *
     * @param string $key
     * @return mixed|null Cached value or false on miss.
     */
    public static function get(string $key): mixed
    {
        global $config, $debug;

        if (!self::$cache) {
            self::init();
        }

        $key = (isset($config['cache']['prefix']) ? $config['cache']['prefix'] : '') . $key;

        $data = false;
        switch ($config['cache']['enabled']) {
            case 'memcached':
                $data = self::$cache->get($key);
                break;
            case 'apc':
                $data = apc_fetch($key);
                break;
            case 'xcache':
                $data = xcache_get($key);
                break;
            case 'php':
                $data = isset(self::$cache[$key]) ? self::$cache[$key] : false;
                break;
            case 'redis':
                $data = json_decode(self::$cache->get($key), true);
                break;
        }

        if (!empty($config['debug'])) {
            $debug['cached'][] = $key . ($data === false ? ' (miss)' : ' (hit)');
        }

        return $data;
    }

    /**
     * Store a value in cache.
     *
     * @param string $key
     * @param mixed $value
     * @param int|false $expires Seconds until expiration or false to use default.
     * @return void
     */
    public static function set(string $key, mixed $value, int|false $expires = false): void
    {
        global $config, $debug;

        $key = $config['cache']['prefix'] . $key;

        if (!$expires) {
            $expires = $config['cache']['timeout'];
        }

        switch ($config['cache']['enabled']) {
            case 'memcached':
                if (!self::$cache) {
                    self::init();
                }
                self::$cache->set($key, $value, $expires);
                break;
            case 'redis':
                if (!self::$cache) {
                    self::init();
                }
                self::$cache->setex($key, $expires, json_encode($value));
                break;
            case 'apc':
                apc_store($key, $value, $expires);
                break;
            case 'xcache':
                xcache_set($key, $value, $expires);
                break;
            case 'php':
                self::$cache[$key] = $value;
                break;
        }

        if ($config['debug']) {
            $debug['cached'][] = $key . ' (set)';
        }
    }

    /**
     * Remove a key from cache.
     *
     * @param string $key
     * @return void
     */
    public static function delete(string $key): void
    {
        global $config, $debug;

        $key = $config['cache']['prefix'] . $key;

        switch ($config['cache']['enabled']) {
            case 'memcached':
            case 'redis':
                if (!self::$cache) {
                    self::init();
                }
                self::$cache->delete($key);
                break;
            case 'apc':
                apc_delete($key);
                break;
            case 'xcache':
                xcache_unset($key);
                break;
            case 'php':
                unset(self::$cache[$key]);
                break;
        }

        if ($config['debug']) {
            $debug['cached'][] = $key . ' (deleted)';
        }
    }

    /**
     * Flush all cached entries.
     *
     * @return bool True on success, false otherwise.
     */
    public static function flush(): bool
    {
        global $config;

        switch ($config['cache']['enabled']) {
            case 'memcached':
                if (!self::$cache) {
                    self::init();
                }
                return self::$cache->flush();
            case 'apc':
                return apc_clear_cache('user');
            case 'php':
                self::$cache = [];
                break;
            case 'redis':
                if (!self::$cache) {
                    self::init();
                }
                return self::$cache->flushDB();
        }

        return false;
    }
}
