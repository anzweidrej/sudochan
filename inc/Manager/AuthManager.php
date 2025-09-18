<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Manager;

defined('TINYBOARD') or exit;

use Sudochan\Cache;
use Sudochan\Handler\ErrorHandler;

class AuthManager
{
    /**
     * Create a salted hash for validating logins.
     *
     * @param string $username
     * @param string $password
     * @param string|false $salt
     * @return array|string Hash and salt pair if salt not provided, otherwise hash string
     */
    public static function mkhash(string $username, string $password, string|false $salt = false): array|string
    {
        global $config;

        if (!$salt) {
            // create some sort of salt for the hash
            $salt = substr(base64_encode(sha1(rand() . time(), true) . $config['cookies']['salt']), 0, 15);
            $generated_salt = true;
        }

        // generate hash (method is not important as long as it's strong)
        $hash = substr(
            base64_encode(
                md5(
                    $username . $config['cookies']['salt'] .
                    sha1(
                        $username . $password . $salt .
                        ($config['mod']['lock_ip'] ? $_SERVER['REMOTE_ADDR'] : ''),
                        true,
                    ),
                    true,
                ),
            ),
            0,
            20,
        );

        if (isset($generated_salt)) {
            return [$hash, $salt];
        } else {
            return $hash;
        }
    }

    /**
     * Generate a random salt string.
     *
     * @return string
     */
    public static function generate_salt(): string
    {
        mt_srand(microtime(true) * 100000 + memory_get_usage(true));
        return md5(uniqid(mt_rand(), true));
    }

    /**
     * Attempt to log in a moderator.
     *
     * @param string $username
     * @param string $password Plain or pre-hashed password depending on $makehash
     * @param bool $makehash True to sha1 the password first
     * @return array|false Moderator data on success, false on failure
     */
    public static function login(string $username, string $password, bool $makehash = true): array|false
    {
        global $mod;

        // SHA1 password
        if ($makehash) {
            $password = sha1($password);
        }

        $query = prepare("SELECT `id`, `type`, `boards`, `password`, `salt` FROM ``mods`` WHERE `username` = :username");
        $query->bindValue(':username', $username);
        $query->execute() or error(db_error($query));

        if ($user = $query->fetch(\PDO::FETCH_ASSOC)) {
            if ($user['password'] === hash('sha256', $user['salt'] . $password)) {
                return $mod = [
                    'id' => $user['id'],
                    'type' => $user['type'],
                    'username' => $username,
                    'hash' => self::mkhash($username, $user['password']),
                    'boards' => explode(',', $user['boards']),
                ];
            }
        }

        return false;
    }

    /**
     * Set moderator auth cookies.
     *
     * @return void
     */
    public static function setCookies(): void
    {
        global $mod, $config;
        if (!$mod) {
            error('setCookies() was called for a non-moderator!');
        }

        setcookie(
            $config['cookies']['mod'],
            $mod['username'] . // username
            ':' .
            $mod['hash'][0] . // password
            ':' .
            $mod['hash'][1], // salt
            [
                'expires'  => time() + $config['cookies']['expire'],
                'path'     => $config['cookies']['jail'] ? $config['cookies']['path'] : '/',
                'domain'   => '',
                'secure'   => false,
                'httponly' => $config['cookies']['httponly'],
                'samesite' => $config['cookies']['samesite'] ?? 'Lax',
            ],
        );
    }

    /**
     * Destroy moderator auth cookies.
     *
     * @return void
     */
    public static function destroyCookies(): void
    {
        global $config;
        // Delete the cookies
        setcookie(
            $config['cookies']['mod'],
            'deleted',
            [
                'expires'  => time() - $config['cookies']['expire'],
                'path'     => $config['cookies']['jail'] ? $config['cookies']['path'] : '/',
                'domain'   => '',
                'secure'   => false,
                'httponly' => true,
                'samesite' => $config['cookies']['samesite'] ?? 'Lax',
            ],
        );
    }

    /**
     * Log a moderator action.
     *
     * @param string $action
     * @param string|null $_board
     * @return void
     */
    public static function modLog(string $action, ?string $_board = null): void
    {
        global $mod, $board, $config;
        $query = prepare("INSERT INTO ``modlogs`` VALUES (:id, :ip, :board, :time, :text)");
        $query->bindValue(':id', $mod['id'], \PDO::PARAM_INT);
        $query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
        $query->bindValue(':time', time(), \PDO::PARAM_INT);
        $query->bindValue(':text', $action);
        if (isset($_board)) {
            $query->bindValue(':board', $_board);
        } elseif (isset($board)) {
            $query->bindValue(':board', $board['uri']);
        } else {
            $query->bindValue(':board', null, \PDO::PARAM_NULL);
        }
        $query->execute() or error(db_error($query));

        if ($config['syslog']) {
            ErrorHandler::_syslog(LOG_INFO, '[mod/' . $mod['username'] . ']: ' . $action);
        }
    }

    /**
     * Authenticate moderator from cookies.
     *
     * @return void Exits and redirects on failure
     */
    public static function authenticate(): void
    {
        global $config, $mod;

        // Return early if session cookie is not set
        if (!isset($_COOKIE[$config['cookies']['mod']])) {
            return;
        }

        // Should be username:hash:salt
        $cookie = explode(':', $_COOKIE[$config['cookies']['mod']]);
        if (count($cookie) !== 3) {
            // Malformed cookies
            self::destroyCookies();
            header('Location: ?/login');
            exit;
        }

        $query = prepare("SELECT `id`, `type`, `boards`, `password` FROM ``mods`` WHERE `username` = :username");
        $query->bindValue(':username', $cookie[0]);
        $query->execute() or error(db_error($query));
        $user = $query->fetch(\PDO::FETCH_ASSOC);

        // Validate user and password hash
        if (!$user || $cookie[1] !== self::mkhash($cookie[0], $user['password'], $cookie[2])) {
            // Malformed cookies or user not found
            self::destroyCookies();
            header('Location: ?/login');
            exit;
        }

        $mod = [
            'id' => $user['id'],
            'type' => $user['type'],
            'username' => $cookie[0],
            'boards' => explode(',', $user['boards']),
        ];
    }

    /**
     * Create PM header info.
     *
     * @return array|false Array with id and waiting count, or false if none
     */
    public static function create_pm_header(): array|false
    {
        global $mod, $config;

        if (
            $config['cache']['enabled'] &&
            ($header = Cache::get('pm_unread_' . $mod['id'])) !== false
        ) {
            if ($header === true) {
                return false;
            }

            return $header;
        }

        $query = prepare("SELECT `id` FROM ``pms`` WHERE `to` = :id AND `unread` = 1");
        $query->bindValue(':id', $mod['id'], \PDO::PARAM_INT);
        $query->execute() or error(db_error($query));

        if ($pm = $query->fetch(\PDO::FETCH_ASSOC)) {
            $header = ['id' => $pm['id'], 'waiting' => $query->rowCount() - 1];
        } else {
            $header = true;
        }

        if ($config['cache']['enabled']) {
            Cache::set('pm_unread_' . $mod['id'], $header);
        }

        if ($header === true) {
            return false;
        }

        return $header;
    }
}
