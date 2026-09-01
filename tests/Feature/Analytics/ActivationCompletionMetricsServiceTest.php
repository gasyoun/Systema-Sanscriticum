<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\LessonView;
use App\Models\Payment;
use App\Models\User;
use App\Services\Analytics\ActivationCompletionMetricsService;
use App\Services\StudentUnitEconomicsService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H3764 — hand-computed activation funnel and completion rates on a seeded
 * cohort. Every assertion below is a number a human can recompute from the
 * fixture comments; that is the point of the exercise (H2378).
 *
 * Fixture — январская когорта 2026, 4 ученика (+1 сотрудник, +1 вне окна):
 *   S1 вошёл, открыл урок через 5 дн., сдал домашнюю  → все три шага
 *   S2 вошёл, открыл урок через 15 дн., домашняя draft → без домашней
 *   S3 вошёл, урок не открывал                        → только вход
 *   S4 не входил вовсе                                → ничего
 * Знаменатель когорты = 4. Вошли 3 (75 %), открыли 2 (50 %), сдали 1 (25 %).
 * Медиана TTFL по двум открывшим = (5 + 15) / 2 = 10 дн.
 */
class ActivationCompletionMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private User $s1;

    private User $s2;

    private User $s3;

    private User $s4;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));
        config(['activation_metrics.min_denominator' => 1]);

        $this->course = Course::factory()->create(['title' => 'Санскрит-1']);

        $this->s1 = User::factory()->create(['login_count' => 4]);
        $this->s2 = User::factory()->create(['login_count' => 2]);
        $this->s3 = User::factory()->create(['login_count' => 1]);
        $this->s4 = User::factory()->create(['login_count' => 0, 'last_login_at' => null]);

        foreach ([$this->s1, $this->s2, $this->s3, $this->s4] as $u) {
            $this->pay($u, 'block_1', 5000, '2026-01-10');
        }

        // Сотрудник с такой же оплатой — в знаменатель активации НЕ входит.
        $staff = User::factory()->create(['role' => Roles::ACCOUNTANT, 'login_count' => 9]);
        $this->pay($staff, 'block_1', 5000, '2026-01-11');

        // Ученик с первой покупкой в 2025 — вне окна когорт.
        $old = User::factory()->create(['login_count' => 3]);
        $this->pay($old, 'block_1', 5000, '2025-03-10');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pay(User $user, string $tariff, float $amount, string $date): void
    {
        Payment::withoutEvents(function () use ($user, $tariff, $amount, $date): void {
            Payment::create([
                'user_id' => $user->id,
                'course_id' => $this->course->id,
                'tariff' => $tariff,
                'amount' => $amount,
                'status' => 'paid',
                'is_conditional' => false,
                'created_at' => Carbon::parse($date.' 12:00:00'),
            ]);
        });
    }

    private function lesson(?int $groupId = null): Lesson
    {
        return Lesson::factory()->create([
            'course_id' => $this->course->id,
            'group_id' => $groupId,
        ]);
    }

    private function opened(User $user, Lesson $lesson, string $openedAt, bool $completed = false): void
    {
        LessonView::create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'course_id' => $this->course->id,
            'first_opened_at' => Carbon::parse($openedAt.' 12:00:00'),
            'last_opened_at' => Carbon::parse($openedAt.' 12:00:00'),
            // is_completed на lesson_views намеренно НЕ трогаем: на проде этот
            // столбец никто не заполняет (0 из 649 строк, 01-09-2026).
            'is_completed' => false,
        ]);

        if ($completed) {
            $this->completeLesson($user, $lesson);
        }
    }

    /** created_at не fillable — датируем сдачу отдельно, иначе фикстура «сдана сегодня». */
    private function homework(User $user, Lesson $lesson, string $status, string $date): void
    {
        $hw = HomeworkSubmission::create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'course_id' => $this->course->id,
            'status' => $status,
        ]);
        $hw->forceFill(['created_at' => Carbon::parse($date.' 12:00:00')])->saveQuietly();
    }

    /** Пройденный урок живёт в пивоте lesson_user — единственный живой источник. */
    private function completeLesson(User $user, Lesson $lesson): void
    {
        DB::table('lesson_user')->insert([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'is_completed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function snapshot(): array
    {
        return app(ActivationCompletionMetricsService::class)->snapshot();
    }

    /** @test */
    public function activation_funnel_matches_the_hand_computed_cohort(): void
    {
        $lesson = $this->lesson();
        $this->opened($this->s1, $lesson, '2026-01-15');   // +5 дн.
        $this->opened($this->s2, $lesson, '2026-01-25');   // +15 дн.

        $this->homework($this->s1, $lesson, 'submitted', '2026-01-20');
        $this->homework($this->s2, $this->lesson(), 'draft', '2026-01-22');   // черновик сдачей не считается

        $a = $this->snapshot()['activation'];

        $this->assertTrue($a['found']);
        $this->assertCount(1, $a['cohorts']);

        $jan = $a['cohorts'][0];
        $this->assertSame('2026-01', $jan['month']);
        $this->assertSame(4, $jan['denominator']);          // сотрудник исключён
        $this->assertSame(3, $jan['logged_in']);
        $this->assertSame(75.0, $jan['logged_in_pct']);
        $this->assertSame(2, $jan['opened_lesson']);
        $this->assertSame(50.0, $jan['opened_lesson_pct']);
        $this->assertSame(1, $jan['submitted_homework']);   // draft не в счёт
        $this->assertSame(25.0, $jan['submitted_homework_pct']);
        $this->assertSame(10.0, $jan['ttfl_median_days']);  // медиана (5, 15)
        $this->assertSame(2, $jan['ttfl_denominator']);
    }

    /** @test */
    public function ttfl_ignores_lessons_opened_before_the_purchase_anchor(): void
    {
        $lesson = $this->lesson();
        // Открыл бесплатную витрину ДО покупки: активацией оплаты это не является,
        // но в «открыл урок» человек попадает — иначе шаг воронки соврёт.
        $this->opened($this->s1, $lesson, '2025-12-01');

        $jan = $this->snapshot()['activation']['cohorts'][0];

        $this->assertSame(1, $jan['opened_lesson']);
        $this->assertSame(0, $jan['ttfl_denominator']);
        $this->assertNull($jan['ttfl_median_days']);
    }

    /** @test */
    public function empty_window_reports_no_data_instead_of_zero_percent(): void
    {
        DB::table('payments')->delete();

        $a = $this->snapshot()['activation'];

        $this->assertFalse($a['found']);
        $this->assertSame([], $a['cohorts']);
        $this->assertNull($a['total']);
    }

    /** @test */
    public function course_completion_uses_the_configured_lesson_threshold(): void
    {
        config(['activation_metrics.completion_lesson_ratio' => 0.8]);

        $lessons = collect(range(1, 5))->map(fn () => $this->lesson());
        // S1 прошёл 4 из 5 = 80 % → дошёл (порог ceil(5 * 0.8) = 4).
        $lessons->take(4)->each(fn (Lesson $l) => $this->opened($this->s1, $l, '2026-02-01', true));
        // S2 прошёл 3 из 5 = 60 % → не дошёл.
        $lessons->take(3)->each(fn (Lesson $l) => $this->opened($this->s2, $l, '2026-02-01', true));

        foreach ([$this->s1, $this->s2, $this->s3, $this->s4] as $u) {
            DB::table('course_user')->insert([
                'user_id' => $u->id,
                'course_id' => $this->course->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $row = $this->snapshot()['completion']['courses'][0];

        $this->assertSame('Санскрит-1', $row['name']);
        $this->assertSame(4, $row['denominator']);
        $this->assertSame(5, $row['lessons_total']);
        $this->assertSame(4, $row['lessons_needed']);
        $this->assertSame(1, $row['completed']);
        $this->assertSame(25.0, $row['completed_pct']);
        // 5, а не 4: у ученика вне окна когорт тоже есть оплата этого курса, но
        // в course_user его не записали. Именно такое расхождение колонка и
        // существует показывать — знаменатель «записаны» и «оплатили» разные.
        $this->assertSame(5, $row['paid_students']);
        $this->assertNotSame($row['denominator'], $row['paid_students']);
    }

    /** @test */
    public function certificates_are_counted_separately_from_the_lesson_threshold(): void
    {
        $this->lesson();
        DB::table('course_user')->insert([
            'user_id' => $this->s1->id,
            'course_id' => $this->course->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Certificate::create([
            'user_id' => $this->s1->id,
            'course_id' => $this->course->id,
            'number' => 'SANSK-1',
            'issued_at' => '2026-03-01',
        ]);

        $row = $this->snapshot()['completion']['courses'][0];

        $this->assertSame(1, $row['denominator']);
        $this->assertSame(1, $row['certified']);
        $this->assertSame(100.0, $row['certified_pct']);
        $this->assertSame(0, $row['completed']);   // порог по урокам не взят
    }

    /** @test */
    public function group_completion_counts_only_that_streams_own_lessons(): void
    {
        $group = Group::factory()->create(['name' => 'Поток-1']);
        $own = collect(range(1, 2))->map(fn () => $this->lesson($group->id));
        $this->lesson();   // урок вне потока — в знаменатель потока не входит

        foreach ([$this->s1, $this->s2] as $u) {
            DB::table('group_user')->insert(['group_id' => $group->id, 'user_id' => $u->id]);
        }
        $own->each(fn (Lesson $l) => $this->opened($this->s1, $l, '2026-02-01', true));

        $row = $this->snapshot()['completion']['groups'][0];

        $this->assertSame('Поток-1', $row['name']);
        $this->assertSame(2, $row['denominator']);
        $this->assertSame(2, $row['lessons_total']);
        $this->assertSame(1, $row['completed']);
        $this->assertSame(50.0, $row['completed_pct']);
    }

    /** @test */
    public function activation_anchor_does_not_fork_from_unit_economics(): void
    {
        $anchors = app(StudentUnitEconomicsService::class)->acquisitionAnchors();

        // Оба сервиса видят один и тот же момент привлечения для S1.
        $this->assertTrue($anchors->has($this->s1->id));
        $this->assertSame('2026-01-10', $anchors[$this->s1->id]['date']->toDateString());

        $jan = $this->snapshot()['activation']['cohorts'][0];
        $this->assertSame('2026-01', $jan['month']);
    }

    /** @test */
    public function lesson_views_is_completed_is_never_trusted_as_the_completion_signal(): void
    {
        // Прод-находка H3764: lesson_views.is_completed не заполняется вообще.
        // Строка просмотра с is_completed=1 и пустым пивотом не должна давать
        // «прошёл курс» — иначе метрика поедет на мёртвом столбце.
        $lesson = $this->lesson();
        LessonView::create([
            'user_id' => $this->s1->id,
            'lesson_id' => $lesson->id,
            'course_id' => $this->course->id,
            'first_opened_at' => Carbon::parse('2026-02-01 12:00:00'),
            'last_opened_at' => Carbon::parse('2026-02-01 12:00:00'),
            'is_completed' => true,
        ]);
        DB::table('course_user')->insert([
            'user_id' => $this->s1->id,
            'course_id' => $this->course->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = $this->snapshot()['completion']['courses'][0];

        $this->assertSame(1, $row['denominator']);
        $this->assertSame(0, $row['completed']);

        // А тот же урок, отмеченный в пивоте, засчитывается.
        $this->completeLesson($this->s1, $lesson);
        $row = $this->snapshot()['completion']['courses'][0];
        $this->assertSame(1, $row['completed']);
    }

    /** @test */
    public function cohorts_older_than_the_telemetry_are_not_measurable_instead_of_zero(): void
    {
        // Первая строка телеметрии уроков появляется только в мае 2026 —
        // январская когорта по шагу «открыл урок» неизмерима, а не 0 %.
        $late = User::factory()->create(['login_count' => 1]);
        $this->pay($late, 'block_1', 5000, '2026-05-10');
        $this->opened($late, $this->lesson(), '2026-05-20');

        $cohorts = collect($this->snapshot()['activation']['cohorts'])->keyBy('month');

        $jan = $cohorts['2026-01'];
        $this->assertFalse($jan['lesson_measurable']);
        $this->assertNull($jan['opened_lesson_pct']);
        $this->assertNull($jan['ttfl_median_days']);

        $may = $cohorts['2026-05'];
        $this->assertTrue($may['lesson_measurable']);
        $this->assertSame(100.0, $may['opened_lesson_pct']);
    }

    /** @test */
    public function a_course_with_signal_survives_the_row_limit(): void
    {
        // Прод-находка 01-09-2026: сигнал был у 5 курсов из 116, и 4 из них не
        // попадали в топ-20 по числу записанных — страница показывала стену нулей
        // и прятала ровно те курсы, где кто-то дошёл.
        config(['activation_metrics.completion_rows' => 2]);

        // Три крупных курса без единого пройденного урока.
        foreach (range(1, 3) as $i) {
            $big = Course::factory()->create(['title' => 'Большой '.$i]);
            Lesson::factory()->create(['course_id' => $big->id]);
            foreach ([$this->s1, $this->s2, $this->s3, $this->s4] as $u) {
                DB::table('course_user')->insert([
                    'user_id' => $u->id,
                    'course_id' => $big->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Маленький курс, где один ученик реально дошёл до конца.
        $small = Course::factory()->create(['title' => 'Маленький, но дошли']);
        $lesson = Lesson::factory()->create(['course_id' => $small->id]);
        DB::table('course_user')->insert([
            'user_id' => $this->s1->id,
            'course_id' => $small->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->completeLesson($this->s1, $lesson);

        $completion = $this->snapshot()['completion'];
        $names = array_column($completion['courses'], 'name');

        $this->assertContains('Маленький, но дошли', $names);
        $this->assertSame(4, $completion['courses_total']);
        // Лимит 2 соблюдён: строка с сигналом вытеснила пустую, а не добавилась сверх.
        $this->assertCount(2, $completion['courses']);
    }

    /** @test */
    public function small_cohorts_are_flagged_unreliable(): void
    {
        config(['activation_metrics.min_denominator' => 5]);

        $jan = $this->snapshot()['activation']['cohorts'][0];

        $this->assertSame(4, $jan['denominator']);
        $this->assertFalse($jan['reliable']);
    }
}
