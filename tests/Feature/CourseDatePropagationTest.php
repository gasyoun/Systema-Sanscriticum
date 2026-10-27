<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * H6369 — Phase 4 роадмапа Google-календаря: правка starts_at/ends_at у
 * CourseBlock сдвигает будущие занятия Schedule его курса по правилу
 * translate (решение MG 10-10-2026): одна дельта для всех будущих строк,
 * интервалы сохраняются, прошедшие занятия не трогаются.
 */
class CourseDatePropagationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Фиксированное «сейчас» (конвенция репо против CI-timebomb):
        // канарейка-модель — субботы блока #39 17.10.2026 … #74 19.06.2027.
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Курс с блоком (субботы 09:00 МСК) и занятиями до/после «сейчас». */
    private function blockWithSessions(): CourseBlock
    {
        $course = Course::factory()->create();

        return CourseBlock::create([
            'course_id' => $course->id,
            'number' => 1,
            'title' => 'Блок грамматики',
            'starts_at' => Carbon::parse('2026-10-17 09:00:00', 'Europe/Moscow'),
            'ends_at' => Carbon::parse('2027-06-19 11:00:00', 'Europe/Moscow'),
            'is_active' => true,
        ]);
    }

    private function createSession(CourseBlock $block, string $start, string $end): Schedule
    {
        return Schedule::create([
            'title' => 'Занятие',
            'course_id' => $block->course_id,
            'start' => Carbon::parse($start, 'Europe/Moscow'),
            'end' => Carbon::parse($end, 'Europe/Moscow'),
        ]);
    }

    /** @test */
    public function shifting_block_starts_at_moves_future_sessions_by_same_delta(): void
    {
        $block = $this->blockWithSessions();

        // Прошедшее занятие (до «сейчас» 2026-10-10) — не должно сдвинуться.
        $past = $this->createSession($block, '2026-10-03 09:00:00', '2026-10-03 11:00:00');
        // Будущие занятия — сдвигаются на ту же дельту, интервалы сохраняются.
        $s1 = $this->createSession($block, '2026-10-17 09:00:00', '2026-10-17 11:00:00');
        $s2 = $this->createSession($block, '2026-10-24 09:00:00', '2026-10-24 11:00:00');
        $s3 = $this->createSession($block, '2026-10-31 09:00:00', '2026-10-31 11:00:00');

        $originalStarts = collect([$s1, $s2, $s3])->mapWithKeys(
            fn (Schedule $s): array => [$s->id => $s->start->copy()]
        );
        $originalEnds = collect([$s1, $s2, $s3])->mapWithKeys(
            fn (Schedule $s): array => [$s->id => $s->end->copy()]
        );

        $block->starts_at = Carbon::parse('2026-10-24 09:00:00', 'Europe/Moscow'); // +7 дней
        $block->save();

        $this->assertSame(
            '2026-10-03 09:00:00',
            $past->refresh()->start->format('Y-m-d H:i:s'),
            'прошедшее занятие не трогается'
        );

        foreach ([$s1, $s2, $s3] as $session) {
            $this->assertSame(
                $originalStarts[$session->id]->addDays(7)->format('Y-m-d H:i:s'),
                $session->refresh()->start->format('Y-m-d H:i:s'),
                'будущее занятие сдвинуто на дельту границы'
            );
            $this->assertSame(
                $originalEnds[$session->id]->addDays(7)->format('Y-m-d H:i:s'),
                $session->refresh()->end->format('Y-m-d H:i:s'),
                'end занятия сдвинут на ту же дельту'
            );
        }

        // Интервалы между занятиями сохранены (translate, не redistribute).
        // Carbon 3 diffIn* возвращают float (ловушка задокументирована в Schedule.php).
        $this->assertSame(7.0, $s1->refresh()->start->diffInDays($s2->refresh()->start));
    }

    /** @test */
    public function shift_logs_boundary_delta_and_row_count(): void
    {
        Log::spy();
        $block = $this->blockWithSessions();
        $this->createSession($block, '2026-10-17 09:00:00', '2026-10-17 11:00:00');
        $this->createSession($block, '2026-10-24 09:00:00', '2026-10-24 11:00:00');

        $block->starts_at = $block->starts_at->copy()->addDays(7);
        $block->save();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            return $message === 'course_block.date_propagation.translate'
                && $context['shifted'] === 2
                && $context['skipped_past'] === 0
                && $context['delta_seconds'] === 7 * 86400
                && $context['boundary'] === 'starts_at';
        })->once();
    }

    /** @test */
    public function ends_at_shift_does_not_move_sessions(): void
    {
        $block = $this->blockWithSessions();
        $s1 = $this->createSession($block, '2026-10-17 09:00:00', '2026-10-17 11:00:00');

        $block->ends_at = $block->ends_at->copy()->addDays(105)->subSeconds(59);
        $block->save();

        $this->assertSame('2026-10-17 09:00:00', $s1->refresh()->start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-17 11:00:00', $s1->end->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function repeated_save_without_date_changes_does_not_shift_anything(): void
    {
        $block = $this->blockWithSessions();
        $s1 = $this->createSession($block, '2026-10-17 09:00:00', '2026-10-17 11:00:00');

        // Идемпотентность повторной правки: save без изменения дат — 0 сдвигов.
        $block->title = $block->title.' (переименован)';
        $block->save();

        $this->assertSame('2026-10-17 09:00:00', $s1->refresh()->start->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function session_without_end_shifts_start_and_keeps_null_end(): void
    {
        $block = $this->blockWithSessions();
        $openEnded = Schedule::create([
            'title' => 'Занятие без end',
            'course_id' => $block->course_id,
            'start' => Carbon::parse('2026-10-17 09:00:00', 'Europe/Moscow'),
        ]);

        $block->starts_at = $block->starts_at->copy()->addDays(7);
        $block->save();

        $openEnded->refresh();
        $this->assertSame('2026-10-24 09:00:00', $openEnded->start->format('Y-m-d H:i:s'));
        $this->assertNull($openEnded->end);
    }

    /** @test */
    public function first_time_date_assignment_from_null_does_not_shift(): void
    {
        $course = Course::factory()->create();
        $block = CourseBlock::create([
            'course_id' => $course->id,
            'number' => 2,
            'title' => 'Блок без дат',
            'is_active' => true,
        ]);
        $s1 = Schedule::create([
            'title' => 'Занятие',
            'course_id' => $course->id,
            'start' => Carbon::parse('2026-10-17 09:00:00', 'Europe/Moscow'),
            'end' => Carbon::parse('2026-10-17 11:00:00', 'Europe/Moscow'),
        ]);

        // Блоку впервые поставили дату: относительно null-границы переводить
        // нечего — занятия не трогаются.
        $block->starts_at = Carbon::parse('2026-10-24 09:00:00', 'Europe/Moscow');
        $block->save();

        $this->assertSame('2026-10-17 09:00:00', $s1->refresh()->start->format('Y-m-d H:i:s'));
    }
}
