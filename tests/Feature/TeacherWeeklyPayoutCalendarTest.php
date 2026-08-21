<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\TeacherWeeklyPayoutCalendar;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\TeacherWeeklyPayoutCalendarService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeacherWeeklyPayoutCalendarTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function fakeTochka(float $closing = 248387.93): array
    {
        return [
            'Data' => [
                'Balance' => [
                    ['accountId' => '40802810020000863757/044525104', 'type' => 'ClosingAvailable', 'dateTime' => '2026-08-21T15:20:33+00:00', 'Amount' => ['amount' => $closing, 'currency' => 'RUB']],
                    ['accountId' => '40802810020000863757/044525104', 'type' => 'OpeningAvailable', 'dateTime' => '2026-08-21T15:20:33+00:00', 'Amount' => ['amount' => $closing, 'currency' => 'RUB']],
                    ['accountId' => '40802810020000863757/044525104', 'type' => 'Expected', 'dateTime' => '2026-08-21T15:20:33+00:00', 'Amount' => ['amount' => 0, 'currency' => 'RUB']],
                ],
            ],
        ];
    }

    private function fakeBank(): void
    {
        Cache::flush();
        config([
            'services.tochka.token' => 'test-token',
            'services.tochka.balance_cache_seconds' => 0,
        ]);
        Http::fake(['*' => Http::response($this->fakeTochka(), 200)]);
    }

    /** @test */
    public function page_hidden_when_flag_off(): void
    {
        config(['features.teacher_weekly_payout_calendar' => false]);
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));
        $this->assertFalse(TeacherWeeklyPayoutCalendar::canAccess());
        $this->get('/admin/teacher-weekly-payout-calendar')->assertForbidden();
    }

    /** @test */
    public function manager_cannot_open_calendar_even_with_flag(): void
    {
        config(['features.teacher_weekly_payout_calendar' => true]);
        $this->actingAs(User::factory()->create(['role' => 'manager', 'is_admin' => true]));
        $this->get('/admin/teacher-weekly-payout-calendar')->assertForbidden();
    }

    /** @test */
    public function accountant_renders_when_flag_on(): void
    {
        $this->fakeBank();
        config(['features.teacher_weekly_payout_calendar' => true]);
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));
        $this->get('/admin/teacher-weekly-payout-calendar')->assertSuccessful();
    }

    /** @test */
    public function raskhod_without_teacher_payouts_is_preliminary_on_anniversary_week(): void
    {
        $this->fakeBank();
        $teacher = Teacher::factory()->create(['name' => 'Fixture Eur', 'payout_currency' => 'EUR']);
        $user = User::factory()->create(['teacher_id' => $teacher->id]);
        $p = Payment::create([
            'user_id' => $user->id,
            'amount' => -100,
            'tariff' => 'Расход',
            'status' => 'paid',
        ]);
        $p->forceFill(['created_at' => '2026-05-11 12:00:00'])->saveQuietly();

        $grid = app(TeacherWeeklyPayoutCalendarService::class)->grid(2026);
        $aug11 = Carbon::parse('2026-08-11');
        $week = collect($grid['weeks'])->firstWhere('iso_week', $aug11->isoWeek);
        $this->assertNotNull($week);
        $due = collect($week['due'])->firstWhere('teacher_id', $teacher->id);
        $this->assertNotNull($due);
        $this->assertTrue($due['preliminary']);
        $this->assertSame('raskhod', $due['last_cash_source']);
        $this->assertSame('EUR', $due['lane']);
        $this->assertSame(TeacherWeeklyPayoutCalendarService::TRIGGER_ANNIVERSARY, $due['trigger']);
        $this->assertSame(TeacherWeeklyPayoutCalendarService::COVER_OPEN_BANK, $week['paypal_cover']);
        $this->assertSame(0, TeacherPayout::query()->count());
    }

    /** @test */
    public function block_four_end_places_teacher_on_that_iso_week(): void
    {
        $this->fakeBank();
        $teacher = Teacher::factory()->create(['name' => 'Fixture Rub']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        CourseBlock::factory()->create([
            'course_id' => $course->id,
            'number' => 4,
            'ends_at' => '2026-09-29 18:00:00',
        ]);

        $grid = app(TeacherWeeklyPayoutCalendarService::class)->grid(2026);
        $end = Carbon::parse('2026-09-29');
        $week = collect($grid['weeks'])->firstWhere('iso_week', $end->isoWeek);
        $due = collect($week['due'] ?? [])->firstWhere('teacher_id', $teacher->id);
        $this->assertNotNull($due);
        $this->assertSame(TeacherWeeklyPayoutCalendarService::TRIGGER_BLOCK4, $due['trigger']);
        $this->assertSame('RUB', $due['lane']);
    }

    /** @test */
    public function artisan_json_leaves_money_tables_unmoved(): void
    {
        $this->fakeBank();
        $payouts = TeacherPayout::query()->count();
        $payments = Payment::query()->count();
        $this->artisan('teacher-payouts:week-calendar', ['--json' => true, '--year' => 2026])
            ->assertSuccessful();
        $this->assertSame($payouts, TeacherPayout::query()->count());
        $this->assertSame($payments, Payment::query()->count());
    }
}
