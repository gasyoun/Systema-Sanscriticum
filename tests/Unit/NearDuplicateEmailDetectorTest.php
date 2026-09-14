<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Services\NearDuplicateEmailDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NearDuplicateEmailDetectorTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function flags_a_typo_domain_with_the_same_local_part(): void
    {
        $existing = User::factory()->create(['email' => 'anastasiadolgopolova25@gmail.com']);
        $newUser = User::factory()->create(['email' => 'anastasiadolgopolova25@gmail.con']);

        $found = (new NearDuplicateEmailDetector)->findFor($newUser);

        $this->assertTrue($found->contains(fn (User $u) => $u->is($existing)));
    }

    /** @test */
    public function ignores_an_exact_match(): void
    {
        // Two rows can't share a unique email in prod, but the detector's own
        // exact-match guard must not fire even if it somehow saw one.
        $user = User::factory()->create(['email' => 'same@example.test']);

        $found = (new NearDuplicateEmailDetector)->findFor($user);

        $this->assertTrue($found->isEmpty());
    }

    /** @test */
    public function ignores_a_different_local_part_even_if_short(): void
    {
        User::factory()->create(['email' => 'ab@example.test']);
        $newUser = User::factory()->create(['email' => 'cd@example.test']);

        $found = (new NearDuplicateEmailDetector)->findFor($newUser);

        $this->assertTrue($found->isEmpty());
    }

    /** @test */
    public function ignores_same_local_part_on_an_unrelated_domain(): void
    {
        User::factory()->create(['email' => 'anna@yandex.ru']);
        $newUser = User::factory()->create(['email' => 'anna@protonmail.com']);

        $found = (new NearDuplicateEmailDetector)->findFor($newUser);

        $this->assertTrue($found->isEmpty());
    }

    /** @test */
    public function flags_a_common_provider_typo(): void
    {
        $existing = User::factory()->create(['email' => 'ivan@gmail.com']);
        $newUser = User::factory()->create(['email' => 'ivan@gmial.com']);

        $found = (new NearDuplicateEmailDetector)->findFor($newUser);

        $this->assertTrue($found->contains(fn (User $u) => $u->is($existing)));
    }
}
