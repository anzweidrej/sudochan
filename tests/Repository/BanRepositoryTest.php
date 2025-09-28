<?php

namespace Sudochan\Tests\Repository;

use Sudochan\Tests\AbstractTestCase;
use Sudochan\Repository\BanRepository;
use Sudochan\Bans;

class BanRepositoryTest extends AbstractTestCase
{
    private BanRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new BanRepository();
    }

    public function testAppealLifecycleAndStaleRemoval(): void
    {
        global $mod;

        $mod = $mod ?? [];

        $banId = Bans::new_ban('127.0.0.1', 'phpunit-test-reason', 0, false, false, false);
        $this->assertNotEmpty($banId, 'Bans::new_ban returned an id');

        $stmt = $this->pdo->prepare('INSERT INTO `ban_appeals` (`ban_id`, `time`, `message`, `denied`) VALUES (:ban_id, :time, :message, 0)');
        $now = time();
        $stmt->execute([':ban_id' => $banId, ':time' => $now, ':message' => 'phpunit appeal']);
        $appealId = (int) $this->pdo->lastInsertId();
        $this->assertGreaterThan(0, $appealId, 'Inserted ban appeal');

        $appeal = $this->repo->selectAppealById($appealId);
        $this->assertIsArray($appeal, 'selectAppealById returned an array');
        $this->assertEquals($appealId, (int) $appeal['id']);

        $active = $this->repo->selectActiveBanAppeals();
        $found = false;
        foreach ($active as $a) {
            if ((int) $a['id'] === $appealId) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Appeal is present in active appeals');

        $this->repo->denyAppealById($appealId);

        $activeAfterDeny = $this->repo->selectActiveBanAppeals();
        $foundAfterDeny = false;
        foreach ($activeAfterDeny as $a) {
            if ((int) $a['id'] === $appealId) {
                $foundAfterDeny = true;
                break;
            }
        }
        $this->assertFalse($foundAfterDeny, 'Appeal no longer present after deny');

        $this->repo->deleteAppealById($appealId);
        $deleted = $this->repo->selectAppealById($appealId);
        $this->assertFalse($deleted, 'Appeal deleted successfully');

        $stmt = $this->pdo->prepare('INSERT INTO `ban_appeals` (`ban_id`, `time`, `message`, `denied`) VALUES (:ban_id, :time, :message, 0)');
        $stmt->execute([':ban_id' => 999999999, ':time' => $now, ':message' => 'stale appeal']);
        $staleId = (int) $this->pdo->lastInsertId();
        $this->assertGreaterThan(0, $staleId, 'Inserted stale ban appeal');

        $this->repo->removeStaleBanAppeals();
        $stale = $this->repo->selectAppealById($staleId);
        $this->assertFalse($stale, 'Stale appeal removed by removeStaleBanAppeals');
    }

    public function testSelectPostThumbFile(): void
    {
        $this->pdo->exec('CREATE TABLE `posts_unit_test` (
            `id` INT NOT NULL PRIMARY KEY,
            `thumb` VARCHAR(255) DEFAULT NULL,
            `file` VARCHAR(255) DEFAULT NULL
        ) ENGINE=InnoDB');

        $stmt = $this->pdo->prepare('INSERT INTO `posts_unit_test` (`id`, `thumb`, `file`) VALUES (:id, :thumb, :file)');
        $stmt->execute([':id' => 12345, ':thumb' => 'thumb.png', ':file' => 'file.png']);

        $row = $this->repo->selectPostThumbFile('unit_test', 12345);
        $this->assertIsArray($row, 'selectPostThumbFile returned array');
        $this->assertEquals('thumb.png', $row['thumb']);
        $this->assertEquals('file.png', $row['file']);

        $this->pdo->exec('DROP TABLE IF EXISTS `posts_unit_test`');
    }
}
