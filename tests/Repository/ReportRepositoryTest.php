<?php

namespace Sudochan\Tests\Repository;

use Sudochan\Tests\AbstractTestCase;
use Sudochan\Repository\ReportRepository;

class ReportRepositoryTest extends AbstractTestCase
{
    private ReportRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ReportRepository();
    }

    private function insertRow(string $table, array $values): int
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

    public function testGetRecentReportsAndGetReportById(): void
    {
        try {
            $res = $this->pdo->query("DESCRIBE `reports`");
            $desc = $res ? $res->fetchAll(\PDO::FETCH_ASSOC) : [];
        } catch (\Throwable $e) {
            $desc = [];
        }

        if (empty($desc)) {
            $this->markTestIncomplete('No `reports` table present; skipping reports tests.');
            return;
        }

        $now = time();
        $r1 = [];
        $r2 = [];
        foreach ($desc as $col) {
            $f = $col['Field'];
            if (stripos($col['Extra'] ?? '', 'auto_increment') !== false) {
                continue;
            }
            $type = $col['Type'] ?? '';
            if ($f === 'board') {
                $r1[$f] = 'rb';
                $r2[$f] = 'rb';
                continue;
            }
            if ($f === 'post') {
                $r1[$f] = 111;
                $r2[$f] = 222;
                continue;
            }
            if ($f === 'ip') {
                $r1[$f] = '203.0.113.1';
                $r2[$f] = '203.0.113.2';
                continue;
            }
            if ($f === 'time') {
                $r1[$f] = $now - 10;
                $r2[$f] = $now;
                continue;
            }
            if (stripos($type, 'int') !== false || stripos($type, 'tinyint') !== false) {
                $r1[$f] = 0;
                $r2[$f] = 0;
            } else {
                $r1[$f] = 'phpunit';
                $r2[$f] = 'phpunit';
            }
        }

        $id1 = $this->insertRow('reports', $r1);
        $id2 = $this->insertRow('reports', $r2);

        $rows = $this->repo->getRecentReports(10);
        $this->assertIsArray($rows);
        $this->assertNotEmpty($rows, 'getRecentReports returned rows');
        $ids = array_map(fn($r) => (int) ($r['id'] ?? 0), $rows);
        $this->assertTrue(in_array($id1, $ids, true) || in_array($id2, $ids, true), 'Inserted report ids present in recent results');

        $rep = $this->repo->getReportById($id1);
        $this->assertIsArray($rep);
        $this->assertEquals($id1, (int) ($rep['id'] ?? $id1));
        $this->assertArrayHasKey('post', $rep);
        $this->assertArrayHasKey('board', $rep);
    }

    public function testGetPostsForBoard(): void
    {
        $board = 'rtest';
        $table = "posts_{$board}";

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `$table` (
            `id` INT NOT NULL PRIMARY KEY,
            `body` TEXT DEFAULT NULL
        ) ENGINE=InnoDB");

        $this->pdo->prepare("DELETE FROM `$table` WHERE `id` IN (1001,1002)")->execute();

        $stmt = $this->pdo->prepare("INSERT INTO `$table` (`id`,`body`) VALUES (:id,:body)");
        $stmt->execute([':id' => 1001, ':body' => 'post1']);
        $stmt->execute([':id' => 1002, ':body' => 'post2']);

        $result = $this->repo->getPostsForBoard([1001, 1002], $board);
        $this->assertIsArray($result);
        $this->assertArrayHasKey(1001, $result);
        $this->assertArrayHasKey(1002, $result);

        $empty = $this->repo->getPostsForBoard([], $board);
        $this->assertSame([], $empty);

        $this->pdo->exec("DROP TABLE IF EXISTS `$table`");
    }

    public function testDeleteReportByPostAndBoardAndByIdAndByIp(): void
    {
        try {
            $res = $this->pdo->query("DESCRIBE `reports`");
            $desc = $res ? $res->fetchAll(\PDO::FETCH_ASSOC) : [];
        } catch (\Throwable $e) {
            $desc = [];
        }

        if (empty($desc)) {
            $this->markTestIncomplete('No `reports` table present; skipping delete tests.');
            return;
        }

        $now = time();
        $row = [];
        foreach ($desc as $col) {
            $f = $col['Field'];
            if (stripos($col['Extra'] ?? '', 'auto_increment') !== false) {
                continue;
            }
            $type = $col['Type'] ?? '';
            if ($f === 'board') {
                $row[$f] = 'delb';
                continue;
            }
            if ($f === 'post') {
                $row[$f] = 777;
                continue;
            }
            if ($f === 'ip') {
                $row[$f] = '198.51.100.9';
                continue;
            }
            if ($f === 'time') {
                $row[$f] = $now;
                continue;
            }
            if (stripos($type, 'int') !== false || stripos($type, 'tinyint') !== false) {
                $row[$f] = 0;
            } else {
                $row[$f] = 'phpunit';
            }
        }

        $insId = $this->insertRow('reports', $row);

        $this->repo->deleteReportByPostAndBoard(777, 'delb');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM `reports` WHERE `board` = :b AND `post` = :p');
        $stmt->execute([':b' => 'delb', ':p' => 777]);
        $this->assertEquals(0, (int) $stmt->fetchColumn(), 'deleteReportByPostAndBoard removed matching rows');

        $rowIp = $row;
        $rowIp['post'] = 888;
        $rowIp['board'] = 'delb2';
        $rowIp['ip'] = '198.51.100.9';
        $idIp = $this->insertRow('reports', $rowIp);

        $this->repo->deleteReportsByIp('198.51.100.9');
        $stmt2 = $this->pdo->prepare('SELECT COUNT(*) FROM `reports` WHERE `ip` = :ip');
        $stmt2->execute([':ip' => '198.51.100.9']);
        $this->assertEquals(0, (int) $stmt2->fetchColumn(), 'deleteReportsByIp removed matching rows');

        $rowId = $row;
        $rowId['post'] = 999;
        $rowId['board'] = 'delb3';
        $id = $this->insertRow('reports', $rowId);
        $this->repo->deleteReportById($id);
        $stmt3 = $this->pdo->prepare('SELECT COUNT(*) FROM `reports` WHERE `id` = :id');
        $stmt3->execute([':id' => $id]);
        $this->assertEquals(0, (int) $stmt3->fetchColumn(), 'deleteReportById removed the row');
    }
}
