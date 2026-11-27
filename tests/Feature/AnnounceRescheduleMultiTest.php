<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\TelegramScheduleReschedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * H8527: мульти-перенос («перенести 24.12 и 31.12 на 26.12 и 28.12») и
 * сдвиг дня недели («перенести четверг на пятницу»). Каждое движение —
 * своя строка и свой чек; неоднозначность → уточнение и ноль мутаций.
 */
class AnnounceRescheduleMultiTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-1003994169966';

    private const TEACHER_TG_ID = 5487293147;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 777]]),
        ]);
        Redis::shouldReceive('set')->andReturn(true);
        config()->set('services.telegram.student_bot_token', 'test-token');
        config()->set('services.telegram_zapisi.announce_reschedule_detect', true);
        config()->set('services.telegram_zapisi.announce_reschedule_group_ids', [$this->group()->id]);
        Carbon::setTestNow(Carbon::parse('2026-11-21 12:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private ?Group $groupCache = null;

    private function group(): Group
    {
        if ($this->groupCache === null) {
            $teacher = Teacher::create(['name' => 'Препод Свой']);
            $course = Course::factory()->create(['slug' => 'grammar-63', 'title' => 'Грамматика по Кочергиной гр.63', 'teacher_id' => $teacher->id]);
            $group = Group::create(['name' => 'Грамматика по Кочергиной гр.63', 'telegram_chat_id' => self::CHAT_ID]);
            $course->groups()->attach($group->id);
            User::factory()->create([
                'telegram_id' => self::TEACHER_TG_ID,
                'role' => 'teacher',
                'teacher_id' => $teacher->id,
            ]);
            $this->groupCache = $group;
        }

        return $this->groupCache;
    }

    private function lesson(string $start, string $title): Schedule
    {
        $start = Carbon::parse($start, 'Europe/Moscow');

        return Schedule::create([
            'title' => $title,
            'start' => $start,
            'end' => $start->copy()->addHours(2),
            'group_id' => $this->group()->id,
            'link' => 'https://zoom.us/j/x',
        ]);
    }

    private function process(string $text, int $messageId = 400): void
    {
        (new ProcessTelegramZapisiUpdate([
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup'],
                'message_id' => $messageId,
                'from' => ['id' => self::TEACHER_TG_ID],
                'text' => $text,
                'date' => now()->timestamp,
            ],
        ]))->handle();
    }

    public function test_multi_date_moves_both_and_sends_two_receipts(): void
    {
        $first = $this->lesson('2026-12-24 08:00:00', 'Кашмир (#1, 24.12.26)');
        $second = $this->lesson('2026-12-31 19:30:00', 'Кашмир (#2, 31.12.26)');

        $this->process('перенести 24.12 и 31.12 на 26.12 и 28.12');

        $this->assertSame('2026-12-26 08:00:00', $first->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-28 19:30:00', $second->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame(2, TelegramScheduleReschedule::count());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains((string) ($request['reply_markup'] ?? ''), 'arsu:'));
    }

    public function test_weekday_shift_moves_all_future_source_weekday_entries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-25 12:00:00', 'Europe/Moscow'));
        $thursdayOne = $this->lesson('2026-11-26 08:00:00', 'Кашмир (#1, 26.11.26)');
        $thursdayTwo = $this->lesson('2026-12-03 08:00:00', 'Кашмир (#2, 03.12.26)');

        // Целевые пятницы свободны: занятый слот — это отказ всей пачке
        // (occupied-тест ниже), здесь сдвиг чистый.
        $this->process('перенести четверг на пятницу');

        $this->assertSame('2026-11-27 08:00:00', $thursdayOne->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-04 08:00:00', $thursdayTwo->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame(2, TelegramScheduleReschedule::count());
        Http::assertSentCount(2);
    }

    public function test_mismatched_date_lists_get_clarification_without_mutation(): void
    {
        $first = $this->lesson('2026-12-24 08:00:00', 'Кашмир (#1, 24.12.26)');
        $second = $this->lesson('2026-12-31 19:30:00', 'Кашмир (#2, 31.12.26)');

        $this->process('перенести 24.12 и 31.12 на 26.12');

        $this->assertSame('2026-12-24 08:00:00', $first->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-31 19:30:00', $second->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame(0, TelegramScheduleReschedule::count());
        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Подтвердите в формате'));
        Http::assertSentCount(1);
    }

    public function test_occupied_target_rolls_back_everything(): void
    {
        $first = $this->lesson('2026-12-24 08:00:00', 'Кашмир (#1, 24.12.26)');
        $second = $this->lesson('2026-12-31 19:30:00', 'Кашмир (#2, 31.12.26)');
        $this->lesson('2026-12-28 19:30:00', 'Уже стоит 28.12');

        $this->process('перенести 24.12 и 31.12 на 26.12 и 28.12');

        $this->assertSame('2026-12-24 08:00:00', $first->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-31 19:30:00', $second->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame(0, TelegramScheduleReschedule::count());
        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Целевой слот уже занят'));
        Http::assertSentCount(1);
    }

    public function test_future_single_date_applies_without_today(): void
    {
        $lesson = $this->lesson('2026-12-05 08:00:00', 'Кашмир (#1, 05.12.26)');

        $this->process('Занятие 05.12 переносим на 10.12');

        $this->assertSame('2026-12-10 08:00:00', $lesson->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame(1, TelegramScheduleReschedule::count());
    }

    public function test_duplicate_multi_message_does_not_double_move(): void
    {
        $this->lesson('2026-12-24 08:00:00', 'Кашмир (#1, 24.12.26)');
        $this->lesson('2026-12-31 19:30:00', 'Кашмир (#2, 31.12.26)');
        $update = [
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup'],
                'message_id' => 401,
                'from' => ['id' => self::TEACHER_TG_ID],
                'text' => 'перенести 24.12 и 31.12 на 26.12 и 28.12',
                'date' => now()->timestamp,
            ],
        ];

        (new ProcessTelegramZapisiUpdate($update))->handle();
        (new ProcessTelegramZapisiUpdate($update))->handle();

        $this->assertSame(2, TelegramScheduleReschedule::count());
        Http::assertSentCount(2);
    }

    public function test_weekday_shift_without_source_entries_gets_clarification(): void
    {
        $this->process('перенести четверг на пятницу');

        $this->assertSame(0, TelegramScheduleReschedule::count());
        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Подтвердите в формате'));
    }
}
