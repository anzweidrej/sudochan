<?php

namespace Sudochan\Tests\Repository;

use Sudochan\Tests\AbstractTestCase;
use Sudochan\Repository\PostRepository;

class PostRepositoryTest extends AbstractTestCase
{
    private PostRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new PostRepository();
    }

    private function seedThreadAndReply(string $board, int $threadId = 1000, int $replyId = 1001): void
    {
        $table = "posts_{$board}";

        $this->ensurePostsTable($board);

        $this->pdo->prepare("DELETE FROM `$table` WHERE `id` IN (:t,:r)")
            ->execute([':t' => $threadId, ':r' => $replyId]);

        $now = time();

        $this->pdo->prepare("INSERT INTO `$table` (`id`,`thread`,`name`,`ip`,`body_nomarkup`,`time`) VALUES (:id,:thread,:name,:ip,:body_nomarkup,:time)")
            ->execute([':id' => $threadId, ':thread' => null, ':name' => 'orig', ':ip' => '127.0.0.1', ':body_nomarkup' => 'orig-body', ':time' => $now]);

        $this->pdo->prepare("INSERT INTO `$table` (`id`,`thread`,`name`,`ip`,`body_nomarkup`,`time`) VALUES (:id,:thread,:name,:ip,:body_nomarkup,:time)")
            ->execute([':id' => $replyId, ':thread' => $threadId, ':name' => 'reply', ':ip' => '127.0.0.1', ':body_nomarkup' => 'reply-body', ':time' => $now]);
    }

    public function testGetThreadById(): void
    {
        $board = 'unit_test';
        $this->seedThreadAndReply($board);

        $thread = $this->repo->selectThreadById($board, 1000);
        $this->assertIsArray($thread);
        $this->assertEquals(1000, (int) ($thread['id'] ?? 0));
    }

    public function testGetRepliesByThread(): void
    {
        $board = 'unit_test';
        $this->seedThreadAndReply($board);

        $replies = $this->repo->selectRepliesByThread($board, 1000);
        $this->assertIsArray($replies);
        $this->assertNotEmpty($replies);
        $this->assertEquals(1001, (int) ($replies[0]['id'] ?? 0));
    }

    public function testUpdateThreadFlags(): void
    {
        $board = 'unit_test';
        $this->seedThreadAndReply($board);

        $this->repo->updateLock($board, 1000, 1);
        $this->repo->updateSticky($board, 1000, 1);
        $this->repo->updateBumplock($board, 1000, 1);

        $after = $this->repo->selectThreadById($board, 1000);
        $this->assertEquals(1, (int) ($after['locked'] ?? 0));
        $this->assertEquals(1, (int) ($after['sticky'] ?? 0));
        $this->assertEquals(1, (int) ($after['sage'] ?? 0));
    }

    public function testUpdatePostRawAndSelectPostById(): void
    {
        $board = 'unit_test';
        $this->seedThreadAndReply($board);

        $this->repo->updatePostRaw($board, 1000, 'newname', 'addr@example.test', 's', 'body html', 'body raw');
        $post = $this->repo->selectPostById($board, 1000);
        $this->assertIsArray($post);
        $this->assertEquals('newname', $post['name'] ?? '');
        $this->assertEquals('addr@example.test', $post['email'] ?? '');
        $this->assertEquals('s', $post['subject'] ?? '');
        $this->assertEquals('body raw', $post['body_nomarkup'] ?? '');
    }
}
