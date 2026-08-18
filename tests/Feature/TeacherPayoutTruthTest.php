<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\CourseStreamComparison;
use App\Filament\Pages\PayoutAttributionGuide;
use App\Filament\Resources\TeacherPayoutAttributionSuggestionResource;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\TeacherPayoutAttributionSuggestion;
use App\Models\User;
use App\Services\TeacherPayoutReconciliation;
use App\Services\TeacherSettlementActPdf;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H3084 (волна 2) — правда о выплатах преподавателю.
 *
 * Фикстура повторяет форму боевой семьи «Кашмирский шиваизм»:
 *
 *   - два живых потока с блоками, тарифами и преподавателем;
 *   - курс-запись без блоков, тарифов, преподавателя и схемы ЗП (курс 424);
 *   - платежи-«Расходы»: часть на служебного пользователя «Системные расходы»,
 *     один — прямо на личного пользователя преподавателя (боевой #13573);
 *   - однофамилец преподавателя, которого связывать МОЛЧА нельзя.
 *
 * Главный инвариант приёмки: ни одна команда волны 2 не создаёт и не меняет
 * строк в `teacher_payouts` и `payments`.
 */
class TeacherPayoutTruthTest extends TestCase
{
    use RefreshDatabase;

    private const FAMILY = 'kasmirskii-sivaizm';

    private Teacher $teacher;

    private User $teacherUser;

    private User $serviceUser;

    private Course $live1;

    private Course $live2;

    private Course $recording;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = Teacher::create(['name' => 'Ворошилов Максим Анатольевич']);

        $this->teacherUser = User::factory()->create([
            'name' => 'Ворошилов Максим Анатольевич',
            'teacher_id' => null,
        ]);
        $this->serviceUser = User::factory()->create(['name' => 'Системные расходы']);

        $this->live1 = Course::factory()->create([
            'title' => 'Кашмирский шиваизм (1 поток, 2025)',
            'course_family' => self::FAMILY,
            'teacher_id' => $this->teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
            'is_active' => true,
        ]);
        $this->live2 = Course::factory()->create([
            'title' => 'Кашмирский шиваизм (2 поток, 2026)',
            'course_family' => self::FAMILY,
            'teacher_id' => $this->teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
            'is_active' => true,
        ]);
        // Курс-запись: ни блоков, ни тарифов, ни преподавателя — как курс 424.
        $this->recording = Course::factory()->create([
            'title' => 'Кашмирский шиваизм 2025 в записи',
            'course_family' => self::FAMILY,
            'teacher_id' => null,
            'salary_type' => null,
            'salary_value' => null,
            'is_active' => false,
        ]);

        foreach ([$this->live1, $this->live2] as $course) {
            foreach ([1, 2] as $n) {
                CourseBlock::create(['course_id' => $course->id, 'number' => $n, 'title' => "Блок {$n}"]);
            }
        }

        // Выручка. Запись продана на 51 000 ₽ — 30 % от неё = 15 300 ₽,
        // те самые деньги, которые не начислялись никогда.
        [$a, $b, $c] = User::factory()->count(3)->create();
        $this->pay($a, $this->live1, 'block_1', 100000);
        $this->pay($b, $this->live2, 'block_1', 50000);
        $this->pay($c, $this->recording, 'block_1', 51000);
    }

    private function pay(User $user, Course $course, string $tariff, float $amount, ?string $at = null): Payment
    {
        // Без событий: PaymentObserver::grantAccess иначе меняет состав групп.
        return Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => $amount,
            'status' => 'paid',
            'tariff' => $tariff,
            'created_at' => $at ?? now(),
        ]));
    }

    /** Шесть «Расходов» на служебного пользователя + один прямо на преподавателя. */
    private function seedExpenses(): void
    {
        $this->pay($this->serviceUser, $this->live1, 'Расход', -47100, '2025-10-01 00:00:00');
        $this->pay($this->serviceUser, $this->live1, 'Расход', -20220, '2025-11-14 00:00:00');
        $this->pay($this->serviceUser, $this->live2, 'Расход', -35630, '2026-03-23 00:00:00');
        $this->pay($this->teacherUser, $this->live2, 'Расход', -50000, '2026-06-02 10:10:00');
    }

    /** @return array{int, float, int, float} строк и сумм в двух денежных таблицах */
    private function moneyFingerprint(): array
    {
        return [
            Payment::count(),
            (float) Payment::sum('amount'),
            TeacherPayout::count(),
            (float) TeacherPayout::sum('amount'),
        ];
    }

    /** @test */
    public function recording_course_inherits_teacher_and_scheme_only_with_apply(): void
    {
        $this->artisan('salary:repair-recording-courses')->assertSuccessful();

        $this->recording->refresh();
        $this->assertNull($this->recording->teacher_id, 'отчётный прогон не пишет в базу');
        $this->assertNull($this->recording->salary_type);

        $this->artisan('salary:repair-recording-courses --apply')->assertSuccessful();

        $this->recording->refresh();
        $this->assertSame($this->teacher->id, (int) $this->recording->teacher_id);
        $this->assertSame('percent', $this->recording->salary_type);
        $this->assertSame(30.0, (float) $this->recording->salary_value);

        // 30 % от 51 000 ₽ = 15 300 ₽ — начисление, которого не было никогда.
        $salary = app(TeacherPayoutReconciliation::class)->forFamily(Course::inFamily(self::FAMILY)->get());
        $this->assertSame(15300.0, $salary['accrued_by_course'][$this->recording->id]);
    }

    /** @test */
    public function recording_course_is_skipped_when_live_streams_disagree_on_terms(): void
    {
        $other = Teacher::create(['name' => 'Другой Преподаватель']);
        $this->live2->update(['teacher_id' => $other->id, 'salary_value' => 40]);

        $this->artisan('salary:repair-recording-courses --apply')->assertSuccessful();

        $this->recording->refresh();
        $this->assertNull($this->recording->teacher_id, 'при расхождении условий авто не угадывает');
    }

    /** @test */
    public function filled_teacher_on_a_recording_course_is_never_overwritten(): void
    {
        $other = Teacher::create(['name' => 'Уже Проставленный']);
        $this->recording->update(['teacher_id' => $other->id]);

        $this->artisan('salary:repair-recording-courses --apply')->assertSuccessful();

        $this->recording->refresh();
        $this->assertSame($other->id, (int) $this->recording->teacher_id);
    }

    /** @test */
    public function teacher_user_link_needs_apply_and_refuses_namesakes(): void
    {
        $this->artisan('salary:link-teacher-users')->assertSuccessful();
        $this->assertNull($this->teacherUser->fresh()->teacher_id, 'отчётный прогон не пишет в базу');

        $this->artisan('salary:link-teacher-users --apply')->assertSuccessful();
        $this->assertSame($this->teacher->id, (int) $this->teacherUser->fresh()->teacher_id);
    }

    /** @test */
    public function a_namesake_blocks_the_link_instead_of_a_silent_guess(): void
    {
        $namesake = User::factory()->create(['name' => 'Ворошилов Максим Анатольевич']);

        $this->artisan('salary:link-teacher-users --apply')->assertSuccessful();

        $this->assertNull($this->teacherUser->fresh()->teacher_id, 'однофамилец не связывается молча');
        $this->assertNull($namesake->fresh()->teacher_id);
    }

    /** @test */
    public function block_dates_come_from_lessons_and_never_from_a_degenerate_import(): void
    {
        // Живой поток 1 — как боевой курс 332: все уроки на одну дату загрузки.
        foreach ([1, 2] as $n) {
            Lesson::factory()->count(2)->create([
                'course_id' => $this->live1->id,
                'block_number' => $n,
                'lesson_date' => '2025-10-08',
            ]);
        }

        // Живой поток 2 — как боевой курс 375: у блоков разные даты.
        Lesson::factory()->create(['course_id' => $this->live2->id, 'block_number' => 1, 'lesson_date' => '2026-03-25']);
        Lesson::factory()->create(['course_id' => $this->live2->id, 'block_number' => 1, 'lesson_date' => '2026-04-07']);
        Lesson::factory()->create(['course_id' => $this->live2->id, 'block_number' => 2, 'lesson_date' => '2026-04-29']);

        $this->artisan('courses:backfill-block-dates')->assertSuccessful();
        $this->assertNull(CourseBlock::where('course_id', $this->live2->id)->where('number', 1)->first()->starts_at);

        $this->artisan('courses:backfill-block-dates --apply')->assertSuccessful();

        $b1 = CourseBlock::where('course_id', $this->live2->id)->where('number', 1)->first();
        $this->assertSame('2026-03-25', $b1->starts_at?->toDateString());
        $this->assertSame('2026-04-07', $b1->ends_at?->toDateString());

        foreach ([1, 2] as $n) {
            $degenerate = CourseBlock::where('course_id', $this->live1->id)->where('number', $n)->first();
            $this->assertNull($degenerate->starts_at, 'дата массового импорта не выдаётся за расписание');
            $this->assertNull($degenerate->ends_at);
        }
    }

    /** @test */
    public function block_dates_never_overwrite_a_human_value(): void
    {
        Lesson::factory()->create(['course_id' => $this->live2->id, 'block_number' => 1, 'lesson_date' => '2026-03-25']);
        Lesson::factory()->create(['course_id' => $this->live2->id, 'block_number' => 2, 'lesson_date' => '2026-04-29']);

        $block = CourseBlock::where('course_id', $this->live2->id)->where('number', 1)->first();
        $block->update(['starts_at' => '2020-01-01 00:00:00']);

        $this->artisan('courses:backfill-block-dates --apply')->assertSuccessful();

        $this->assertSame('2020-01-01', $block->fresh()->starts_at?->toDateString());
    }

    /** @test */
    public function the_detector_skips_the_payment_that_is_already_counted_directly(): void
    {
        $this->seedExpenses();
        $this->artisan('salary:link-teacher-users --apply')->assertSuccessful();

        $direct = Payment::where('user_id', $this->teacherUser->id)->where('tariff', 'Расход')->firstOrFail();

        $this->artisan('salary:detect-payout-attributions --apply')->assertSuccessful();

        $this->assertSame(3, TeacherPayoutAttributionSuggestion::count(), 'предлагаются только неразмеченные «Расходы»');
        $this->assertDatabaseMissing('teacher_payout_attribution_suggestions', ['payment_id' => $direct->id]);
        $this->assertSame(
            TeacherPayoutAttributionSuggestion::STATUS_PENDING,
            TeacherPayoutAttributionSuggestion::first()->status,
            'детектор заводит строки только в статусе «ожидает»',
        );
    }

    /** @test */
    public function the_detector_writes_nothing_without_apply_and_never_duplicates(): void
    {
        $this->seedExpenses();

        $this->artisan('salary:detect-payout-attributions')->assertSuccessful();
        $this->assertSame(0, TeacherPayoutAttributionSuggestion::count());

        $this->artisan('salary:detect-payout-attributions --apply')->assertSuccessful();
        $first = TeacherPayoutAttributionSuggestion::count();

        $this->artisan('salary:detect-payout-attributions --apply')->assertSuccessful();
        $this->assertSame($first, TeacherPayoutAttributionSuggestion::count(), 'повторный прогон не плодит дублей');
    }

    /** @test */
    public function a_confirmed_attribution_enters_paid_out_and_clears_the_preliminary_mark(): void
    {
        $this->seedExpenses();
        $this->artisan('salary:link-teacher-users --apply')->assertSuccessful();
        $this->artisan('salary:detect-payout-attributions --apply')->assertSuccessful();

        $courses = Course::inFamily(self::FAMILY)->get();
        $before = app(TeacherPayoutReconciliation::class)->forFamily($courses);
        $this->assertFalse($before['attribution_confirmed']);
        // Прямой платёж уже в «выплачено», три предложения — ещё нет.
        $this->assertSame(50000.0, $before['paid_out']);
        $this->assertCount(3, $before['pending_candidates']);

        TeacherPayoutAttributionSuggestion::query()->update([
            'status' => TeacherPayoutAttributionSuggestion::STATUS_CONFIRMED,
            'resolved_at' => now(),
        ]);

        $after = app(TeacherPayoutReconciliation::class)->forFamily($courses);
        $this->assertSame(152950.0, $after['paid_out'], '50 000 + 47 100 + 20 220 + 35 630');
        $this->assertSame([], $after['pending_candidates']);
        $this->assertTrue($after['attribution_confirmed'], 'слово «предварительно» уходит само');
    }

    /**
     * Тот самый риск задвоения #13573: платёж, УЖЕ учтённый напрямую, получает
     * ещё и подтверждённое предложение. Дедупликация идёт по `payment_id`, а не
     * по сумме, — иначе «выплачено» вырастет на 50 000 ₽ из воздуха.
     *
     * @test
     */
    public function a_directly_counted_payment_is_never_counted_twice(): void
    {
        $this->seedExpenses();
        $this->artisan('salary:link-teacher-users --apply')->assertSuccessful();

        $direct = Payment::where('user_id', $this->teacherUser->id)->where('tariff', 'Расход')->firstOrFail();

        // Предложение, заведённое ДО связки (или руками) и подтверждённое.
        TeacherPayoutAttributionSuggestion::create([
            'payment_id' => $direct->id,
            'teacher_id' => $this->teacher->id,
            'course_id' => $direct->course_id,
            'course_family' => self::FAMILY,
            'amount' => 50000,
            'paid_on' => '2026-06-02',
            'confidence' => 0.9,
            'reason' => 'заведено до связки пользователя с преподавателем',
            'status' => TeacherPayoutAttributionSuggestion::STATUS_CONFIRMED,
            'resolved_at' => now(),
        ]);

        $salary = app(TeacherPayoutReconciliation::class)->forFamily(Course::inFamily(self::FAMILY)->get());

        $this->assertSame(50000.0, $salary['paid_out'], 'платёж посчитан один раз, а не дважды');
        $this->assertCount(
            1,
            array_filter($salary['paid_out_lines'], fn (array $l): bool => $l['payment_id'] === $direct->id),
            'в разбивке «выплачено» платёж встречается ровно одной строкой',
        );
    }

    /** @test */
    public function a_rejected_attribution_leaves_the_queue_without_entering_paid_out(): void
    {
        $this->seedExpenses();
        $this->artisan('salary:detect-payout-attributions --apply')->assertSuccessful();

        TeacherPayoutAttributionSuggestion::query()->update([
            'status' => TeacherPayoutAttributionSuggestion::STATUS_REJECTED,
            'resolved_at' => now(),
        ]);

        $salary = app(TeacherPayoutReconciliation::class)->forFamily(Course::inFamily(self::FAMILY)->get());

        $this->assertSame(0.0, $salary['paid_out'], 'отклонённое — это аренда, а не выплата');
        $this->assertSame([], $salary['pending_candidates']);
        $this->assertTrue($salary['attribution_confirmed'], 'вопрос закрыт человеком — «предварительно» уходит');
    }

    /**
     * Приёмочный инвариант H3084: денежные таблицы неприкосновенны.
     *
     * @test
     */
    public function no_wave_two_command_touches_teacher_payouts_or_payments(): void
    {
        $this->seedExpenses();
        TeacherPayout::create([
            'teacher_id' => $this->teacher->id,
            'course_id' => $this->live1->id,
            'amount' => 1000,
            'paid_at' => now(),
        ]);

        Lesson::factory()->create(['course_id' => $this->live2->id, 'block_number' => 1, 'lesson_date' => '2026-03-25']);
        Lesson::factory()->create(['course_id' => $this->live2->id, 'block_number' => 2, 'lesson_date' => '2026-04-29']);

        $before = $this->moneyFingerprint();

        foreach ([
            'salary:repair-recording-courses',
            'salary:link-teacher-users',
            'courses:backfill-block-dates',
            'salary:detect-payout-attributions',
        ] as $command) {
            $this->artisan($command)->assertSuccessful();
            $this->artisan($command.' --apply')->assertSuccessful();
        }

        // Подтверждение разметки — тоже не запись в денежные таблицы.
        TeacherPayoutAttributionSuggestion::query()->update([
            'status' => TeacherPayoutAttributionSuggestion::STATUS_CONFIRMED,
            'resolved_at' => now(),
        ]);
        app(TeacherPayoutReconciliation::class)->forFamily(Course::inFamily(self::FAMILY)->get());

        $this->assertSame($before, $this->moneyFingerprint(), 'ни строк, ни сумм в teacher_payouts / payments не изменилось');
        $this->assertSame(0, DB::table('teacher_payouts')->where('created_at', '>', now()->subMinute())->where('amount', '!=', 1000)->count());
    }

    /**
     * Очередь подтверждения стоит на том же гейте, что «Потоки курса», с
     * которых на неё ведёт ссылка. Разъедься эти два гейта — админ открывал бы
     * экран с жёлтой плашкой «подтвердите разметку» и упирался в 403 по ссылке
     * из неё. MG 18-08-2026: администратор всегда видит всё.
     *
     * @test
     */
    public function the_confirmation_queue_stands_on_the_same_gate_as_the_screen_that_links_to_it(): void
    {
        $cases = [
            Roles::ACCOUNTANT => true,
            Roles::SUPER_ADMIN => true,
            Roles::ADMIN => true,
            Roles::MANAGER => false,
            Roles::TEACHER => false,
        ];

        foreach ($cases as $role => $allowed) {
            $this->actingAs(User::factory()->create(['role' => $role]));

            $this->assertSame(
                $allowed,
                TeacherPayoutAttributionSuggestionResource::canViewAny(),
                "очередь подтверждения для роли {$role}",
            );
            $this->assertSame(
                CourseStreamComparison::canAccess(),
                TeacherPayoutAttributionSuggestionResource::canViewAny(),
                "гейты «Потоки курса» и очереди разошлись на роли {$role}",
            );
            $this->assertSame(
                $allowed,
                PayoutAttributionGuide::canAccess(),
                "инструкция в кабинете для роли {$role}",
            );
        }
    }

    /**
     * Инструкция живёт В КАБИНЕТЕ и питается живой очередью, а не переписанным
     * от руки списком: инструкция, разошедшаяся с экраном, хуже отсутствующей.
     * Ruling MG 18-08-2026 — рабочие указания сотруднику не выкладываются на
     * всеобщее обозрение, поэтому копии этого текста в публичном репозитории
     * быть не должно.
     *
     * @test
     */
    public function the_in_cabinet_guide_lists_exactly_what_the_queue_holds(): void
    {
        $this->seedExpenses();
        $this->artisan('salary:detect-payout-attributions --apply')->assertSuccessful();

        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));

        $rendered = $this->get(PayoutAttributionGuide::getUrl())->assertOk();

        foreach (TeacherPayoutAttributionSuggestion::pending()->get() as $row) {
            $rendered->assertSee((string) $row->payment_id);
        }

        $rendered->assertSee('не переводит никаких денег', false);
        $rendered->assertSee($this->teacher->name);
    }

    /** @test */
    public function the_public_repository_carries_no_copy_of_the_staff_instruction(): void
    {
        // Публичный репозиторий: фамилия преподавателя, номера платежей и суммы
        // в файле памятки — это и есть «всеобщее обозрение», которое запрещено.
        foreach ([
            'docs/MANUAL_ACCOUNTANT_PAYOUT_ATTRIBUTION_RU.md',
            'docs/MANUAL_ACCOUNTANT_PAYOUT_ATTRIBUTION_RU.meta.md',
            'docs/img/h3084',
        ] as $path) {
            $this->assertFileDoesNotExist(base_path($path), "«{$path}» вернулся в публичный репозиторий");
        }
    }

    /** @test */
    public function nobody_can_create_a_suggestion_by_hand(): void
    {
        // Предложение рождается только детектором: строка, заведённая руками,
        // означала бы «выплату», которой не соответствует ни один платёж.
        $this->actingAs(User::factory()->create(['role' => Roles::ACCOUNTANT]));

        $this->assertFalse(TeacherPayoutAttributionSuggestionResource::canCreate());
    }

    /** @test */
    public function the_settlement_act_carries_the_numbers_and_an_empty_top_up_decision_row(): void
    {
        $this->seedExpenses();
        $this->artisan('salary:repair-recording-courses --apply')->assertSuccessful();
        $this->artisan('salary:link-teacher-users --apply')->assertSuccessful();

        $service = app(TeacherSettlementActPdf::class);
        $data = $service->data(self::FAMILY);

        $this->assertNotNull($data);
        $this->assertSame('Ворошилов Максим Анатольевич', $data['teacherName']);
        // 30 % от (100 000 + 50 000 + 51 000).
        $this->assertSame(60300.0, $data['accrued']);
        $this->assertSame(50000.0, $data['paidOut']);
        $this->assertSame(10300.0, $data['remainder']);
        $this->assertFalse($data['attributionConfirmed'], 'три «Расхода» ещё не размечены');

        // Запись помечена отдельно — её деньги не должны выглядеть как живой поток.
        $recordingRow = collect($data['streams'])->firstWhere('is_recording', true);
        $this->assertNotNull($recordingRow);
        $this->assertSame(15300.0, $recordingRow['accrued']);

        $html = view('pdf.teacher-settlement-act', $data)->render();
        $this->assertStringContainsString('решение о доплате сверх остатка', mb_strtolower($html));
        $this->assertStringContainsString('class="blank"', $html, 'строка решения оставлена пустой');

        $pdf = $service->make(self::FAMILY);
        $this->assertNotNull($pdf);
        $this->assertStringStartsWith('%PDF', $pdf->output());
    }
}
