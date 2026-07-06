<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Concerns\LocksMadelineSession;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MadelineSessionLockTest extends TestCase
{
    /** Anonymous host exposing the protected trait method for testing. */
    private function runner(): object
    {
        return new class
        {
            use LocksMadelineSession;

            public function run(callable $work, int $wait): mixed
            {
                return $this->withMadelineSessionLock($work, $wait);
            }
        };
    }

    public function test_work_runs_when_session_is_free(): void
    {
        $this->assertSame('done', $this->runner()->run(fn () => 'done', 0));
    }

    public function test_work_is_skipped_when_session_is_held_elsewhere(): void
    {
        // Simulate another MadelineProto command holding the shared session.
        $held = Cache::lock('madeline-session', 30);
        $this->assertTrue($held->get());

        $called = false;
        $result = $this->runner()->run(function () use (&$called) {
            $called = true;

            return 'done';
        }, 0);

        // Busy → returns null and never opens the session.
        $this->assertNull($result);
        $this->assertFalse($called);

        $held->release();
    }

    public function test_lock_is_released_after_work_so_the_next_run_proceeds(): void
    {
        $this->runner()->run(fn () => 'first', 0);
        // If the first run leaked the lock, this would come back busy (null).
        $this->assertSame('second', $this->runner()->run(fn () => 'second', 0));
    }
}
