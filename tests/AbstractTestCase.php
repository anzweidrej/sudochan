<?php

namespace Sudochan\Tests;

use PHPUnit\Framework\TestCase;

class AbstractTestCase extends TestCase
{
    protected ?\PDO $pdo = null;
    protected array $configBackup = [];

    protected function setUp(): void
    {
        global $pdo, $config;

        $result = sql_open();
        if ($result instanceof \PDO) {
            $pdo = $result;
        } elseif (!isset($pdo) || !($pdo instanceof \PDO)) {
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
                $pdo = $GLOBALS['pdo'];
            } else {
                $this->fail('Database connection not available via sql_open()');
            }
        }

        $this->pdo = $pdo;
        $this->pdo->beginTransaction();

        $this->configBackup = $config ?? [];

        if (!isset($GLOBALS['config'])) {
            $GLOBALS['config'] = [];
        }
        $GLOBALS['config']['mod']['ip_recentposts'] = $GLOBALS['config']['mod']['ip_recentposts'] ?? 10;
        $GLOBALS['config']['ban_show_post'] = $GLOBALS['config']['ban_show_post'] ?? true;
    }

    protected function tearDown(): void
    {
        global $pdo, $config;

        if ($this->pdo && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        $config = $this->configBackup;
        $_POST = [];
    }

    protected function getPdo(): \PDO
    {
        if (!$this->pdo) {
            $this->fail('PDO not initialized');
        }
        return $this->pdo;
    }

    protected function ensureColumnExists(string $table, string $column, string $definition): void
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column));
        $has = $stmt && count($stmt->fetchAll()) > 0;
        if (!$has) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }

    protected function ensureCommonTables(): void
    {
        $pdo = $this->getPdo();

        $tables = [
            'mods' => "
                CREATE TABLE IF NOT EXISTS `mods` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `username` VARCHAR(255) DEFAULT '',
                    `password` VARCHAR(255) DEFAULT NULL,
                    `type` TINYINT DEFAULT 0
                ) ENGINE=InnoDB
            ",
            'modlogs' => "
                CREATE TABLE IF NOT EXISTS `modlogs` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `mod` INT DEFAULT NULL,
                    `ip` VARCHAR(45) DEFAULT NULL,
                    `board` VARCHAR(32) DEFAULT NULL,
                    `time` INT DEFAULT NULL,
                    `message` TEXT DEFAULT NULL
                ) ENGINE=InnoDB
            ",
            'pms' => "
                CREATE TABLE IF NOT EXISTS `pms` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `from_mod` INT DEFAULT NULL,
                    `to_mod` INT DEFAULT NULL,
                    `message` TEXT DEFAULT NULL,
                    `time` INT DEFAULT NULL,
                    `read` TINYINT DEFAULT 0
                ) ENGINE=InnoDB
            ",
            'reports' => "
                CREATE TABLE IF NOT EXISTS `reports` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `time` INT DEFAULT NULL,
                    `ip` VARCHAR(45) DEFAULT NULL,
                    `board` VARCHAR(32) DEFAULT NULL,
                    `post` INT DEFAULT NULL,
                    `reason` TEXT DEFAULT NULL
                ) ENGINE=InnoDB
            ",
            'noticeboard' => "
                CREATE TABLE IF NOT EXISTS `noticeboard` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `mod` INT DEFAULT NULL,
                    `message` TEXT DEFAULT NULL,
                    `time` INT DEFAULT NULL
                ) ENGINE=InnoDB
            ",
            'theme_settings' => "
                CREATE TABLE IF NOT EXISTS `theme_settings` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `theme` VARCHAR(255) DEFAULT NULL,
                    `name` VARCHAR(255) DEFAULT NULL,
                    `value` TEXT DEFAULT NULL
                ) ENGINE=InnoDB
            ",
            'ban_appeals' => "
                CREATE TABLE IF NOT EXISTS `ban_appeals` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `ban_id` INT DEFAULT NULL,
                    `time` INT DEFAULT NULL,
                    `message` TEXT DEFAULT NULL,
                    `denied` TINYINT DEFAULT 0
                ) ENGINE=InnoDB
            ",
            'cites' => "
                CREATE TABLE IF NOT EXISTS `cites` (
                    `board` VARCHAR(32) DEFAULT NULL,
                    `post` INT DEFAULT NULL,
                    `target_board` VARCHAR(32) DEFAULT NULL,
                    `target` INT DEFAULT NULL
                ) ENGINE=InnoDB
            ",
            'antispam' => "
                CREATE TABLE IF NOT EXISTS `antispam` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `board` VARCHAR(32) DEFAULT NULL,
                    `ip` VARCHAR(45) DEFAULT NULL,
                    `hash` VARCHAR(64) DEFAULT NULL,
                    `time` INT DEFAULT NULL
                ) ENGINE=InnoDB
            ",
            'users' => "
                CREATE TABLE IF NOT EXISTS `users` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `username` VARCHAR(255) DEFAULT '',
                    `email` VARCHAR(255) DEFAULT NULL
                ) ENGINE=InnoDB
            ",
        ];

        foreach ($tables as $name => $createSql) {
            try {
                $exists = (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($name))->fetchColumn();
            } catch (\Throwable $e) {
                $exists = false;
            }
            if (!$exists) {
                try {
                    $pdo->exec($createSql);
                } catch (\Throwable $e) {
                }
            }
        }
    }

    protected function ensurePostsTable(string $board): void
    {
        $pdo = $this->getPdo();
        $table = "posts_{$board}";

        $create = "
            CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` INT NOT NULL PRIMARY KEY,
                `thread` INT DEFAULT NULL,
                `locked` TINYINT DEFAULT 0,
                `sticky` TINYINT DEFAULT 0,
                `sage` TINYINT DEFAULT 0,
                `name` VARCHAR(255) DEFAULT NULL,
                `email` VARCHAR(255) DEFAULT NULL,
                `subject` VARCHAR(255) DEFAULT NULL,
                `body` TEXT DEFAULT NULL,
                `body_nomarkup` TEXT DEFAULT NULL,
                `thumb` VARCHAR(255) DEFAULT NULL,
                `thumbwidth` INT DEFAULT NULL,
                `thumbheight` INT DEFAULT NULL,
                `ip` VARCHAR(45) DEFAULT NULL,
                `time` INT DEFAULT NULL
            ) ENGINE=InnoDB
        ";

        try {
            $pdo->exec($create);
        } catch (\Throwable $e) {
        }
    }
}
