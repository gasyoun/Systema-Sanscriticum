<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\Tariff;
use App\Models\User;
use App\Services\BlockAccessMaterializer;
use App\Services\DebtPaymentResolver;
use App\Services\StudentDebtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DebtSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private function courseWithCurrentBlock2(): Course
    {
        $course = Course::factory()->create(['is_active' => true]);
        CourseBlock::factory()->for($course)->create(['number' => 1]);
        CourseBlock::factory()->for($course)->current()->create(['number' => 2]);

        return $course;
    }

    private function debtRow(User $user, Course $course): object
    {
        return app(StudentDebtsService::class)->forUser($user)->firstWhere('course_id', $course->id);
    }

    // ---- Resolver -------------------------------------------------------

    /** @test */
    public function resolver_offers_next_and_whole_for_an_installment_debt(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();
        $group = (string) Str::uuid();

        foreach ([[-3, 2000], [30, 2000], [60, 2000]] as [$days, $amount]) {
            PaymentPromise::create([
                'user_id' => $user->id, 'course_id' => $course->id,
                'promised_at' => now()->addDays($days)->toDateString(), 'amount' => $amount,
                'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
            ]);
        }

        $opts = app(DebtPaymentResolver::class)->optionsFor($this->debtRow($user, $course));

        $this->assertSame('arrangement', $opts['type']);
        $this->assertNotNull($opts['next']);
        $this->assertSame(2000.0, $opts['next']['amount']);
        $this->assertTrue($opts['next']['overdue'], 'Ближайшее обещание просрочено.');
        $this->assertNotNull($opts['whole'], 'Для рассрочки из 3 обещаний должна быть кнопка «Погасить всё».');
        $this->assertSame(6000.0, $opts['whole']['amount']);
    }

    /** @test */
    public function resolver_offers_no_whole_button_for_a_single_promise(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();

        PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addDays(5)->toDateString(), 'amount' => 5000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        $opts = app(DebtPaymentResolver::class)->optionsFor($this->debtRow($user, $course));

        $this->assertSame('arrangement', $opts['type']);
        $this->assertNotNull($opts['next']);
        $this->assertNull($opts['whole'], 'Одиночное обещание не показывает «Погасить всё».');
    }

    /** @test */
    public function resolver_maps_a_not_renewed_block_debt_to_the_matching_block_tariff(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();

        // Оплачен блок №1, текущий №2 — долг.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 4000, 'tariff' => 'block_1', 'status' => 'paid',
            'start_block' => 1, 'end_block' => 1,
        ]);

        $block2Tariff = Tariff::create([
            'course_id' => $course->id, 'title' => 'Блок 2', 'type' => 'block',
            'block_number' => 2, 'price' => 4000, 'is_active' => true,
        ]);

        $opts = app(DebtPaymentResolver::class)->optionsFor($this->debtRow($user, $course));

        $this->assertSame('tariff', $opts['type']);
        $this->assertNull($opts['full'], 'Долг не по всему курсу → полный тариф не предлагаем.');
        $this->assertCount(1, $opts['blocks']);
        $this->assertSame(2, $opts['blocks'][0]['number']);
        $this->assertStringContainsString('/checkout/'.$block2Tariff->id, $opts['blocks'][0]['url']);
        $this->assertSame([], $opts['unpriced_blocks']);
    }

    /** @test */
    public function resolver_flags_unpriced_blocks_when_no_tariff_exists(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();

        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 4000, 'tariff' => 'block_1', 'status' => 'paid',
            'start_block' => 1, 'end_block' => 1,
        ]);

        // Тариф на блок №2 не заведён.
        $opts = app(DebtPaymentResolver::class)->optionsFor($this->debtRow($user, $course));

        $this->assertSame('none', $opts['type']);
        $this->assertSame([2], $opts['unpriced_blocks']);
    }

    // ---- Auto-fulfilment on real payment --------------------------------

    /** @test */
    public function real_payment_auto_fulfils_the_linked_single_promise(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();

        $promise = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addDays(5)->toDateString(), 'amount' => 5000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        // Реальный self-service платёж приходит оплаченным (вебхук эквивалент).
        $payment = Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 5000, 'tariff' => 'block_2', 'status' => 'paid',
            'start_block' => 2, 'end_block' => 2,
            'is_conditional' => false, 'linked_promise_id' => $promise->id,
        ]);

        $promise->refresh();
        $this->assertSame(PaymentPromise::STATUS_FULFILLED, $promise->status);
        $this->assertSame($payment->id, $promise->fulfilled_payment_id);
    }

    /** @test */
    public function paying_the_whole_plan_closes_every_unmet_promise_in_the_group(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();
        $group = (string) Str::uuid();

        $lead = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->subDays(3)->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
        ]);
        $second = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addMonth()->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
        ]);

        // Платёж на весь остаток (4000) → закрываем обе строки графика.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 4000, 'tariff' => 'block_2', 'status' => 'paid',
            'start_block' => 2, 'end_block' => 2,
            'is_conditional' => false, 'linked_promise_id' => $lead->id,
        ]);

        $this->assertSame(PaymentPromise::STATUS_FULFILLED, $lead->fresh()->status);
        $this->assertSame(PaymentPromise::STATUS_FULFILLED, $second->fresh()->status);
    }

    /** @test */
    public function paying_one_installment_closes_only_that_promise(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();
        $group = (string) Str::uuid();

        $lead = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->subDays(3)->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
        ]);
        $second = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addMonth()->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
        ]);

        // Платёж лишь на один взнос (2000) → закрываем только lead.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 2000, 'tariff' => 'block_2', 'status' => 'paid',
            'start_block' => 2, 'end_block' => 2,
            'is_conditional' => false, 'linked_promise_id' => $lead->id,
        ]);

        $this->assertSame(PaymentPromise::STATUS_FULFILLED, $lead->fresh()->status);
        $this->assertSame(PaymentPromise::STATUS_ACTIVE, $second->fresh()->status, 'Второй взнос остаётся открытым.');
    }

    /** @test */
    public function conditional_payment_does_not_fulfil_the_promise(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();

        $promise = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addDays(5)->toDateString(), 'amount' => 5000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        // Conditional-доступ «под честное слово» — денег нет, обещание не гасит.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 0, 'tariff' => 'block_2', 'status' => 'paid',
            'start_block' => 2, 'end_block' => 2,
            'is_conditional' => true, 'linked_promise_id' => $promise->id,
        ]);

        $this->assertSame(PaymentPromise::STATUS_ACTIVE, $promise->fresh()->status);
    }

    // ---- Controller -----------------------------------------------------

    /** @test */
    public function paying_someone_elses_promise_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();

        $promise = PaymentPromise::create([
            'user_id' => $owner->id, 'course_id' => $course->id,
            'promised_at' => now()->addDays(5)->toDateString(), 'amount' => 5000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        $this->actingAs($intruder)
            ->post(route('student.debt.promise.pay', $promise))
            ->assertForbidden();
    }

    /** @test */
    public function paying_an_already_closed_promise_redirects_back_with_error(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();

        $promise = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addDays(5)->toDateString(), 'amount' => 5000,
            'status' => PaymentPromise::STATUS_FULFILLED,
        ]);

        $this->actingAs($user)
            ->from(route('student.dashboard'))
            ->post(route('student.debt.promise.pay', $promise))
            ->assertRedirect(route('student.dashboard').'#debts')
            ->assertSessionHas('error');
    }

    /**
     * Регресс: на проде flash error после fail оплаты не рендерился в кабинете
     * (только password_status/bot_status) → «кнопка ничего не делает».
     *
     * @test
     */
    public function debt_pay_error_flash_is_visible_on_the_dashboard(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();

        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 5000, 'tariff' => 'block_2', 'status' => 'pending',
            'is_conditional' => false, 'is_self_service' => true,
        ]);

        $promise = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addDays(5)->toDateString(), 'amount' => 5000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->from(route('student.dashboard'))
            ->post(route('student.debt.promise.pay', $promise))
            ->assertRedirect(route('student.dashboard').'#debts')
            ->assertSessionHas('error');

        // Flash живёт один следующий GET — кабинет обязан его отрисовать.
        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('У вас уже есть незавершённый заказ', false)
            ->assertSee('fa-triangle-exclamation', false);
    }

    /** @test */
    public function dashboard_renders_the_pay_cta_for_a_student_with_an_installment_debt(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();
        $group = (string) Str::uuid();

        foreach ([[-3, 2000], [30, 2000]] as [$days, $amount]) {
            PaymentPromise::create([
                'user_id' => $user->id, 'course_id' => $course->id,
                'promised_at' => now()->addDays($days)->toDateString(), 'amount' => $amount,
                'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
            ]);
        }

        $this->actingAs($user)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Оплатить')
            ->assertSee('Погасить всё');
    }

    // ---- Phase 2: multi-block access via sibling rows ------------------

    /** @test */
    public function a_multi_block_range_payment_materialises_sibling_access_rows(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        foreach ([5, 6, 7] as $n) {
            CourseBlock::factory()->for($course)->create(['number' => $n]);
        }

        // Реальный платёж с диапазоном 5–7 несёт лишь ключ block_5.
        $primary = Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 9000, 'tariff' => 'block_5', 'status' => 'paid',
            'start_block' => 5, 'end_block' => 7,
            'is_conditional' => false, 'is_self_service' => true,
        ]);

        $keys = Payment::where('user_id', $user->id)->where('course_id', $course->id)
            ->paid()->pluck('tariff')->all();

        $this->assertContains('block_5', $keys);
        $this->assertContains('block_6', $keys, 'Блок 6 должен открыться sibling-строкой.');
        $this->assertContains('block_7', $keys, 'Блок 7 должен открыться sibling-строкой.');

        // Sibling-строки нулевые и помечены access_grant, привязаны к primary.
        $siblings = Payment::where('transaction_id', 'access_grant_#'.$primary->id)->get();
        $this->assertCount(2, $siblings);
        $this->assertTrue($siblings->every(fn ($p) => (float) $p->amount === 0.0));
    }

    /** @test */
    public function sibling_materialisation_is_idempotent_on_webhook_replay(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        foreach ([1, 2] as $n) {
            CourseBlock::factory()->for($course)->create(['number' => $n]);
        }

        $primary = Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 4000, 'tariff' => 'block_1', 'status' => 'paid',
            'start_block' => 1, 'end_block' => 2,
            'is_conditional' => false, 'is_self_service' => true,
        ]);

        // Повторный вызов материализатора (дубль вебхука) не плодит строки.
        app(BlockAccessMaterializer::class)->materialize($primary->fresh());

        $this->assertCount(1, Payment::where('transaction_id', 'access_grant_#'.$primary->id)->get());
    }

    /** @test */
    public function a_full_tariff_payment_creates_no_siblings(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        foreach ([1, 2, 3] as $n) {
            CourseBlock::factory()->for($course)->create(['number' => $n]);
        }

        $primary = Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 12000, 'tariff' => 'full', 'status' => 'paid',
            'start_block' => 1, 'end_block' => 3,
            'is_conditional' => false, 'is_self_service' => true,
        ]);

        $this->assertCount(0, Payment::where('transaction_id', 'access_grant_#'.$primary->id)->get());
    }

    /** @test */
    public function reversing_the_primary_removes_its_sibling_access_rows(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        foreach ([1, 2] as $n) {
            CourseBlock::factory()->for($course)->create(['number' => $n]);
        }

        $primary = Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 4000, 'tariff' => 'block_1', 'status' => 'paid',
            'start_block' => 1, 'end_block' => 2,
            'is_conditional' => false, 'is_self_service' => true,
        ]);
        $this->assertCount(1, Payment::where('transaction_id', 'access_grant_#'.$primary->id)->get());

        $primary->update(['status' => 'canceled']);

        $this->assertCount(0, Payment::where('transaction_id', 'access_grant_#'.$primary->id)->get());
    }

    // ---- Phase 2: duplicate-pending guard ------------------------------

    /** @test */
    public function a_second_pending_self_service_order_for_the_course_is_rejected(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();

        // Уже висит незавершённый self-service заказ по курсу.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 5000, 'tariff' => 'block_2', 'status' => 'pending',
            'is_conditional' => false, 'is_self_service' => true,
        ]);

        $promise = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addDays(5)->toDateString(), 'amount' => 5000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->from(route('student.dashboard'))
            ->post(route('student.debt.promise.pay', $promise))
            ->assertRedirect(route('student.dashboard').'#debts')
            ->assertSessionHas('error');

        // Второй pending не создан.
        $this->assertSame(1, Payment::where('user_id', $user->id)->where('status', 'pending')->count());
    }

    // ---- Phase 2: custom partial (greedy) ------------------------------

    /** @test */
    public function a_custom_partial_payment_closes_only_the_early_covered_installments(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();
        $group = (string) Str::uuid();

        $p1 = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->subDays(2)->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
        ]);
        $p2 = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addMonth()->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
        ]);
        $p3 = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addMonths(2)->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
        ]);

        // Платёж на 4000 из графика 6000 приходит оплаченным → закрывает первые два.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 4000, 'tariff' => 'block_2', 'status' => 'paid',
            'start_block' => 2, 'end_block' => 2,
            'is_conditional' => false, 'is_self_service' => true, 'linked_promise_id' => $p1->id,
        ]);

        $this->assertSame(PaymentPromise::STATUS_FULFILLED, $p1->fresh()->status);
        $this->assertSame(PaymentPromise::STATUS_FULFILLED, $p2->fresh()->status);
        $this->assertSame(PaymentPromise::STATUS_ACTIVE, $p3->fresh()->status, 'Третий взнос не покрыт — остаётся открытым.');
    }

    /** @test */
    public function a_custom_amount_below_the_next_instalment_is_rejected(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();
        $group = (string) Str::uuid();
        PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addWeek()->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
        ]);
        PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addMonth()->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
        ]);

        $this->actingAs($user)
            ->from(route('student.dashboard'))
            ->post(route('student.debt.course.pay-all', $course), ['amount' => 500])
            ->assertRedirect(route('student.dashboard').'#debts')
            ->assertSessionHas('error');
    }

    // ---- Phase 2: prana ------------------------------------------------

    /** @test */
    public function spending_prana_reduces_the_cash_amount_and_still_fulfils_the_promise(): void
    {
        Http::fake([
            '*/payments_with_receipt' => Http::response([
                'Data' => ['paymentLink' => 'https://pay.tochka.test/p', 'paymentLinkId' => 'pl-1'],
            ], 200),
        ]);

        $user = User::factory()->create(['prana_balance' => 6000]);
        $course = $this->courseWithCurrentBlock2();
        $promise = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addWeek()->toDateString(), 'amount' => 2000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        // 2000 ₽, доля 30% → до 600 ₽ праной = 6000 очков (rate 10).
        $this->actingAs($user)
            ->post(route('student.debt.promise.pay', $promise), ['prana_amount' => 6000])
            ->assertRedirect('https://pay.tochka.test/p');

        $payment = Payment::where('user_id', $user->id)->where('status', 'pending')->firstOrFail();
        $this->assertSame(1400.0, (float) $payment->amount, 'Наличные = 2000 − 600 (прана).');
        $this->assertSame(6000, (int) $payment->prana_spent);
        $this->assertSame(0, (int) $user->fresh()->prana_balance, 'Прана списана.');

        // Долг-стоимость = 1400 + 600 = 2000 → обещание закрывается при оплате.
        $payment->update(['status' => 'paid']);
        $this->assertSame(PaymentPromise::STATUS_FULFILLED, $promise->fresh()->status);
    }

    // ---- Phase 2: bundle ----------------------------------------------

    /**
     * Курс блоков 1–4 (текущий 4); оплачены 1 и 2 → долг = блоки 3,4 (плоский,
     * без договорённости). Тарифы на 3 и 4 → доступен бандл на оба.
     */
    private function plainTwoBlockDebtCourse(User $user): Course
    {
        $course = Course::factory()->create(['is_active' => true]);
        CourseBlock::factory()->for($course)->create(['number' => 1]);
        CourseBlock::factory()->for($course)->create(['number' => 2]);
        CourseBlock::factory()->for($course)->create(['number' => 3]);
        CourseBlock::factory()->for($course)->current()->create(['number' => 4]);

        foreach ([1, 2] as $n) {
            Payment::create([
                'user_id' => $user->id, 'course_id' => $course->id,
                'amount' => 4000, 'tariff' => 'block_'.$n, 'status' => 'paid',
                'start_block' => $n, 'end_block' => $n, 'is_conditional' => false,
            ]);
        }
        Tariff::create(['course_id' => $course->id, 'title' => 'Блок 3', 'type' => 'block', 'block_number' => 3, 'price' => 4000, 'is_active' => true]);
        Tariff::create(['course_id' => $course->id, 'title' => 'Блок 4', 'type' => 'block', 'block_number' => 4, 'price' => 4000, 'is_active' => true]);

        return $course;
    }

    /** @test */
    public function resolver_offers_a_bundle_for_a_plain_multi_block_debt(): void
    {
        $user = User::factory()->create();
        $course = $this->plainTwoBlockDebtCourse($user);

        $debt = app(StudentDebtsService::class)->forUser($user)->firstWhere('course_id', $course->id);
        $this->assertNotNull($debt);
        $this->assertSame([3, 4], $debt->debt_block_numbers);

        $opts = app(DebtPaymentResolver::class)->optionsFor($debt, $user);
        $this->assertSame('tariff', $opts['type']);
        $this->assertNotNull($opts['bundle']);
        $this->assertSame(8000.0, $opts['bundle']['amount']);
    }

    /** @test */
    public function pay_bundle_creates_one_ranged_payment_that_opens_every_owed_block(): void
    {
        Http::fake([
            '*/payments_with_receipt' => Http::response([
                'Data' => ['paymentLink' => 'https://pay.tochka.test/b', 'paymentLinkId' => 'pl-b'],
            ], 200),
        ]);

        $user = User::factory()->create();
        $course = $this->plainTwoBlockDebtCourse($user);

        $this->actingAs($user)
            ->post(route('student.debt.course.pay-bundle', $course))
            ->assertRedirect('https://pay.tochka.test/b');

        $bundle = Payment::where('user_id', $user->id)->where('status', 'pending')->firstOrFail();
        $this->assertSame(8000.0, (float) $bundle->amount);
        $this->assertSame(3, (int) $bundle->start_block);
        $this->assertSame(4, (int) $bundle->end_block);
        $this->assertTrue((bool) $bundle->is_self_service);

        // При оплате открываются оба блока (block_3 своим ключом + block_4 sibling).
        $bundle->update(['status' => 'paid']);
        $keys = Payment::where('user_id', $user->id)->where('course_id', $course->id)->paid()->pluck('tariff')->all();
        $this->assertContains('block_3', $keys);
        $this->assertContains('block_4', $keys);
    }

    // ---- Phase 2: reschedule ------------------------------------------

    /** @test */
    public function a_student_can_reschedule_the_next_promise_within_the_limit(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();
        $promise = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addDay()->toDateString(), 'amount' => 3000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        $newDate = now()->addDays(10)->toDateString();
        $this->actingAs($user)
            ->from(route('student.dashboard'))
            ->post(route('student.debt.promise.reschedule', $promise), ['new_date' => $newDate])
            ->assertRedirect(route('student.dashboard').'#debts')
            ->assertSessionHas('success');

        $fresh = $promise->fresh();
        $this->assertSame($newDate, $fresh->promised_at->toDateString());
        $this->assertNotNull($fresh->student_rescheduled_at);
    }

    /** @test */
    public function a_second_reschedule_by_the_student_is_rejected(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();
        $promise = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addDay()->toDateString(), 'amount' => 3000,
            'status' => PaymentPromise::STATUS_ACTIVE,
            'student_rescheduled_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->from(route('student.dashboard'))
            ->post(route('student.debt.promise.reschedule', $promise), ['new_date' => now()->addDays(5)->toDateString()])
            ->assertSessionHas('error');
    }

    /** @test */
    public function rescheduling_beyond_the_limit_is_rejected(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();
        $promise = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addDay()->toDateString(), 'amount' => 3000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->from(route('student.dashboard'))
            ->post(route('student.debt.promise.reschedule', $promise), ['new_date' => now()->addDays(30)->toDateString()])
            ->assertSessionHas('error');

        $this->assertNull($promise->fresh()->student_rescheduled_at);
    }

    /** @test */
    public function rescheduling_someone_elses_promise_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();
        $promise = PaymentPromise::create([
            'user_id' => $owner->id, 'course_id' => $course->id,
            'promised_at' => now()->addDay()->toDateString(), 'amount' => 3000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        $this->actingAs($intruder)
            ->post(route('student.debt.promise.reschedule', $promise), ['new_date' => now()->addDays(5)->toDateString()])
            ->assertForbidden();
    }

    /** @test */
    public function paying_a_promise_creates_a_pending_payment_and_redirects_to_the_bank(): void
    {
        Http::fake([
            '*/payments_with_receipt' => Http::response([
                'Data' => ['paymentLink' => 'https://pay.tochka.test/abc', 'paymentLinkId' => 'link-abc'],
            ], 200),
        ]);

        $user = User::factory()->create();
        $course = $this->courseWithCurrentBlock2();

        $promise = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addDays(5)->toDateString(), 'amount' => 5000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)
            ->post(route('student.debt.promise.pay', $promise))
            ->assertRedirect('https://pay.tochka.test/abc');

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 5000,
            'status' => 'pending',
            'is_conditional' => false,
            'linked_promise_id' => $promise->id,
            'transaction_id' => 'link-abc',
        ]);

        // Обещание ещё открыто — закроется только когда вебхук переведёт платёж в paid.
        $this->assertSame(PaymentPromise::STATUS_ACTIVE, $promise->fresh()->status);
    }
}
