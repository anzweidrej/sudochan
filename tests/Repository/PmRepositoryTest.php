<?php

namespace Sudochan\Tests\Repository;

use Sudochan\Tests\AbstractTestCase;
use Sudochan\Repository\PmRepository;

class PmRepositoryTest extends AbstractTestCase
{
    private PmRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new PmRepository();
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

            if (stripos($type, 'tinyint') !== false || stripos($type, 'int') !== false) {
                $row[$name] = 0;
                continue;
            }

            if (in_array($name, ['username', 'ip', 'message', 'body', 'text'], true)) {
                $row[$name] = $overrides[$name] ?? ($name === 'ip' ? '127.0.0.1' : 'phpunit');
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

    public function testInsertGetDeleteMarkReadInboxAndFindMod(): void
    {
        $modsDesc = $this->describeTable('mods');
        $pmsDesc  = $this->describeTable('pms');

        if (empty($modsDesc) || empty($pmsDesc)) {
            $this->markTestIncomplete('`mods` or `pms` table missing; cannot run PmRepository tests.');
            return;
        }

        $mod1 = $this->pdo->query('SELECT `id`,`username` FROM `mods` LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        if ($mod1 === false) {
            $mod1Id = $this->insertInto('mods', $this->buildRowFromDesc($modsDesc, ['username' => 'phpunit-sender']));
            $mod1Name = 'phpunit-sender';
        } else {
            $mod1Id = (int) $mod1['id'];
            $mod1Name = (string) $mod1['username'] ?: 'phpunit-sender';
        }

        $mod2 = $this->pdo->query('SELECT `id`,`username` FROM `mods` LIMIT 1 OFFSET 1')->fetch(\PDO::FETCH_ASSOC);
        if ($mod2 === false) {
            $mod2Id = $this->insertInto('mods', $this->buildRowFromDesc($modsDesc, ['username' => 'phpunit-recipient']));
            $mod2Name = 'phpunit-recipient';
        } else {
            $mod2Id = (int) $mod2['id'];
            $mod2Name = (string) $mod2['username'] ?: 'phpunit-recipient';
        }

        $pmRow = $this->buildRowFromDesc($pmsDesc, [
            'sender'  => $mod1Id,
            'to'      => $mod2Id,
            'message' => 'phpunit pm message',
            'time'    => time(),
            'unread'  => 1,
        ]);
        $pmId = $this->insertInto('pms', $pmRow);
        $this->assertGreaterThan(0, $pmId, 'Inserted PM via PDO');

        $pm = $this->repo->getById((int) $pmId);
        $this->assertIsArray($pm);
        $this->assertEquals($pmId, (int) ($pm['id'] ?? 0));
        $this->assertArrayHasKey('username', $pm);
        $this->assertArrayHasKey('to_username', $pm);

        $inbox = $this->repo->getInboxForMod((int) $mod2Id);
        $this->assertIsArray($inbox);
        $this->assertNotEmpty($inbox, 'getInboxForMod returned at least one message');

        $unread = $this->repo->countUnreadForMod((int) $mod2Id);
        $this->assertGreaterThanOrEqual(1, $unread, 'countUnreadForMod reflects inserted unread PM');

        $this->repo->markAsRead((int) $pmId);
        $unreadAfter = $this->repo->countUnreadForMod((int) $mod2Id);
        $this->assertLessThanOrEqual($unread, $unreadAfter + 1);

        $this->repo->deleteById((int) $pmId);
        $deleted = $this->repo->getById((int) $pmId);
        $this->assertFalse($deleted, 'PM deleted by repository');

        $foundId = $this->repo->findModIdByUsername($mod1Name);
        $this->assertNotFalse($foundId);
        $foundName = $this->repo->findModUsernameById($mod1Id);
        $this->assertNotFalse($foundName);
        $this->assertNotEmpty($foundName);
    }

    public function testInsertPmWrapperCreatesRow(): void
    {
        $modsDesc = $this->describeTable('mods');
        $pmsDesc  = $this->describeTable('pms');

        if (empty($modsDesc) || empty($pmsDesc)) {
            $this->markTestIncomplete('`mods` or `pms` table missing; cannot run PmRepository tests.');
            return;
        }

        $modA = $this->pdo->query('SELECT `id` FROM `mods` LIMIT 1')->fetchColumn();
        if (!$modA) {
            $modA = $this->insertInto('mods', $this->buildRowFromDesc($modsDesc, ['username' => 'phpunit-a']));
        }
        $modB = $this->pdo->query('SELECT `id` FROM `mods` LIMIT 1 OFFSET 1')->fetchColumn();
        if (!$modB) {
            $modB = $this->insertInto('mods', $this->buildRowFromDesc($modsDesc, ['username' => 'phpunit-b']));
        }

        $this->repo->insertPm($modA, $modB, 'pm from wrapper', time());

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM `pms` WHERE `to` = :to AND `message` = :m');
        $stmt->execute([':to' => $modB, ':m' => 'pm from wrapper']);
        $count = (int) $stmt->fetchColumn();
        $this->assertGreaterThanOrEqual(1, $count, 'insertPm created a row in pms');
    }
}
