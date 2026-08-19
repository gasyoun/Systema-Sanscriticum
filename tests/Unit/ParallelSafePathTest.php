<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ParallelSafePath;
use Tests\TestCase;

/**
 * H3156: пути под `storage/`, которые сервисы пишут в фиксированное место,
 * не должны совпадать у разных параллельных тест-воркеров.
 */
class ParallelSafePathTest extends TestCase
{
    private string|false $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = getenv('TEST_TOKEN');
    }

    protected function tearDown(): void
    {
        if ($this->original === false) {
            putenv('TEST_TOKEN');
            unset($_ENV['TEST_TOKEN']);
        } else {
            putenv('TEST_TOKEN='.$this->original);
            $_ENV['TEST_TOKEN'] = $this->original;
        }

        parent::tearDown();
    }

    private function putToken(?string $token): void
    {
        if ($token === null) {
            putenv('TEST_TOKEN');
            unset($_ENV['TEST_TOKEN']);

            return;
        }

        putenv('TEST_TOKEN='.$token);
        $_ENV['TEST_TOKEN'] = $token;
    }

    public function test_without_token_the_path_is_the_plain_storage_path(): void
    {
        $this->putToken(null);

        $this->assertSame(
            storage_path('app/grammar_lab_sample.json'),
            ParallelSafePath::storage('app/grammar_lab_sample.json'),
        );
        $this->assertNull(ParallelSafePath::token());
    }

    public function test_two_workers_never_share_a_path(): void
    {
        $this->putToken('1');
        $first = ParallelSafePath::storage('app/grammar_lab_sample.json');

        $this->putToken('2');
        $second = ParallelSafePath::storage('app/grammar_lab_sample.json');

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('grammar_lab_sample.json', $first);
        $this->assertStringContainsString('grammar_lab_sample.json', $second);
    }

    public function test_a_hostile_token_never_reaches_the_path(): void
    {
        // TEST_TOKEN приходит из окружения — подставлять его в путь как есть
        // не нужно; мусорный токен трактуется как «не параллельный прогон».
        $this->putToken('../../etc');

        $this->assertNull(ParallelSafePath::token());
        $this->assertSame(
            storage_path('app/x.json'),
            ParallelSafePath::storage('app/x.json'),
        );
    }

    public function test_leading_separators_do_not_escape_storage(): void
    {
        $this->putToken('3');

        $path = ParallelSafePath::storage('/app/x.json');

        $this->assertStringStartsWith(storage_path('parallel/3'), $path);
    }
}
