<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\CaptureReferral;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ReferralProgramTest extends TestCase
{
    use RefreshDatabase;

    private function paidPayment(User $user, string $status = 'paid'): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => $status,
        ]);
    }

    /** @test */
    public function referral_code_is_generated_lazily_and_is_unique(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->assertNotEmpty($a->referralCode());
        $this->assertSame($a->referralCode(), $a->fresh()->referral_code);
        $this->assertNotSame($a->referralCode(), $b->referralCode());
    }

    /** @test */
    public function attach_referrer_links_by_code_and_guards_edge_cases(): void
    {
        $referrer = User::factory()->create();
        $code = $referrer->referralCode();
        $service = app(ReferralService::class);

        // self-код игнорируется
        $service->attachReferrer($referrer, $code);
        $this->assertNull($referrer->fresh()->referred_by);

        // невалидный код игнорируется
        $new = User::factory()->create();
        $service->attachReferrer($new, 'NOPE');
        $this->assertNull($new->fresh()->referred_by);

        // валидный код привязывает
        $service->attachReferrer($new, $code);
        $this->assertSame($referrer->id, $new->fresh()->referred_by);

        // повторно не перезаписывает
        $other = User::factory()->create();
        $service->attachReferrer($new, $other->referralCode());
        $this->assertSame($referrer->id, $new->fresh()->referred_by);
    }

    /** @test */
    public function referrer_is_rewarded_credit_on_referred_first_payment(): void
    {
        config(['referral.credit_amount' => 500]);
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by' => $referrer->id]);

        $this->paidPayment($referred); // observer.created() → reward

        $this->assertDatabaseHas('referral_rewards', [
            'referrer_id' => $referrer->id,
            'referred_id' => $referred->id,
            'amount' => '500.00',
        ]);
        $this->assertSame(500.0, (float) $referrer->fresh()->referral_credit);
    }

    /** @test */
    public function reward_is_granted_only_once_per_referred_student(): void
    {
        config(['referral.credit_amount' => 500]);
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by' => $referrer->id]);

        $this->paidPayment($referred);
        $this->paidPayment($referred); // вторая оплата того же приглашённого

        $this->assertSame(1, \App\Models\ReferralReward::where('referred_id', $referred->id)->count());
        $this->assertSame(500.0, (float) $referrer->fresh()->referral_credit);
    }

    /** @test */
    public function reward_fires_on_pending_to_paid_transition(): void
    {
        config(['referral.credit_amount' => 500]);
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by' => $referrer->id]);

        $payment = $this->paidPayment($referred, 'pending');
        $this->assertSame(0.0, (float) $referrer->fresh()->referral_credit);

        $payment->update(['status' => 'paid']); // observer.updated() → reward

        $this->assertSame(500.0, (float) $referrer->fresh()->referral_credit);
    }

    /** @test */
    public function no_reward_when_student_was_not_referred(): void
    {
        $student = User::factory()->create(['referred_by' => null]);
        $this->paidPayment($student);

        $this->assertSame(0, \App\Models\ReferralReward::count());
    }

    /**
     * Регрессия (money-core, H071 #3): награда — только за ПЕРВУЮ РЕАЛЬНУЮ ОПЛАТУ
     * КУРСА. Не курсовые/бесплатные оплаченные события не должны минтить кредит и
     * сжигать разовую награду (unique referred_id).
     *
     * @test
     *
     * @dataProvider nonQualifyingPayments
     */
    public function no_reward_on_non_course_payments(array $attributes): void
    {
        config(['referral.credit_amount' => 500]);
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by' => $referrer->id]);

        Payment::create(array_merge([
            'user_id' => $referred->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ], $attributes));

        // Ни награды, ни кредита, ни занятого разового слота.
        $this->assertSame(0, \App\Models\ReferralReward::where('referred_id', $referred->id)->count());
        $this->assertSame(0.0, (float) $referrer->fresh()->referral_credit);
    }

    public static function nonQualifyingPayments(): array
    {
        return [
            'deposit' => [['tariff' => 'deposit']],
            'trial' => [['tariff' => 'trial']],
            'expense' => [['tariff' => 'Расход', 'amount' => -1000]],
            'salary_payout' => [['tariff' => 'salary_payout', 'amount' => -5000]],
            'conditional promise (0₽)' => [['amount' => 0, 'is_conditional' => true]],
            'zero-amount order' => [['amount' => 0]],
            'no course_id' => [['course_id' => null]],
        ];
    }

    /** @test */
    public function real_course_purchase_after_non_qualifying_still_rewards(): void
    {
        // Разовый слот НЕ должен быть сожжён не курсовым событием: после депозита
        // первая настоящая оплата курса всё-таки награждает пригласившего.
        config(['referral.credit_amount' => 500]);
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by' => $referrer->id]);

        Payment::create([
            'user_id' => $referred->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => 2000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);
        $this->assertSame(0.0, (float) $referrer->fresh()->referral_credit);

        $this->paidPayment($referred); // реальная оплата курса

        $this->assertSame(500.0, (float) $referrer->fresh()->referral_credit);
    }

    /** @test */
    public function reward_is_clawed_back_when_referred_payment_is_reversed(): void
    {
        // Регрессия (money-core, H071 #12): при откате платежа приглашённого
        // (paid → failed/canceled) награда рефереру не снималась — он держал
        // реальный кредит за отменённую покупку, а строка ReferralReward навсегда
        // блокировала будущую награду за этого приглашённого.
        config(['referral.credit_amount' => 500]);
        $referrer = User::factory()->create(['referral_credit' => 0]);
        $referred = User::factory()->create(['referred_by' => $referrer->id]);

        $payment = $this->paidPayment($referred);
        $this->assertSame(500.0, (float) $referrer->fresh()->referral_credit);

        // Откат оплаты (вебхук отмены/возврата).
        $payment->update(['status' => 'failed']);

        // Кредит снят, строка награды удалена — разовый слот свободен.
        $this->assertSame(0.0, (float) $referrer->fresh()->referral_credit);
        $this->assertSame(0, \App\Models\ReferralReward::where('referred_id', $referred->id)->count());

        // Новая настоящая оплата приглашённого снова награждает.
        $this->paidPayment($referred);
        $this->assertSame(500.0, (float) $referrer->fresh()->referral_credit);
    }

    /** @test */
    public function clawback_floors_referrer_credit_at_zero_when_already_spent(): void
    {
        config(['referral.credit_amount' => 500]);
        $referrer = User::factory()->create(['referral_credit' => 0]);
        $referred = User::factory()->create(['referred_by' => $referrer->id]);

        $payment = $this->paidPayment($referred); // +500
        // Реферер потратил 300 из 500 до отмены.
        $referrer->forceFill(['referral_credit' => 200])->save();

        $payment->update(['status' => 'canceled']);

        // Не уходим в минус: max(0, 200 − 500) = 0.
        $this->assertSame(0.0, (float) $referrer->fresh()->referral_credit);
    }

    /** @test */
    public function middleware_stores_ref_code_in_session(): void
    {
        $request = Request::create('/landing?ref=ABC123', 'GET');
        $request->setLaravelSession($this->app['session']->driver());

        (new CaptureReferral)->handle($request, fn ($r) => response('ok'));

        $this->assertSame('ABC123', $request->session()->get('ref'));
    }
}
