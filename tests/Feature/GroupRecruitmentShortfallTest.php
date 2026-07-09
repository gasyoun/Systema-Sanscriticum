<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendMessengerAlerts;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GroupRecruitmentShortfallTest extends TestCase
{
    use RefreshDatabase;

    private function studentInGroup(Group $group, array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(['telegram_id' => fake()->unique()->numerify('#########')], $attrs));
        $user->groups()->attach($group->id);

        return $user;
    }

    /** @test */
    public function it_notifies_students_when_group_is_under_enrolled_within_the_lead_window(): void
    {
        Queue::fake();

        $group = Group::create([
            'name' => 'Недобор А',
            'status' => Group::STATUS_FORMING,
            'min_size' => 5,
            'planned_start_date' => now()->addDays(2)->toDateString(),
        ]);
        $student = $this->studentInGroup($group);

        $this->artisan('groups:notify-forming-shortfall')->assertSuccessful();

        Queue::assertPushed(SendMessengerAlerts::class, fn ($job) => $job->user->is($student));
        $this->assertNotNull($group->fresh()->recruitment_notified_at);
    }

    /** @test */
    public function it_does_not_notify_twice_for_the_same_group(): void
    {
        Queue::fake();

        $group = Group::create([
            'name' => 'Недобор Б',
            'status' => Group::STATUS_FORMING,
            'min_size' => 5,
            'planned_start_date' => now()->addDays(2)->toDateString(),
        ]);
        $this->studentInGroup($group);

        $this->artisan('groups:notify-forming-shortfall')->assertSuccessful();
        $this->artisan('groups:notify-forming-shortfall')->assertSuccessful(); // дедуп

        Queue::assertPushed(SendMessengerAlerts::class, 1);
    }

    /** @test */
    public function it_ignores_groups_outside_the_lead_window(): void
    {
        Queue::fake();

        $group = Group::create([
            'name' => 'Далеко',
            'status' => Group::STATUS_FORMING,
            'min_size' => 5,
            'planned_start_date' => now()->addDays(10)->toDateString(),
        ]);
        $this->studentInGroup($group);

        $this->artisan('groups:notify-forming-shortfall')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_does_not_notify_a_group_that_already_reached_min_size(): void
    {
        Queue::fake();

        $group = Group::create([
            'name' => 'Набрана',
            'status' => Group::STATUS_FORMING,
            'min_size' => 1,
            'planned_start_date' => now()->addDays(2)->toDateString(),
        ]);
        $this->studentInGroup($group);

        $this->artisan('groups:notify-forming-shortfall')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_does_not_notify_when_disabled_in_settings(): void
    {
        Queue::fake();
        MarketingSetting::create(['recruitment_notify_enabled' => false]);

        $group = Group::create([
            'name' => 'Выключено',
            'status' => Group::STATUS_FORMING,
            'min_size' => 5,
            'planned_start_date' => now()->addDays(2)->toDateString(),
        ]);
        $this->studentInGroup($group);

        $this->artisan('groups:notify-forming-shortfall')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_uses_lead_days_from_settings(): void
    {
        Queue::fake();
        MarketingSetting::create(['recruitment_notify_lead_days' => 5]);

        $group = Group::create([
            'name' => 'Окно 5 дней',
            'status' => Group::STATUS_FORMING,
            'min_size' => 5,
            'planned_start_date' => now()->addDays(5)->toDateString(),
        ]);
        $student = $this->studentInGroup($group);

        $this->artisan('groups:notify-forming-shortfall')->assertSuccessful();

        Queue::assertPushed(SendMessengerAlerts::class, fn ($job) => $job->user->is($student));
    }

    /** @test */
    public function overriding_the_start_date_re_arms_the_notification(): void
    {
        $group = Group::create([
            'name' => 'Перенос',
            'status' => Group::STATUS_FORMING,
            'min_size' => 5,
            'planned_start_date' => now()->addDays(2)->toDateString(),
            'recruitment_notified_at' => now(),
        ]);

        $group->update(['start_date_override' => now()->addDays(9)->toDateString()]);

        $this->assertNull($group->fresh()->recruitment_notified_at);
    }

    /** @test */
    public function active_groups_are_not_notified(): void
    {
        Queue::fake();

        $group = Group::create([
            'name' => 'Уже активна',
            'status' => Group::STATUS_ACTIVE,
            'min_size' => 5,
            'planned_start_date' => now()->addDays(2)->toDateString(),
        ]);
        $this->studentInGroup($group);

        $this->artisan('groups:notify-forming-shortfall')->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
