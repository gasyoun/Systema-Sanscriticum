<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendMessengerAlerts;
use App\Jobs\SendTelegramChatMessageJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\Intake;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GroupRecruitmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.curators_chat_id' => '-1001234567890']);
    }

    private function studentInGroup(Group $group, array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(['telegram_id' => fake()->unique()->numerify('#########')], $attrs));
        $user->groups()->attach($group->id);

        return $user;
    }

    /** @test */
    public function it_notifies_students_and_curators_when_a_forming_group_is_under_enrolled_within_the_lead_window(): void
    {
        Queue::fake();

        $course = Course::factory()->create();
        $group = Group::create([
            'name' => 'Поток 61',
            'status' => 'forming',
            'min_size' => 5,
            'planned_start_date' => today()->addDays(2),
        ]);
        $course->groups()->attach($group->id);
        $student = $this->studentInGroup($group);

        $this->artisan('groups:notify-forming-shortfall')->assertSuccessful();

        Queue::assertPushed(SendMessengerAlerts::class, fn ($job) => $job->user->is($student) && str_contains($job->text, 'недобор') === false && str_contains($job->text, 'набирается'));
        Queue::assertPushed(SendTelegramChatMessageJob::class, fn ($job) => str_contains($job->text, 'недобрана'));
        $this->assertNotNull($group->fresh()->recruitment_notified_at);
    }

    /** @test */
    public function it_does_not_notify_when_the_group_already_reached_min_size(): void
    {
        Queue::fake();

        $group = Group::create([
            'name' => 'Набрана',
            'status' => 'forming',
            'min_size' => 1,
            'planned_start_date' => today()->addDays(2),
        ]);
        $this->studentInGroup($group);

        $this->artisan('groups:notify-forming-shortfall')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertNull($group->fresh()->recruitment_notified_at);
    }

    /** @test */
    public function it_does_not_notify_twice_for_the_same_shortfall(): void
    {
        Queue::fake();

        $group = Group::create([
            'name' => 'Дедуп',
            'status' => 'forming',
            'min_size' => 5,
            'planned_start_date' => today()->addDays(2),
        ]);
        $this->studentInGroup($group);

        $this->artisan('groups:notify-forming-shortfall')->assertSuccessful();
        $this->artisan('groups:notify-forming-shortfall')->assertSuccessful();

        Queue::assertPushed(SendMessengerAlerts::class, 1);
    }

    /** @test */
    public function it_ignores_groups_outside_the_lead_window(): void
    {
        Queue::fake();

        $group = Group::create([
            'name' => 'Ещё рано',
            'status' => 'forming',
            'min_size' => 5,
            'planned_start_date' => today()->addDays(10),
        ]);
        $this->studentInGroup($group);

        $this->artisan('groups:notify-forming-shortfall')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_ignores_groups_without_a_min_size(): void
    {
        Queue::fake();

        $group = Group::create([
            'name' => 'Без порога',
            'status' => 'forming',
            'planned_start_date' => today()->addDays(2),
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
            'status' => 'forming',
            'min_size' => 5,
            'planned_start_date' => today()->addDays(2),
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
            'name' => 'Окно 5',
            'status' => 'forming',
            'min_size' => 5,
            'planned_start_date' => today()->addDays(5),
        ]);
        $student = $this->studentInGroup($group);

        $this->artisan('groups:notify-forming-shortfall')->assertSuccessful();

        Queue::assertPushed(SendMessengerAlerts::class, fn ($job) => $job->user->is($student));
    }

    /** @test */
    public function rescheduling_the_start_date_re_arms_the_shortfall_notification(): void
    {
        $group = Group::create([
            'name' => 'Перенос',
            'status' => 'forming',
            'min_size' => 5,
            'planned_start_date' => today()->addDays(2),
            'recruitment_notified_at' => now(),
        ]);

        $group->update(['start_date_override' => today()->addDays(20)]);

        $this->assertNull($group->fresh()->recruitment_notified_at);
        $this->assertTrue($group->fresh()->effectiveStartDate()->isSameDay(today()->addDays(20)));
    }

    /** @test */
    public function group_is_recruited_once_active_users_reach_min_size(): void
    {
        $group = Group::create(['name' => 'Порог', 'min_size' => 2]);
        $this->assertFalse($group->isRecruited());

        $this->studentInGroup($group);
        $this->assertFalse($group->fresh()->isRecruited());

        $this->studentInGroup($group);
        $this->assertTrue($group->fresh()->isRecruited());
    }

    /** @test */
    public function group_without_min_size_is_always_considered_recruited(): void
    {
        $group = Group::create(['name' => 'Без лимита']);

        $this->assertTrue($group->isRecruited());
    }

    /** @test */
    public function waitlist_preferred_schedule_is_the_existing_availability_signal_for_a_forming_group(): void
    {
        $intake = Intake::create(['label' => 'Июль 2026', 'target_year' => 2026, 'status' => 'forming']);
        $group = Group::create(['name' => 'Поток 62', 'intake_id' => $intake->id, 'status' => 'forming']);

        WaitlistEntry::create([
            'intake_id' => $intake->id,
            'name' => 'Ольга',
            'preferred_schedule' => 'Суббота 9:00',
            'timezone_note' => '+3 МСК',
        ]);

        $this->assertSame(1, $group->intake->waitlistEntries()->count());
        $this->assertSame('Суббота 9:00', $group->intake->waitlistEntries()->first()->preferred_schedule);
    }
}
