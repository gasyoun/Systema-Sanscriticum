<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Jobs\SendTelegramChatMessageJob;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OnboardingNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'services.telegram.onboarding_chat_id' => '-1009998887770',
            'services.telegram.bot_token' => 'test-token',
        ]);
    }

    /** @test */
    public function first_login_pings_onboarding_chat(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'login_count' => 0]);

        event(new Login('web', $user, false));

        Queue::assertPushed(SendTelegramChatMessageJob::class, fn (SendTelegramChatMessageJob $job) => $job->chatId === '-1009998887770'
            && str_contains($job->text, 'Первый вход'));
    }

    /** @test */
    public function second_login_does_not_ping(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'login_count' => 1]);

        event(new Login('web', $user, false));

        Queue::assertNotPushed(SendTelegramChatMessageJob::class);
    }

    /** @test */
    public function admin_first_login_does_not_ping(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'login_count' => 0]);

        event(new Login('web', $admin, false));

        Queue::assertNotPushed(SendTelegramChatMessageJob::class);
    }

    /** @test */
    public function nothing_sent_when_onboarding_chat_not_configured(): void
    {
        config(['services.telegram.onboarding_chat_id' => null]);

        $user = User::factory()->create(['is_admin' => false, 'login_count' => 0]);

        event(new Login('web', $user, false));

        Queue::assertNotPushed(SendTelegramChatMessageJob::class);
    }

    /** @test */
    public function weekly_digest_counts_only_real_email_with_access_sent(): void
    {
        $sent = "\n\n[Доступ отправлен: 01.05.2026 10:00]";

        // 3 — доступ выслан, реальный email, не заходили
        User::factory()->count(3)->create([
            'is_admin' => false, 'login_count' => 0, 'note' => 'студент'.$sent,
        ]);

        // 1 — доступ выслан, реальный email, заходил
        User::factory()->create(['is_admin' => false, 'login_count' => 5, 'note' => 'ок'.$sent]);

        // Доступ НЕ выслан (нет штампа) — исключается
        User::factory()->create(['is_admin' => false, 'login_count' => 0, 'note' => 'просто заметка']);

        // Доступ выслан, но email-заглушка @no-email.com — исключается
        User::factory()->create([
            'is_admin' => false, 'login_count' => 0,
            'email' => 'ivan_1234@no-email.com', 'note' => 'импорт'.$sent,
        ]);

        // Админ со штампом — исключается
        User::factory()->create(['is_admin' => true, 'login_count' => 0, 'note' => 'admin'.$sent]);

        $this->artisan('onboarding:weekly-digest')->assertSuccessful();

        // accessSent = 4 (3 не зашли + 1 зашёл), notEntered = 3 → 75%
        Queue::assertPushed(SendTelegramChatMessageJob::class, function (SendTelegramChatMessageJob $job) {
            return $job->chatId === '-1009998887770'
                && str_contains($job->text, 'Не зашли в кабинет')
                && str_contains($job->text, 'Доступ выслан (реальный email): <b>4</b>')
                && str_contains($job->text, '<b>3</b>')
                && str_contains($job->text, '75%');
        });
    }
}
