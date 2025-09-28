<?php

namespace Sudochan\Tests\Repository;

use Sudochan\Tests\AbstractTestCase;
use Sudochan\Repository\LogRepository;

class LogRepositoryTest extends AbstractTestCase
{
    private LogRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new LogRepository();
    }

    private function describeTable(string $table): array
    {
        return $this->pdo->query("DESCRIBE `$table`")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function buildRowFromDesc(array $desc, array $overrides = []): array
    {
        $row = [];
        $now = time();
        foreach ($desc as $col) {
            $name = $col['Field'];
            if (array_key_exists($name, $overrides)) {
                $row[$name] = $overrides[$name];
                continue;
            }
            if (stripos($col['Extra'] ?? '', 'auto_increment') !== false) {
                continue;
            }
            $type = $col['Type'] ?? '';

            if (in_array($name, ['time', 'created', 'ts'], true)) {
                $row[$name] = $now;
                continue;
            }

            if (stripos($type, 'int') !== false || stripos($type, 'tinyint') !== false) {
                $row[$name] = 0;
                continue;
            }

            if (in_array($name, ['username', 'board', 'ip', 'text', 'message', 'body'], true)) {
                if ($name === 'ip') {
                    $row[$name] = '127.0.0.1';
                } elseif ($name === 'text') {
                    $row[$name] = 'phpunit log';
                } else {
                    $row[$name] = 'phpunit';
                }
                continue;
            }

            $row[$name] = 'phpunit';
        }
        return $row;
    }

    private function insertInto(string $table, array $values): int
    {
        $cols = array_map(fn($c) => "`$c`", array_keys($values));
        $ph   = array_map(fn($c) => ":$c", array_keys($values));
        $sql  = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
        $stmt = $this->pdo->prepare($sql);
        foreach ($values as $k => $v) {
            $stmt->bindValue(":$k", $v);
        }
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    public function testGetLogsCountGetUserLogsAndCountUserLogs(): void
    {
        $modsDesc = $this->describeTable('mods');
        if (empty($modsDesc)) {
            $this->markTestIncomplete('No `mods` table present; cannot test LogRepository properly.');
            return;
        }

        $modRow = $this->pdo->query('SELECT `id`,`username` FROM `mods` LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        if ($modRow === false) {
            $modData = $this->buildRowFromDesc($modsDesc, ['username' => 'phpunit-mod']);
            $modId = $this->insertInto('mods', $modData);
            $username = $modData['username'];
        } else {
            $modId = (int) $modRow['id'];
            $username = (string) $modRow['username'];
            if ($username === '') {
                $username = 'phpunit-mod';
            }
        }

        $modlogsDesc = $this->describeTable('modlogs');
        if (empty($modlogsDesc)) {
            $this->markTestIncomplete('No `modlogs` table present; cannot test LogRepository.');
            return;
        }

        $beforeTotal = $this->repo->countLogs();

        $row1 = $this->buildRowFromDesc($modlogsDesc, [
            'mod' => $modId,
            'ip' => '127.0.0.1',
            'board' => 'b',
            'time' => time() - 10,
            'text' => 'phpunit log 1',
        ]);
        $row2 = $this->buildRowFromDesc($modlogsDesc, [
            'mod' => $modId,
            'ip' => '127.0.0.2',
            'board' => 'b',
            'time' => time(),
            'text' => 'phpunit log 2',
        ]);

        $this->insertInto('modlogs', $row1);
        $this->insertInto('modlogs', $row2);

        $afterTotal = $this->repo->countLogs();
        $this->assertGreaterThanOrEqual($beforeTotal + 2, $afterTotal, 'countLogs should increase after inserts');

        $logs = $this->repo->getLogs(0, 10);
        $this->assertIsArray($logs);
        $this->assertNotEmpty($logs, 'getLogs returned rows');
        $foundText2 = false;
        foreach ($logs as $l) {
            $this->assertArrayHasKey('username', $l);
            $this->assertArrayHasKey('text', $l);
            if (strpos((string) ($l['text'] ?? ''), 'phpunit log 2') !== false) {
                $foundText2 = true;
            }
        }
        $this->assertTrue($foundText2, 'Newest inserted log found in getLogs');

        $userLogs = $this->repo->getUserLogs($username, 0, 10);
        $this->assertIsArray($userLogs);
        $this->assertNotEmpty($userLogs, 'getUserLogs returned rows for username');

        $countUser = $this->repo->countUserLogs($username);
        $this->assertGreaterThanOrEqual(2, $countUser, 'countUserLogs should reflect inserted rows for this user');
    }
}
