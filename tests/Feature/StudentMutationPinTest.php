<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\TelegramScheduleReschedule;
use App\Models\TelegramStudentQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * H8527 мутационный pin: студенческий текст НИКОГДА не применяет
 * перенос/отмену/архив/стоп. Сторонний текст студента попадает только в
 * стопочку (proposal), расписание трогают только teacher/staff.
 */
class StudentMutationPinTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-1003994169966';

    private const STUDENT_TG_ID = 777001;

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
        config()->set('services.telegram_zapisi.announce_cancel_detect', true);

        MarketingSetting::flushCached();
        MarketingSetting::create(['zapisi_bot_token' => 'ZAPISITOKEN']);

        $course = Course::factory()->create(['slug' => 'grammar-63', 'title' => 'Грамматика по Кочергиной гр.63']);
        $this->group = Group::create(['name' => 'Грамматика по Кочергиной гр.63', 'telegram_chat_id' => self::CHAT_ID]);
        $course->groups()->attach($this->group->id);
        $this->groupStatusBefore = (string) $this->group->status;
        config()->set('services.telegram_zapisi.announce_reschedule_group_ids', [$this->group->id]);

        User::factory()->create([
            'telegram_id' => self::STUDENT_TG_ID,
            'role' => 'student',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-21 12:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private Group $group;

    private string $groupStatusBefore;

    private function lesson(string $start, string $title): Schedule
    {
        $start = Carbon::parse($start, 'Europe/Moscow');

        return Schedule::create([
            'title' => $title,
            'start' => $start,
            'end' => $start->copy()->addHours(2),
            'group_id' => $this->group->id,
            'link' => 'https://zoom.us/j/x',
        ]);
    }

    private function process(string $text, int $messageId = 300, ?int $fromId = null): void
    {
        (new ProcessTelegramZapisiUpdate([
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup'],
                'message_id' => $messageId,
                'from' => ['id' => $fromId ?? self::STUDENT_TG_ID],
                'text' => $text,
                'date' => now()->timestamp,
            ],
        ]))->handle();
    }

    private function assertNothingMutated(): void
    {
        $this->assertSame(0, TelegramScheduleReschedule::count());
        foreach (Schedule::query()->where('group_id', $this->group->id)->get() as $entry) {
            $this->assertSame(
                $entry->getOriginal('start')?->format('Y-m-d H:i:s'),
                $entry->fresh()->getOriginal('start')?->format('Y-m-d H:i:s'),
            );
        }
    }

    public function test_student_multi_date_reschedule_text_never_mutates(): void
    {
        $first = $this->lesson('2026-12-24 08:00:00', 'Кашмир (#1, 24.12.26)');
        $second = $this->lesson('2026-12-31 19:30:00', 'Кашмир (#2, 31.12.26)');

        $this->process('может перенести 24.12 и 31.12 на 26.12 и 28.12?');

        $this->assertSame('2026-12-24 08:00:00', $first->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-31 19:30:00', $second->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertNothingMutated();
        $question = TelegramStudentQuestion::query()->sole();
        $this->assertSame('proposal', $question->kind);
    }

    public function test_student_weekday_shift_proposal_never_mutates(): void
    {
        $this->lesson('2026-11-26 08:00:00', 'Кашмир (#1, 26.11.26)');

        $this->process('может на другой день недели перенести?');

        $this->assertNothingMutated();
        $this->assertSame(1, TelegramStudentQuestion::query()->where('kind', 'proposal')->count());
    }

    public function test_student_stop_and_cancel_texts_never_mutate(): void
    {
        $lesson = $this->lesson('2026-11-26 08:00:00', 'Кашмир (#1, 26.11.26)');

        $this->process('стоп', messageId: 301);
        $this->process('Отмена 26.11', messageId: 302);
        $this->process('Каникулы с 26.11 по 06.12', messageId: 303);

        $this->assertSame('2026-11-26 08:00:00', $lesson->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertNothingMutated();
        $this->assertSame($this->groupStatusBefore, (string) $this->group->fresh()->status);
    }

    public function test_unrecognized_sender_is_fully_silent(): void
    {
        $this->lesson('2026-12-24 08:00:00', 'Кашмир (#1, 24.12.26)');

        $this->process('перенести 24.12 и 31.12 на 26.12 и 28.12', fromId: 999999);

        $this->assertNothingMutated();
        $this->assertSame(0, TelegramStudentQuestion::count());
        Http::assertNothingSent();
    }

    public function test_teacher_multi_date_still_applies(): void
    {
        $first = $this->lesson('2026-12-24 08:00:00', 'Кашмир (#1, 24.12.26)');
        $second = $this->lesson('2026-12-31 19:30:00', 'Кашмир (#2, 31.12.26)');

        $teacher = Teacher::create(['name' => 'Препод Свой']);
        $this->group->courses->first()->update(['teacher_id' => $teacher->id]);
        User::factory()->create([
            'telegram_id' => 5487293147,
            'role' => 'teacher',
            'teacher_id' => $teacher->id,
        ]);

        $this->process('перенести 24.12 и 31.12 на 26.12 и 28.12', fromId: 5487293147);

        $this->assertSame('2026-12-26 08:00:00', $first->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-28 19:30:00', $second->fresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame(2, TelegramScheduleReschedule::count());
        $this->assertSame(0, TelegramStudentQuestion::count());
    }
}
