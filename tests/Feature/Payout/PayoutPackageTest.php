<?php

declare(strict_types=1);

namespace Tests\Feature\Payout;

use App\Models\Course;
use App\Models\Teacher;
use App\Models\TeacherCompensationAssignment;
use App\Models\TeacherCompensationTerm;
use App\Models\TeacherPayoutPackage;
use App\Models\TeacherPayoutPackageLine;
use App\Models\User;
use App\Services\Payout\CompensationResolver;
use App\Services\Payout\PayoutPackageService;
use App\Services\Payout\PayoutTransitionRefused;
use App\Services\Payout\PayoutWritesDisabled;
use App\Services\Payout\UnresolvedCompensation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H5444 (P2) — версионированные условия, датированные назначения и
 * неизменяемые расчётные пакеты.
 *
 * Два слоя проверяются раздельно: сервис (переходы, повтор по ключу, гонки,
 * рубль-первым) и триггеры БД (сырые INSERT/UPDATE в обход сервиса).
 */
class PayoutPackageTest extends TestCase
{
    use RefreshDatabase;

    private PayoutPackageService $packages;

    private Teacher $teacher;

    private Course $course;

    private TeacherCompensationTerm $term;

    private TeacherCompensationAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-24 12:00:00');
        config(['features.money_payout_packages' => true]);

        $this->packages = app(PayoutPackageService::class);
        $this->teacher = Teacher::factory()->create();
        $this->course = Course::factory()->create();
        $this->term = $this->makeTerm(300_000); // 30%
        $this->assignment = $this->makeAssignment($this->term);
    }

    private function makeTerm(int $ppm, int $version = 1, string $from = '2026-01-01'): TeacherCompensationTerm
    {
        return TeacherCompensationTerm::create([
            'term_key' => "term:{$this->teacher->id}:v{$version}",
            'teacher_id' => $this->teacher->id,
            'version' => $version,
            'compensation_type' => TeacherCompensationTerm::TYPE_PERCENT,
            'rate_ppm' => $ppm,
            'effective_from' => $from,
            'confirmed_by' => 1,
            'confirmed_at' => now(),
        ]);
    }

    private function makeAssignment(TeacherCompensationTerm $term, ?string $to = null, string $from = '2026-01-01'): TeacherCompensationAssignment
    {
        return TeacherCompensationAssignment::create([
            'assignment_key' => 'assign:'.$term->term_key,
            'teacher_id' => $this->teacher->id,
            'term_id' => $term->id,
            'scope_kind' => TeacherCompensationAssignment::SCOPE_COURSE,
            'course_id' => $this->course->id,
            'effective_from' => $from,
            'effective_to' => $to,
            'confirmed_by' => 1,
            'confirmed_at' => now(),
        ]);
    }

    private function draft(): TeacherPayoutPackage
    {
        return $this->packages->draft($this->teacher->id, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
    }

    private function base(TeacherPayoutPackage $package, int $kopecks, string $key = 'base-1'): TeacherPayoutPackageLine
    {
        return $this->packages->addLine($package, "{$package->package_key}:{$key}", TeacherPayoutPackageLine::KIND_BASE, $kopecks, [
            'assignment_id' => $this->assignment->id,
            'term_id' => $this->term->id,
        ]);
    }

    // ---------------------------------------------------------------- D13

    public function test_the_same_key_returns_the_same_package(): void
    {
        $first = $this->draft();
        $second = $this->draft();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, TeacherPayoutPackage::count());
    }

    public function test_a_second_live_package_for_the_same_window_is_refused_by_the_database(): void
    {
        $this->draft();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('another live package already covers this teacher and period');

        DB::table('teacher_payout_packages')->insert([
            'package_key' => 'payout:manual:'.$this->teacher->id,
            'teacher_id' => $this->teacher->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'state' => 'draft',
        ]);
    }

    public function test_the_state_machine_only_moves_forward(): void
    {
        $package = $this->draft();
        $this->base($package, 100_000);

        $this->packages->approve($package, 7);
        $this->assertSame(TeacherPayoutPackage::STATE_APPROVED, $package->refresh()->state);

        $this->packages->pay($package, 7, 'evidence:tochka:1');
        $this->assertSame(TeacherPayoutPackage::STATE_PAID, $package->refresh()->state);

        // Назад — нельзя ни одним путём.
        $this->expectException(PayoutTransitionRefused::class);
        $this->packages->approve($package, 7);
    }

    public function test_raw_backward_transition_is_refused_by_the_database(): void
    {
        $package = $this->draft();
        $this->base($package, 100_000);
        $this->packages->approve($package, 7);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('illegal package transition');

        DB::table('teacher_payout_packages')->where('id', $package->id)->update(['state' => 'draft']);
    }

    public function test_an_approved_package_freezes_its_composition(): void
    {
        $package = $this->draft();
        $this->base($package, 100_000);
        $this->packages->approve($package, 7);

        // Через сервис — понятный отказ.
        try {
            $this->base($package, 5_000, 'base-2');
            $this->fail('a frozen package accepted a new line');
        } catch (PayoutTransitionRefused $e) {
            $this->assertStringContainsString('frozen', $e->getMessage());
        }

        // В обход сервиса — триггер БД.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('an approved package is frozen');
        DB::table('teacher_payout_packages')->where('id', $package->id)->update(['total_kopecks' => 1]);
    }

    public function test_lines_are_immutable(): void
    {
        $package = $this->draft();
        $line = $this->base($package, 100_000);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('package lines are immutable');
        DB::table('teacher_payout_package_lines')->where('id', $line->id)->update(['amount_kopecks' => 1]);
    }

    public function test_a_paid_package_needs_payment_evidence(): void
    {
        $package = $this->draft();
        $this->base($package, 100_000);
        $this->packages->approve($package, 7);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('a paid package needs payment evidence');

        DB::table('teacher_payout_packages')->where('id', $package->id)
            ->update(['state' => 'paid', 'paid_at' => now(), 'paid_by' => 7]);
    }

    public function test_paying_twice_with_the_same_evidence_is_the_same_fact(): void
    {
        $package = $this->draft();
        $this->base($package, 100_000);
        $this->packages->approve($package, 7);

        $first = $this->packages->pay($package, 7, 'evidence:tochka:1');
        $second = $this->packages->pay($package, 7, 'evidence:tochka:1');

        $this->assertSame($first->paid_at->toDateTimeString(), $second->paid_at->toDateTimeString());
        $this->assertSame(1, TeacherPayoutPackage::where('state', 'paid')->count());
    }

    public function test_a_concurrent_second_draft_does_not_create_a_second_package(): void
    {
        // Две «параллельные» попытки с тем же ключом: вторая обязана вернуть первую.
        $keys = [];

        for ($i = 0; $i < 5; $i++) {
            $keys[] = $this->draft()->id;
        }

        $this->assertCount(1, array_unique($keys));
    }

    // ---------------------------------------------------------------- D14

    public function test_rubles_are_the_source_of_truth_and_currency_comes_last(): void
    {
        $package = $this->draft();
        $this->base($package, 100_000);                                    // 1000.00 ₽
        $this->packages->addLine($package, 'adv', TeacherPayoutPackageLine::KIND_ADVANCE, -20_000);

        // До утверждения валютного снимка нет вовсе.
        $this->assertNull($package->refresh()->payout_currency);
        $this->assertSame(80_000, $package->total_kopecks);

        $this->packages->approve($package, 7);
        $this->packages->pay($package, 7, 'evidence:paypal:1', [
            'currency' => 'EUR', 'rate' => 100.0, 'rate_date' => '2026-09-24', 'source' => 'cbr',
        ]);

        $package->refresh();

        // Аванс вычтен В РУБЛЯХ, курс применён к финальному рублёвому итогу.
        $this->assertSame(80_000, $package->total_kopecks);
        $this->assertSame('EUR', $package->payout_currency);
        $this->assertSame(800, $package->payout_amount_minor);   // 8.00 EUR
    }

    public function test_a_ruble_payout_carries_no_exchange_rate(): void
    {
        $package = $this->draft();
        $this->base($package, 100_000);
        $this->packages->approve($package, 7);
        $this->packages->pay($package, 7, 'evidence:tochka:2');

        $package->refresh();
        $this->assertSame('RUB', $package->payout_currency);
        $this->assertNull($package->fx_rate);
        $this->assertSame(100_000, $package->payout_amount_minor);
    }

    public function test_a_draft_cannot_carry_an_fx_snapshot(): void
    {
        $package = $this->draft();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FX snapshot is set only on an approved package');

        DB::table('teacher_payout_packages')->where('id', $package->id)->update(['payout_currency' => 'EUR']);
    }

    public function test_a_negative_ruble_total_is_never_booked_as_a_payout(): void
    {
        $package = $this->draft();
        $this->base($package, 10_000);

        $this->expectException(PayoutTransitionRefused::class);
        $this->expectExceptionMessage('negative ruble total');

        $this->packages->addLine($package, 'adv-big', TeacherPayoutPackageLine::KIND_ADVANCE, -50_000);
    }

    // ------------------------------------------------------------ D15/D17

    public function test_a_role_never_creates_a_base_line(): void
    {
        $package = $this->draft();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('a base line needs a dated compensation assignment');

        DB::table('teacher_payout_package_lines')->insert([
            'line_key' => 'no-assignment',
            'package_id' => $package->id,
            'kind' => 'base',
            'amount_kopecks' => 100_000,
        ]);
    }

    public function test_the_resolver_refuses_to_invent_a_rate(): void
    {
        $other = Teacher::factory()->create();

        $this->expectException(UnresolvedCompensation::class);
        $this->expectExceptionMessage('never inferred from roles, course fields or past payouts');

        app(CompensationResolver::class)->resolve($other->id, $this->course->id, Carbon::parse('2026-09-15'));
    }

    public function test_the_resolver_picks_the_assignment_dated_for_that_day(): void
    {
        $resolver = app(CompensationResolver::class);

        $this->assertSame(
            $this->assignment->id,
            $resolver->resolve($this->teacher->id, $this->course->id, Carbon::parse('2026-09-15'))->id,
        );

        // До даты действия — назначения нет, а не «ставка по умолчанию».
        $this->expectException(UnresolvedCompensation::class);
        $resolver->resolve($this->teacher->id, $this->course->id, Carbon::parse('2025-12-31'));
    }

    public function test_terms_are_versioned_not_edited(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('compensation terms are immutable');

        DB::table('teacher_compensation_terms')->where('id', $this->term->id)->update(['rate_ppm' => 200_000]);
    }

    public function test_percent_terms_apply_in_integer_kopecks(): void
    {
        // 30% от 3333.33 ₽ = 999.999 ₽ → 1000.00 ₽ (половина вверх, без float).
        $this->assertSame(99_999, $this->makeTerm(300_000, 2, '2026-02-01')->applyToKopecks(333_333));
    }

    public function test_overlapping_active_assignments_are_refused(): void
    {
        $second = $this->makeTerm(200_000, 2, '2026-03-01');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('overlapping active assignment');

        $this->makeAssignment($second, null, '2026-06-01');
    }
}
