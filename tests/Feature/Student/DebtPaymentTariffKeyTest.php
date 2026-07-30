<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Lesson;
use App\Models\MarketingSetting;
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

/**
 * H393: платёж самообслуживания должен нести ключ доступа, соответствующий
 * РЕАЛЬНО оплаченному объёму.
 *  - взнос рассрочки открывает только свой блок (block_N), не весь курс (full);
 *  - «Оплатить всё» многоблочного долга идёт через настоящий bundle-тариф.
 */
class DebtPaymentTariffKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarketingSetting::flushCached();
        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => ['paymentLink' => 'https://pay.tochka.test/x', 'paymentLinkId' => 'tx_h393'],
            ], 200),
            '*/payments_with_receipt' => Http::response([
                'Data' => ['paymentLink' => 'https://pay.tochka.test/x', 'paymentLinkId' => 'tx_h393'],
            ], 200),
        ]);
    }

    /** Курс из N блоков (последний — текущий), без реальных оплат. */
    private function courseWithBlocks(int $count): Course
    {
        $course = Course::factory()->create(['is_active' => true]);
        for ($n = 1; $n <= $count; $n++) {
            $block = CourseBlock::factory()->for($course);
            $n === $count ? $block->current()->create(['number' => $n]) : $block->create(['number' => $n]);
        }

        return $course;
    }

    /** @param  array<int, int>  $daysAndAmount  список [days, amount] */
    private function installmentPlan(User $user, Course $course, array $schedule): array
    {
        $group = (string) Str::uuid();
        $promises = [];
        foreach ($schedule as [$days, $amount]) {
            $promises[] = PaymentPromise::create([
                'user_id' => $user->id, 'course_id' => $course->id,
                'promised_at' => now()->addDays($days)->toDateString(), 'amount' => $amount,
                'status' => PaymentPromise::STATUS_ACTIVE, 'installment_group_id' => $group,
            ]);
        }

        return $promises;
    }

    // ---- Bug 1: installment opens only its block ----------------------------

    /** @test */
    public function paying_one_installment_opens_only_its_block_not_the_whole_course(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithBlocks(4);
        [$p1] = $this->installmentPlan($user, $course, [[-3, 3000], [30, 3000], [60, 3000], [90, 3000]]);

        $this->actingAs($user)
            ->post(route('student.debt.promise.pay', $p1))
            ->assertRedirect('https://pay.tochka.test/x');

        $payment = Payment::where('user_id', $user->id)->where('status', 'pending')->firstOrFail();
        $this->assertSame('block_1', $payment->tariff, 'Взнос №1 открывает блок 1, а не весь курс.');
        $this->assertSame(1, (int) $payment->start_block);
        $this->assertSame(1, (int) $payment->end_block);
    }

    /** @test */
    public function block_grant_unlocks_only_that_block_after_payment(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithBlocks(4);
        $lesson1 = Lesson::factory()->create(['course_id' => $course->id, 'block_number' => 1, 'block_half' => null]);
        $lesson2 = Lesson::factory()->create(['course_id' => $course->id, 'block_number' => 2, 'block_half' => null]);
        [$p1] = $this->installmentPlan($user, $course, [[-3, 3000], [30, 3000], [60, 3000], [90, 3000]]);

        $this->actingAs($user)->post(route('student.debt.promise.pay', $p1))->assertRedirect();
        $payment = Payment::where('user_id', $user->id)->where('status', 'pending')->firstOrFail();
        $payment->update(['status' => 'paid']);

        $this->actingAs($user->fresh())
            ->get(route('student.lesson', [$course->slug, $lesson1->id]))
            ->assertOk();
        // Блок 2 не оплачен → доступа нет (редирект прочь с урока, не 200).
        $this->actingAs($user->fresh())
            ->get(route('student.lesson', [$course->slug, $lesson2->id]))
            ->assertStatus(302);
    }

    /** @test */
    public function paying_the_second_installment_opens_the_second_block(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithBlocks(4);
        [$p1, $p2] = $this->installmentPlan($user, $course, [[-3, 3000], [30, 3000], [60, 3000], [90, 3000]]);

        // Первый взнос уже оплачен реально → блок 1 закрыт из долга.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 3000, 'tariff' => 'block_1', 'status' => 'paid',
            'start_block' => 1, 'end_block' => 1, 'is_conditional' => false,
            'linked_promise_id' => $p1->id,
        ]);

        $this->actingAs($user)->post(route('student.debt.promise.pay', $p2))->assertRedirect();

        $payment = Payment::where('user_id', $user->id)->where('status', 'pending')->firstOrFail();
        $this->assertSame('block_2', $payment->tariff);
        $this->assertSame(2, (int) $payment->start_block);
        $this->assertSame(2, (int) $payment->end_block);
    }

    /** @test */
    public function paying_the_whole_remaining_plan_opens_all_remaining_blocks(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithBlocks(4);
        // Оплачен блок 1; остаток графика — три взноса на блоки 2..4.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 3000, 'tariff' => 'block_1', 'status' => 'paid',
            'start_block' => 1, 'end_block' => 1, 'is_conditional' => false,
        ]);
        $this->installmentPlan($user, $course, [[-3, 3000], [30, 3000], [60, 3000]]);

        $this->actingAs($user)->post(route('student.debt.course.pay-all', $course))->assertRedirect();

        $payment = Payment::where('user_id', $user->id)->where('status', 'pending')->firstOrFail();
        $this->assertSame('block_2', $payment->tariff);
        $this->assertSame(2, (int) $payment->start_block);
        $this->assertSame(4, (int) $payment->end_block);

        // При оплате открываются блоки 2..4 (block_2 + siblings block_3/4).
        $payment->update(['status' => 'paid']);
        $keys = Payment::where('user_id', $user->id)->where('course_id', $course->id)->paid()->pluck('tariff')->all();
        foreach (['block_2', 'block_3', 'block_4'] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    /** @test */
    public function a_solo_promise_without_grant_opens_only_the_first_unpaid_block(): void
    {
        // Solo promise ≠ full: один взнос без кураторского full-гранта = 1 блок.
        $user = User::factory()->create();
        $course = $this->courseWithBlocks(4);
        $promise = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addDays(5)->toDateString(), 'amount' => 5000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)->post(route('student.debt.promise.pay', $promise))->assertRedirect();

        $payment = Payment::where('user_id', $user->id)->where('status', 'pending')->firstOrFail();
        $this->assertSame('block_1', $payment->tariff);
        $this->assertSame(1, (int) $payment->start_block);
        $this->assertSame(1, (int) $payment->end_block);
    }

    /** @test */
    public function self_service_copies_block_scope_from_conditional_grant(): void
    {
        // Куратор открыл «Блок 1» под обещание → pending self-service = block_1, не full.
        $user = User::factory()->create();
        $course = $this->courseWithBlocks(4);
        $promise = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addDays(5)->toDateString(), 'amount' => 4800,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 0, 'tariff' => 'block_1', 'status' => 'paid',
            'start_block' => 1, 'end_block' => 1,
            'is_conditional' => true, 'linked_promise_id' => $promise->id,
            'transaction_id' => 'promise_grant_#'.$promise->id,
        ]));

        $this->actingAs($user)->post(route('student.debt.promise.pay', $promise))->assertRedirect();

        $payment = Payment::where('user_id', $user->id)
            ->where('is_self_service', true)
            ->where('status', 'pending')
            ->firstOrFail();
        $this->assertSame('block_1', $payment->tariff);
        $this->assertSame(1, (int) $payment->start_block);
        $this->assertSame(1, (int) $payment->end_block);
        $this->assertSame(4800.0, (float) $payment->amount);
        $this->assertFalse((bool) $payment->is_conditional);
    }

    /** @test */
    public function self_service_copies_full_scope_when_curator_granted_full(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithBlocks(3);
        $promise = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addDays(5)->toDateString(), 'amount' => 15000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 0, 'tariff' => 'full', 'status' => 'paid',
            'start_block' => null, 'end_block' => null,
            'is_conditional' => true, 'linked_promise_id' => $promise->id,
            'transaction_id' => 'promise_grant_#'.$promise->id,
        ]));

        $this->actingAs($user)->post(route('student.debt.promise.pay', $promise))->assertRedirect();

        $payment = Payment::where('user_id', $user->id)
            ->where('is_self_service', true)
            ->where('status', 'pending')
            ->firstOrFail();
        $this->assertSame('full', $payment->tariff);
        $this->assertNull($payment->start_block);
        $this->assertNull($payment->end_block);
    }

    /** @test */
    public function self_service_copies_multi_block_range_from_conditional_grants(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithBlocks(5);
        $promise = PaymentPromise::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'promised_at' => now()->addDays(5)->toDateString(), 'amount' => 9000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);
        foreach ([2, 3] as $n) {
            Payment::withoutEvents(fn () => Payment::create([
                'user_id' => $user->id, 'course_id' => $course->id,
                'amount' => 0, 'tariff' => 'block_'.$n, 'status' => 'paid',
                'start_block' => $n, 'end_block' => $n,
                'is_conditional' => true, 'linked_promise_id' => $promise->id,
                'transaction_id' => 'promise_grant_#'.$promise->id.'_'.$n,
            ]));
        }

        $this->actingAs($user)->post(route('student.debt.promise.pay', $promise))->assertRedirect();

        $payment = Payment::where('user_id', $user->id)
            ->where('is_self_service', true)
            ->where('status', 'pending')
            ->firstOrFail();
        $this->assertSame('block_2', $payment->tariff);
        $this->assertSame(2, (int) $payment->start_block);
        $this->assertSame(3, (int) $payment->end_block);
    }

    // ---- Bug 2: real bundle tariff -----------------------------------------

    /** @test */
    public function bundle_tariff_purchase_opens_its_block_range(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithBlocks(5);
        foreach ([1, 2, 3, 4, 5] as $n) {
            Lesson::factory()->create(['course_id' => $course->id, 'block_number' => $n, 'block_half' => null]);
        }
        $bundle = Tariff::create([
            'course_id' => $course->id, 'title' => 'Блоки 2–4', 'type' => 'bundle',
            'start_block' => 2, 'end_block' => 4, 'price' => 12000, 'is_active' => true,
        ]);

        // accessKey диапазонного бандла = block_{start}.
        $this->assertSame('block_2', $bundle->accessKey());

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $bundle->id])
            ->assertRedirect();

        $payment = Payment::where('user_id', $user->id)->latest('id')->first();
        $this->assertSame('block_2', $payment->tariff);
        $this->assertSame(2, (int) $payment->start_block);
        $this->assertSame(4, (int) $payment->end_block);

        $payment->update(['status' => 'paid']);
        $keys = Payment::where('user_id', $user->id)->where('course_id', $course->id)->paid()->pluck('tariff')->all();
        foreach (['block_2', 'block_3', 'block_4'] as $key) {
            $this->assertContains($key, $keys);
        }
        $this->assertNotContains('block_1', $keys);
        $this->assertNotContains('block_5', $keys);
    }

    /** @test */
    public function resolver_points_pay_all_at_the_bundle_tariff_checkout_when_one_exists(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithBlocks(4);
        // Оплачены блоки 1,2 → долг = блоки 3,4.
        foreach ([1, 2] as $n) {
            Payment::create([
                'user_id' => $user->id, 'course_id' => $course->id,
                'amount' => 4000, 'tariff' => 'block_'.$n, 'status' => 'paid',
                'start_block' => $n, 'end_block' => $n, 'is_conditional' => false,
            ]);
        }
        Tariff::create(['course_id' => $course->id, 'title' => 'Блок 3', 'type' => 'block', 'block_number' => 3, 'price' => 4000, 'is_active' => true]);
        Tariff::create(['course_id' => $course->id, 'title' => 'Блок 4', 'type' => 'block', 'block_number' => 4, 'price' => 4000, 'is_active' => true]);
        $bundle = Tariff::create([
            'course_id' => $course->id, 'title' => 'Блоки 3–4', 'type' => 'bundle',
            'start_block' => 3, 'end_block' => 4, 'price' => 7000, 'is_active' => true,
        ]);

        $debt = app(StudentDebtsService::class)->forUser($user)->firstWhere('course_id', $course->id);
        $opts = app(DebtPaymentResolver::class)->optionsFor($debt, $user);

        $this->assertNotNull($opts['bundle']);
        $this->assertSame('GET', $opts['bundle']['method']);
        $this->assertStringContainsString('/checkout/'.$bundle->id, $opts['bundle']['url']);
        $this->assertSame(7000.0, $opts['bundle']['amount'], 'Цена бандла — из тарифа, а не сумма блоков.');
    }

    /** @test */
    public function resolver_falls_back_to_pay_bundle_when_no_bundle_tariff(): void
    {
        $user = User::factory()->create();
        $course = $this->courseWithBlocks(4);
        foreach ([1, 2] as $n) {
            Payment::create([
                'user_id' => $user->id, 'course_id' => $course->id,
                'amount' => 4000, 'tariff' => 'block_'.$n, 'status' => 'paid',
                'start_block' => $n, 'end_block' => $n, 'is_conditional' => false,
            ]);
        }
        Tariff::create(['course_id' => $course->id, 'title' => 'Блок 3', 'type' => 'block', 'block_number' => 3, 'price' => 4000, 'is_active' => true]);
        Tariff::create(['course_id' => $course->id, 'title' => 'Блок 4', 'type' => 'block', 'block_number' => 4, 'price' => 4000, 'is_active' => true]);

        $debt = app(StudentDebtsService::class)->forUser($user)->firstWhere('course_id', $course->id);
        $opts = app(DebtPaymentResolver::class)->optionsFor($debt, $user);

        $this->assertNotNull($opts['bundle']);
        $this->assertSame('POST', $opts['bundle']['method']);
        $this->assertSame(8000.0, $opts['bundle']['amount']);
    }
}
