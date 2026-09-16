<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PurchaseConfirmationMail;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H5024 (MG Q12, 16-09-2026): личная ссылка-приглашение (User::referralLink())
 * на публичной /verify под сертификатом и в письме об оплате — обе поверхности
 * за config('partner.enabled'). При OFF — байт-в-байт как раньше.
 */
class ReferralInviteSurfacesH5024Test extends TestCase
{
    use RefreshDatabase;

    private function certificateFor(User $user): Certificate
    {
        $course = Course::factory()->create(['title' => 'Основы санскрита']);

        return Certificate::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'student_name' => 'Иван Петров',
            'course_title' => $course->title,
            'issued_at' => now(),
        ]);
    }

    private function paidPaymentFor(User $user): Payment
    {
        $course = Course::factory()->create(['title' => 'Санскрит с нуля']);

        return Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]));
    }

    /** @test */
    public function verify_page_shows_holder_referral_link_when_partner_flag_on(): void
    {
        config(['partner.enabled' => true, 'referral.credit_amount' => 500]);
        $holder = User::factory()->create();
        $cert = $this->certificateFor($holder);

        $response = $this->get('/verify/'.$cert->number);

        $response->assertOk()
            ->assertSee('data-testid="certificate-referral-invite"', false)
            ->assertSee('Пригласить в школу')
            ->assertSee($holder->fresh()->referralLink())
            ->assertSee('в знак благодарности');

        $html = $response->getContent();
        foreach (['награда', 'бонус', 'заработок', 'только сегодня', 'успей', 'осталось мест'] as $token) {
            $this->assertStringNotContainsStringIgnoringCase($token, $html, "verify: token «{$token}»");
        }
        // Счётчиков social-proof на публичной странице нет (MG Q12: без счётчиков).
        $this->assertStringNotContainsString('Пришли по вашей рекомендации', $html);
    }

    /** @test */
    public function verify_page_hides_referral_block_when_partner_flag_off(): void
    {
        config(['partner.enabled' => false]);
        $holder = User::factory()->create();
        $cert = $this->certificateFor($holder);

        $this->get('/verify/'.$cert->number)
            ->assertOk()
            ->assertSee($cert->number)
            ->assertDontSee('data-testid="certificate-referral-invite"', false)
            ->assertDontSee('Пригласить в школу');

        $this->assertNull($holder->fresh()->referral_code, 'код не должен генерироваться при выключенном флаге');
    }

    /** @test */
    public function not_found_verify_page_has_no_referral_block_even_when_flag_on(): void
    {
        config(['partner.enabled' => true]);

        $this->get('/verify/2099-ZZZZZ')
            ->assertOk()
            ->assertSee('Сертификат не найден')
            ->assertDontSee('data-testid="certificate-referral-invite"', false);
    }

    /** @test */
    public function purchase_confirmation_carries_referral_link_when_partner_flag_on(): void
    {
        config(['partner.enabled' => true, 'referral.credit_amount' => 500]);
        $user = User::factory()->create(['name' => 'Мария']);
        $payment = $this->paidPaymentFor($user);

        $html = (new PurchaseConfirmationMail($payment))->render();

        $this->assertStringContainsString($user->fresh()->referralLink(), $html);
        $this->assertStringContainsString('ссылка-приглашение', $html);
        $this->assertStringContainsString('500 ₽', $html);

        // Тот же голосовой контракт, что и у PurchaseOnboardingSequenceTest.
        foreach (['🎉', '🚀', '✨', '🙏', '🎁', '!!!', 'только сегодня', 'успей', 'Спешите', 'осталось мест'] as $token) {
            $this->assertStringNotContainsString($token, $html, "mail: token «{$token}»");
        }
        $this->assertStringNotContainsString('ё', str_replace('всё', '', $html), 'mail: ё вне исключения «всё»');
    }

    /** @test */
    public function purchase_confirmation_has_no_referral_block_when_partner_flag_off(): void
    {
        config(['partner.enabled' => false]);
        $user = User::factory()->create(['name' => 'Мария']);
        $payment = $this->paidPaymentFor($user);

        $html = (new PurchaseConfirmationMail($payment))->render();

        $this->assertStringNotContainsString('ссылка-приглашение', $html);
        $this->assertStringNotContainsString('/?ref=', $html);
        $this->assertNull($user->fresh()->referral_code);
    }
}
