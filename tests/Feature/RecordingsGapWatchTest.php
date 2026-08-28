<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\RecordingGapAlert;
use App\Models\Schedule;
use App\Services\Recordings\N8nZoomExecutionProbe;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule as ConsoleSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3209: yesterday had a live slot, recording not in cabinet by morning.
 */
class RecordingsGapWatchTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $yesterday2000;

    protected function setUp(): void
    {
        parent::setUp();

        $tz = 'Europe/Moscow';
        $this->yesterday2000 = CarbonImmutable::now($tz)->subDay()->setTime(20, 0);

        config([
            'app.timezone' => $tz,
            'services.telegram.bot_token' => 'test-token',
            'recording_gap.telegram_chat_id' => '11111',
            'recording_gap.n8n_api_base' => 'https://context-ai.ru',
            'recording_gap.n8n_api_key' => '',
            'recording_gap.n8n_workflow_id' => '1EIqqNzMl5NNIxST',
            'recording_gap.skip_title_substrings' => ['Созвон отдела Заботы'],
            'recording_gap.skip_course_ids' => [],
        ]);
    }

    public function test_empty_yesterday_is_exit_zero(): void
    {
        $code = Artisan::call('recordings:gap-watch', ['--dry' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Пробелов записей нет', Artisan::output());
    }

    public function test_missing_recording_yesterday_exits_one_and_prints_payload(): void
    {
        $this->seedLiveSlot(withChat: true);

        $code = Artisan::call('recordings:gap-watch', ['--dry' => true]);
        $out = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Курс тест', $out);
        $this->assertStringContainsString('no-lesson', $out);
        $this->assertStringContainsString('--dry: Telegram не отправлен.', $out);
        $this->assertStringContainsString('Записи не в кабинете', $out);
        // H3557: имя группы в строке — кликабельная ссылка на чат группы.
        $this->assertStringContainsString('<a href="https://t.me/c/999">Группа А</a>', $out);
    }

    public function test_aging_gap_gets_token_expiry_warning_and_fresh_does_not(): void
    {
        // «Вчера 20:00» старше 20 ч НЕ всегда: утренний прогон (до 16:00 МСК)
        // давал <20 ч и тест флакал. Морозим «сейчас» на сегодня 17:00 —
        // слоту гарантированно ≥21 ч, предупреждение детерминировано.
        CarbonImmutable::setTestNow(CarbonImmutable::now('Europe/Moscow')->setTime(17, 0));
        try {
            $this->seedLiveSlot(withChat: true);

            Artisan::call('recordings:gap-watch', ['--dry' => true]);
            $out = Artisan::output();

            $this->assertStringContainsString('токен записи истекает', $out);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_fresh_same_day_gap_has_no_token_warning(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 14:00', 'Europe/Moscow'));
        try {
            $course = Course::factory()->live()->create(['title' => 'Курс тест']);
            $group = Group::create(['name' => 'Группа А', 'telegram_chat_id' => '-100999']);
            Schedule::create([
                'title' => 'Live',
                'course_id' => $course->id,
                'group_id' => $group->id,
                'start' => CarbonImmutable::now('Europe/Moscow')->subHours(5),
            ]);

            Artisan::call('recordings:gap-watch', ['--stale' => true, '--dry' => true]);
            $out = Artisan::output();

            $this->assertStringContainsString('Курс тест', $out);
            $this->assertStringNotContainsString('токен записи истекает', $out);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_published_lesson_with_rutube_is_exit_zero(): void
    {
        ['course' => $course] = $this->seedLiveSlot(withChat: true);

        Lesson::factory()->create([
            'course_id' => $course->id,
            'group_id' => null,
            'title' => 'Занятие',
            'lesson_date' => $this->yesterday2000->toDateString(),
            'rutube_url' => 'https://rutube.ru/video/fixture/',
            'is_published' => true,
        ]);

        $this->assertSame(0, Artisan::call('recordings:gap-watch', ['--dry' => true]));
    }

    public function test_group_without_telegram_chat_id_is_skipped_unless_all(): void
    {
        $this->seedLiveSlot(withChat: false);

        $this->assertSame(0, Artisan::call('recordings:gap-watch', ['--dry' => true]));

        $code = Artisan::call('recordings:gap-watch', ['--dry' => true, '--all' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('no-lesson', Artisan::output());
    }

    public function test_staff_meeting_title_is_skipped_via_allow_list_not_sql(): void
    {
        $course = Course::factory()->live()->create(['title' => 'Внутреннее']);
        $group = Group::create([
            'name' => 'Забота',
            'telegram_chat_id' => '-1001',
        ]);
        Schedule::create([
            'title' => 'Созвон отдела Заботы',
            'course_id' => $course->id,
            'group_id' => $group->id,
            'start' => $this->yesterday2000,
        ]);

        $this->assertSame(0, Artisan::call('recordings:gap-watch', ['--dry' => true, '--all' => true]));
    }

    public function test_live_send_posts_one_telegram_and_dedupes(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->seedLiveSlot(withChat: true);

        // H3557: успешная отправка = exit 0 (раньше всегда был FAILURE —
        // 14 ложных ERROR в сутки в laravel.log).
        $this->assertSame(0, Artisan::call('recordings:gap-watch'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) $request['text'], 'Курс тест'));

        // Дедуп теперь персистентный: строка recording_gap_alerts переживает
        // cache:clear автодеплоя.
        $this->assertSame(1, RecordingGapAlert::query()->count());

        $this->assertSame(0, Artisan::call('recordings:gap-watch'));
        $this->assertSame(1, collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.telegram.org'))
            ->count());
    }

    public function test_n8n_exec_1423_attaches_tos_forbidden(): void
    {
        $list = json_decode((string) file_get_contents(base_path('tests/fixtures/n8n_zoom_exec_1423.json')), true);
        $detail = json_decode((string) file_get_contents(base_path('tests/fixtures/n8n_zoom_exec_1423_detail.json')), true);

        Http::fake(function ($request) use ($list, $detail) {
            $url = $request->url();
            if (str_contains($url, '/api/v1/executions/1423')) {
                return Http::response($detail, 200);
            }
            if (str_contains($url, '/api/v1/executions')) {
                return Http::response($list, 200);
            }

            return Http::response(['ok' => true], 200);
        });

        config(['recording_gap.n8n_api_key' => 'test-n8n-key']);
        $this->seedLiveSlot(withChat: true);

        $code = Artisan::call('recordings:gap-watch', ['--dry' => true]);
        $out = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('exec 1423', $out);
        $this->assertStringContainsString('tos_forbidden', $out);
    }

    public function test_n8n_unreachable_is_skip_soft(): void
    {
        Http::fake([
            'https://context-ai.ru/*' => fn () => throw new \RuntimeException('n8n down'),
        ]);
        config(['recording_gap.n8n_api_key' => 'test-n8n-key']);
        $this->seedLiveSlot(withChat: true);

        $code = Artisan::call('recordings:gap-watch', ['--dry' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('n8n skip-soft', Artisan::output());
    }

    public function test_error_class_parses_credits_and_tos_from_blob(): void
    {
        $credits = 'OpenRouter 402 This request requires more credits, or fewer max_tokens. You requested up to 64000 tokens, but can only afford 60971';
        $tos = 'The request is prohibited due to a violation of provider Terms Of Service';

        $this->assertSame('credits', N8nZoomExecutionProbe::classify($credits));
        $this->assertSame('tos_forbidden', N8nZoomExecutionProbe::classify($tos));
        $this->assertSame('other', N8nZoomExecutionProbe::classify('node timeout'));
    }

    public function test_kernel_schedules_gap_watch_at_eight_moscow(): void
    {
        $event = $this->eventFor('recordings:gap-watch');

        $this->assertNotNull($event, 'recordings:gap-watch должен быть в расписании.');
        $this->assertSame('0 8 * * *', $event->expression);
    }

    /**
     * @return array{course: Course, group: Group, schedule: Schedule}
     */
    private function seedLiveSlot(bool $withChat): array
    {
        $course = Course::factory()->live()->create(['title' => 'Курс тест']);
        $group = Group::create([
            'name' => 'Группа А',
            'telegram_chat_id' => $withChat ? '-100999' : null,
        ]);
        $schedule = Schedule::create([
            'title' => 'Live',
            'course_id' => $course->id,
            'group_id' => $group->id,
            'start' => $this->yesterday2000,
        ]);

        return compact('course', 'group', 'schedule');
    }

    private function eventFor(string $needle): ?Event
    {
        $schedule = $this->app->make(ConsoleSchedule::class);
        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, $needle)) {
                return $event;
            }
        }

        return null;
    }
}
