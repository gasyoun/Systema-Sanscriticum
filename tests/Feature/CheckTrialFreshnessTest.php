<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H4966: монитор свежести пина пробного занятия. Видимый курс с платным
 * пробным, чей trial_schedule_id указывает в прошлое, деградирует в тишину
 * (виджет записи, NextIntroSession) — прод 16-09-2026: 4 курса, 3-102 дня в
 * прошлом, 0 bookable, 0 book_token. Тест закрепляет, что монитор ловит это
 * ЯВНО (алерт + непустой список), а не молча репортит «просто не bookable».
 */
class CheckTrialFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private function courseWithTrialSchedule(Carbon $start, float $price = 500): Course
    {
        $course = Course::factory()->create(['is_visible' => true]);
        $schedule = Schedule::create([
            'title' => 'Пробное занятие',
            'course_id' => $course->id,
            'group_id' => null,
            'start' => $start,
        ]);
        $course->update(['trial_price' => $price, 'trial_schedule_id' => $schedule->id]);

        return $course->fresh();
    }

    /** @test */
    public function detects_and_alerts_on_stale_past_pin(): void
    {
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.admin_id' => '111']);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $stale = $this->courseWithTrialSchedule(now()->subDays(5));

        $this->assertTrue($stale->hasStaleTrialPin());

        $this->artisan('trial:check-freshness')
            ->expectsOutputToContain('Протухших пинов: 1')
            ->assertSuccessful();

        Http::assertSent(fn ($req) => str_contains($req->url(), 'telegram.org')
            && str_contains((string) ($req->data()['text'] ?? ''), $stale->slug));
    }

    /** @test */
    public function fresh_future_pin_is_not_reported_as_stale(): void
    {
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.admin_id' => '111']);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $fresh = $this->courseWithTrialSchedule(now()->addDays(5));

        $this->assertFalse($fresh->hasStaleTrialPin());

        $this->artisan('trial:check-freshness')
            ->expectsOutputToContain('Протухших пинов пробного занятия нет')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    /** @test */
    public function invisible_course_with_stale_pin_is_not_flagged(): void
    {
        $course = $this->courseWithTrialSchedule(now()->subDays(5));
        $course->update(['is_visible' => false]);

        $this->assertFalse($course->fresh()->hasStaleTrialPin());
    }
}
