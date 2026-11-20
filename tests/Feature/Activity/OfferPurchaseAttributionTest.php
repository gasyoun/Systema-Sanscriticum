<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Observers\PaymentTelemetryObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H7968 — атрибуция «клик по офферу кабинета → подтверждённая оплата»,
 * флаг features.cabinet_offer_attribution (default OFF).
 *
 * Обсервер вызывается напрямую (как это делает зарегистрированный
 * PaymentTelemetryObserver): прочие Payment-обсерверы (рефералы, джобы,
 * deal-bridge) вне скоупа атрибуции и не должны мешать её приёмке.
 */
class OfferPurchaseAttributionTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00'));
        config()->set('features.cabinet_offer_attribution', true);
        config()->set('features.cabinet_offer_attribution_window_days', 7);
        config()->set('conversion.excluded_tariffs', ['Расход', 'salary_payout', 'deposit', 'trial']);

        $this->course = Course::factory()->create([
            'title' => 'Санскрит',
            'slug' => 'sanskrit-offer-attr',
            'is_visible' => true,
        ]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function click(array $data = [], ?Carbon $at = null): int
    {
        return (int) DB::table('activity_events')->insertGetId([
            'user_id' => $this->user->id,
            'session_id' => null,
            'event_type' => ActivityEvent::OFFER_CLICK,
            'event_data' => json_encode($data ?: [
                'kind' => 'next-block',
                'course_id' => (string) $this->course->id,
            ], JSON_UNESCAPED_UNICODE),
            'url' => null,
            'ip_address' => null,
            'created_at' => $at ?? now(),
        ]);
    }

    private function payment(array $attrs = [], string $status = 'pending'): Payment
    {
        return Payment::withoutEvents(fn (): Payment => Payment::create(array_merge([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'amount' => 10000,
            'tariff' => 'full',
            'status' => $status,
            'is_conditional' => false,
        ], $attrs)));
    }

    /**
     * Вебхук подтвердил статус: сохраняем без чужих обсерверов, затем гоняем
     * телеметрию ровно как зарегистрированный PaymentTelemetryObserver.
     */
    private function confirm(Payment $payment, string $status = 'paid'): void
    {
        $payment->status = $status;
        Payment::withoutEvents(fn (): bool => $payment->save());

        app(PaymentTelemetryObserver::class)->updated($payment);
    }

    private function attributedCount(): int
    {
        return DB::table('activity_events')
            ->where('user_id', $this->user->id)
            ->where('event_type', ActivityEvent::OFFER_PURCHASE)
            ->count();
    }

    /** @test */
    public function eligible_successful_payment_emits_exactly_one_attributed_purchase(): void
    {
        $clickId = $this->click(['kind' => 'next-block', 'course_id' => (string) $this->course->id]);
        $payment = $this->payment();
        $this->confirm($payment);

        $this->assertSame(1, $this->attributedCount());

        $event = DB::table('activity_events')
            ->where('event_type', ActivityEvent::OFFER_PURCHASE)
            ->first();
        $data = (array) json_decode((string) $event->event_data, true);
        $this->assertSame((int) $payment->id, (int) $data['payment_id']);
        $this->assertSame($clickId, (int) $data['click_id']);
        $this->assertSame('next-block', $data['offer_kind']);
    }

    /** @test */
    public function repeated_callbacks_do_not_duplicate_attribution(): void
    {
        $this->click();
        $payment = $this->payment();
        $this->confirm($payment);

        // Повторная доставка callback (duplicate / reordered webhook delivery).
        app(PaymentTelemetryObserver::class)->updated($payment);
        app(PaymentTelemetryObserver::class)->updated($payment);
        $this->confirm($payment, 'paid');

        $this->assertSame(1, $this->attributedCount());
    }

    /** @test */
    public function born_paid_payment_attributes_once_via_created(): void
    {
        $this->click();
        $payment = $this->payment([], 'paid');

        $observer = app(PaymentTelemetryObserver::class);
        $observer->created($payment);
        $observer->created($payment);

        $this->assertSame(1, $this->attributedCount());
    }

    /** @test */
    public function unpaid_failed_and_refunded_before_confirmation_do_not_count(): void
    {
        $this->click();

        // Не подтверждён: остался pending (callback статус не изменил).
        $pending = $this->payment();
        app(PaymentTelemetryObserver::class)->updated($pending);
        $this->assertSame(0, $this->attributedCount());

        // Подтверждение не наступило: failed и canceled/refund-до-paid.
        $this->confirm($this->payment(), 'failed');
        $this->confirm($this->payment(), 'canceled');
        $this->confirm($this->payment(), 'refunded');

        $this->assertSame(0, $this->attributedCount());
    }

    /** @test */
    public function unrelated_click_does_not_count(): void
    {
        $other = Course::factory()->create(['slug' => 'other-course-offer-attr']);
        $this->click(['kind' => 'next-block', 'course_id' => (string) $other->id]);

        $this->confirm($this->payment());

        $this->assertSame(0, $this->attributedCount());
    }

    /** @test */
    public function expired_click_does_not_count(): void
    {
        $this->click(['kind' => 'next-block', 'course_id' => (string) $this->course->id], now()->subDays(8));

        $this->confirm($this->payment());

        $this->assertSame(0, $this->attributedCount());
    }

    /** @test */
    public function flag_off_preserves_previous_behavior(): void
    {
        config()->set('features.cabinet_offer_attribution', false);
        $this->click();
        $payment = $this->payment([], 'paid');

        $observer = app(PaymentTelemetryObserver::class);
        $observer->created($payment);
        $this->confirm($payment);

        $this->assertSame(0, $this->attributedCount());

        $this->artisan('cabinet:baseline', ['--days' => 14])
            ->expectsOutputToContain('OFF — offer.purchase не пишется')
            ->assertExitCode(0);
    }

    /** @test */
    public function report_aggregates_and_separates_observed_from_causal_lift(): void
    {
        // Одна атрибутированная пара + один «висячий» клик без покупки.
        $this->click(['kind' => 'next-block', 'course_id' => (string) $this->course->id]);
        $this->confirm($this->payment());
        $this->click(['kind' => 'ownership', 'course_id' => (string) $this->course->id]);

        $this->artisan('cabinet:baseline', ['--days' => 14])
            ->expectsOutputToContain('offer.purchase')
            ->expectsOutputToContain('Клики офферов (offer.click) за окно отчёта: 2, из них без атрибуции: 1')
            ->expectsOutputToContain('НЕ причинный revenue lift')
            ->expectsOutputToContain('окно «клик → подтверждение оплаты» — 7 сут.')
            ->assertExitCode(0);
    }
}
