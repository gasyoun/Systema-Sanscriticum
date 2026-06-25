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
    public function referrer_is_rewarded_prana_on_referred_first_payment(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by' => $referrer->id]);

        $this->paidPayment($referred); // observer.created() → reward

        $this->assertDatabaseHas('prana_transactions', [
            'user_id' => $referrer->id,
            'reason' => ReferralService::REWARD_REASON,
        ]);
        $this->assertSame(100, (int) $referrer->fresh()->prana_balance);
    }

    /** @test */
    public function reward_is_granted_only_once_per_referred_student(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by' => $referrer->id]);

        $this->paidPayment($referred);
        $this->paidPayment($referred); // вторая оплата того же приглашённого

        $this->assertSame(1, \App\Models\PranaTransaction::where('reason', ReferralService::REWARD_REASON)->count());
        $this->assertSame(100, (int) $referrer->fresh()->prana_balance);
    }

    /** @test */
    public function reward_fires_on_pending_to_paid_transition(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by' => $referrer->id]);

        $payment = $this->paidPayment($referred, 'pending');
        $this->assertSame(0, (int) $referrer->fresh()->prana_balance);

        $payment->update(['status' => 'paid']); // observer.updated() → reward

        $this->assertSame(100, (int) $referrer->fresh()->prana_balance);
    }

    /** @test */
    public function no_reward_when_student_was_not_referred(): void
    {
        $student = User::factory()->create(['referred_by' => null]);
        $this->paidPayment($student);

        $this->assertSame(0, \App\Models\PranaTransaction::where('reason', ReferralService::REWARD_REASON)->count());
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
