<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\TeacherSalaries;
use App\Mail\PaypalClaimStudentAckMail;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\FinanceSnapshot;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\TariffForeignPrice;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\Payments\PaypalClaimAmountCheck;
use App\Services\PayoutRunService;
use App\Services\TeacherSalaryService;
use App\Support\Roles;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H5442 — P0 денежной волны (D4, D5, D11, D13, D14, D20, fail-closed) за
 * флагом features.payment_fix_wave1. Каждый блок: волна ON чинит, волна OFF
 * воспроизводит прежнее поведение (prod-inert as merged).
 */
class MoneyP0WaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
        MarketingSetting::flushCached();

        config([
            'services.paypal.enabled' => true,
            'services.paypal.me_link' => 'https://www.paypal.com/paypalme/school',
            'services.admin.email' => 'admin@example.test',
            'services.paypal.trust_existing_students' => true,
            'features.paypal_fixed_price_list' => true,
            'features.payment_fix_wave1' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // PayPal-заявка: сумма, валюта, 5%, ключ повтора (D4, D20)
    // ------------------------------------------------------------------

    private function tariffWithPrice(float $eur = 100.0, bool $withGroup = true): Tariff
    {
        $course = Course::factory()->create();
        if ($withGroup) {
            Group::factory()->create()->courses()->attach($course);
        }
        $tariff = Tariff::factory()->for($course)->block(2)->create(['price' => 8000]);
        TariffForeignPrice::create(['tariff_id' => $tariff->id, 'currency' => 'EUR', 'price' => $eur, 'fx_rate' => 86.4, 'computed_at' => now()]);

        return $tariff;
    }

    private function student(): User
    {
        return User::factory()->create(['created_at' => now()->subDays(30)]);
    }

    private function claim(User $user, Tariff $tariff, float $amount, array $extra = [])
    {
        return $this->actingAs($user)->post(route('paypal.claim.store', $tariff), array_merge([
            'foreign_amount' => $amount,
            'foreign_currency' => 'EUR',
            'paypal_payer' => 'payer@example.com',
            'paid_on' => now()->toDateString(),
        ], $extra));
    }

    private function lastClaim(): Payment
    {
        return Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->latest('id')->firstOrFail();
    }

    public function test_exact_amount_is_auto_confirmed_with_replay_key(): void
    {
        $tariff = $this->tariffWithPrice(100.0);
        $this->claim($this->student(), $tariff, 100.0, ['paypal_txn' => 'TX-EXACT'])->assertSessionHasNoErrors();

        $p = $this->lastClaim();
        $this->assertSame('paid', $p->status);
        $this->assertSame(PaypalClaimAmountCheck::EXACT, $p->claimMeta('amount_check')['verdict']);
        $this->assertNotNull($p->claim_replay_key);
    }

    public function test_underpayment_within_five_percent_is_confirmed_and_student_notified(): void
    {
        $tariff = $this->tariffWithPrice(100.0);
        $user = $this->student();
        $this->claim($user, $tariff, 96.0)
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'меньше цены на 4.00 €'));

        $p = $this->lastClaim();
        $this->assertSame('paid', $p->status);
        $check = $p->claimMeta('amount_check');
        $this->assertSame(PaypalClaimAmountCheck::UNDERPAID_WITHIN, $check['verdict']);
        $this->assertSame(-4.0, (float) $check['diff']);
        $this->assertTrue($check['notify_underpayment']);

        Mail::assertQueued(PaypalClaimStudentAckMail::class, fn ($mail) => str_contains($mail->render(), 'меньше цены на 4.00 €'));
    }

    public function test_exactly_five_percent_is_within_tolerance(): void
    {
        $tariff = $this->tariffWithPrice(100.0);
        $this->claim($this->student(), $tariff, 95.0)->assertSessionHasNoErrors();

        $this->assertSame('paid', $this->lastClaim()->status);
        $this->assertSame(PaypalClaimAmountCheck::UNDERPAID_WITHIN, $this->lastClaim()->claimMeta('amount_check')['verdict']);
    }

    public function test_overpayment_within_five_percent_is_documented_without_debt_notice(): void
    {
        $tariff = $this->tariffWithPrice(100.0);
        $this->claim($this->student(), $tariff, 104.0)
            ->assertSessionHas('success', fn (string $m) => ! str_contains($m, 'меньше цены'));

        $p = $this->lastClaim();
        $this->assertSame('paid', $p->status);
        $check = $p->claimMeta('amount_check');
        $this->assertSame(PaypalClaimAmountCheck::OVERPAID_WITHIN, $check['verdict']);
        $this->assertSame(4.0, (float) $check['diff']);
        $this->assertFalse($check['notify_underpayment']);

        Mail::assertQueued(PaypalClaimStudentAckMail::class, fn ($mail) => ! str_contains($mail->render(), 'меньше цены'));
    }

    public function test_deviation_beyond_five_percent_stays_pending_both_directions(): void
    {
        $tariff = $this->tariffWithPrice(100.0);
        $user = $this->student();

        $this->claim($user, $tariff, 94.99, ['paypal_txn' => 'TX-LOW'])->assertSessionHasNoErrors();
        $low = $this->lastClaim();
        $this->assertSame('pending', $low->status);
        $this->assertFalse($low->isAutoTrustedPaypal());
        $this->assertSame(PaypalClaimAmountCheck::BEYOND, $low->claimMeta('amount_check')['verdict']);

        $this->claim($user, $tariff, 105.01, ['paypal_txn' => 'TX-HIGH'])->assertSessionHasNoErrors();
        $this->assertSame('pending', $this->lastClaim()->status);

        $this->assertFalse($user->fresh()->courses()->where('courses.id', $tariff->course_id)->exists());
    }

    public function test_currency_without_expected_price_stays_pending(): void
    {
        $tariff = $this->tariffWithPrice(100.0);
        // USD-строки прайса нет, а живого курса в тестах нет → ожидаемой цены нет.
        config(['services.currency.cbr_url' => 'http://127.0.0.1:9/none']);
        Http::fake(['*' => Http::response('', 500)]);

        $this->claim($this->student(), $tariff, 117.0, ['foreign_currency' => 'USD'])->assertSessionHasNoErrors();

        $p = $this->lastClaim();
        $this->assertSame('pending', $p->status);
        $this->assertSame(PaypalClaimAmountCheck::NO_EXPECTED, $p->claimMeta('amount_check')['verdict']);
    }

    public function test_replayed_claim_without_txn_is_rejected_by_stable_key(): void
    {
        $tariff = $this->tariffWithPrice(100.0);
        $user = $this->student();

        $this->claim($user, $tariff, 100.0, ['paid_on' => now()->subDays(3)->toDateString()])->assertSessionHasNoErrors();
        // Отключаем «тот же день» H5007, чтобы проверить именно стабильный ключ.
        Carbon::setTestNow(now()->addDays(2));
        $this->claim($user, $tariff, 100.0, ['paid_on' => now()->subDays(5)->toDateString()])->assertSessionHasErrors('paypal_txn');
        Carbon::setTestNow();

        $this->assertSame(1, Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->count());
    }

    public function test_same_txn_cannot_be_claimed_for_another_tariff(): void
    {
        $a = $this->tariffWithPrice(100.0);
        $b = $this->tariffWithPrice(100.0);
        $user = $this->student();

        $this->claim($user, $a, 100.0, ['paypal_txn' => 'TX-ONE'])->assertSessionHasNoErrors();
        $this->claim($user, $b, 100.0, ['paypal_txn' => ' tx-one '])->assertSessionHasErrors('paypal_txn');

        $this->assertSame(1, Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->count());
    }

    public function test_replay_key_is_unique_at_data_level(): void
    {
        $tariff = $this->tariffWithPrice(100.0);
        $this->claim($this->student(), $tariff, 100.0, ['paypal_txn' => 'TX-RACE'])->assertSessionHasNoErrors();
        $key = $this->lastClaim()->claim_replay_key;

        $this->expectException(UniqueConstraintViolationException::class);
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => $tariff->course_id,
            'amount' => 8000,
            'tariff' => $tariff->accessKey(),
            'status' => 'pending',
            'provider' => Payment::PROVIDER_PAYPAL,
            'claim_replay_key' => $key,
        ]));
    }

    public function test_flag_off_keeps_pre_fix_trusted_claim_behaviour(): void
    {
        config(['features.payment_fix_wave1' => false]);
        $tariff = $this->tariffWithPrice(100.0, withGroup: false);

        $this->claim($this->student(), $tariff, 40.0, ['paypal_txn' => 'TX-OFF'])->assertSessionHasNoErrors();

        $p = $this->lastClaim();
        $this->assertSame('paid', $p->status, 'flag OFF: trusted claim is paid regardless of amount (audited H1)');
        $this->assertNull($p->claimMeta('amount_check'));
        $this->assertNull($p->claim_replay_key);
    }

    // ------------------------------------------------------------------
    // Fail-closed доступ (P0 п.4)
    // ------------------------------------------------------------------

    public function test_trusted_claim_on_course_without_groups_stays_pending_with_typed_exception(): void
    {
        $tariff = $this->tariffWithPrice(100.0, withGroup: false);
        $this->claim($this->student(), $tariff, 100.0)->assertSessionHasNoErrors();

        $p = $this->lastClaim();
        $this->assertSame('pending', $p->status);
        $this->assertSame('no_access_groups', $p->claimMeta('reconciliation_exception'));
    }

    public function test_paid_payment_on_course_without_groups_fails_closed_under_wave(): void
    {
        $course = Course::factory()->create();
        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        Payment::create(['user_id' => $user->id, 'course_id' => $course->id, 'amount' => 5000, 'tariff' => 'full', 'status' => 'paid']);
    }

    public function test_paid_payment_on_course_without_groups_is_silent_when_flags_off(): void
    {
        config(['features.payment_fix_wave1' => false, 'features.grant_access_fail_closed' => false]);
        $course = Course::factory()->create();
        $user = User::factory()->create();

        $p = Payment::create(['user_id' => $user->id, 'course_id' => $course->id, 'amount' => 5000, 'tariff' => 'full', 'status' => 'paid']);
        $this->assertSame('paid', $p->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Выплаты: возврат единожды (D11), пакет (D13), рубль ≥ 0 (D14)
    // ------------------------------------------------------------------

    private function pay(Course $course, array $attrs, ?string $at = null): Payment
    {
        return Payment::withoutEvents(function () use ($course, $attrs, $at) {
            $p = Payment::create(array_merge([
                'course_id' => $course->id,
                'status' => 'paid',
                'is_conditional' => false,
                'received_account' => Payment::RECEIVED_SCHOOL,
            ], $attrs));
            if ($at !== null) {
                $p->created_at = Carbon::parse($at);
                $p->save();
            }

            return $p;
        });
    }

    private function percentCourse(Teacher $teacher, float $pct = 50): Course
    {
        return Course::factory()->create(['teacher_id' => $teacher->id, 'salary_type' => 'percent', 'salary_value' => $pct]);
    }

    public function test_refund_line_is_withheld_once_across_recalculations(): void
    {
        $teacher = Teacher::create(['name' => 'Тест Возврат']);
        $course = $this->percentCourse($teacher);
        CourseBlock::create(['course_id' => $course->id, 'number' => 1, 'is_active' => true]);
        $this->pay($course, ['user_id' => User::factory()->create()->id, 'amount' => 10000, 'tariff' => 'block_1', 'start_block' => 1, 'end_block' => 1]);
        $refund = $this->pay($course, ['user_id' => User::factory()->create()->id, 'amount' => -2000, 'tariff' => 'Расход', 'start_block' => 1, 'end_block' => 1]);

        $service = app(TeacherSalaryService::class);
        $first = $service->blockGroupRevenueDetail($course->id, 1, null, ['teacher_id' => $teacher->id]);
        $this->assertSame(8000.0, $first['total']);

        // Блок выплачен: breakdown хранит и оплату, и возвратную строку.
        TeacherPayout::create([
            'teacher_id' => $teacher->id, 'amount' => 4000, 'type' => TeacherPayout::TYPE_REGULAR, 'paid_at' => now(),
            'breakdown' => ['course_id' => $course->id, 'block_number' => 1, 'payments' => $first['lines']],
        ]);

        $again = $service->blockGroupRevenueDetail($course->id, 1, null, ['teacher_id' => $teacher->id]);
        $this->assertSame([], $again['lines'], 'the refund was already withheld once — it must not be deducted again');

        config(['features.payment_fix_wave1' => false]);
        $legacy = $service->blockGroupRevenueDetail($course->id, 1, null, ['teacher_id' => $teacher->id]);
        $this->assertSame([(int) $refund->id], array_column($legacy['lines'], 'payment_id'), 'flag OFF reproduces audit MEDIUM #12');
    }

    public function test_negative_payable_rub_is_floored_with_typed_exception(): void
    {
        $teacher = Teacher::create(['name' => 'Лейтан Эдгар']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id, 'title' => 'Синтаксис', 'salary_type' => 'percent', 'salary_value' => 60]);
        CourseBlock::create(['course_id' => $course->id, 'number' => 65, 'is_active' => true, 'starts_at' => '2026-07-21', 'ends_at' => '2026-08-25']);
        $this->pay($course, ['user_id' => User::factory()->create()->id, 'amount' => 4800, 'tariff' => 'block_65', 'start_block' => 65, 'end_block' => 65], '2026-08-01');
        TeacherPayout::create(['teacher_id' => $teacher->id, 'amount' => 5000, 'type' => TeacherPayout::TYPE_ADVANCE, 'paid_at' => Carbon::parse('2026-07-01'), 'settled_amount' => 2000]);

        $runner = app(PayoutRunService::class);
        $row = $runner->runForTeacher($teacher, Carbon::parse('2026-08-26'), Carbon::parse('2026-07-24'));

        $this->assertSame(0.0, (float) $row['payable_rub']);
        $this->assertSame('negative_payable_rub', $row['reconciliation_exceptions'][0]['type']);
        $this->assertEqualsWithDelta(2649.60 - 3000.0, $row['reconciliation_exceptions'][0]['unabsorbed_rub'], 0.02);

        config(['features.payment_fix_wave1' => false]);
        $legacy = $runner->runForTeacher($teacher, Carbon::parse('2026-08-26'), Carbon::parse('2026-07-24'));
        $this->assertEqualsWithDelta(2649.60 - 3000.0, $legacy['payable_rub'], 0.02);
    }

    public function test_eur_payable_is_derived_from_final_rub_after_advances(): void
    {
        $teacher = Teacher::create(['name' => 'Лейтан Эдгар', 'payout_currency' => 'EUR']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id, 'title' => 'Синтаксис', 'salary_type' => 'percent', 'salary_value' => 60]);
        CourseBlock::create(['course_id' => $course->id, 'number' => 65, 'is_active' => true, 'starts_at' => '2026-07-21', 'ends_at' => '2026-08-25']);
        $this->pay($course, ['user_id' => User::factory()->create()->id, 'amount' => 24000, 'tariff' => 'block_65', 'start_block' => 65, 'end_block' => 65], '2026-08-01');
        TeacherPayout::create(['teacher_id' => $teacher->id, 'amount' => 980, 'type' => TeacherPayout::TYPE_ADVANCE, 'paid_at' => Carbon::parse('2026-07-01')]);
        FinanceSnapshot::create(['type' => FinanceSnapshot::TYPE_FX_EUR_RUB, 'amount_minor' => FinanceSnapshot::toMinor(98.0), 'currency' => 'EUR', 'entered_at' => Carbon::parse('2026-08-26'), 'note' => 'тест']);

        $row = app(PayoutRunService::class)->runForTeacher($teacher, Carbon::parse('2026-08-26'), Carbon::parse('2026-07-24'));

        // 24000×0,92×0,6 = 13248 − аванс 980 = 12268 ₽ → 12268/98 = 125,18 €.
        $this->assertSame(12268.0, (float) $row['payable_rub']);
        $this->assertEqualsWithDelta(125.18, $row['payable_eur'], 0.01);
    }

    public function test_direct_receipt_on_since_day_is_not_deducted_twice_pin(): void
    {
        $teacher = Teacher::create(['name' => 'Лейтан Эдгар', 'payout_currency' => 'EUR']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id, 'title' => 'Синтаксис', 'salary_type' => 'percent', 'salary_value' => 60]);
        CourseBlock::create(['course_id' => $course->id, 'number' => 65, 'is_active' => true, 'starts_at' => '2026-07-21', 'ends_at' => '2026-08-25']);
        $this->pay($course, ['user_id' => User::factory()->create()->id, 'amount' => 24000, 'tariff' => 'block_65', 'start_block' => 65, 'end_block' => 65], '2026-08-01');
        // Прямая оплата В день отсечки: её вычел прошлый прогон (окно по конец 24-07).
        $this->pay($course, [
            'user_id' => User::factory()->create()->id, 'amount' => 3000, 'tariff' => 'block_65', 'start_block' => 65, 'end_block' => 65,
            'received_account' => Payment::RECEIVED_TEACHER, 'received_by_teacher_id' => $teacher->id,
            'foreign_amount' => 30.0, 'foreign_currency' => 'EUR',
        ], '2026-07-24 15:00');

        $runner = app(PayoutRunService::class);
        $row = $runner->runForTeacher($teacher, Carbon::parse('2026-08-26'), Carbon::parse('2026-07-24'));
        $this->assertSame(0.0, (float) $row['direct_receipts']['total']);

        config(['features.payment_fix_wave1' => false]);
        $legacy = $runner->runForTeacher($teacher, Carbon::parse('2026-08-26'), Carbon::parse('2026-07-24'));
        // Не дефект (gap-matrix «already fixed»): дата поступления парсится до дня,
        // `> since.startOfDay()` уже исключает день отсечки — пин на оба режима.
        $this->assertSame(0.0, (float) $legacy['direct_receipts']['total']);
    }

    public function test_block_payout_double_submit_creates_one_package(): void
    {
        $teacher = Teacher::create(['name' => 'Ольга Литвиненко']);
        User::factory()->create(['teacher_id' => $teacher->id]);
        $course = $this->percentCourse($teacher, 60);
        CourseBlock::create(['course_id' => $course->id, 'number' => 1, 'starts_at' => now()->subMonth(), 'ends_at' => now()]);
        $this->pay($course, ['user_id' => User::factory()->create()->id, 'amount' => 100000, 'tariff' => 'full']);
        $accountant = User::factory()->create(['role' => Roles::ACCOUNTANT]);

        $form = [
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'block_number' => 1,
            'salary_type' => 'percent',
            'base_revenue' => 100000,
            'teacher_percent' => 60,
            'coefficient' => 100,
            'post_to_finance' => false,
            'email_report' => false,
        ];

        Livewire::actingAs($accountant)->test(TeacherSalaries::class)->callAction('block_payout', $form);
        Livewire::actingAs($accountant)->test(TeacherSalaries::class)->callAction('block_payout', $form);

        $this->assertSame(1, TeacherPayout::query()->where('teacher_id', $teacher->id)->count());
        $this->assertSame(
            TeacherPayout::blockSettlementKey($teacher->id, $course->id, 1, null),
            TeacherPayout::query()->where('teacher_id', $teacher->id)->value('settlement_key'),
        );

        config(['features.payment_fix_wave1' => false]);
        Livewire::actingAs($accountant)->test(TeacherSalaries::class)->callAction('block_payout', $form);
        $this->assertSame(2, TeacherPayout::query()->where('teacher_id', $teacher->id)->count(), 'flag OFF reproduces audit MEDIUM #15');
    }

    // ------------------------------------------------------------------
    // Read-only отчёт (D5)
    // ------------------------------------------------------------------

    public function test_report_writes_nothing_and_lists_affected_rows(): void
    {
        config(['features.payment_fix_wave1' => false]);
        $tariff = $this->tariffWithPrice(100.0);
        $user = $this->student();
        // Историческая авто-доверенная заявка на 40 € при цене 100 € (audit H1).
        $this->claim($user, $tariff, 40.0, ['paypal_txn' => 'TX-HIST'])->assertSessionHasNoErrors();
        // Дубль поблочной выплаты.
        $teacher = Teacher::create(['name' => 'Тест Дубль']);
        foreach ([1, 2] as $_) {
            TeacherPayout::create(['teacher_id' => $teacher->id, 'amount' => 1000, 'type' => TeacherPayout::TYPE_REGULAR, 'paid_at' => now(),
                'breakdown' => ['course_id' => $tariff->course_id, 'block_number' => 2, 'payments' => []]]);
        }

        $counts = [Payment::count(), TeacherPayout::count()];
        $path = storage_path('framework/testing/h5442-report.json');

        $this->assertSame(0, Artisan::call('money:p0-wave-report', ['--json' => $path]));

        $this->assertSame($counts, [Payment::count(), TeacherPayout::count()]);
        $report = json_decode((string) file_get_contents($path), true);
        $this->assertSame(0, $report['db_writes']);
        $this->assertSame(1, $report['paypal_claims']['trusted_that_would_stay_pending']);
        $this->assertSame(1, $report['duplicate_packages']['packages_with_duplicates']);
        $this->assertFalse($report['wave_flag_live']);
        @unlink($path);
    }
}
