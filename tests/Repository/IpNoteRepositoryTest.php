<?php

namespace Sudochan\Tests\Repository;

use Sudochan\Tests\AbstractTestCase;
use Sudochan\Repository\IpNoteRepository;

class IpNoteRepositoryTest extends AbstractTestCase
{
    private IpNoteRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new IpNoteRepository();
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

            if (in_array($name, ['username', 'board', 'ip', 'text', 'message', 'body', 'uri', 'title'], true)) {
                $row[$name] = $overrides[$name] ?? ($name === 'ip' ? '127.0.0.1' : 'phpunit');
                continue;
            }

            if ($name === 'hash') {
                $row[$name] = sha1(uniqid((string) mt_rand(), true));
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

    public function testInsertAndRemoveNoteAndGetIpNotes(): void
    {
        $modsDesc = $this->describeTable('mods');
        if (empty($modsDesc)) {
            $this->markTestIncomplete('No `mods` table present; cannot test insertNote/getIpNotes/removeNote.');
            return;
        }

        $modId = (int) ($this->pdo->query('SELECT `id` FROM `mods` LIMIT 1')->fetchColumn() ?: 0);
        if ($modId === 0) {
            $modRow = $this->buildRowFromDesc($modsDesc, ['username' => 'phpunit-mod']);
            $modId = $this->insertInto('mods', $modRow);
            $this->assertGreaterThanOrEqual(1, $modId, 'Inserted a mod for testing');
        }

        $ip = '198.51.100.5';
        $body = 'phpunit ip note';

        $this->repo->insertNote($ip, $modId, $body);

        $notes = $this->repo->getIpNotesQuery($ip);
        $this->assertIsArray($notes);
        $this->assertNotEmpty($notes, 'getIpNotesQuery returned at least one note');
        $found = false;
        foreach ($notes as $n) {
            if ((string) ($n['ip'] ?? '') === $ip && strpos((string) ($n['body'] ?? ''), 'phpunit') !== false) {
                $found = true;
                $noteId = (int) ($n['id'] ?? 0);
                $this->assertArrayHasKey('username', $n, 'Joined username present');
                break;
            }
        }
        $this->assertTrue($found, 'Inserted IP note found via getIpNotesQuery');

        $this->repo->removeNote($ip, $noteId);
        $after = $this->repo->getIpNotesQuery($ip);
        $this->assertNotContains($noteId, array_map(fn($r) => (int) ($r['id'] ?? 0), $after), 'Note removed by removeNote');
    }

    public function testGetPostsQuery(): void
    {
        $boardUri = 'unit_test_ipn';
        $table = "posts_{$boardUri}";

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `$table` (
            `id` INT NOT NULL PRIMARY KEY,
            `sticky` TINYINT DEFAULT 0,
            `ip` VARCHAR(45) DEFAULT NULL,
            `body` TEXT DEFAULT NULL
        ) ENGINE=InnoDB");

        $ip = '203.0.113.7';
        $this->pdo->prepare("DELETE FROM `$table` WHERE `id` = :id")->execute([':id' => 123456]);
        $stmt = $this->pdo->prepare("INSERT INTO `$table` (`id`,`sticky`,`ip`,`body`) VALUES (:id,:sticky,:ip,:body)");
        $stmt->execute([':id' => 123456, ':sticky' => 0, ':ip' => $ip, ':body' => 'phpunit post']);

        $GLOBALS['config']['mod']['ip_recentposts'] = 10;

        $board = ['uri' => $boardUri];
        $posts = $this->repo->getPostsQuery($board, $ip);
        $this->assertIsArray($posts);
        $this->assertNotEmpty($posts, 'getPostsQuery returned at least one post');
        $ids = array_map(fn($r) => (int) ($r['id'] ?? 0), $posts);
        $this->assertContains(123456, $ids, 'Inserted post returned by getPostsQuery');

        $this->pdo->exec("DROP TABLE IF EXISTS `$table`");
    }

    public function testGetModLogsByIpQuery(): void
    {
        $modlogsDesc = $this->describeTable('modlogs');
        if (empty($modlogsDesc)) {
            $this->markTestIncomplete('No `modlogs` table present; skipping getModLogsByIpQuery test.');
            return;
        }

        $modsDesc = $this->describeTable('mods');
        $modId = (int) ($this->pdo->query('SELECT `id` FROM `mods` LIMIT 1')->fetchColumn() ?: 0);
        if ($modId === 0 && !empty($modsDesc)) {
            $modId = $this->insertInto('mods', $this->buildRowFromDesc($modsDesc, ['username' => 'phpunit-mod']));
        }

        $ipFragment = '198.51.100.9';
        $row = $this->buildRowFromDesc($modlogsDesc, [
            'mod' => $modId ?: 0,
            'ip' => $ipFragment,
            'board' => 'b',
            'time' => time(),
            'text' => "Action referencing {$ipFragment}",
        ]);
        $this->insertInto('modlogs', $row);

        $logs = $this->repo->getModLogsByIpQuery($ipFragment);
        $this->assertIsArray($logs);
        $this->assertNotEmpty($logs, 'getModLogsByIpQuery returned at least one row');
        $found = false;
        foreach ($logs as $l) {
            if (strpos((string) ($l['text'] ?? ''), $ipFragment) !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Inserted modlog returned by getModLogsByIpQuery');
    }
}
