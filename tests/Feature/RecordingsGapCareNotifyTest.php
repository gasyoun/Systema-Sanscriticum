<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MG 24-08-2026: stuck recording alerts must reach the care department
 * chat as well as the ops pulse, same payload, same morning run.
 */
class RecordingsGapCareNotifyTest extends TestCase
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
            'recording_gap.care_telegram_chat_id' => '',
            'recording_gap.n8n_api_key' => '',
        ]);
    }

    public function test_care_chat_receives_same_alert_when_configured(): void
    {
        config(['recording_gap.care_telegram_chat_id' => '-1002079934542']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
        $this->seedLiveSlot();

        $this->assertSame(0, Artisan::call('recordings:gap-watch'));

        $sent = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.telegram.org'))
            ->values();
        $this->assertSame(
            ['11111', '-1002079934542'],
            $sent->map(fn ($pair) => (string) $pair[0]['chat_id'])->all(),
        );

        // H3557: копия заботы помечена заголовком — два чата читаются как
        // адресаты, а не как дубль; админская копия без метки.
        $byChat = $sent->mapWithKeys(fn ($pair) => [(string) $pair[0]['chat_id'] => (string) $pair[0]['text']]);
        $this->assertStringStartsWith('<b>[Отдел заботы]</b>', (string) $byChat->get('-1002079934542'));
        $this->assertStringContainsString('Записи не в кабинете', (string) $byChat->get('-1002079934542'));
        $this->assertStringStartsWith('<b>Записи не в кабинете / ТГ</b>', (string) $byChat->get('11111'));
    }

    public function test_without_care_chat_only_admin_is_sent(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
        $this->seedLiveSlot();

        $this->assertSame(0, Artisan::call('recordings:gap-watch'));

        $sent = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.telegram.org'));
        $this->assertSame(1, $sent->count());
        $this->assertSame('11111', (string) $sent->first()[0]['chat_id']);
    }

    public function test_deduped_second_run_sends_to_neither(): void
    {
        config(['recording_gap.care_telegram_chat_id' => '-1002079934542']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
        $this->seedLiveSlot();

        Artisan::call('recordings:gap-watch');
        Artisan::call('recordings:gap-watch');

        $sent = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.telegram.org'));
        $this->assertSame(2, $sent->count());
    }

    /**
     * @return array{course: Course, group: Group, schedule: Schedule}
     */
    private function seedLiveSlot(): array
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
            'start' => $this->yesterday2000,
        ]);

        return compact('course', 'group', 'schedule');
    }
}
