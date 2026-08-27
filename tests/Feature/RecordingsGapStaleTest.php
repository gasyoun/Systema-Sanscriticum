<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule as ConsoleSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MG 24-08-2026: a midday lesson must not wait for the 08:00 next-morning
 * pass — today's slots older than stale_hours without a recording alert the
 * same day (hourly tick, deduped).
 */
class RecordingsGapStaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Europe/Moscow',
            'services.telegram.bot_token' => 'test-token',
            'recording_gap.telegram_chat_id' => '11111',
            'recording_gap.care_telegram_chat_id' => '',
            'recording_gap.n8n_api_key' => '',
            'recording_gap.stale_hours' => 4,
            'recording_gap.stale_enabled' => true,
        ]);

        // Фризим «сейчас» в середину московского дня: без этого слот
        // «6 часов назад», засеянный около полуночи MSK, уезжает на предыдущий
        // день и same-day логика законно его не находит (красный вечерний CI
        // 25-08-2026 на зелёном днём main).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 14:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_recent_slot_below_sla_is_not_flagged(): void
    {
        $this->seedSlotStartedHoursAgo(1);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->assertSame(0, Artisan::call('recordings:gap-watch', ['--stale' => true]));
        $this->assertStringContainsString('Сегодняшних слотов старше', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_stale_slot_today_is_flagged_same_day(): void
    {
        $this->seedSlotStartedHoursAgo(6);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $code = Artisan::call('recordings:gap-watch', ['--stale' => true]);
        $out = Artisan::output();

        // H3557: успешная отправка алерта = exit 0, а не FAILURE.
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Курс тест', $out);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));
    }

    public function test_default_morning_pass_ignores_today(): void
    {
        $this->seedSlotStartedHoursAgo(6);

        $this->assertSame(0, Artisan::call('recordings:gap-watch', ['--dry' => true]));
        $this->assertStringContainsString('Пробелов записей нет', Artisan::output());
    }

    public function test_hourly_tick_dedupes_within_the_day(): void
    {
        $this->seedSlotStartedHoursAgo(6);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        Artisan::call('recordings:gap-watch', ['--stale' => true]);
        Artisan::call('recordings:gap-watch', ['--stale' => true]);

        $sent = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.telegram.org'));
        $this->assertSame(1, $sent->count());
    }

    public function test_kernel_runs_stale_tick_hourly_and_respects_kill_switch(): void
    {
        $schedule = $this->app->make(ConsoleSchedule::class);
        /** @var ?Event $event */
        $event = null;
        foreach ($schedule->events() as $candidate) {
            if (str_contains((string) $candidate->command, 'gap-watch') && str_contains((string) $candidate->command, '--stale')) {
                $event = $candidate;
                break;
            }
        }

        $this->assertNotNull($event, '--stale тик должен быть в расписании.');
        $this->assertSame('41 * * * *', $event->expression);
        $this->assertTrue($event->filtersPass($this->app));
        config(['recording_gap.stale_enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));
    }

    /**
     * @return array{course: Course, group: Group, schedule: Schedule}
     */
    private function seedSlotStartedHoursAgo(int $hours): array
    {
        $course = Course::factory()->live()->create(['title' => 'Курс тест']);
        $group = Group::create([
            'name' => 'Группа А',
            'telegram_chat_id' => '-100999',
        ]);
        $schedule = Schedule::create([
            'title' => 'Live',
            'course_id' => $course->id,
            'group_id' => $group->id,
            'start' => CarbonImmutable::now('Europe/Moscow')->subHours($hours),
        ]);

        return compact('course', 'group', 'schedule');
    }
}
