<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\FinanceSnapshot;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\PayoutRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H4520 — payout:run: якоря ≤1 % + ловушки (pass-through, пустой ends_at,
 * предоплата #14142, read-only отпечатки).
 *
 * Якоря (handoff H4520, lock before work):
 *  - Лейтан 26-08 = 29 145,60 ₽ (52 800 × 92 % × 60 %) — docs/PAYROLL_LEITAN_MONTHLY_RECON_26-08-2026.md §3б;
 *  - Костина 25-08 = 28 704 ₽ ((54 000+42 000+8 000) × 92 % × 30 %) — PAYOUT_VERIFICATION_KOSTINA_BLOCK3_28-08-2026.md;
 *  - Xoom 24-07 = 632,49 € (справочно; ₽-строка ведомости 54 794,40 ₽, fx восстановлен из неё: 54 794,40/632,49).
 */
class PayoutRunTest extends TestCase
{
    use RefreshDatabase;

    private PayoutRunService $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = app(PayoutRunService::class);
    }

    private function pay(Course $course, array $attrs, ?string $at = null): Payment
    {
        return Payment::withoutEvents(function () use ($course, $attrs, $at) {
            $payment = Payment::create(array_merge([
                'course_id' => $course->id,
                'status' => 'paid',
                'is_conditional' => false,
                'received_account' => Payment::RECEIVED_SCHOOL,
            ], $attrs));
            if ($at !== null) {
                $payment->created_at = Carbon::parse($at);
                $payment->save();
            }

            return $payment;
        });
    }

    private function percentCourse(Teacher $teacher, string $title, float $pct): Course
    {
        return Course::factory()->create([
            'teacher_id' => $teacher->id,
            'title' => $title,
            'salary_type' => 'percent',
            'salary_value' => $pct,
        ]);
    }

    private function block(Course $course, int $number, ?string $starts = null, ?string $ends = null): CourseBlock
    {
        return CourseBlock::create([
            'course_id' => $course->id,
            'number' => $number,
            'is_active' => true,
            'starts_at' => $starts,
            'ends_at' => $ends,
        ]);
    }

    private function fx(float $rate, string $enteredAt): void
    {
        FinanceSnapshot::create([
            'type' => FinanceSnapshot::TYPE_FX_EUR_RUB,
            'amount_minor' => FinanceSnapshot::toMinor($rate),
            'currency' => 'EUR',
            'entered_at' => Carbon::parse($enteredAt),
            'note' => 'тестовый снимок курса ЦБ',
        ]);
    }

    /** Лейтан 26-08: 52 800 × 92 % × 60 % = 29 145,60 ₽ (якорь, ≤1 %). */
    public function test_leitan_anchor_2608_reproduces_29145_60(): void
    {
        $leytan = Teacher::create(['name' => 'Лейтан Эдгар', 'payout_currency' => 'EUR']);
        $syntax = $this->percentCourse($leytan, 'Синтаксис', 60);
        $vash = $this->percentCourse($leytan, 'Васиштха', 60);

        // История блоков 1–36 Синтаксиса (все завершены в июне) — под предоплату #14142.
        for ($n = 1; $n <= 36; $n++) {
            $this->block($syntax, $n, '2026-05-01', '2026-06-30');
        }
        $this->block($syntax, 64, '2026-07-01', '2026-07-20');
        $this->block($syntax, 65, '2026-07-21', '2026-08-25');
        $this->block($syntax, 66, '2026-08-26', '2026-09-20'); // будущее — не входит
        $this->block($vash, 10, '2026-07-30', '2026-08-13');

        // 65-й блок: 5 платных из 7 (Соловьева и Магдалинский не оплатили).
        $group = Group::create(['name' => 'Синтаксис 65']);
        $syntax->groups()->attach($group->id); // pivot course_group — для должников
        for ($i = 1; $i <= 5; $i++) {
            $u = User::factory()->create();
            $group->users()->attach($u->id);
            $this->pay($syntax, ['user_id' => $u->id, 'amount' => 4800, 'tariff' => 'block_65', 'start_block' => 65, 'end_block' => 65], '2026-08-01');
        }
        foreach (['Соловьева', 'Магдалинский'] as $i => $name) {
            $u = User::factory()->create(['name' => $name]);
            $group->users()->attach($u->id);
        }

        // 10-й Васиштхи: 5 платных, два платежа — до отсечки 24.07 (любыми датами прихода).
        for ($i = 1; $i <= 3; $i++) {
            $u = User::factory()->create();
            $this->pay($vash, ['user_id' => $u->id, 'amount' => 4800, 'tariff' => 'block_10', 'start_block' => 10, 'end_block' => 10], '2026-08-02');
        }
        $this->pay($vash, ['user_id' => User::factory()->create()->id, 'amount' => 4800, 'tariff' => 'block_10', 'start_block' => 10, 'end_block' => 10], '2026-07-18');
        $this->pay($vash, ['user_id' => User::factory()->create()->id, 'amount' => 4800, 'tariff' => 'block_10', 'start_block' => 10, 'end_block' => 10], '2026-07-21');

        // 64-й блок завершён 20.07 (до отсечки): один поздний платёж 01.08 → перерасчёт.
        $this->pay($syntax, ['user_id' => User::factory()->create()->id, 'amount' => 4800, 'tariff' => 'block_64', 'start_block' => 64, 'end_block' => 64], '2026-08-01');

        // Предоплата #14142 (Соловьева, 144 000, блоки 1–36, задним числом).
        $solovieva = User::factory()->create(['name' => 'Соловьева Ольга']);
        $this->pay($syntax, ['user_id' => $solovieva->id, 'amount' => 144000, 'tariff' => 'full', 'start_block' => 1, 'end_block' => 36], '2026-08-10');

        $this->fx(98.5182, '2026-08-26');

        $row = $this->runner->runForTeacher($leytan, Carbon::parse('2026-08-26'), Carbon::parse('2026-07-24'));

        $this->assertEqualsWithDelta(29145.60, $row['accrued_formula_rub'], 291.45); // якорь ≤1 %
        $this->assertEqualsWithDelta(296.0, $row['payable_eur'], 2.96); // €296 по ЦБ 26-08 ≤1 %
        $this->assertSame(52800.0, $row['base_total_rub']);
        // Фикс-регистр (Новикова 1920 активен по конфигу) показан, но НЕ вычтен:
        $this->assertSame(1920.0, $row['registry_deductions_rub']);
        $this->assertSame(29145.60, $row['payable_rub']);

        // Перерасчёт прошлого блока вошёл, предоплата — нет.
        $this->assertCount(1, $row['prior_blocks']);
        $this->assertSame(4800.0, $row['prior_rub']);
        $this->assertCount(1, $row['prepayment_rent']);
        $rent = $row['prepayment_rent'][0];
        $this->assertSame(36, $rent['covered_blocks']);
        $this->assertEqualsWithDelta(2208.0, $rent['teacher_rent_per_month_rub'], 0.01); // 4000 × 92 % × 60 %
        $this->assertEqualsWithDelta(79488.0, $rent['teacher_rent_total_rub'], 0.5);
    }

    /** Костина 25-08: 104 000 × 92 % × 30 % = 28 704 ₽ (якорь, ≤1 %). */
    public function test_kostina_anchor_2508_reproduces_28704(): void
    {
        $kostina = Teacher::create(['name' => 'Костина Екатерина']);
        $g1 = $this->percentCourse($kostina, 'Гр. 1, среда 8:00', 30);
        $g2 = $this->percentCourse($kostina, 'Гр. 2, суббота 13:00', 30);
        $int = $this->percentCourse($kostina, 'Летний интенсив', 30);

        $this->block($g1, 3, '2026-08-01', '2026-08-22');
        $this->block($g2, 3, '2026-08-01', '2026-08-22');
        $this->block($int, 1, '2026-07-11', '2026-08-01');

        for ($i = 1; $i <= 9; $i++) {
            $this->pay($g1, ['user_id' => User::factory()->create()->id, 'amount' => 6000, 'tariff' => 'block_3', 'start_block' => 3, 'end_block' => 3], '2026-07-20');
        }
        for ($i = 1; $i <= 7; $i++) {
            $this->pay($g2, ['user_id' => User::factory()->create()->id, 'amount' => 6000, 'tariff' => 'block_3', 'start_block' => 3, 'end_block' => 3], '2026-07-21');
        }
        $this->pay($int, ['user_id' => User::factory()->create()->id, 'amount' => 8000, 'tariff' => 'block_1', 'start_block' => 1, 'end_block' => 1], '2026-08-01');

        $row = $this->runner->runForTeacher($kostina, Carbon::parse('2026-08-25'), Carbon::parse('2026-07-01'));

        $this->assertEqualsWithDelta(28704.0, $row['accrued_formula_rub'], 287.0); // якорь ≤1 %
        $this->assertSame(104000.0, $row['base_total_rub']);
        $this->assertSame(28704.0, $row['payable_rub']); // фикс-регистр Костиной пуст
        $this->assertSame(6.0, $row['npd_pct']); // показывается по конфигу, отдельный шаг
    }

    /** Xoom 24-07 = 632,49 € (справочно): ₽-строка ведомости 54 794,40, fx восстановлен из неё. */
    public function test_xoom_anchor_2407_reproduces_632_49_eur(): void
    {
        $leytan = Teacher::create(['name' => 'Лейтан Эдгар']);
        $syntax = $this->percentCourse($leytan, 'Синтаксис', 60);
        $vash = $this->percentCourse($leytan, 'Васиштха', 60);
        // Xoom-якорь: 64-й блок завершён к 20.07 (в окне), 65-й начат позже.
        $this->block($syntax, 63, '2026-06-20', '2026-07-15');
        $this->block($syntax, 64, '2026-07-16', '2026-07-20');
        $this->block($vash, 9, '2026-06-25', '2026-07-20');

        for ($i = 1; $i <= 10; $i++) {
            $this->pay($syntax, ['user_id' => User::factory()->create()->id, 'amount' => 4800, 'tariff' => 'block_63', 'start_block' => 63, 'end_block' => 63], '2026-07-01');
        }
        for ($i = 1; $i <= 4; $i++) {
            $this->pay($syntax, ['user_id' => User::factory()->create()->id, 'amount' => 4800, 'tariff' => 'block_64', 'start_block' => 64, 'end_block' => 64], '2026-07-02');
        }
        for ($i = 1; $i <= 8; $i++) {
            $this->pay($vash, ['user_id' => User::factory()->create()->id, 'amount' => 4008, 'tariff' => 'block_9', 'start_block' => 9, 'end_block' => 9], '2026-07-03');
        }

        // fx восстановлен из ведомости 25-08: 54 794,40 ₽ / 632,49 € (справочный якорь).
        $this->fx(round(54794.40 / 632.49, 4), '2026-07-24');

        $row = $this->runner->runForTeacher($leytan, Carbon::parse('2026-07-24'), Carbon::parse('2026-06-24'));

        $this->assertEqualsWithDelta(54794.40, $row['accrued_formula_rub'], 547.94); // ₽-строка ведомости ≤1 %
        $this->assertEqualsWithDelta(632.49, $row['payable_eur'], 6.32); // якорь ≤1 %
    }

    /** Ловушка MG 10-09: pass-through — НЕ вычет, отдельный список (приёмка №3). */
    public function test_pass_through_receipts_listed_separately_and_not_deducted(): void
    {
        $leytan = Teacher::create(['name' => 'Лейтан Эдгар']);
        $other = Teacher::create(['name' => 'Другой Препод']);
        $syntax = $this->percentCourse($leytan, 'Синтаксис', 60);
        $foreign = $this->percentCourse($other, 'Чужой курс', 30);
        $this->block($syntax, 65, '2026-07-21', '2026-08-25');

        $student = User::factory()->create();
        $this->pay($syntax, ['user_id' => $student->id, 'amount' => 24000, 'tariff' => 'block_65', 'start_block' => 65, 'end_block' => 65], '2026-08-01');

        // Ученик чужого курса заплатил через счёт Лейтана.
        $this->pay($foreign, [
            'user_id' => User::factory()->create()->id,
            'amount' => 20000,
            'tariff' => 'block_1', 'start_block' => 1, 'end_block' => 1,
            'received_account' => Payment::RECEIVED_TEACHER,
            'received_by_teacher_id' => $leytan->id,
            'foreign_amount' => 200.0,
            'foreign_currency' => 'EUR',
        ], '2026-08-05');

        $row = $this->runner->runForTeacher($leytan, Carbon::parse('2026-08-26'), Carbon::parse('2026-07-24'));

        // База — только свой блок; посреднические НЕ вычтены.
        $this->assertSame(24000.0, $row['base_total_rub']);
        $this->assertSame(13248.0, $row['payable_rub']); // 24000×0,92×0,6 = 13248, вычетов нет
        $this->assertCount(1, $row['pass_through']['lines']);
        $this->assertSame(20000.0, $row['pass_through']['total_rub']);
        $this->assertSame('Чужой курс', $row['pass_through']['lines'][0]['course_title']);
    }

    /** Прямая оплата СВОЕГО курса на личный счёт — вычет по номиналу (EUR-линия). */
    public function test_direct_own_course_receipt_is_deducted_nominal(): void
    {
        $leytan = Teacher::create(['name' => 'Лейтан Эдгар', 'payout_currency' => 'EUR']);
        $syntax = $this->percentCourse($leytan, 'Синтаксис', 60);
        $this->block($syntax, 65, '2026-07-21', '2026-08-25');

        $this->pay($syntax, ['user_id' => User::factory()->create()->id, 'amount' => 24000, 'tariff' => 'block_65', 'start_block' => 65, 'end_block' => 65], '2026-08-01');
        // Назарова заплатила Лейтану напрямую (70 EUR), курс — его собственный.
        $this->pay($syntax, [
            'user_id' => User::factory()->create()->id,
            'amount' => 7000,
            'tariff' => 'block_65', 'start_block' => 65, 'end_block' => 65,
            'received_account' => Payment::RECEIVED_TEACHER,
            'received_by_teacher_id' => $leytan->id,
            'foreign_amount' => 70.0,
            'foreign_currency' => 'EUR',
        ], '2026-08-02');

        $this->fx(98.0, '2026-08-26');
        $row = $this->runner->runForTeacher($leytan, Carbon::parse('2026-08-26'), Carbon::parse('2026-07-24'));

        // Прямая оплата — НЕ выручка блока (schoolReceived фильтр), это вычет по номиналу.
        $this->assertSame(24000.0, $row['base_total_rub']);
        $this->assertSame(70.0, $row['direct_receipts']['total']);
        // ₽-сторона не тронута (вычет в валюте выплаты, EUR):
        $this->assertSame(13248.0, $row['payable_rub']); // 24000×0,92×0,6
        // €-сторона: (24000×0,92×0,6)/98 − 70 = 135,18 − 70 = 65,18
        $this->assertEqualsWithDelta(65.18, $row['payable_eur'], 0.02);
    }

    /** Приёмка №4: пустой ends_at → фолбэк на занятия с ⚠; совсем без дат → видимый ⚠, не ноль. */
    public function test_empty_ends_at_falls_back_to_lessons_with_visible_warning(): void
    {
        $leytan = Teacher::create(['name' => 'Лейтан Эдгар']);
        $syntax = $this->percentCourse($leytan, 'Синтаксис', 60);
        // Блок 65: ends_at пуст, есть занятия (фолбэк 12.08).
        $this->block($syntax, 65, '2026-07-21', null);
        foreach (['2026-08-05', '2026-08-12'] as $lessonDate) {
            DB::table('lessons')->insert([
                'course_id' => (string) $syntax->id,
                'title' => 'Урок',
                'lesson_date' => $lessonDate,
                'block_number' => 65,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        // Блок 66: ends_at пуст, занятий нет — неопределимо.
        $this->block($syntax, 66, null, null);

        $this->pay($syntax, ['user_id' => User::factory()->create()->id, 'amount' => 4800, 'tariff' => 'block_65', 'start_block' => 65, 'end_block' => 65], '2026-08-06');

        $row = $this->runner->runForTeacher($leytan, Carbon::parse('2026-08-26'), Carbon::parse('2026-07-24'));

        $this->assertSame(4800.0, $row['base_total_rub']); // блок 65 вошёл по занятиям
        $this->assertTrue($row['blocks'][0]['ends_at_estimated']);
        $this->assertStringContainsString('оценено по занятиям', implode(' ', $row['warnings']));
        $this->assertStringContainsString('неопределимо', implode(' ', $row['warnings'])); // блок 66 — видимый ⚠
    }

    /** Приёмка №5: предоплата #14142 отсутствует в «к выплате», рента помесячно. */
    public function test_retroactive_prepayment_is_excluded_and_rented_monthly(): void
    {
        $leytan = Teacher::create(['name' => 'Лейтан Эдгар']);
        $syntax = $this->percentCourse($leytan, 'Синтаксис', 60);
        for ($n = 1; $n <= 36; $n++) {
            $this->block($syntax, $n, '2026-05-01', '2026-06-30');
        }
        $this->block($syntax, 65, '2026-07-21', '2026-08-25');

        $this->pay($syntax, ['user_id' => User::factory()->create()->id, 'amount' => 4800, 'tariff' => 'block_65', 'start_block' => 65, 'end_block' => 65], '2026-08-01');
        $this->pay($syntax, ['user_id' => User::factory()->create(['name' => 'Соловьева Ольга'])->id, 'amount' => 144000, 'tariff' => 'full', 'start_block' => 1, 'end_block' => 36], '2026-08-10');

        $row = $this->runner->runForTeacher($leytan, Carbon::parse('2026-08-26'), Carbon::parse('2026-07-24'));

        $this->assertSame(4800.0, $row['base_total_rub']); // 144 000 не в базе окна
        $this->assertSame(2649.60, round($row['payable_rub'], 2)); // 4800×0,92×0,6
        $this->assertCount(1, $row['prepayment_rent']);
        $this->assertEqualsWithDelta(2208.0, $row['prepayment_rent'][0]['teacher_rent_per_month_rub'], 0.01);
    }

    /** Авто-отсечка: последняя выплата; без истории — явная ошибка, не ноль. */
    public function test_since_defaults_to_last_payout_and_errors_without_history(): void
    {
        $leytan = Teacher::create(['name' => 'Лейтан Эдгар']);
        $syntax = $this->percentCourse($leytan, 'Синтаксис', 60);
        $this->block($syntax, 65, '2026-07-21', '2026-08-25');
        $this->pay($syntax, ['user_id' => User::factory()->create()->id, 'amount' => 4800, 'tariff' => 'block_65', 'start_block' => 65, 'end_block' => 65], '2026-08-01');

        TeacherPayout::create([
            'teacher_id' => $leytan->id,
            'amount' => 63249,
            'type' => TeacherPayout::TYPE_REGULAR,
            'paid_at' => Carbon::parse('2026-07-24'),
        ]);

        $row = $this->runner->runForTeacher($leytan, Carbon::parse('2026-08-26'), null);
        $this->assertSame('2026-07-24', $row['window']['since']);

        // Препод без истории и без --since — явная ошибка.
        $novice = Teacher::create(['name' => 'Новичок Тест']);
        $err = $this->runner->runForTeacher($novice, Carbon::parse('2026-08-26'), null);
        $this->assertArrayHasKey('error', $err);
        $this->assertStringContainsString('--since', $err['error']);
    }

    /** Команда: read-only отпечатки целы, json содержит якорь, --teacher без --all. */
    public function test_command_is_read_only_and_outputs_json_anchor(): void
    {
        $leytan = Teacher::create(['name' => 'Лейтан Эдгар']);
        $syntax = $this->percentCourse($leytan, 'Синтаксис', 60);
        $this->block($syntax, 65, '2026-07-21', '2026-08-25');
        for ($i = 1; $i <= 5; $i++) {
            $this->pay($syntax, ['user_id' => User::factory()->create()->id, 'amount' => 4800, 'tariff' => 'block_65', 'start_block' => 65, 'end_block' => 65], '2026-08-01');
        }
        $this->fx(98.5182, '2026-08-26');

        $before = [
            Payment::query()->count(),
            TeacherPayout::query()->count(),
            User::query()->count(),
            FinanceSnapshot::query()->count(),
        ];

        $this->artisan('payout:run', [
            '--teacher' => (string) $leytan->id,
            '--on' => '2026-08-26',
            '--since' => '2026-07-24',
            '--format' => 'json',
        ])->assertExitCode(0);

        $after = [
            Payment::query()->count(),
            TeacherPayout::query()->count(),
            User::query()->count(),
            FinanceSnapshot::query()->count(),
        ];
        $this->assertSame($before, $after, 'READ-ONLY: отпечатки money-таблиц изменились');

        // Марина-формат и ведомость тоже доступны.
        $this->artisan('payout:run', [
            '--teacher' => (string) $leytan->id,
            '--on' => '2026-08-26',
            '--since' => '2026-07-24',
            '--format' => 'marina,report',
        ])->assertExitCode(0);
    }

    /** Незакрытый аванс вычитается из «к выплате». */
    public function test_outstanding_advance_is_deducted(): void
    {
        $leytan = Teacher::create(['name' => 'Лейтан Эдгар']);
        $syntax = $this->percentCourse($leytan, 'Синтаксис', 60);
        $this->block($syntax, 65, '2026-07-21', '2026-08-25');
        $this->pay($syntax, ['user_id' => User::factory()->create()->id, 'amount' => 4800, 'tariff' => 'block_65', 'start_block' => 65, 'end_block' => 65], '2026-08-01');

        TeacherPayout::create([
            'teacher_id' => $leytan->id,
            'amount' => 5000,
            'type' => TeacherPayout::TYPE_ADVANCE,
            'paid_at' => Carbon::parse('2026-07-01'),
            'settled_amount' => 2000,
        ]);

        $row = $this->runner->runForTeacher($leytan, Carbon::parse('2026-08-26'), Carbon::parse('2026-07-24'));

        $this->assertSame(3000.0, $row['advances_total_rub']);
        // 4800×0,92×0,6 = 2649,60 − 3000 → отрицательный остаток виден (не прячется)
        $this->assertEqualsWithDelta(2649.60 - 3000.0, $row['payable_rub'], 0.02);
    }

    /** Должники по курсам: «5 платных из 7, не оплатили — X, Y». */
    public function test_debtors_list_group_members_without_block_payment(): void
    {
        $leytan = Teacher::create(['name' => 'Лейтан Эдгар']);
        $syntax = $this->percentCourse($leytan, 'Синтаксис', 60);
        $this->block($syntax, 65, '2026-07-21', '2026-08-25');

        $group = Group::create(['name' => 'Синтаксис 65']);
        $syntax->groups()->attach($group->id);
        for ($i = 1; $i <= 5; $i++) {
            $u = User::factory()->create();
            $group->users()->attach($u->id);
            $this->pay($syntax, ['user_id' => $u->id, 'amount' => 4800, 'tariff' => 'block_65', 'start_block' => 65, 'end_block' => 65], '2026-08-01');
        }
        $group->users()->attach(User::factory()->create(['name' => 'Соловьева Ольга'])->id);
        $group->users()->attach(User::factory()->create(['name' => 'Магдалинский Тест'])->id);

        $row = $this->runner->runForTeacher($leytan, Carbon::parse('2026-08-26'), Carbon::parse('2026-07-24'));
        $debtors = $this->runner->debtorsFor($leytan, $row['blocks']);

        $this->assertCount(1, $debtors);
        $b = $debtors[0]['blocks'][0];
        $this->assertSame(7, $b['group_size']);
        $this->assertSame(5, $b['paid']);
        $this->assertEqualsCanonicalizing(['Соловьева Ольга', 'Магдалинский Тест'], $b['non_payers']);
    }

    /** H4629 (1): marina — формула 1-в-1 «(база × 92%) × ставка = …», перерасчёт отдельной строкой, «К оплате». */
    public function test_marina_format_reproduces_chat_formula_style(): void
    {
        $kostina = Teacher::create(['name' => 'Костина Екатерина']);
        $hindi = $this->percentCourse($kostina, 'Хинди', 30);
        $this->block($hindi, 1, '2026-06-20', '2026-07-10');
        $this->block($hindi, 2, '2026-07-25', '2026-08-22');
        for ($i = 1; $i <= 8; $i++) {
            $this->pay($hindi, ['user_id' => User::factory()->create()->id, 'amount' => 6000, 'tariff' => 'block_2', 'start_block' => 2, 'end_block' => 2], '2026-08-01');
        }
        // Поздняя оплата завершённого ДО отсечки блока 1 → перерасчёт.
        $this->pay($hindi, ['user_id' => User::factory()->create()->id, 'amount' => 6000, 'tariff' => 'block_1', 'start_block' => 1, 'end_block' => 1], '2026-08-05');

        // Костина — EUR-получатель по конфигу: «итого ... = N €» и «К оплате: X € / Y руб.».
        $this->fx(98.5182, '2026-08-26');

        // Artisan::call вместо $this->artisan(): PendingCommand-мок (expectsOutputToContain)
        // каршится на >1 ожидании — Mockery-ожидание с atLeast()->times(0) не исчерпывается
        // и перехватывает все doWrite, остальные substring-ожидания никогда не срабатывают.
        $code = Artisan::call('payout:run', [
            '--teacher' => (string) $kostina->id,
            '--on' => '2026-08-26',
            '--since' => '2026-07-24',
            '--format' => 'marina',
        ]);
        $this->assertSame(0, $code);
        $out = Artisan::output();
        foreach ([
            'Костина Екатерина 2-й блок Хинди по 26.08.2026:',
            '🔹️ Хинди:',
            '2-й блок: платных 8: (48000 × 92%) × 30% = 44160 × 30% = 13248 р.',
            'перерасчёт за 1-й блок: платных 1: (6000 × 92%) × 30% = 5520 × 30% = 1656 р.',
            'итого: 13248 + 1656 = 14904 р. = 151,28 €',
            'Итого начислено: 14 904,00 руб. или 151,28 €',
            'К оплате: 151,28 € / 14 904,00 руб.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $out);
        }
    }

    /** H4629 (1): marina для EUR-получателя — «= N €» на строке, «Всего», «К оплате: X € / Y руб.». */
    public function test_marina_eur_lane_appends_euro_and_vsego(): void
    {
        $leytan = Teacher::create(['name' => 'Лейтан Эдгар', 'payout_currency' => 'EUR']);
        $syntax = $this->percentCourse($leytan, 'Синтаксис', 60);
        $vash = $this->percentCourse($leytan, 'Васиштха', 60);
        $this->block($syntax, 64, '2026-07-16', '2026-07-28');
        $this->block($vash, 10, '2026-07-25', '2026-08-13');
        // 24000 × 92% × 60% = 13248; 10200 × 92% × 60% = 5630,40.
        $this->pay($syntax, ['user_id' => User::factory()->create()->id, 'amount' => 24000, 'tariff' => 'block_64', 'start_block' => 64, 'end_block' => 64], '2026-07-28');
        $this->pay($vash, ['user_id' => User::factory()->create()->id, 'amount' => 10200, 'tariff' => 'block_10', 'start_block' => 10, 'end_block' => 10], '2026-08-01');

        $this->fx(98.5182, '2026-08-26');

        $code = Artisan::call('payout:run', [
            '--teacher' => (string) $leytan->id,
            '--on' => '2026-08-26',
            '--since' => '2026-07-24',
            '--format' => 'marina',
        ]);
        $this->assertSame(0, $code);
        $out = Artisan::output();
        foreach ([
            '64-й блок Синтаксис',
            '(24000 × 92%) × 60% = 22080 × 60% = 13248 р. = 134,47 €',
            '(10200 × 92%) × 60% = 9384 × 60% = 5630,40 р. = 57,15 €',
            'Всего: 134,47 + 57,15 = 191,62 евро',
            'К оплате: 191,62 € / 18 878,40 руб.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $out);
        }
    }

    /** H4629 (2): detailed — перерасчёты поимённо + должники, очищенные от вступивших позже блока. */
    public function test_detailed_lists_named_recalcs_and_cleans_late_joined_debtors(): void
    {
        $leytan = Teacher::create(['name' => 'Лейтан Эдгар']);
        $syntax = $this->percentCourse($leytan, 'Синтаксис', 60);
        $this->block($syntax, 64, '2026-07-01', '2026-07-20');
        $this->block($syntax, 65, '2026-07-21', '2026-08-25');

        $group = Group::create(['name' => 'Синтаксис 65']);
        $syntax->groups()->attach($group->id);
        for ($i = 1; $i <= 5; $i++) {
            $u = User::factory()->create();
            $group->users()->attach($u->id);
            $this->pay($syntax, ['user_id' => $u->id, 'amount' => 4800, 'tariff' => 'block_65', 'start_block' => 65, 'end_block' => 65], '2026-08-01');
        }
        // Член группы ДО блока, не оплатил — остаётся должником.
        $solovieva = User::factory()->create(['name' => 'Соловьева Ольга']);
        $group->users()->attach($solovieva->id, ['created_at' => '2026-08-01 10:00:00', 'updated_at' => '2026-08-01 10:00:00']);
        // Вступил в группу ПОСЛЕ завершения блока — из должников чистится.
        $late = User::factory()->create(['name' => 'Поздний Тест']);
        $group->users()->attach($late->id, ['created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-01 10:00:00']);
        // Поздняя оплата блока 64 (завершён до отсечки) → поимённый перерасчёт.
        $latePayer = $this->pay($syntax, ['user_id' => User::factory()->create(['name' => 'Поздняя Оплата'])->id, 'amount' => 4800, 'tariff' => 'block_64', 'start_block' => 64, 'end_block' => 64], '2026-08-05');

        $code = Artisan::call('payout:run', [
            '--teacher' => (string) $leytan->id,
            '--on' => '2026-08-26',
            '--since' => '2026-07-24',
            '--format' => 'detailed',
        ]);
        $this->assertSame(0, $code);
        $out = Artisan::output();
        foreach ([
            '### Перерасчёты старых блоков (поимённо)',
            sprintf('| Поздняя Оплата | Синтаксис | 64 | 4 800,00 | 05.08.2026 | %d |', $latePayer->id),
            '### Должники по завершённым блокам',
            '— не оплатили: Соловьева Ольга',
            'не считаем должниками (вступили в группу после завершения блока): Поздний Тест',
        ] as $expected) {
            $this->assertStringContainsString($expected, $out);
        }
    }

    /** H4629 (3): payments — лента по датам: дата, ученик, курс, блок, доля, метод. */
    public function test_payments_feed_lists_shares_by_date_with_method(): void
    {
        $leytan = Teacher::create(['name' => 'Лейтан Эдгар']);
        $syntax = $this->percentCourse($leytan, 'Синтаксис', 60);
        $this->block($syntax, 65, '2026-07-21', '2026-08-25');

        $payer = $this->pay($syntax, [
            'user_id' => User::factory()->create(['name' => 'Плательщик СБП'])->id,
            'amount' => 4800, 'tariff' => 'block_65', 'start_block' => 65, 'end_block' => 65,
            'payment_method' => 'sbp',
        ], '2026-08-01');
        $cashPayer = $this->pay($syntax, ['user_id' => User::factory()->create(['name' => 'Плательщик Наличные'])->id, 'amount' => 19200, 'tariff' => 'block_65', 'start_block' => 65, 'end_block' => 65, 'payment_method' => 'cash'], '2026-07-30');

        $code = Artisan::call('payout:run', [
            '--teacher' => (string) $leytan->id,
            '--on' => '2026-08-26',
            '--since' => '2026-07-24',
            '--format' => 'payments',
        ]);
        $this->assertSame(0, $code);
        $out = Artisan::output();
        foreach ([
            '# Лента платежей расчёта',
            '**30.07.2026**',
            '- Плательщик Наличные — «Синтаксис», блок 65 — доля блока #'.$cashPayer->id.' — 19 200,00 р. — Наличные',
            '- Плательщик СБП — «Синтаксис», блок 65 — доля блока #'.$payer->id.' — 4 800,00 р. — СБП',
            'Доли расчёта: 24 000,00 р.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $out);
        }
    }
}
