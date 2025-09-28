<?php

namespace Sudochan\Tests\Repository;

use Sudochan\Tests\AbstractTestCase;
use Sudochan\Repository\UserRepository;

class UserRepositoryTest extends AbstractTestCase
{
    private UserRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new UserRepository();
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

            if (in_array($name, ['time', 'created', 'ts'], true) || stripos($type, 'int') !== false && preg_match('/\b(time|ts|created)\b/i', $name)) {
                $row[$name] = $now;
                continue;
            }

            if (stripos($type, 'tinyint') !== false || stripos($type, 'int') !== false) {
                $row[$name] = 0;
                continue;
            }

            if ($name === 'username') {
                $row[$name] = $overrides[$name] ?? 'phpunit-user-' . uniqid();
                continue;
            }

            if (in_array($name, ['password', 'salt', 'boards'], true)) {
                $row[$name] = $overrides[$name] ?? 'phpunit';
                continue;
            }

            $row[$name] = $overrides['fallback_string'] ?? 'phpunit';
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

    public function testInsertGetByIdGetAllUsersDeleteById(): void
    {
        $modsDesc = $this->describeTable('mods');
        if (empty($modsDesc)) {
            $this->markTestIncomplete('No `mods` table present; skipping UserRepository insert/get/delete tests.');
            return;
        }

        $username = 'phpunit_ins_' . uniqid();
        $password = 'pwhash';
        $salt     = 'salt';
        $type     = 1;
        $boards   = 'a,b';

        $newId = (int) $this->repo->insertUser($username, $password, $salt, $type, $boards);
        $this->assertGreaterThan(0, $newId, 'insertUser returned an id');

        $user = $this->repo->getById($newId);
        $this->assertIsArray($user);
        $this->assertEquals($username, $user['username'] ?? '', 'getById returns inserted username');

        $all = $this->repo->getAllUsers();
        $this->assertIsArray($all);
        $found = false;
        foreach ($all as $u) {
            if (($u['id'] ?? 0) == $newId) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'getAllUsers contains inserted user');

        $this->repo->deleteById($newId);
        $after = $this->repo->getById($newId);
        $this->assertFalse($after, 'deleteById removed the user');
    }

    public function testUpdateUsernameBoardsPasswordSaltTypeAndGettersAndModLogs(): void
    {
        $modsDesc = $this->describeTable('mods');
        $modlogsDesc = $this->describeTable('modlogs');

        if (empty($modsDesc)) {
            $this->markTestIncomplete('No `mods` table present; skipping UserRepository update/getter tests.');
            return;
        }

        $modRow = $this->buildRowFromDesc($modsDesc, ['username' => 'phpunit_upd_' . uniqid()]);
        $modId = $this->insertInto('mods', $modRow);
        $this->assertGreaterThan(0, $modId, 'Inserted mod for update tests');

        $this->repo->updateUsernameBoards($modId, 'newname_' . uniqid(), 'x,y');
        $fetched = $this->repo->getById($modId);
        $this->assertIsArray($fetched);
        $this->assertStringStartsWith('newname_', (string) ($fetched['username'] ?? ''), 'updateUsernameBoards updated username');

        $this->repo->updatePasswordAndSalt($modId, 'newpw', 'newsalt');
        $row = $this->repo->getById($modId);
        $this->assertIsArray($row);
        $this->assertArrayHasKey('password', $row);
        $this->assertArrayHasKey('salt', $row);

        $this->repo->updateType($modId, 9);
        $typeRow = $this->repo->getTypeAndUsername($modId);
        $this->assertIsArray($typeRow);
        $this->assertEquals(9, (int) ($typeRow['type'] ?? 0), 'updateType changed type');
        $this->assertArrayHasKey('username', $typeRow);

        if (!empty($modlogsDesc)) {
            $logRow = $this->buildRowFromDesc($modlogsDesc, [
                'mod' => $modId,
                'text' => 'phpunit modlog',
                'time' => time(),
            ]);
            $this->insertInto('modlogs', $logRow);

            $logs = $this->repo->getModLogs($modId, 5);
            $this->assertIsArray($logs);
            $this->assertNotEmpty($logs, 'getModLogs returned rows for mod');
        }

        $this->pdo->prepare('DELETE FROM `mods` WHERE `id` = :id')->execute([':id' => $modId]);
    }
}
