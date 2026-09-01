<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Повторное приглашение в кабинет студентов с выданным доступом, которые
 * никогда не логинились (`login_count = 0`). Популяция — ровно та же, что
 * считает еженедельная сводка OnboardingWeeklyDigest: штамп «[Доступ отправлен»
 * в note + реальный email, НЕ только платившие (см. класс команды).
 *
 * NB: Mail::fake() ставим до создания пользователей — на всякий случай, если
 * какой-то observer тоже шлёт письма.
 */
class SendCabinetInvitesTest extends TestCase
{
    use RefreshDatabase;

    private function sleepingStudentWithAccess(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'note' => '[Доступ отправлен: '.now()->subWeek()->format('d.m.Y').']',
            'login_count' => 0,
        ], $attrs));
    }

    /** @test */
    public function dry_run_does_not_send_or_mark(): void
    {
        $user = $this->sleepingStudentWithAccess(['email' => 'sleeper@example.com']);
        Mail::fake();

        $this->artisan('students:send-login-invites')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertNull($user->fresh()->cabinet_invite_sent_at);
    }

    /** @test */
    public function send_emails_login_link_to_sleeping_student_and_marks_them(): void
    {
        $user = $this->sleepingStudentWithAccess(['email' => 'sleeper@example.com', 'telegram_id' => null]);
        Mail::fake();

        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();

        Mail::assertQueued(PasswordResetMail::class, fn (PasswordResetMail $m) => $m->user->is($user));
        $this->assertNotNull($user->fresh()->cabinet_invite_sent_at);
    }

    /** @test */
    public function students_who_already_logged_in_are_excluded(): void
    {
        $active = $this->sleepingStudentWithAccess(['email' => 'active@example.com', 'login_count' => 3]);
        Mail::fake();

        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertNull($active->fresh()->cabinet_invite_sent_at);
    }

    /** @test */
    public function students_without_access_stamp_are_excluded(): void
    {
        $noAccess = User::factory()->create(['email' => 'noaccess@example.com', 'note' => null, 'login_count' => 0]);
        Mail::fake();

        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertNull($noAccess->fresh()->cabinet_invite_sent_at);
    }

    /** @test */
    public function already_invited_are_skipped_unless_resend(): void
    {
        $user = $this->sleepingStudentWithAccess(['email' => 'sleeper@example.com', 'cabinet_invite_sent_at' => now()->subWeek()]);
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
        $user = $this->sleepingStudentWithAccess(['email' => 'tg@example.com', 'telegram_id' => '123456']);
        Mail::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();

        Mail::assertNotQueued(PasswordResetMail::class);             // не email
        Http::assertSent(fn ($req) => str_contains($req->url(), 'telegram')); // а Telegram
        $this->assertNotNull($user->fresh()->cabinet_invite_sent_at);
    }

    /** @test */
    public function telegram_invite_message_includes_student_guide_link(): void
    {
        $user = $this->sleepingStudentWithAccess(['email' => 'tg@example.com', 'telegram_id' => '123456']);
        Mail::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();

        Http::assertSent(function ($req) {
            $body = json_encode($req->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return str_contains($body, 'руководство')
                && str_contains($body, '/docs/rukovodstvo-studenta.pdf');
        });
    }

    /** @test */
    public function vk_linked_student_without_telegram_gets_invite_via_vk(): void
    {
        $user = $this->sleepingStudentWithAccess(['email' => 'vk@example.com', 'telegram_id' => null, 'vk_id' => '987654']);
        Mail::fake();
        Http::fake(['*' => Http::response(['response' => 1], 200)]);

        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();

        Mail::assertNotQueued(PasswordResetMail::class);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'vk.com'));
        $this->assertNotNull($user->fresh()->cabinet_invite_sent_at);
    }

    /** @test */
    public function sms_channel_used_only_when_configured_and_no_telegram_or_vk(): void
    {
        config(['services.sms_ru.api_id' => 'test-api-id']);
        $user = $this->sleepingStudentWithAccess([
            'email' => 'sms@example.com',
            'telegram_id' => null,
            'vk_id' => null,
            'phone' => '79161234567',
        ]);
        Mail::fake();
        Http::fake(['*' => Http::response(['status' => 'OK', 'sms' => ['79161234567' => ['status' => 'OK']]], 200)]);

        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();

        Mail::assertNotQueued(PasswordResetMail::class);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'sms.ru'));
        $this->assertNotNull($user->fresh()->cabinet_invite_sent_at);
    }
}
