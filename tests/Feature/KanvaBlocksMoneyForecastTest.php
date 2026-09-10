<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Schedule\CanvasMoney;
use App\Services\Schedule\TextbookScale;
use App\Services\Schedule\WeeklyFinishReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class KanvaBlocksMoneyForecastTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function block_marker_wins_over_arithmetic(): void
    {
        // Урок 4 с маркером *3.1* = блок 3 (не ceil(4/4)=1).
        $this->assertSame(3, TextbookScale::parseBlockMarker('Кочергина 4 (читка) (#9, 08.09.26) 1-е занятие 3-го блока (*3.1*)'));
        $this->assertNull(TextbookScale::parseBlockMarker('Кочергина 4 (читка)'));
    }

    /** @test */
    public function blocks_total_from_tariffs_first(): void
    {
        $course = Course::factory()->create();
        Tariff::create(['course_id' => $course->id, 'title' => 'Блок 1', 'tariff' => 'block_1', 'block_number' => 1, 'price' => 1000]);
        Tariff::create(['course_id' => $course->id, 'title' => 'Блок 2', 'tariff' => 'block_2', 'block_number' => 2, 'price' => 1000]);

        // Канва 40 уроков → арифметика дала бы 10; тарифы говорят: 2.
        $this->assertSame(2, TextbookScale::blocksTotal($course->id, 40));
    }

    /** @test */
    public function unpaid_counts_only_uncovered_blocks(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        foreach ([4, 5, 6] as $b) {
            Tariff::create(['course_id' => $course->id, 'title' => 'Блок '.$b, 'tariff' => 'block_'.$b, 'block_number' => $b, 'price' => 3000]);
        }

        // Оплачены блоки 4-5 (диапазон), блок 6 не оплачен.
        Payment::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'amount' => 6000, 'tariff' => 'block_4_5', 'start_block' => 4, 'end_block' => 5,
            'status' => 'paid',
        ]);

        $unpaid = CanvasMoney::unpaidFor($student, $course, 3, 6);

        $this->assertSame(3000.0, $unpaid['amount']);
        $this->assertSame(4, $unpaid['blocks_from']);
        $this->assertSame(6, $unpaid['blocks_to']);
        $this->assertSame('неоплачено 3 000 ₽ (блоки 4-6)', CanvasMoney::humanize($unpaid));
    }

    /** @test */
    public function fully_paid_student_yields_zero(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        Payment::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'amount' => 100000, 'tariff' => 'full', 'status' => 'paid',
        ]);

        $unpaid = CanvasMoney::unpaidFor($student, $course, 3, 10);
        $this->assertSame(0.0, $unpaid['amount']);
        $this->assertSame('всё оплачено', CanvasMoney::humanize($unpaid));
    }

    /** @test */
    public function refund_and_failed_payments_do_not_cover(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        Tariff::create(['course_id' => $course->id, 'title' => 'Блок 5', 'tariff' => 'block_5', 'block_number' => 5, 'price' => 4000]);
        Payment::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'amount' => 4000, 'tariff' => 'block_5', 'start_block' => 5, 'status' => 'refunded',
        ]);

        $unpaid = CanvasMoney::unpaidFor($student, $course, 4, 5);
        $this->assertSame(4000.0, $unpaid['amount']);
    }

    /** @test */
    public function half_block_pays_half_price(): void
    {
        $course = Course::factory()->create();
        $student = User::factory()->create();
        Tariff::create(['course_id' => $course->id, 'title' => 'Блок 4 h1', 'tariff' => 'block_4_h1', 'block_number' => 4, 'block_half' => 1, 'price' => 2000]);
        Tariff::create(['course_id' => $course->id, 'title' => 'Блок 4 h2', 'tariff' => 'block_4_h2', 'block_number' => 4, 'block_half' => 2, 'price' => 2000]);
        Payment::create([
            'user_id' => $student->id, 'course_id' => $course->id,
            'amount' => 2000, 'tariff' => 'block_4_h1', 'status' => 'paid',
        ]);

        // Хвост тарифа block_4_h1: coversBlockHalf(4,1)=true (tariff key), (4,2)=false → половина цены.
        // Базовая цена блока = первый попавшийся тариф блока (2000) → остаток 1000? Нет:
        // половина от 2000 = 1000. Проверяем механику честно.
        $unpaid = CanvasMoney::unpaidFor($student, $course, 3, 4);
        $this->assertSame(1000.0, $unpaid['amount']);
    }

    /** @test */
    public function forecast_skips_new_year_and_summer(): void
    {
        // 6 оставшихся занятий, темп 1/неделю, старт 20.11.2026:
        // 4 недели уходят до НГ (27.11-18.12), 29.12-11.01 каникула пропускается,
        // финал — вторая половина января 2027.
        $f = TextbookScale::finishForecast(6, Carbon::parse('2026-11-20'), 1.0);
        $this->assertSame('январь 2027', $f);

        // Старт 1 июня: лето (норма до 15.09) пропускается → финал в сентябре;
        // макс. поздно (лето до 15.10) → октябрь.
        $f = TextbookScale::finishForecast(2, Carbon::parse('2026-06-01'), 1.0);
        $this->assertSame('сентябрь 2026', $f);
    }

    /** @test */
    public function tg_output_contains_no_money(): void
    {
        $course = Course::factory()->create(['title' => 'Грамматика по Кочергиной гр.99', 'is_active' => true, 'is_visible' => true]);
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        $student = User::factory()->create();
        $group->users()->attach($student->id);
        foreach ([1, 2] as $i) {
            $day = now()->subDays(14 - $i * 2);
            Schedule::create(['title' => 'S'.$i, 'start' => $day->format('Y-m-d H:i:s'), 'group_id' => $group->id, 'course_id' => $course->id]);
            Lesson::create(['title' => 'Кочергина '.$i.' (читка)', 'course_id' => $course->id, 'lesson_date' => $day->format('Y-m-d H:i:s')]);
        }
        Schedule::create(['title' => 'F', 'start' => now()->addDays(5)->format('Y-m-d H:i:s'), 'group_id' => $group->id, 'course_id' => $course->id]);
        Tariff::create(['course_id' => $course->id, 'title' => 'Блок 1', 'tariff' => 'block_1', 'block_number' => 1, 'price' => 5000]);

        $report = WeeklyFinishReport::build();
        $chunks = WeeklyFinishReport::telegramChunks($report);

        $this->assertStringContainsString('блок', $chunks[0]);
        $this->assertStringContainsString('финал:', $chunks[0]);
        // H4495 (MG 09-09): публичный пост — ТОЛЬКО группы. Никаких студентов,
        // ссылок на админку и денег.
        $this->assertStringNotContainsString('₽', $chunks[0]);
        $this->assertStringNotContainsString('<a href', $chunks[0]);
        $this->assertStringNotContainsString('не был ни разу', $chunks[0]);
        foreach ($report[0]['students'] as $s) {
            $this->assertStringNotContainsString($s['user']->name, implode('', $chunks));
        }
    }
}
