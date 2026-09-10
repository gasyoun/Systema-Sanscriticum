<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * H4519: анонс отмены обычными словами → кнопка [Снять DD.MM? Да/Нет].
 * Снимает только явный тап автором; чужой тап отклонён; явные команды
 * («Отмена 17.09») приоритетны; флаг off — тишина.
 */
class AnnounceCancelTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-100888';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 777]]),
        ]);
        config()->set('services.telegram_zapisi.announce_cancel_detect', true);
        config()->set('services.telegram.student_bot_token', 'test-token');
        Carbon::setTestNow('2026-09-10 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedTeacherWorld(): Group
    {
        $teacher = Teacher::create(['name' => 'Препод Свой']);
        $course = Course::create(['title' => 'Курс', 'slug' => 'crs-'.substr(md5(uniqid('', true)), 0, 10), 'teacher_id' => $teacher->id]);
        $group = Group::create(['name' => 'Группа', 'telegram_chat_id' => self::CHAT_ID]);
        $course->groups()->attach($group->id);
        User::create([
            'name' => 'Препод',
            'email' => 'own@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'teacher',
            'teacher_id' => $teacher->id,
            'telegram_id' => 1001,
        ]);

        return $group;
    }

    private function scheduleAt(Carbon $start, string $title, int $groupId): Schedule
    {
        return Schedule::create([
            'title' => $title,
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $start->copy()->addHour()->format('Y-m-d H:i:s'),
            'group_id' => $groupId,
            'link' => 'https://zoom.us/j/x',
        ]);
    }

    private function nextThursday(): Carbon
    {
        $date = now()->startOfDay();

        while ($date->dayOfWeek !== 4) {
            $date->addDay();
        }

        return $date;
    }

    private function process(array $update): void
    {
        (new ProcessTelegramZapisiUpdate($update))->handle();
    }

    private function message(string $text, int $fromId = 1001): array
    {
        return [
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup'],
                'message_id' => 901,
                'from' => ['id' => $fromId],
                'text' => $text,
                'date' => time(),
            ],
        ];
    }

    public function test_announcement_offers_button_with_weekday_candidate(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        $lesson = $this->scheduleAt($this->nextThursday()->setTime(20, 0), 'Медленное чтение (#45)', $group->id);

        $this->process($this->message('Друзья, в четверг не смогу, врач — занятие переносится'));

        Http::assertSent(function (Request $request) use ($lesson): bool {
            if (! str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            return str_contains((string) $request['reply_markup'], 'acx:'.$lesson->id)
                && str_contains((string) $request['text'], 'Снять с расписания');
        });
        Queue::assertNotPushed(SendZapisiBotMessageJob::class);
        $this->assertFalse($lesson->fresh()->trashed());
    }

    public function test_explicit_dated_command_does_not_offer(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        $lesson = $this->scheduleAt($this->nextThursday()->setTime(20, 0), 'Занятие А', $group->id);

        $this->process($this->message('Отмена '.$lesson->start->format('d.m')));

        Http::assertNothingSent();
        $this->assertTrue($lesson->fresh()->trashed());
    }

    public function test_flag_off_is_silent(): void
    {
        config()->set('services.telegram_zapisi.announce_cancel_detect', false);
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        $lesson = $this->scheduleAt($this->nextThursday()->setTime(20, 0), 'Занятие А', $group->id);

        $this->process($this->message('в четверг не смогу'));

        Http::assertNothingSent();
        $this->assertFalse($lesson->fresh()->trashed());
    }

    public function test_stranger_message_is_ignored(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        $lesson = $this->scheduleAt($this->nextThursday()->setTime(20, 0), 'Занятие А', $group->id);

        $this->process($this->message('отменяю занятие!', 9999));

        Http::assertNothingSent();
        $this->assertFalse($lesson->fresh()->trashed());
    }

    public function test_callback_yes_cancels_single_and_edits_offer(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        $lesson = $this->scheduleAt($this->nextThursday()->setTime(20, 0), 'Занятие А (#45)', $group->id);
        $other = $this->scheduleAt($this->nextThursday()->addWeek()->setTime(20, 0), 'Занятие Б', $group->id);

        $this->process([
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => 'cb1',
                'from' => ['id' => 1001],
                'data' => 'acx:'.$lesson->id,
                'message' => ['chat' => ['id' => self::CHAT_ID], 'message_id' => 555],
            ],
        ]);

        $this->assertTrue($lesson->fresh()->trashed());
        $this->assertFalse($other->fresh()->trashed(), 'cancelSingle не должен сдвигать цепочку');
        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/editMessageText')
                && str_contains((string) $request['text'], 'снято с расписания');
        });
        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
    }

    public function test_callback_from_stranger_is_denied(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        $lesson = $this->scheduleAt($this->nextThursday()->setTime(20, 0), 'Занятие А', $group->id);

        $this->process([
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => 'cb2',
                'from' => ['id' => 9999],
                'data' => 'acx:'.$lesson->id,
                'message' => ['chat' => ['id' => self::CHAT_ID], 'message_id' => 555],
            ],
        ]);

        $this->assertFalse($lesson->fresh()->trashed());
    }

    public function test_callback_no_keeps_schedule(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        $lesson = $this->scheduleAt($this->nextThursday()->setTime(20, 0), 'Занятие А', $group->id);

        $this->process([
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => 'cb3',
                'from' => ['id' => 1001],
                'data' => 'acxn',
                'message' => ['chat' => ['id' => self::CHAT_ID], 'message_id' => 555],
            ],
        ]);

        $this->assertFalse($lesson->fresh()->trashed());
        Queue::assertNotPushed(SendZapisiBotMessageJob::class);
    }

    public function test_past_schedule_is_refused(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        $lesson = $this->scheduleAt(now()->subDays(2)->setTime(20, 0), 'Занятие А', $group->id);

        $this->process([
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => 'cb4',
                'from' => ['id' => 1001],
                'data' => 'acx:'.$lesson->id,
                'message' => ['chat' => ['id' => self::CHAT_ID], 'message_id' => 555],
            ],
        ]);

        $this->assertFalse($lesson->fresh()->trashed());
    }

    public function test_double_tap_is_idempotent(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        $group = $this->seedTeacherWorld();
        $lesson = $this->scheduleAt($this->nextThursday()->setTime(20, 0), 'Занятие А', $group->id);

        $callback = [
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => 'cb5',
                'from' => ['id' => 1001],
                'data' => 'acx:'.$lesson->id,
                'message' => ['chat' => ['id' => self::CHAT_ID], 'message_id' => 555],
            ],
        ];

        $this->process($callback);
        $this->process($callback);

        $this->assertTrue($lesson->fresh()->trashed());
        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
    }
}
