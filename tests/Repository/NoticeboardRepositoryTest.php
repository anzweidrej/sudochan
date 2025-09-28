<?php

namespace Sudochan\Tests\Repository;

use Sudochan\Tests\AbstractTestCase;
use Sudochan\Repository\NoticeboardRepository;

class NoticeboardRepositoryTest extends AbstractTestCase
{
    private NoticeboardRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new NoticeboardRepository();
    }

    private function describeTable(string $table): array
    {
        return $this->pdo->query("DESCRIBE `$table`")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function testInsertFetchCountAndDeleteFlow(): void
    {
        $desc = $this->describeTable('noticeboard');
        if (empty($desc)) {
            $this->markTestIncomplete('No `noticeboard` table present; skipping NoticeboardRepository tests.');
            return;
        }

        $modsDesc = $this->describeTable('mods');
        if (empty($modsDesc)) {
            $haveMods = false;
        } else {
            $haveMods = true;
        }

        $name = 'phpunit';
        $time = time();
        $subject = 'phpunit notice ' . uniqid();
        $body = 'This is a test noticeboard entry';

        $before = (int) $this->repo->countNoticeboard();

        $modId = 0;
        if ($haveMods) {
            $modId = (int) ($this->pdo->query('SELECT `id` FROM `mods` LIMIT 1')->fetchColumn() ?: 0);
            if ($modId === 0) {
                $cols = array_column($modsDesc, 'Field');
                $row = [];
                foreach ($modsDesc as $col) {
                    $f = $col['Field'];
                    if (stripos($col['Extra'] ?? '', 'auto_increment') !== false) {
                        continue;
                    }
                    if ($f === 'username') {
                        $row[$f] = 'phpunit-mod';
                        continue;
                    }
                    $type = $col['Type'] ?? '';
                    if (stripos($type, 'int') !== false) {
                        $row[$f] = 0;
                    } else {
                        $row[$f] = 'phpunit';
                    }
                }
                $colsQ = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
                $ph = implode(',', array_map(fn($c) => ":$c", array_keys($row)));
                $stmt = $this->pdo->prepare("INSERT INTO `mods` ($colsQ) VALUES ($ph)");
                foreach ($row as $k => $v) {
                    $stmt->bindValue(":$k", $v);
                }
                $stmt->execute();
                $modId = (int) $this->pdo->lastInsertId();
            }
        }

        $insertedId = $this->repo->insertNotice($modId, $time, $subject, $body);
        $this->assertGreaterThan(0, $insertedId, 'insertNotice returned an id');

        $after = (int) $this->repo->countNoticeboard();
        $this->assertGreaterThanOrEqual($before + 1, $after, 'countNoticeboard increased after insert');

        $rows = $this->repo->fetchNoticeboard(0, 50);
        $this->assertIsArray($rows, 'fetchNoticeboard returned an array');
        $found = false;
        foreach ($rows as $r) {
            if ((int) ($r['id'] ?? 0) === (int) $insertedId) {
                $found = true;
                $this->assertSame($subject, (string) ($r['subject'] ?? ''));
                $this->assertSame($body, (string) ($r['body'] ?? ''));
                if ($haveMods) {
                    $this->assertArrayHasKey('username', $r, 'fetchNoticeboard returns username when mods table exists');
                }
                break;
            }
        }
        $this->assertTrue($found, 'Inserted noticeboard entry found in fetchNoticeboard results');

        $this->repo->deleteNotice((int) $insertedId);

        $final = (int) $this->repo->countNoticeboard();
        $this->assertEquals($before, $final, 'countNoticeboard returned to previous value after delete');

        $rowsAfter = $this->repo->fetchNoticeboard(0, 50);
        $ids = array_map(fn($r) => (int) ($r['id'] ?? 0), $rowsAfter);
        $this->assertNotContains((int) $insertedId, $ids, 'Deleted noticeboard id not present after delete');
    }
}
