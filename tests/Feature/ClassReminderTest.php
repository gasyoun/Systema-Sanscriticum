<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendMessengerAlerts;
use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClassReminderTest extends TestCase
{
    use RefreshDatabase;

    private function studentInGroup(Group $group, array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(['telegram_id' => fake()->unique()->numerify('#########')], $attrs));
        $user->groups()->attach($group->id);

        return $user;
    }

    /** @test */
    public function it_reminds_group_students_about_a_class_within_the_window(): void
    {
        Queue::fake();

        $group = Group::create(['name' => 'Поток А']);
        $student = $this->studentInGroup($group);

        $schedule = Schedule::create([
            'title' => 'Грамматика',
            'group_id' => $group->id,
            'start' => now()->addMinutes(30),
            'link' => 'https://zoom.us/j/123',
        ]);

        $this->artisan('classes:remind-upcoming')->assertSuccessful();

        Queue::assertPushed(SendMessengerAlerts::class, fn ($job) => $job->user->is($student));
        $this->assertNotNull($schedule->fresh()->reminded_at);
    }

    /** @test */
    public function it_does_not_remind_twice_for_the_same_class(): void
    {
        Queue::fake();

        $group = Group::create(['name' => 'Поток Б']);
        $this->studentInGroup($group);

        Schedule::create([
            'title' => 'Лекция',
            'group_id' => $group->id,
            'start' => now()->addMinutes(20),
        ]);

        $this->artisan('classes:remind-upcoming')->assertSuccessful();
        $this->artisan('classes:remind-upcoming')->assertSuccessful(); // дедуп

        Queue::assertPushed(SendMessengerAlerts::class, 1);
    }

    /** @test */
    public function it_ignores_classes_outside_the_lead_window(): void
    {
        Queue::fake();

        $group = Group::create(['name' => 'Поток В']);
        $this->studentInGroup($group);

        Schedule::create([
            'title' => 'Поздно',
            'group_id' => $group->id,
            'start' => now()->addHours(3),
        ]);

        $this->artisan('classes:remind-upcoming')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_skips_students_without_a_linked_messenger(): void
    {
        Queue::fake();

        $group = Group::create(['name' => 'Поток Г']);
        $this->studentInGroup($group, ['telegram_id' => null, 'vk_id' => null]);

        Schedule::create([
            'title' => 'Без мессенджера',
            'group_id' => $group->id,
            'start' => now()->addMinutes(15),
        ]);

        $this->artisan('classes:remind-upcoming')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_reminds_course_students_when_class_is_scoped_to_a_course(): void
    {
        Queue::fake();

        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Группа курса']);
        $course->groups()->attach($group->id);
        $student = $this->studentInGroup($group);

        Schedule::create([
            'title' => 'Общее по курсу',
            'course_id' => $course->id, // group_id null → берём всех студентов курса
            'start' => now()->addMinutes(40),
        ]);

        $this->artisan('classes:remind-upcoming')->assertSuccessful();

        Queue::assertPushed(SendMessengerAlerts::class, fn ($job) => $job->user->is($student));
    }

    /** @test */
    public function it_does_not_remind_when_disabled_in_settings(): void
    {
        Queue::fake();
        MarketingSetting::create(['class_reminders_enabled' => false]);

        $group = Group::create(['name' => 'Выключено']);
        $this->studentInGroup($group);

        Schedule::create([
            'title' => 'Занятие',
            'group_id' => $group->id,
            'start' => now()->addMinutes(30),
        ]);

        $this->artisan('classes:remind-upcoming')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_uses_lead_minutes_from_settings(): void
    {
        Queue::fake();
        // Окно расширено настройкой до 120 мин — занятие через 90 мин попадает.
        MarketingSetting::create(['class_reminder_lead_minutes' => 120]);

        $group = Group::create(['name' => 'Окно 120']);
        $student = $this->studentInGroup($group);

        Schedule::create([
            'title' => 'Через 90 минут',
            'group_id' => $group->id,
            'start' => now()->addMinutes(90),
        ]);

        $this->artisan('classes:remind-upcoming')->assertSuccessful();

        Queue::assertPushed(SendMessengerAlerts::class, fn ($job) => $job->user->is($student));
    }

    /** @test */
    public function default_lead_window_excludes_class_in_90_minutes(): void
    {
        Queue::fake();
        // Без настройки — дефолт 60 мин: занятие через 90 мин НЕ попадает.
        $group = Group::create(['name' => 'Дефолт 60']);
        $this->studentInGroup($group);

        Schedule::create([
            'title' => 'Через 90 минут',
            'group_id' => $group->id,
            'start' => now()->addMinutes(90),
        ]);

        $this->artisan('classes:remind-upcoming')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** @test */
    public function rescheduling_a_class_re_arms_the_reminder(): void
    {
        $group = Group::create(['name' => 'Перенос']);

        $schedule = Schedule::create([
            'title' => 'Перенос',
            'group_id' => $group->id,
            'start' => now()->addMinutes(30),
            'reminded_at' => now(),
        ]);

        $schedule->update(['start' => now()->addDay()]);

        $this->assertNull($schedule->fresh()->reminded_at);
    }
}
