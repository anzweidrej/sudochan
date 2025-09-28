<?php

namespace Sudochan\Tests\Repository;

use Sudochan\Tests\AbstractTestCase;
use Sudochan\Repository\NewsRepository;

class NewsRepositoryTest extends AbstractTestCase
{
    private NewsRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new NewsRepository();
    }

    public function testInsertFetchCountAndDeleteFlow(): void
    {
        $desc = $this->pdo->query("DESCRIBE `news`")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if (empty($desc)) {
            $this->markTestIncomplete('No `news` table present in schema; skipping NewsRepository tests.');
            return;
        }

        $name    = 'phpunit';
        $time    = time();
        $subject = 'phpunit news ' . uniqid();
        $body    = 'This is a test news body';

        $before = (int) $this->repo->count();

        $id = $this->repo->insert($name, $time, $subject, $body);
        $this->assertGreaterThan(0, $id, 'insert returned a new id');

        $after = (int) $this->repo->count();
        $this->assertGreaterThanOrEqual($before + 1, $after, 'count increased after insert');

        $rows = $this->repo->fetchPage(0, 50);
        $this->assertIsArray($rows, 'fetchPage returned an array');
        $found = false;
        foreach ($rows as $r) {
            if ((int) ($r['id'] ?? 0) === (int) $id) {
                $found = true;
                $this->assertSame($subject, (string) ($r['subject'] ?? ''));
                $this->assertSame($body, (string) ($r['body'] ?? ''));
                break;
            }
        }
        $this->assertTrue($found, 'Inserted news row present in fetchPage results');

        $this->repo->deleteById((int) $id);

        $final = (int) $this->repo->count();
        $this->assertEquals($before, $final, 'count returned to before-insert value after delete');

        $rowsAfter = $this->repo->fetchPage(0, 50);
        $ids = array_map(fn($r) => (int) ($r['id'] ?? 0), $rowsAfter);
        $this->assertNotContains((int) $id, $ids, 'Deleted news id is not present after delete');
    }
}
