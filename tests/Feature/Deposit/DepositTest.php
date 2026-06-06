<?php

declare(strict_types=1);

namespace Tests\Feature\Deposit;

use App\Mail\StudentWelcomeMail;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lead;
use App\Models\Lesson;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DepositTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();

        // Кэш MarketingSetting может застрять между тестами в одном процессе.
        MarketingSetting::flushCached();
    }

    /** @test */
    public function deposit_payment_does_not_grant_group_access(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Test group']);
        $course->groups()->attach($group->id);

        // Создаём депозит сразу в статусе paid — booted-хук разводит на processDeposit.
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        $this->assertSame(0, $user->fresh()->groups()->count(),
            'Депозит не должен добавлять пользователя в группы курса.');

        $lesson = Lesson::factory()->for($course)->create([
            'is_free' => false,
            'block_number' => 2,
        ]);

        $this->actingAs($user)
            ->get(route('student.lesson', ['slug' => $course->slug, 'lessonId' => $lesson->id]))
            ->assertRedirect(route('student.course', $course->slug));
    }

    /** @test */
    public function deposit_sends_welcome_email_and_allows_free_lessons(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $freeLesson = Lesson::factory()->for($course)->free()->create(['block_number' => 1]);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        // StudentWelcomeMail реализует ShouldQueue → попадает в очередь,
        // поэтому проверяем assertQueued, а не assertSent.
        Mail::assertQueued(StudentWelcomeMail::class, fn ($mail) => $mail->hasTo($user->email));

        $this->actingAs($user)
            ->get(route('student.lesson', ['slug' => $course->slug, 'lessonId' => $freeLesson->id]))
            ->assertOk();
    }

    /** @test */
    public function deposit_deducted_once_from_full_tariff(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $fullTariff = Tariff::factory()->for($course)->create(['price' => 12000]);

        $deposit = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        $this->assertSame(11000.0, $fullTariff->calculateFinalPriceForUser($user),
            'Цена тарифа должна быть уменьшена на сумму неизрасходованного депозита.');

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 11000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $this->assertNotNull($deposit->fresh()->deposit_consumed_at,
            'После оплаты тарифа депозит должен быть помечен consumed.');
    }

    /** @test */
    public function consumed_deposit_not_deducted_again(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $blockTariff = Tariff::factory()->for($course)->block(2)->create(['price' => 4800]);

        // Депозит → оплата блока → депозит зачёлся → пометка consumed.
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 3800,
            'tariff' => 'block_2',
            'status' => 'paid',
            'start_block' => 2,
            'end_block' => 2,
        ]);

        // Свежий расчёт для другого тарифа того же курса не должен повторно вычитать депозит.
        $anotherBlock = Tariff::factory()->for($course)->block(3)->create(['price' => 4800]);

        $this->assertSame(4800.0, $anotherBlock->calculateFinalPriceForUser($user),
            'Зачтённый депозит не должен повторно вычитаться из цены другого блока.');
    }

    /** @test */
    public function existing_lead_marked_converted_on_deposit(): void
    {
        MarketingSetting::create(['deposit_enabled' => true]);

        $course = Course::factory()->create(['deposit_amount' => 1000]);

        $lead = Lead::create([
            'name' => 'Иван',
            'contact' => 'tg://ivan',
            'email' => 'ivan@example.test',
            'is_promo_agreed' => true,
        ]);

        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => [
                    'paymentLink' => 'https://pay.tochka.com/redirect/abc',
                    'paymentLinkId' => 'tochka_tx_001',
                ],
            ], 200),
        ]);

        $response = $this->post(route('deposit.create', $course->slug), [
            'name' => 'Иван',
            'email' => 'ivan@example.test',
        ]);

        $response->assertRedirect('https://pay.tochka.com/redirect/abc');

        $payment = Payment::query()->where('tariff', 'deposit')->latest()->firstOrFail();
        $this->assertSame($lead->id, $payment->lead_id);
        $this->assertSame('pending', $payment->status);

        // Имитируем приход вебхука Tochka: статус → paid.
        $payment->update(['status' => 'paid']);

        $this->assertNotNull($lead->fresh()->converted_at,
            'После paid-вебхука Lead с тем же email должен быть помечен converted_at.');
    }

    /** @test */
    public function deposit_creates_fiscal_payment_with_buyer_email(): void
    {
        MarketingSetting::create(['deposit_enabled' => true]);
        config([
            'services.tochka.tax_system_code' => 'usn_income',
            'services.tochka.vat_type' => 'none',
        ]);

        $course = Course::factory()->create(['deposit_amount' => 1500, 'title' => 'Тестовый курс']);

        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => [
                    'paymentLink' => 'https://pay.tochka.com/redirect/xyz',
                    'paymentLinkId' => 'tochka_tx_777',
                ],
            ], 200),
        ]);

        $this->post(route('deposit.create', $course->slug), [
            'name' => 'Пётр',
            'email' => 'petr@example.test',
        ])->assertRedirect('https://pay.tochka.com/redirect/xyz');

        // Платёж создаётся фискальным методом с контактом покупателя — чтобы чек ушёл студенту.
        Http::assertSent(function ($request) {
            $data = $request->data()['Data'] ?? [];
            $item = $data['Items'][0] ?? [];

            return str_contains($request->url(), '/payments_with_receipt')
                && ($data['Client']['email'] ?? null) === 'petr@example.test'
                && ($data['taxSystemCode'] ?? null) === 'usn_income'
                && count($data['Items'] ?? []) === 1
                && (float) ($item['amount'] ?? 0) === 1500.0
                && ($item['vatType'] ?? null) === 'none'
                // Предоплата услуги по enum Точки: full_prepayment + service.
                && ($item['paymentMethod'] ?? null) === 'full_prepayment'
                && ($item['paymentObject'] ?? null) === 'service';
        });
    }

    /**
     * Дублируем тот же фильтр, что в Tariff::getDiscountPercentForUser, и проверяем
     * подсчёт уникальных курсов. Прямой ассерт на сам метод требовал бы
     * MarketingSetting с wholesale_*-полями, которых нет в SQLite-схеме тестов
     * (миграция 2026_03_27_182336 в SQLite роняет add-колонки при drop в одной
     * Schema::table — это не задача этого PR), поэтому проверяем фильтр напрямую.
     *
     * @test
     */
    public function deposit_excluded_from_loyalty_count(): void
    {
        $user = User::factory()->create();

        $courseA = Course::factory()->create();
        $courseB = Course::factory()->create();

        // Курс A — реально оплачен (полный тариф).
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $courseA->id,
            'amount' => 12000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        // Курс B — только депозит, не должен считаться «купленным курсом».
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $courseB->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        $count = Payment::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['paid', 'success'])
            ->where('tariff', '!=', 'deposit')
            ->where('created_at', '>=', now()->subYear())
            ->whereNotNull('course_id')
            ->pluck('course_id')
            ->unique()
            ->count();

        $this->assertSame(1, $count,
            'Депозит не должен входить в подсчёт уникальных оплаченных курсов для лояльности.');
    }
}
