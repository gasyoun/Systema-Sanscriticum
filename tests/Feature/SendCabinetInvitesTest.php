<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\SendCabinetInvites;
use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Повторное приглашение в кабинет студентов с выданным доступом, которые
 * никогда не логинились (`login_count = 0`). Популяция — ровно та же, что
 * считает еженедельная сводка OnboardingWeeklyDigest: штамп «[Доступ отправлен»
 * в note + реальный email, НЕ только платившие (см. класс команды).
 *
 * H4966: ссылка теперь multi-day `MagicLinkToken` (cabinet_invite), не
 * 60-минутный брокер сброса пароля — email-ветка шлёт Mail::raw() вместо
 * App\Mail\PasswordResetMail, поэтому шлём/проверяем Mailable::class (анонимный
 * класс от Mail::raw).
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

        Mail::assertNothingSent();
        $this->assertNull($user->fresh()->cabinet_invite_sent_at);
    }

    /** @test */
    public function send_emails_login_link_to_sleeping_student_and_marks_them(): void
    {
        $user = $this->sleepingStudentWithAccess(['email' => 'sleeper@example.com', 'telegram_id' => null]);
        Mail::fake();

        $this->artisan('students:send-login-invites', ['--send' => true])
            ->expectsOutputToContain('Отправлено')
            ->assertSuccessful();

        Mail::assertSent(Mailable::class);
        $this->assertNotNull($user->fresh()->cabinet_invite_sent_at);
    }

    /**
     * H4966: раньше письмо несло 60-минутную ссылку сброса пароля
     * (config('auth.passwords.users.expire') = 60) — 84.4% из 269 приглашённых
     * так и не вошли. Теперь ссылка живёт INVITE_TTL_MINUTES (7 дней) и остаётся
     * валидной далеко за пределами часа.
     */
    /** @test */
    public function invite_link_stays_valid_well_past_sixty_minutes(): void
    {
        $user = $this->sleepingStudentWithAccess(['email' => 'sleeper@example.com', 'telegram_id' => null]);

        $token = MagicLinkToken::issueFor($user, SendCabinetInvites::INVITE_PURPOSE, SendCabinetInvites::INVITE_TTL_MINUTES);

        $this->travel(90)->minutes(); // за пределами 60-минутного брокера сброса пароля

        $link = MagicLinkToken::findActive($token, SendCabinetInvites::INVITE_PURPOSE);
        $this->assertNotNull($link, 'Ссылка-приглашение должна оставаться живой через 90 минут');

        $response = $this->get(route('cabinet.invite', $token));
        $response->assertRedirect(route('student.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    /** @test */
    public function students_who_already_logged_in_are_excluded(): void
    {
        $active = $this->sleepingStudentWithAccess(['email' => 'active@example.com', 'login_count' => 3]);
        Mail::fake();

        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull($active->fresh()->cabinet_invite_sent_at);
    }

    /** @test */
    public function students_without_access_stamp_are_excluded(): void
    {
        $noAccess = User::factory()->create(['email' => 'noaccess@example.com', 'note' => null, 'login_count' => 0]);
        Mail::fake();

        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull($noAccess->fresh()->cabinet_invite_sent_at);
    }

    /** @test */
    public function recently_invited_are_skipped_unless_resend(): void
    {
        $user = $this->sleepingStudentWithAccess(['email' => 'sleeper@example.com', 'cabinet_invite_sent_at' => now()->subDays(2)]);
        Mail::fake();

        // Без --resend, свежая отправка (2 дня < окна auto-resend) — пропускается.
        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();
        Mail::assertNothingSent();

        // С --resend — приглашается снова немедленно.
        $this->artisan('students:send-login-invites', ['--send' => true, '--resend' => true])->assertSuccessful();
        Mail::assertSent(Mailable::class);
    }

    /**
     * H4966 SHIP §4: `cabinet_invite_sent_at` больше не постоянное исключение —
     * не заходившего после AUTO_RESEND_AFTER_DAYS снова подхватывает батч БЕЗ
     * ручного --resend (232 никогда не заходивших live 16-09-2026).
     */
    /** @test */
    public function never_logged_in_student_becomes_reinvitable_after_auto_resend_window(): void
    {
        $user = $this->sleepingStudentWithAccess([
            'email' => 'sleeper@example.com',
            'cabinet_invite_sent_at' => now()->subDays(SendCabinetInvites::AUTO_RESEND_AFTER_DAYS + 1),
        ]);
        Mail::fake();

        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();

        Mail::assertSent(Mailable::class, fn (Mailable $m) => $m->hasTo($user->email));
        $this->assertTrue($user->fresh()->cabinet_invite_sent_at->isAfter(now()->subMinute()));
    }

    /** @test */
    public function telegram_linked_student_gets_invite_via_telegram_not_email(): void
    {
        $user = $this->sleepingStudentWithAccess(['email' => 'tg@example.com', 'telegram_id' => '123456']);
        Mail::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();

        Mail::assertNothingSent();             // не email
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
                && str_contains($body, '/help/kabinet');
        });
    }

    /** @test */
    public function include_no_stamp_adds_never_logged_users_without_access_stamp(): void
    {
        $noStamp = User::factory()->create(['email' => 'nostamp@example.com', 'note' => null, 'login_count' => 0]);
        Mail::fake();

        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();
        Mail::assertNothingSent();

        $this->artisan('students:send-login-invites', ['--send' => true, '--include-no-stamp' => true])->assertSuccessful();
        Mail::assertSent(Mailable::class, fn (Mailable $m) => $m->hasTo($noStamp->email));
        $this->assertNotNull($noStamp->fresh()->cabinet_invite_sent_at);
    }

    /** @test */
    public function vk_linked_student_without_telegram_gets_invite_via_vk(): void
    {
        $user = $this->sleepingStudentWithAccess(['email' => 'vk@example.com', 'telegram_id' => null, 'vk_id' => '987654']);
        Mail::fake();
        Http::fake(['*' => Http::response(['response' => 1], 200)]);

        $this->artisan('students:send-login-invites', ['--send' => true])->assertSuccessful();

        Mail::assertNothingSent();
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

        Mail::assertNothingSent();
        Http::assertSent(fn ($req) => str_contains($req->url(), 'sms.ru'));
        $this->assertNotNull($user->fresh()->cabinet_invite_sent_at);
    }
}
