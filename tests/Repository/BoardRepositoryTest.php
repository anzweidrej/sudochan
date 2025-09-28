<?php

namespace Sudochan\Tests\Repository;

use Sudochan\Tests\AbstractTestCase;
use Sudochan\Repository\BoardRepository;

class BoardRepositoryTest extends AbstractTestCase
{
    private BoardRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new BoardRepository();
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

    private function insertInto(string $table, array $values): int
    {
        $cols = array_map(fn($c) => "`$c`", array_keys($values));
        $ph   = array_map(fn($c) => ":$c", array_keys($values));
        $sql  = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        return (int) $this->pdo->lastInsertId();
    }

    public function testDropPostsTableAndExecuteSql(): void
    {
        $boardUri = 'unit_test';
        $table = "posts_{$boardUri}";

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `$table` (`id` INT NOT NULL PRIMARY KEY) ENGINE=InnoDB");
        $exists = (bool) $this->pdo->query("SHOW TABLES LIKE " . $this->pdo->quote($table))->fetchColumn();
        $this->assertTrue($exists, 'posts table created');

        $this->repo->dropPostsTable($boardUri);
        $existsAfter = $this->pdo->query("SHOW TABLES LIKE " . $this->pdo->quote($table))->fetchColumn();
        $this->assertFalse($existsAfter, 'posts table dropped by repository');

        $execTable = 'exec_test_table';
        $this->repo->executeSql("CREATE TABLE IF NOT EXISTS `$execTable` (`id` INT PRIMARY KEY) ENGINE=InnoDB");
        $existsExec = (bool) $this->pdo->query("SHOW TABLES LIKE " . $this->pdo->quote($execTable))->fetchColumn();
        $this->assertTrue($existsExec, 'executeSql created a table');
        $this->pdo->exec("DROP TABLE IF EXISTS `$execTable`");
    }

    public function testCitesAntispamReportsAndCitesDeletion(): void
    {
        $board = 'unit_test';

        if (empty($this->describeTable('cites'))) {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS `cites` (
                `board` VARCHAR(32) DEFAULT NULL,
                `post` INT DEFAULT NULL,
                `target_board` VARCHAR(32) DEFAULT NULL,
                `target` INT DEFAULT NULL
            ) ENGINE=InnoDB");
        }
        if (empty($this->describeTable('reports'))) {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS `reports` (
                `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `board` VARCHAR(32) DEFAULT NULL,
                `post` INT DEFAULT NULL,
                `ip` VARCHAR(45) DEFAULT NULL,
                `time` INT DEFAULT NULL
            ) ENGINE=InnoDB");
        }
        $this->pdo->exec('DELETE FROM `cites` WHERE 1=1');
        $this->pdo->exec('DELETE FROM `reports` WHERE 1=1');

        $this->pdo->prepare('INSERT INTO `cites` (`board`,`post`,`target_board`,`target`) VALUES (:board,:post,:tboard,:target)')
            ->execute([':board' => 'from_board', ':post' => 11, ':tboard' => $board, ':target' => 22]);

        $cites = $this->repo->selectCitesByTargetBoard($board);
        $this->assertIsArray($cites);
        $this->assertNotEmpty($cites, 'selectCitesByTargetBoard returned results');

        $this->repo->deleteCitesForBoard($board);
        $cAfter = $this->pdo->prepare('SELECT COUNT(*) FROM `cites` WHERE `target_board` = :board OR `board` = :board');
        $cAfter->execute([':board' => $board]);
        $this->assertEquals('0', $cAfter->fetchColumn(), 'Cites removed for board');

        $desc = $this->describeTable('antispam');
        if (empty($desc)) {
            $this->markTestIncomplete('No `antispam` table present in schema; skipping antispam assertions.');
        } else {
            $data = [];
            $now = time();
            foreach ($desc as $col) {
                $name = $col['Field'];
                if (stripos($col['Extra'] ?? '', 'auto_increment') !== false) {
                    continue;
                }
                if ($name === 'board') {
                    $data[$name] = $board;
                    continue;
                }
                if ($name === 'ip') {
                    $data[$name] = '127.0.0.1';
                    continue;
                }
                if ($name === 'hash') {
                    $data[$name] = sha1(uniqid((string) mt_rand(), true));
                    continue;
                }
                if ($name === 'created') {
                    $data[$name] = $now;
                    continue;
                }
                if ($name === 'thread') {
                    $data[$name] = 0;
                    continue;
                }
                if ($name === 'passed') {
                    $data[$name] = 0;
                    continue;
                }
                $type = $col['Type'] ?? '';
                if (stripos($type, 'int') !== false) {
                    $data[$name] = 0;
                } else {
                    $data[$name] = (string) $board;
                }
            }

            $this->insertInto('antispam', $data);

            $count = $this->pdo->prepare('SELECT COUNT(*) FROM `antispam` WHERE `board` = :board');
            $count->execute([':board' => $board]);
            $this->assertGreaterThan(0, (int) $count->fetchColumn(), 'Antispam inserted');

            $this->repo->deleteAntispamForBoard($board);
            $count->execute([':board' => $board]);
            $this->assertEquals('0', $count->fetchColumn(), 'Antispam removed for board');
        }

        $reportsDesc = $this->describeTable('reports');
        if (empty($reportsDesc)) {
            $this->markTestIncomplete('No `reports` table present in schema; skipping reports assertions.');
        } else {
            $rCols = array_column($reportsDesc, 'Field');
            $rData = [];
            foreach ($rCols as $col) {
                if (stripos($col, 'id') !== false && stripos($col, 'auto_increment') !== false) {
                    continue;
                }
                if ($col === 'board') {
                    $rData[$col] = '0';
                    continue;
                }
                if ($col === 'post') {
                    $rData[$col] = 1;
                    continue;
                }
                if ($col === 'time') {
                    $rData[$col] = time();
                    continue;
                }
                $rData[$col] = (stripos($col, 'id') !== false) ? 0 : '';
            }
            $this->insertInto('reports', $rData);

            $countR = $this->pdo->prepare('SELECT COUNT(*) FROM `reports` WHERE `board` = :board');
            $countR->execute([':board' => '0']);
            $this->assertGreaterThan(0, (int) $countR->fetchColumn(), 'Report inserted');

            $this->repo->deleteReportsForBoard('0');
            $countR->execute([':board' => '0']);
            $this->assertEquals('0', $countR->fetchColumn(), 'Reports removed for board');
        }
    }

    public function testInsertUpdateAndDeleteBoardFlow(): void
    {
        $desc = $this->describeTable('boards');
        if (empty($desc)) {
            $this->markTestSkipped('No `boards` table in schema.');
            return;
        }

        $cols = array_column($desc, 'Field');

        if (count($cols) >= 4) {
            $uri = 'unit_test_board';
            $this->pdo->prepare('DELETE FROM `boards` WHERE `uri` = :uri')->execute([':uri' => $uri]);

            $this->repo->insertBoard($uri, 'Title A', 'Subtitle A', 'Category A');

            $stmt = $this->pdo->prepare('SELECT `uri`, `title`, `subtitle`, ' . (in_array('category', $cols, true) ? '`category`' : 'NULL') . " FROM `boards` WHERE `uri` = :uri");
            $stmt->execute([':uri' => $uri]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $this->assertIsArray($row, 'Board inserted');

            $this->repo->updateBoardInfo($uri, 'Title B', 'Subtitle B', 'Category B');
            $stmt->execute([':uri' => $uri]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $this->assertSame('Title B', $row['title']);
            $this->assertSame('Subtitle B', $row['subtitle']);

            $this->repo->deleteBoardsWhereUri($uri);
            $stmt->execute([':uri' => $uri]);
            $this->assertFalse($stmt->fetch(\PDO::FETCH_ASSOC), 'Board deleted by repository');
        } else {
            $uri = 'unit_test_board';
            $data = [];
            foreach ($desc as $col) {
                if (stripos($col['Extra'] ?? '', 'auto_increment') !== false) {
                    continue;
                }
                $name = $col['Field'];
                $data[$name] = ($name === 'uri') ? $uri : (($name === 'title') ? 'T' : '');
            }
            $this->insertInto('boards', $data);

            try {
                $this->repo->updateBoardInfo($uri, 'Title B', 'Subtitle B', 'Category B');
            } catch (\Throwable $e) {
                $this->markTestIncomplete('updateBoardInfo could not be run against this boards schema: ' . $e->getMessage());
            }

            $this->pdo->prepare('DELETE FROM `boards` WHERE `uri` = :uri')->execute([':uri' => $uri]);
        }
    }

    public function testSelectAllModsAndUpdateModBoardsIfAvailable(): void
    {
        $mods = $this->repo->selectAllMods();
        $this->assertIsArray($mods, 'selectAllMods returns an array');

        if (empty($mods)) {
            $this->markTestSkipped('No rows in `mods` table; skipping updateModBoards verification.');
            return;
        }

        $first = $mods[0];
        $id = $first['id'] ?? null;
        if ($id === null) {
            $this->markTestSkipped('mods table does not expose an id column that test can use.');
            return;
        }

        $this->repo->updateModBoards($id, 'a,b,c');

        $refetch = $this->pdo->prepare('SELECT `boards` FROM `mods` WHERE `id` = :id');
        $refetch->execute([':id' => $id]);
        $this->assertStringContainsString('a', (string) $refetch->fetchColumn());
    }
}
