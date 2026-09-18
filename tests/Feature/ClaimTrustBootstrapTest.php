<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H5083 (remediation confirmed H5046) · claim-trusted-autopaid-session-bootstrap-paypal-bank.
 *
 * Regression: auto-trust полуинтегрированных каналов (PayPal/bank claim)
 * привязан к ФАКТУ существующего ученика (User::isEstablishedClaimStudent:
 * возраст аккаунта ≥ 7 дней ИЛИ проведённый платёж), а не к presence-сессии.
 *
 * Прежнее поведение (Nv06): resolveUser минтил аккаунт+сессию любому гостю с
 * новым email; второй POST в ТОЙ ЖЕ сессии проходил auth()->check() →
 * Payment сразу 'paid' → доступ/выручка без движения денег.
 */
class ClaimTrustBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
        Http::fake();

        Config::set('services.paypal.enabled', true);
        Config::set('services.paypal.me_link', 'https://www.paypal.com/paypalme/school');
        Config::set('services.bank_claim.enabled', true);
        Config::set('services.admin.email', 'admin@example.test');
    }

    private function paypalTariff(): Tariff
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'H5083 PP группа']);
        $course->groups()->attach($group->id);

        return Tariff::factory()->for($course)->block(1)->create(['price' => 4800]);
    }

    private function bankTariff(): Tariff
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'H5083 банк группа']);
        $course->groups()->attach($group->id);

        return Tariff::factory()->for($course)->block(1)->create(['price' => 4800]);
    }

    private function paypalPayload(string $email): array
    {
        return [
            'name' => 'Гость H5083',
            'email' => $email,
            'foreign_amount' => 105,
            'foreign_currency' => 'USD',
            'paypal_payer' => 'guest@example.test',
            'paid_on' => now()->subDay()->toDateString(),
        ];
    }

    private function bankPayload(string $email): array
    {
        return [
            'name' => 'Гость H5083',
            'email' => $email,
            'foreign_amount' => '90',
            'foreign_currency' => 'EUR',
            'sender_name' => 'H5083 SENDER',
            'paid_on' => now()->subDay()->toDateString(),
        ];
    }

    /** Проведённый (paid) платёж в прошлом — без модель-событий. */
    private function seedPriorPaidPayment(User $user): Payment
    {
        return Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => 4800,
            'tariff' => 'prior',
            'status' => 'paid',
            'provider' => Payment::PROVIDER_BANK_SEPA,
        ]));
    }

    /** @test */
    public function paypal_second_claim_in_self_minted_session_stays_pending(): void
    {
        $tariff = $this->paypalTariff();

        // POST 1 — гость с новым email: аккаунт+сессия наминчены формой, pending.
        $this->post('/paypal/'.$tariff->id, $this->paypalPayload('h5083pp@example.test'))
            ->assertRedirect();

        $user = User::where('email', 'h5083pp@example.test')->firstOrFail();
        $this->assertSame(auth()->id(), $user->id, 'формой минчена аутентифицированная сессия (как в Nv06)');

        // POST 2 — та же сессия, минуту спустя: сессия больше не основание
        // для auto-trust — аккаунту меньше 7 дней и проведённых платежей нет.
        $this->post('/paypal/'.$tariff->id, $this->paypalPayload('h5083pp@example.test'))
            ->assertRedirect();

        $second = Payment::where('user_id', $user->id)->orderBy('id')->get()->last();
        $this->assertSame('pending', $second->status, 'second-POST bootstrap закрыт: свежеминченный аккаунт не trusted');
        $this->assertFalse((bool) ($second->claim_meta['auto_trusted'] ?? false));
        $this->assertSame(
            0,
            $user->groups()->count(),
            'доступ не открыт: ни одного paid-платежа не создано',
        );
        $this->assertSame(0, Payment::where('user_id', $user->id)->paid()->count());

        Http::assertNothingSent();
    }

    /** @test */
    public function bank_second_claim_in_self_minted_session_stays_pending(): void
    {
        $tariff = $this->bankTariff();

        $this->post('/bank/'.$tariff->id, $this->bankPayload('h5083bank@example.test'))
            ->assertRedirect();
        $this->post('/bank/'.$tariff->id, $this->bankPayload('h5083bank@example.test'))
            ->assertRedirect();

        $user = User::where('email', 'h5083bank@example.test')->firstOrFail();
        $this->assertSame(2, Payment::where('user_id', $user->id)->count());
        $this->assertSame(
            2,
            Payment::where('user_id', $user->id)->where('status', 'pending')->count(),
            'оба банковских запроса из самопроминченной сессии остаются pending',
        );
        $this->assertSame(0, $user->groups()->count());
    }

    /** @test */
    public function paypal_established_student_with_prior_paid_payment_is_trusted(): void
    {
        $tariff = $this->paypalTariff();
        // Свежий по дате аккаунт, но с проведённым платёжом в прошлом —
        // вторая ветка isEstablishedClaimStudent().
        $user = User::factory()->create();
        $this->seedPriorPaidPayment($user);

        $this->actingAs($user)
            ->post('/paypal/'.$tariff->id, [
                'foreign_amount' => 40,
                'foreign_currency' => 'EUR',
                'paypal_payer' => 'payer@example.test',
                'paid_on' => now()->subDay()->toDateString(),
            ])
            ->assertRedirect();

        $payment = Payment::where('user_id', $user->id)
            ->where('provider', Payment::PROVIDER_PAYPAL)
            ->latest()->firstOrFail();

        $this->assertSame('paid', $payment->status, 'prior-paid ученик по-прежнему auto-trusted');
        $this->assertTrue((bool) ($payment->claim_meta['auto_trusted'] ?? false));
        $this->assertTrue(
            $user->fresh()->groups()->where('groups.id', $tariff->course->groups()->first()->id)->exists(),
            'ruлинг 22-08-2026 сохранён: существующему ученику доступ сразу',
        );
    }

    /** @test */
    public function bank_established_student_by_account_age_is_trusted(): void
    {
        $tariff = $this->bankTariff();
        $user = User::factory()->create(['created_at' => now()->subDays(30)]);

        $this->actingAs($user)
            ->post('/bank/'.$tariff->id, [
                'foreign_amount' => '90',
                'foreign_currency' => 'EUR',
                'sender_name' => 'H5083 ESTABLISHED',
                'paid_on' => now()->subDay()->toDateString(),
            ])
            ->assertRedirect();

        $payment = Payment::where('user_id', $user->id)
            ->where('provider', Payment::PROVIDER_BANK_SEPA)
            ->latest()->firstOrFail();

        $this->assertSame('paid', $payment->status, 'аккаунт старше 7 дней без платежей — тоже существующий ученик');
        $this->assertTrue((bool) ($payment->claim_meta['auto_trusted'] ?? false));
        $this->assertSame(
            1,
            $user->fresh()->groups()->where('groups.id', $tariff->course->groups()->first()->id)->count(),
            'доступ открыт штатным конвейером',
        );
    }
}
