<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\DebtWinBackAttempt;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Reactivation\WinBackSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Отправка шаблона реактивации + журнал попытки (H221): логируем канал, не
 * дублируем в окне 24 ч, отвечаем «нет каналов», когда писать некуда.
 */
class WinBackSenderTest extends TestCase
{
    use RefreshDatabase;

    private function sender(): WinBackSender
    {
        return app(WinBackSender::class);
    }

    /** @test */
    public function sending_logs_attempt_with_channel(): void
    {
        Mail::fake();
        // Только email-канал: без телеграм/вк, чтобы не дёргать мессенджер-джобу.
        $user = User::factory()->create(['email' => 'ghost@example.com']);
        $tpl = MessageTemplate::factory()->create();

        $res = $this->sender()->send($user->id, null, null, $tpl, null);

        $this->assertSame('sent', $res['status']);
        $this->assertContains('email', $res['channels']);
        $this->assertDatabaseHas('debt_win_back_attempts', [
            'user_id' => $user->id,
            'template_id' => $tpl->id,
            'channel' => 'email',
        ]);
    }

    /** @test */
    public function second_send_within_24h_is_skipped(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'ghost2@example.com']);
        $tpl = MessageTemplate::factory()->create();

        DebtWinBackAttempt::create([
            'user_id' => $user->id,
            'template_id' => $tpl->id,
            'channel' => 'email',
            'sent_at' => now()->subHours(2),
        ]);

        $res = $this->sender()->send($user->id, null, null, $tpl, null);

        $this->assertSame('skipped', $res['status']);
        $this->assertSame(1, DebtWinBackAttempt::where('user_id', $user->id)->count());
    }

    /** @test */
    public function no_channels_when_no_contacts(): void
    {
        $user = User::factory()->create(['email' => 'not-an-email', 'telegram_id' => null, 'vk_id' => null]);
        $tpl = MessageTemplate::factory()->create();

        $res = $this->sender()->send($user->id, null, null, $tpl, null);

        $this->assertSame('no_channels', $res['status']);
        $this->assertDatabaseCount('debt_win_back_attempts', 0);
    }
}
