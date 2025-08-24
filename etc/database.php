<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

defined('TINYBOARD') or exit;

use Sudochan\PreparedQueryDebug;

/**
 * Opens a PDO connection if not already open.
 */
function sql_open(): PDO|true
{
    global $pdo, $config, $debug;
    if (isset($pdo) && $pdo) {
        return true;
    }

    if ($config['debug']) {
        $start = microtime(true);
    }

    $unix_socket = (isset($config['db']['server'][0]) && $config['db']['server'][0] === ':')
        ? substr($config['db']['server'], 1)
        : false;

    $dsn = $config['db']['type'] . ':' .
        ($unix_socket ? 'unix_socket=' . $unix_socket : 'host=' . $config['db']['server']) .
        ';dbname=' . $config['db']['database'];

    if (!empty($config['db']['dsn'])) {
        $dsn .= ';' . $config['db']['dsn'];
    }

    try {
        $options = [
            \PDO::ATTR_TIMEOUT => $config['db']['timeout'],
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ];
        if ($config['db']['persistent']) {
            $options[\PDO::ATTR_PERSISTENT] = true;
        }
        $pdo = new \PDO($dsn, $config['db']['user'], $config['db']['password'], $options);

        if ($config['debug']) {
            $debug['time']['db_connect'] = '~' . round((microtime(true) - $start) * 1000, 2) . 'ms';
        }

        if (mysql_version() >= 50503) {
            query('SET NAMES utf8mb4') or error(db_error());
        } else {
            query('SET NAMES utf8') or error(db_error());
        }
        return $pdo;
    } catch (\PDOException $e) {
        $message = $e->getMessage();

        // Remove any sensitive information
        $message = str_replace($config['db']['user'], '<em>hidden</em>', $message);
        $message = str_replace($config['db']['password'], '<em>hidden</em>', $message);

        // Print error
        error(_('Database error: ') . $message);
    }
}

/**
 * Returns MySQL version as an integer.
 */
function mysql_version(): int|false
{
    global $pdo;

    $version = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
    $v = explode('.', $version);
    if (count($v) !== 3) {
        return false;
    }
    return (int) sprintf("%02d%02d%02d", $v[0], $v[1], $v[2]);
}

/**
 * Prepares a SQL query.
 */
function prepare(string $query): PDOStatement|PreparedQueryDebug
{
    global $pdo, $debug, $config;

    $query = preg_replace('/``(' . $config['board_regex'] . ')``/u', '`' . $config['db']['prefix'] . '$1`', $query);

    sql_open();

    if ($config['debug']) {
        return new PreparedQueryDebug($query);
    }

    return $pdo->prepare($query);
}

/**
 * Executes a SQL query.
 */
function query(string $query): PDOStatement|false
{
    global $pdo, $debug, $config;

    $query = preg_replace('/``(' . $config['board_regex'] . ')``/u', '`' . $config['db']['prefix'] . '$1`', $query);

    sql_open();

    if ($config['debug']) {
        if ($config['debug_explain'] && preg_match('/^(SELECT|INSERT|UPDATE|DELETE) /i', $query)) {
            $explain = $pdo->query("EXPLAIN $query") or error(db_error());
        }
        $start = microtime(true);
        $queryObj = $pdo->query($query);
        if (!$queryObj) {
            return false;
        }
        $time = microtime(true) - $start;
        $debug['sql'][] = [
            'query'   => $queryObj->queryString,
            'rows'    => $queryObj->rowCount(),
            'explain' => isset($explain) ? $explain->fetchAll(\PDO::FETCH_ASSOC) : null,
            'time'    => '~' . round($time * 1000, 2) . 'ms',
        ];
        $debug['time']['db_queries'] += $time;
        return $queryObj;
    }

    return $pdo->query($query);
}

/**
 * Returns the last database error.
 */
function db_error(?PDOStatement $PDOStatement = null): string
{
    global $pdo, $db_error;

    if (isset($PDOStatement)) {
        $db_error = $PDOStatement->errorInfo();
        return $db_error[2];
    }

    $db_error = $pdo->errorInfo();
    return $db_error[2];
}
