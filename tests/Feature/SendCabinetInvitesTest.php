<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Повторное приглашение в кабинет «спящих» оплативших студентов (никогда не
 * логинились). Исходное письмо с паролем многие не заметили (спам).
 *
 * NB: создание `paid`-платежа дёргает PaymentObserver (welcome-письма), поэтому
 * Mail::fake() ставим ПОСЛЕ настройки — ловим только письма самой команды.
 */
class SendCabinetInvitesTest extends TestCase
{
    use RefreshDatabase;

    private function paidStudent(array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(['last_login_at' => null], $attrs));
        Payment::create([
            'user_id' => $user->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        return $user;
    }

    /** @test */
    public function dry_run_does_not_send_or_mark(): void
    {
        $user = $this->paidStudent(['email' => 'sleeper@example.com']);
        Mail::fake();

        $this->artisan('students:send-login-invites')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertNull($user->fresh()->cabinet_invite_sent_at);
    }

    /** @test */
    public function send_emails_login_link_to_sleeping_paid_student_and_marks_them(): void
    {
        $user = $this->paidStudent(['email' => 'sleeper@example.com', 'telegram_id' => null]);
        Mail::fake();

        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();

        Mail::assertQueued(PasswordResetMail::class, fn (PasswordResetMail $m) => $m->user->is($user));
        $this->assertNotNull($user->fresh()->cabinet_invite_sent_at);
    }

    /** @test */
    public function students_who_already_logged_in_are_excluded(): void
    {
        $active = $this->paidStudent(['email' => 'active@example.com', 'last_login_at' => now()->subDay()]);
        Mail::fake();

        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertNull($active->fresh()->cabinet_invite_sent_at);
    }

    /** @test */
    public function students_without_paid_payment_are_excluded(): void
    {
        $noPay = User::factory()->create(['email' => 'nopay@example.com', 'last_login_at' => null]);
        Mail::fake();

        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertNull($noPay->fresh()->cabinet_invite_sent_at);
    }

    /** @test */
    public function already_invited_are_skipped_unless_resend(): void
    {
        $user = $this->paidStudent(['email' => 'sleeper@example.com', 'cabinet_invite_sent_at' => now()->subWeek()]);
        Mail::fake();

        // Без --resend — пропускается.
        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();
        Mail::assertNothingQueued();

        // С --resend — приглашается снова.
        $this->artisan('students:send-login-invites', ['--send' => true, '--resend' => true])->assertSuccessful();
        Mail::assertQueued(PasswordResetMail::class);
    }

    /** @test */
    public function telegram_linked_student_gets_invite_via_telegram_not_email(): void
    {
        $user = $this->paidStudent(['email' => 'tg@example.com', 'telegram_id' => '123456']);
        Mail::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();

        Mail::assertNotQueued(PasswordResetMail::class);             // не email
        Http::assertSent(fn ($req) => str_contains($req->url(), 'telegram')); // а Telegram
        $this->assertNotNull($user->fresh()->cabinet_invite_sent_at);
    }
}
