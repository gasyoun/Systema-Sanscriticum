<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Telegram\DateAwareCancelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * H4253: датированная отмена «Отмена 23.09 и 30.10» — занятие убирается
 * БЕЗ сдвига цепочки (soft delete), календарь остаётся на месте.
 * teacher-роль — только свои группы; staff — любые; повторы гасятся.
 */
class DateAwareCancelCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-100777';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function seedTeacherWorld(): array
    {
        $teacher = Teacher::create(['name' => 'Препод Свой']);
        $course = Course::create(['title' => 'Курс', 'slug' => 'crs-'.substr(md5(uniqid('', true)), 0, 10), 'teacher_id' => $teacher->id]);
        $group = Group::create(['name' => 'Своя группа', 'telegram_chat_id' => self::CHAT_ID]);
        $course->groups()->attach($group->id);
        User::create([
            'name' => 'Препод',
            'email' => 'own@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'teacher',
            'teacher_id' => $teacher->id,
            'telegram_id' => 1001,
        ]);

        return [$teacher, $course, $group];
    }

    private function scheduleAt(string $date, string $title, ?int $groupId = null): Schedule
    {
        return Schedule::create([
            'title' => $title,
            'start' => $date.' 20:00:00',
            'end' => $date.' 21:00:00',
            'group_id' => $groupId,
            'link' => 'https://zoom.us/j/x',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function message(string $text, array $overrides = []): array
    {
        return array_merge([
            'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup'],
            'message_id' => 901,
            'from' => ['id' => 1001],
            'text' => $text,
        ], $overrides);
    }

    public function test_dated_cancel_removes_rows_without_shift(): void
    {
        Redis::shouldReceive('set')->once()->andReturn(true);
        [, , $group] = $this->seedTeacherWorld();

        $d1 = now()->addDays(10)->format('Y-m-d');
        $d2 = now()->addDays(20)->format('Y-m-d');
        $first = $this->scheduleAt($d1, 'Занятие А', $group->id);
        $second = $this->scheduleAt($d2, 'Занятие Б', $group->id);
        $nextWeek = $this->scheduleAt(now()->addDays(17)->format('Y-m-d'), 'Занятие В', $group->id);

        app(DateAwareCancelService::class)->handle($this->message(
            'Отмена '.$first->start->format('d.m').' и '.$second->start->format('d.m')
        ));

        // Удалены мягко, БЕЗ сдвига: оставшиеся строки не тронуты.
        $this->assertTrue($first->fresh()->trashed());
        $this->assertTrue($second->fresh()->trashed());
        $this->assertFalse($nextWeek->fresh()->trashed());
        $this->assertSame(
            now()->addDays(17)->format('Y-m-d'),
            $nextWeek->fresh()->start->toDateString(),
        );

        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
        Queue::assertPushed(SendZapisiBotMessageJob::class, function (SendZapisiBotMessageJob $job): bool {
            return str_contains($job->text, 'отменены');
        });
    }

    public function test_teacher_cannot_cancel_foreign_group(): void
    {
        Redis::shouldReceive('set')->never();
        $this->seedTeacherWorld();

        // Чужой группе — свой chat id: матч чата ведёт в ЕЁ чат, а преподаватель
        // ею не руководит. (Один chat_id на две группы сделал бы матч недетерминированным.)
        $foreign = Group::create(['name' => 'Чужая группа', 'telegram_chat_id' => '-100888']);
        $row = $this->scheduleAt(now()->addDays(10)->format('Y-m-d'), 'Чужое занятие', $foreign->id);

        app(DateAwareCancelService::class)->handle($this->message(
            'Отмена '.$row->start->format('d.m'),
            ['chat' => ['id' => '-100888', 'type' => 'supergroup']]
        ));

        $this->assertFalse($row->fresh()->trashed());
        Queue::assertNothingPushed();
    }

    public function test_unknown_sender_is_silent(): void
    {
        Redis::shouldReceive('set')->never();
        [, , $group] = $this->seedTeacherWorld();
        $row = $this->scheduleAt(now()->addDays(10)->format('Y-m-d'), 'Занятие', $group->id);

        app(DateAwareCancelService::class)->handle($this->message(
            'Отмена '.$row->start->format('d.m'),
            ['from' => ['id' => 4242]]
        ));

        $this->assertFalse($row->fresh()->trashed());
        Queue::assertNothingPushed();
    }

    public function test_past_date_goes_to_missing_and_is_not_deleted(): void
    {
        Redis::shouldReceive('set')->once()->andReturn(true);
        [, , $group] = $this->seedTeacherWorld();

        $past = now()->subDays(3);
        $row = Schedule::create([
            'title' => 'Прошедшее',
            'start' => $past->format('Y-m-d').' 20:00:00',
            'end' => $past->format('Y-m-d').' 21:00:00',
            'group_id' => $group->id,
        ]);

        app(DateAwareCancelService::class)->handle($this->message('Отмена '.$row->start->format('d.m')));

        $this->assertFalse($row->fresh()->trashed());
        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
    }

    public function test_duplicate_message_suppressed(): void
    {
        Redis::shouldReceive('set')->twice()->andReturn(true, false);
        [, , $group] = $this->seedTeacherWorld();
        $row = $this->scheduleAt(now()->addDays(10)->format('Y-m-d'), 'Занятие', $group->id);
        $text = 'Отмена '.$row->start->format('d.m');

        app(DateAwareCancelService::class)->handle($this->message($text));
        app(DateAwareCancelService::class)->handle($this->message($text));

        $this->assertTrue($row->fresh()->trashed());
        Queue::assertPushed(SendZapisiBotMessageJob::class, 1);
    }

    public function test_plain_cancel_word_without_dates_is_ignored(): void
    {
        Redis::shouldReceive('set')->never();
        [, , $group] = $this->seedTeacherWorld();
        $row = $this->scheduleAt(now()->addDays(10)->format('Y-m-d'), 'Занятие', $group->id);

        app(DateAwareCancelService::class)->handle($this->message('Отмена занятия'));

        $this->assertFalse($row->fresh()->trashed());
        Queue::assertNothingPushed();
    }

    public function test_admin_can_cancel_any_group(): void
    {
        Redis::shouldReceive('set')->once()->andReturn(true);
        $this->seedTeacherWorld();
        User::create([
            'name' => 'Менеджер',
            'email' => 'mgr@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'manager',
            'telegram_id' => 1002,
        ]);

        $row = $this->scheduleAt(now()->addDays(10)->format('Y-m-d'), 'Занятие', Group::first()->id);

        app(DateAwareCancelService::class)->handle($this->message(
            'Отменяю '.$row->start->format('d.m'),
            ['from' => ['id' => 1002]]
        ));

        $this->assertTrue($row->fresh()->trashed());
    }
}
