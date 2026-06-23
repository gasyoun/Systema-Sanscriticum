<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendMessengerAlerts;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DebtorAutoReminderTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->course = Course::factory()->create(['is_active' => true, 'slug' => 'auto-course']);
    }

    /** Должник «no_payment»: в группе курса, реального платежа нет. */
    private function makeDebtor(array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $group = Group::create(['name' => 'Поток '.fake()->unique()->numberBetween(1, 9999)]);
        $this->course->groups()->attach($group->id);
        $user->groups()->attach($group->id);

        return $user;
    }

    private function enable(array $overrides = []): void
    {
        MarketingSetting::create(array_merge(['debt_reminders_enabled' => true], $overrides));
    }

    /** @test */
    public function it_reminds_a_debtor_and_logs_it(): void
    {
        Queue::fake();
        CourseBlock::factory()->for($this->course)->current()->create(['number' => 1]);
        $debtor = $this->makeDebtor(['telegram_id' => '555001']);
        $this->enable();

        $this->artisan('debts:remind')->assertSuccessful();

        Queue::assertPushed(SendMessengerAlerts::class, fn ($job) => $job->user->is($debtor));
        $this->assertDatabaseHas('debt_reminders', [
            'user_id' => $debtor->id,
            'course_id' => $this->course->id,
        ]);
    }

    /** @test */
    public function it_does_not_remind_twice_within_cadence(): void
    {
        Queue::fake();
        CourseBlock::factory()->for($this->course)->current()->create(['number' => 1]);
        $this->makeDebtor(['telegram_id' => '555002']);
        $this->enable(['debt_reminder_cadence_days' => 7]);

        $this->artisan('debts:remind')->assertSuccessful();
        $this->artisan('debts:remind')->assertSuccessful();

        Queue::assertPushed(SendMessengerAlerts::class, 1);
    }

    /** @test */
    public function it_sends_nothing_when_disabled(): void
    {
        Queue::fake();
        CourseBlock::factory()->for($this->course)->current()->create(['number' => 1]);
        $this->makeDebtor(['telegram_id' => '555003']);
        MarketingSetting::create(['debt_reminders_enabled' => false]);

        $this->artisan('debts:remind')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('debt_reminders', 0);
    }

    /** @test */
    public function it_reminds_about_upcoming_unpaid_block_even_if_current_is_paid(): void
    {
        Queue::fake();
        // Блок 1 уже шёл (оплачен студентом), блок 2 стартует через 5 дней (не оплачен).
        CourseBlock::factory()->for($this->course)
            ->withDates(now()->subDays(30), now()->subDay())->create(['number' => 1]);
        CourseBlock::factory()->for($this->course)
            ->withDates(now()->addDays(5), now()->addDays(35))->create(['number' => 2]);

        $student = $this->makeDebtor(['telegram_id' => '555010']);
        Payment::create([
            'user_id' => $student->id,
            'course_id' => $this->course->id,
            'amount' => 5000,
            'tariff' => 'block_1',
            'start_block' => 1,
            'end_block' => 1,
            'status' => 'paid',
            'is_conditional' => false,
        ]);

        $this->enable(); // lead=7 → блок 2 (через 5 дней) в окне

        $this->artisan('debts:remind')->assertSuccessful();

        // Ушло ровно одно напоминание — про блок 2 (по блоку 1 студент оплачен).
        Queue::assertPushed(SendMessengerAlerts::class, 1);
        Queue::assertPushed(SendMessengerAlerts::class, fn ($job) => $job->user->is($student));
        $this->assertDatabaseHas('debt_reminders', [
            'user_id' => $student->id,
            'course_id' => $this->course->id,
            'block_number' => 2,
        ]);
    }

    /** @test */
    public function it_skips_far_future_block_but_sends_when_lead_is_wide(): void
    {
        Queue::fake();
        // Блок начнётся через 30 дней, текущего нет.
        CourseBlock::factory()->for($this->course)
            ->withDates(now()->addDays(30), now()->addDays(60))
            ->create(['number' => 1]);
        $this->makeDebtor(['telegram_id' => '555004']);

        // lead=7 → рано, не шлём.
        $this->enable(['debt_reminder_lead_days' => 7]);
        $this->artisan('debts:remind')->assertSuccessful();
        Queue::assertNothingPushed();

        // Расширяем окно до 45 дней → теперь попадает (значит должник был, дело в окне).
        MarketingSetting::query()->update(['debt_reminder_lead_days' => 45]);
        MarketingSetting::flushCached();
        $this->artisan('debts:remind')->assertSuccessful();
        Queue::assertPushed(SendMessengerAlerts::class, 1);
    }
}
