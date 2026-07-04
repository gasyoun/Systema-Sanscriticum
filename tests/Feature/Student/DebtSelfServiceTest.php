<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\Tariff;
use App\Models\User;
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
            ->assertRedirect(route('student.dashboard'))
            ->assertSessionHas('error');
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
