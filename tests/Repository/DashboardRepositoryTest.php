<?php

namespace Sudochan\Tests\Repository;

use Sudochan\Tests\AbstractTestCase;
use Sudochan\Repository\DashboardRepository;

class DashboardRepositoryTest extends AbstractTestCase
{
    private DashboardRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new DashboardRepository();
    }

    private function describeTable(string $table): array
    {
        try {
            $res = $this->pdo->query("DESCRIBE `$table`");
            if ($res === false) {
                return [];
            }
            return $res->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            return [];
        }
    }

    private function buildRowFromDesc(array $desc, array $overrides = []): array
    {
        $row = [];
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
            if ($name === 'username' || $name === 'name' || $name === 'message' || $name === 'body' || $name === 'notice') {
                $row[$name] = 'phpunit';
                continue;
            }
            if ($name === 'mod' || $name === 'to' || $name === 'board' || $name === 'post' || $name === 'time' || stripos($type, 'int') !== false) {
                $row[$name] = 0;
                continue;
            }
            if ($name === 'unread' || $name === 'deleted' || $name === 'seen') {
                $row[$name] = 1;
                continue;
            }
            $row[$name] = (string) ($overrides['fallback_string'] ?? 'phpunit');
        }
        return $row;
    }

    private function insertInto(string $table, array $values): int
    {
        $cols = array_map(fn($c) => "`$c`", array_keys($values));
        $ph   = array_map(fn($c) => ":$c", array_keys($values));
        $sql  = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        return (int) $this->pdo->lastInsertId();
    }

    public function testGetNoticeboardPreviewAndUnreadPmCountAndReportsCountAndPostsWithoutThread(): void
    {
        $modsDesc = $this->describeTable('mods');
        if (empty($modsDesc)) {
            $this->markTestIncomplete('No `mods` table present; cannot fully test noticeboard/pms behavior.');
            return;
        }

        $modId = $this->pdo->query('SELECT `id` FROM `mods` LIMIT 1')->fetchColumn();
        if (!$modId) {
            $modRow = $this->buildRowFromDesc($modsDesc, ['username' => 'phpunit-mod']);
            $modId = $this->insertInto('mods', $modRow);
            $this->assertGreaterThanOrEqual(1, $modId, 'Inserted mod for testing');
        }

        $nbDesc = $this->describeTable('noticeboard');
        if (empty($nbDesc)) {
            $this->markTestIncomplete('No `noticeboard` table present; skipping noticeboard assertions.');
        } else {
            $nbRow = $this->buildRowFromDesc($nbDesc, ['mod' => $modId, 'message' => 'phpunit notice', 'time' => time()]);
            $nbId = $this->insertInto('noticeboard', $nbRow);

            $preview = $this->repo->getNoticeboardPreview(5);
            $this->assertIsArray($preview, 'getNoticeboardPreview returned an array');
            $this->assertNotEmpty($preview, 'getNoticeboardPreview returned at least one row');

            $found = false;
            foreach ($preview as $row) {
                if ((int) ($row['id'] ?? 0) === $nbId) {
                    $found = true;
                    $this->assertArrayHasKey('username', $row);
                    break;
                }
            }
            $this->assertTrue($found, 'Inserted noticeboard row present in preview');
        }

        $pmsDesc = $this->describeTable('pms');
        if (empty($pmsDesc)) {
            $this->markTestIncomplete('No `pms` table present; skipping pm assertions.');
        } else {
            $pmRow = $this->buildRowFromDesc($pmsDesc, ['to' => $modId, 'unread' => 1, 'time' => time()]);
            $this->insertInto('pms', $pmRow);

            $count = $this->repo->getUnreadPmCount((int) $modId);
            $this->assertIsInt((int) $count);
            $this->assertGreaterThanOrEqual(1, (int) $count, 'Unread PM count increased after insert');
        }

        $reportsDesc = $this->describeTable('reports');
        if (empty($reportsDesc)) {
            $this->markTestIncomplete('No `reports` table present; skipping reports assertions.');
        } else {
            $before = (int) $this->repo->getReportsCount();
            $rRow = $this->buildRowFromDesc($reportsDesc, ['board' => '0', 'post' => 1, 'time' => time()]);
            $this->insertInto('reports', $rRow);
            $after = (int) $this->repo->getReportsCount();
            $this->assertGreaterThanOrEqual($before + 1, $after, 'getReportsCount increased after insert');
        }

        $boardUri = 'unit_test';
        $table = "posts_{$boardUri}";
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `$table` (
            `id` INT NOT NULL PRIMARY KEY,
            `thread` INT DEFAULT NULL
        ) ENGINE=InnoDB");

        $this->pdo->prepare("INSERT INTO `$table` (`id`, `thread`) VALUES (:id, :thread)")
            ->execute([':id' => 99991, ':thread' => null]);
        $this->pdo->prepare("INSERT INTO `$table` (`id`, `thread`) VALUES (:id, :thread)")
            ->execute([':id' => 99992, ':thread' => 1]);

        $posts = $this->repo->getPostsWithoutThread($boardUri);
        $this->assertIsArray($posts, 'getPostsWithoutThread returned array');
        $ids = array_map(fn($r) => (int) ($r['id'] ?? 0), $posts);
        $this->assertContains(99991, $ids, 'Post with NULL thread returned by getPostsWithoutThread');

        $this->pdo->exec("DROP TABLE IF EXISTS `$table`");
    }
}
