<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exports\CourseStreamComparisonExport;
use App\Filament\Pages\CourseBlockParticipants;
use App\Filament\Pages\CourseStreamComparison;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\User;
use App\Services\CourseStreamComparisonReport;
use App\Support\CourseFamilyMatcher;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H3083 — экран сравнения потоков курса (волна 1, только чтение).
 *
 * Фикстура повторяет форму боевой семьи «Кашмирский шиваизм»: два живых потока
 * с блоками и тарифами и один курс-запись без блоков вовсе, доступ к которому
 * выдан платежами с ключами block_N.
 */
class CourseStreamComparisonTest extends TestCase
{
    use RefreshDatabase;

    private Teacher $teacher;

    private Course $stream1;

    private Course $stream2;

    private Course $recording;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = Teacher::create(['name' => 'Преподаватель Тестовый']);

        $this->stream1 = Course::factory()->create([
            'title' => 'Тестовый курс (1 поток, 2025)',
            'course_family' => 'testovyi-kurs',
            'teacher_id' => $this->teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
            'is_active' => true,
        ]);
        $this->stream2 = Course::factory()->create([
            'title' => 'Тестовый курс (2 поток, 2026)',
            'course_family' => 'testovyi-kurs',
            'teacher_id' => $this->teacher->id,
            'salary_type' => 'percent',
            'salary_value' => 30,
            'is_active' => true,
        ]);
        // Курс-запись: ни блоков, ни тарифов, ни преподавателя — как курс 424.
        $this->recording = Course::factory()->create([
            'title' => 'Тестовый курс 2025 в записи',
            'course_family' => 'testovyi-kurs',
            'teacher_id' => null,
            'salary_type' => null,
            'is_active' => false,
        ]);

        foreach ([$this->stream1, $this->stream2] as $course) {
            foreach ([1, 2] as $n) {
                CourseBlock::create(['course_id' => $course->id, 'number' => $n, 'title' => "Блок {$n}"]);
            }
        }
    }

    private function pay(User $user, Course $course, string $tariff, float $amount): Payment
    {
        // Без событий: PaymentObserver::grantAccess иначе меняет состав групп.
        return Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => $amount,
            'status' => 'paid',
            'tariff' => $tariff,
        ]));
    }

    /** @test */
    public function streams_stand_side_by_side_with_blocks_money_and_crossover(): void
    {
        [$a, $b, $c, $d] = User::factory()->count(4)->create();

        // Поток 1: a и b купили оба блока, c — только первый.
        $this->pay($a, $this->stream1, 'block_1', 3000);
        $this->pay($a, $this->stream1, 'block_2', 3000);
        $this->pay($b, $this->stream1, 'block_1', 3000);
        $this->pay($b, $this->stream1, 'block_2', 3000);
        $this->pay($c, $this->stream1, 'block_1', 3000);
        // d купил курс целиком: доступ к обоим блокам есть, но выручки блока нет.
        $this->pay($d, $this->stream1, 'full', 10000);

        // Поток 2: b пришёл повторно (пересечение 1 ∩ 2 = 1 человек).
        $this->pay($b, $this->stream2, 'block_1', 3500);

        // Запись: купил только c, который на 2-м потоке не был.
        $this->pay($c, $this->recording, 'block_1', 3000);

        $report = app(CourseStreamComparisonReport::class)->forFamily('testovyi-kurs');
        $this->assertNotNull($report);

        $byId = collect($report['streams'])->keyBy('course_id');

        // --- поток 1: два счёта по блоку расходятся ровно на покупателя `full`
        $blocks = collect($byId[$this->stream1->id]['blocks'])->keyBy('number');
        $this->assertSame(3, $blocks[1]['buyers'], 'блок 1 купили трое');
        $this->assertSame(4, $blocks[1]['access'], 'доступ есть у четверых — плюс купивший курс целиком');
        $this->assertSame(9000.0, $blocks[1]['revenue'], 'выручка блока — только блочные тарифы');
        $this->assertSame(2, $blocks[2]['buyers']);
        $this->assertSame(3, $blocks[2]['access']);

        $this->assertSame(4, $byId[$this->stream1->id]['payers']);
        // 5 блочных платежей по 3 000 + один «весь курс» за 10 000.
        $this->assertSame(25000.0, $byId[$this->stream1->id]['revenue']);

        // --- роли потоков
        $this->assertSame(CourseFamilyMatcher::ROLE_LIVE, $byId[$this->stream1->id]['role']);
        $this->assertSame(CourseFamilyMatcher::ROLE_LIVE, $byId[$this->stream2->id]['role']);
        $this->assertSame(CourseFamilyMatcher::ROLE_RECORDING, $byId[$this->recording->id]['role']);

        // --- у курса-записи блоков нет, но колонка не пуста: номера выведены
        //     из ключей тарифов платежей.
        $this->assertSame([1], collect($byId[$this->recording->id]['blocks'])->pluck('number')->all());
        $this->assertSame(1, $byId[$this->recording->id]['blocks'][0]['buyers']);

        // --- пересечение потоков
        $pair = collect($report['crossover']['pairs'])->first(
            fn ($p) => $p['from_course_id'] === $this->stream1->id && $p['to_course_id'] === $this->stream2->id,
        );
        $this->assertSame(1, $pair['count']);

        // --- покупатели записи, не бывшие ни на одном живом потоке: c был на
        //     потоке 1, поэтому «только запись» — ноль.
        $rec = collect($report['crossover']['recording'])->firstWhere('course_id', $this->recording->id);
        $this->assertSame(1, $rec['buyers']);
        $this->assertSame(1, $rec['also_live']);
        $this->assertSame(0, $rec['only_recording']);

        // --- отток поимённо: c оплатил блок 1 и не оплатил блок 2
        $dropped = $byId[$this->stream1->id]['dropped_between_blocks'];
        $this->assertCount(1, $dropped);
        $this->assertSame(1, $dropped[0]['count']);
        $this->assertSame($c->id, $dropped[0]['users'][0]['id']);
    }

    /** @test */
    public function accrual_is_gross_and_expenses_land_in_the_confirmation_queue(): void
    {
        $student = User::factory()->create();
        $this->pay($student, $this->stream1, 'block_1', 10000);

        // Платёж-«Расход» на служебного пользователя: выплата это или аренда —
        // из данных не следует, поэтому в «выплачено» он попасть не должен.
        $service = User::factory()->create(['name' => 'Системные расходы']);
        $this->pay($service, $this->stream1, 'Расход', -1000);

        // Тот же «Расход», но заведённый прямо на пользователя преподавателя —
        // сомневаться не в чем, он идёт в «выплачено».
        $teacherUser = User::factory()->create(['teacher_id' => $this->teacher->id]);
        $this->pay($teacherUser, $this->stream1, 'Расход', -500);

        $report = app(CourseStreamComparisonReport::class)->forFamily('testovyi-kurs');
        $salary = $report['salary'];

        // Валовое начисление: 30 % от 10 000, БЕЗ вычета «Расходов» из базы.
        // С вычетом получилось бы 30 % от 8 500 = 2 550 — и те же деньги были
        // бы вычтены дважды (второй раз в paid_out).
        $this->assertSame(3000.0, $salary['accrued']);
        $this->assertSame(500.0, $salary['paid_out']);
        $this->assertSame(2500.0, $salary['remainder']);

        // Неразмеченный расход ждёт человека, поэтому остаток предварительный.
        $this->assertFalse($salary['attribution_confirmed']);
        $this->assertCount(1, $salary['pending_candidates']);
        $this->assertSame(1000.0, $salary['pending_total']);
        $this->assertSame(1500.0, $salary['remainder_if_all_confirmed']);
    }

    /** @test */
    public function attendance_coverage_is_reported_even_when_it_is_zero(): void
    {
        $student = User::factory()->create();
        $this->pay($student, $this->stream1, 'block_1', 3000);

        $report = app(CourseStreamComparisonReport::class)->forFamily('testovyi-kurs');

        $this->assertSame(1, $report['attendance']['total_users']);
        $this->assertSame(0, $report['attendance']['covered_users']);
        $this->assertSame(0.0, $report['attendance']['coverage_ratio']);
        $this->assertCount(1, $report['attendance']['bought_all_never_watched']);
    }

    /** @test */
    public function page_renders_for_accountant_with_coverage_badge_and_preliminary_marker(): void
    {
        $student = User::factory()->create(['name' => 'Иван Тестов']);
        $this->pay($student, $this->stream1, 'block_1', 10000);
        $service = User::factory()->create(['name' => 'Системные расходы']);
        $this->pay($service, $this->stream1, 'Расход', -1000);

        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);

        Livewire::actingAs($accountant)->test(CourseStreamComparison::class)
            ->assertSuccessful()
            ->assertSet('family', 'testovyi-kurs')
            // Плашка покрытия обязана присутствовать: без неё пустая колонка
            // посещаемости читается как «никто не ходил».
            ->assertSee('Данные о посещаемости есть по')
            // Остаток при неподтверждённой разметке — только со словом «предварительно».
            ->assertSee('предварительно')
            ->assertSee('Тестовый курс (1 поток, 2025)');
    }

    /** @test */
    public function both_screens_are_closed_to_manager_and_teacher_and_open_to_accountant(): void
    {
        $cases = [
            Roles::ACCOUNTANT => true,
            Roles::SUPER_ADMIN => true,
            Roles::ADMIN => false,   // сознательное сужение доступа, решение №7
            Roles::MANAGER => false,
            Roles::TEACHER => false,
        ];

        foreach ($cases as $role => $allowed) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user);

            $this->assertSame($allowed, CourseStreamComparison::canAccess(), "«Потоки курса» для роли {$role}");
            $this->assertSame($allowed, CourseBlockParticipants::canAccess(), "«Участники по блокам» для роли {$role}");
            $this->assertSame($allowed, CourseStreamComparison::shouldRegisterNavigation(), "меню «Потоки курса» для роли {$role}");
        }
    }

    /** @test */
    public function backfill_writes_nothing_without_apply_and_never_overwrites_a_manual_value(): void
    {
        $auto = Course::factory()->create(['title' => 'Другой курс (1 поток, 2026)', 'course_family' => null]);
        $manual = Course::factory()->create(['title' => 'Другой курс (2 поток, 2026)', 'course_family' => 'ruchnaia-semia']);

        $this->artisan('courses:backfill-families')->assertExitCode(0);
        $this->assertNull($auto->fresh()->course_family, 'без --apply в базу не пишется ничего');

        $this->artisan('courses:backfill-families --apply')->assertExitCode(0);
        $this->assertSame('drugoi-kurs', $auto->fresh()->course_family);
        $this->assertSame('ruchnaia-semia', $manual->fresh()->course_family, 'ручное значение не перетирается');
    }

    /** @test */
    public function verify_command_is_green_when_the_reference_family_is_absent(): void
    {
        // На пустой базе семьи из эталона нет: «нечего сверять» и «не сошлось» —
        // разные события, и первое не должно ронять прогон.
        $this->artisan('report:verify-stream-comparison')->assertExitCode(0);
    }

    /** @test */
    public function verify_command_rejects_an_unknown_family(): void
    {
        $this->artisan('report:verify-stream-comparison --family=net-takoi-semi')->assertExitCode(1);
    }

    /** @test */
    public function export_carries_the_coverage_badge_and_a_row_per_student(): void
    {
        $a = User::factory()->create(['name' => 'Алексеев Алексей']);
        $b = User::factory()->create(['name' => 'Борисов Борис']);
        $this->pay($a, $this->stream1, 'block_1', 3000);
        $this->pay($b, $this->stream1, 'block_1', 3000);
        $this->pay($b, $this->stream2, 'block_1', 3000);

        // Неразмеченный «Расход» — чтобы в файл попала и пометка о предварительном
        // остатке, а не только плашка покрытия.
        $service = User::factory()->create(['name' => 'Системные расходы']);
        $this->pay($service, $this->stream1, 'Расход', -500);

        $report = app(CourseStreamComparisonReport::class)->forFamily('testovyi-kurs');
        $rows = (new CourseStreamComparisonExport($report))->array();

        $flat = collect($rows)->map(fn ($r) => implode(' | ', array_map(fn ($c) => (string) $c, $r)))->implode("\n");

        $this->assertStringContainsString('Покрытие посещаемости: данные есть по 0 из 2', $flat);
        $this->assertStringContainsString('ПРЕДВАРИТЕЛЬНО', $flat);
        $this->assertStringContainsString('Алексеев Алексей', $flat);
        $this->assertStringContainsString('Борисов Борис', $flat);
    }
}
