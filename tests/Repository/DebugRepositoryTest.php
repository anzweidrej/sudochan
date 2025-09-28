<?php

namespace Sudochan\Tests\Repository;

use Sudochan\Tests\AbstractTestCase;
use Sudochan\Repository\DebugRepository;

class DebugRepositoryTest extends AbstractTestCase
{
    private DebugRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new DebugRepository();
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
                if (in_array($name, ['passed', 'unread', 'deleted', 'thread', 'post', 'isreply'], true)) {
                    $row[$name] = 0;
                } else {
                    $row[$name] = 0;
                }
                continue;
            }

            if (in_array($name, ['board', 'target_board', 'username', 'uri', 'title', 'name'], true)) {
                $row[$name] = 'unit_test';
                continue;
            }
            if ($name === 'hash') {
                $row[$name] = sha1(uniqid((string) mt_rand(), true));
                continue;
            }
            if ($name === 'ip') {
                $row[$name] = '127.0.0.1';
                continue;
            }

            $row[$name] = 'phpunit';
        }
        return $row;
    }

    private function insertUsingApp(string $table, array $values): int
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

    public function testAntispamCountsTopRecentAndPurge(): void
    {
        $desc = $this->describeTable('antispam');
        if (empty($desc)) {
            $this->markTestIncomplete('No `antispam` table present; skipping antispam tests.');
            return;
        }

        $now = time();
        $r1 = $this->buildRowFromDesc($desc, ['board' => 'unit_test', 'passed' => 0, 'created' => $now - 300, 'expires' => null]);
        $r2 = $this->buildRowFromDesc($desc, ['board' => 'unit_test', 'passed' => 2, 'created' => $now - 200, 'expires' => null]);
        $r3 = $this->buildRowFromDesc($desc, ['board' => 'unit_test', 'passed' => 1, 'created' => $now - 100, 'expires' => $now + 3600]);

        $this->insertUsingApp('antispam', $r1);
        $this->insertUsingApp('antispam', $r2);
        $this->insertUsingApp('antispam', $r3);

        $count = $this->repo->countAntispam("`board` = " . $this->pdo->quote('unit_test'));
        $this->assertGreaterThanOrEqual(3, $count, 'countAntispam should include inserted rows');

        $expiring = $this->repo->countExpiringAntispam("`board` = " . $this->pdo->quote('unit_test'));
        $this->assertGreaterThanOrEqual(1, $expiring, 'countExpiringAntispam should count rows with expires');

        $top = $this->repo->getTopAntispam("`board` = " . $this->pdo->quote('unit_test'));
        $this->assertIsArray($top);
        $this->assertNotEmpty($top, 'getTopAntispam returned rows');

        $recent = $this->repo->getRecentAntispam("`board` = " . $this->pdo->quote('unit_test'));
        $this->assertIsArray($recent);
        $this->assertNotEmpty($recent, 'getRecentAntispam returned rows');

        $target = $this->buildRowFromDesc($desc, ['board' => 'purge_test_board', 'expires' => null]);
        $this->insertUsingApp('antispam', $target);

        $where = '`board` = ' . $this->pdo->quote('purge_test_board');
        $this->repo->purgeAntispam($where, 3600);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM `antispam` WHERE `board` = :board AND `expires` IS NOT NULL');
        $stmt->execute([':board' => 'purge_test_board']);
        $this->assertGreaterThanOrEqual(1, (int) $stmt->fetchColumn(), 'purgeAntispam should set expires for matching rows');
    }

    public function testGetFloodPosts(): void
    {
        $desc = $this->describeTable('flood');
        if (empty($desc)) {
            $this->markTestIncomplete('No `flood` table present; skipping flood tests.');
            return;
        }

        $row = $this->buildRowFromDesc($desc, ['time' => time()]);
        $this->insertUsingApp('flood', $row);

        $flood = $this->repo->getFloodPosts();
        $this->assertIsArray($flood);
        $this->assertNotEmpty($flood, 'getFloodPosts returned data');
    }

    public function testGetRecentPostsAcrossBoards(): void
    {
        $boardsDesc = $this->describeTable('boards');
        if (empty($boardsDesc)) {
            $this->markTestIncomplete('No `boards` table present; skipping getRecentPosts test.');
            return;
        }

        $uri = 'unit_test_dbg';
        $stmt = $this->pdo->prepare('DELETE FROM `boards` WHERE `uri` = :uri');
        $stmt->execute([':uri' => $uri]);

        $boardRow = $this->buildRowFromDesc($boardsDesc, ['uri' => $uri, 'title' => 'PHPUnit']);
        $this->insertUsingApp('boards', $boardRow);

        $table = "posts_{$uri}";
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `$table` (
            `id` INT NOT NULL PRIMARY KEY,
            `time` INT DEFAULT NULL
        ) ENGINE=InnoDB");

        $stmt = $this->pdo->prepare("DELETE FROM `$table` WHERE `id` = :id");
        $stmt->execute([':id' => 55555]);

        $stmt = $this->pdo->prepare("INSERT INTO `$table` (`id`, `time`) VALUES (:id, :time)");
        $stmt->bindValue(':id', 55555, \PDO::PARAM_INT);
        $stmt->bindValue(':time', time(), \PDO::PARAM_INT);
        $stmt->execute();

        $uris = $this->pdo->query('SELECT `uri` FROM `boards`')->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        foreach ($uris as $u) {
            $u = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $u);
            if ($u === '') {
                continue;
            }
            $t = "posts_" . $u;
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS `$t` (`id` INT NOT NULL PRIMARY KEY, `time` INT DEFAULT NULL) ENGINE=InnoDB");
        }

        $posts = $this->repo->getRecentPosts(50);
        $this->assertIsArray($posts);
        $found = false;
        foreach ($posts as $p) {
            if (($p['board'] ?? '') === $uri && (int) ($p['id'] ?? 0) === 55555) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'getRecentPosts returned the inserted post with board field');

        $this->pdo->exec("DROP TABLE IF EXISTS `$table`");
    }
}
