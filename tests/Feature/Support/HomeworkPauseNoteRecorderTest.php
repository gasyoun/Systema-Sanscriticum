<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\User;
use App\Services\StudentChatService;
use App\Services\Support\HomeworkPauseNoteRecorder;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HomeworkPauseNoteRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_requires_homework_and_life_cues(): void
    {
        $r = app(HomeworkPauseNoteRecorder::class);

        $this->assertTrue($r->matches(
            'Намасте с домашкой пока застой, с ребенком на больничном, выбило из колеи. Постараюсь догнать'
        ));
        $this->assertFalse($r->matches('Постараюсь догоню в другую группу с более удобным временем'));
        $this->assertFalse($r->matches('Когда дедлайн по домашке?'));
        $this->assertFalse($r->matches('Ребёнок на больничном, не приду на урок'));
    }

    public function test_record_appends_note_once_per_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 12:00:00', 'Europe/Moscow'));
        config(['app.timezone' => 'Europe/Moscow']);

        $user = User::factory()->create(['note' => '@BakshaevaN']);
        $r = app(HomeworkPauseNoteRecorder::class);
        $text = 'С домашкой пока застой, с ребенком на больничном';

        $this->assertTrue($r->recordIfMatches($user, $text, 'web-chat'));
        $user->refresh();
        $this->assertStringContainsString('[auto-hw-pause:2026-08-06]', $user->note);
        $this->assertStringContainsString('@BakshaevaN', $user->note);
        $this->assertStringContainsString('пауза по ДЗ', $user->note);

        $this->assertFalse($r->recordIfMatches($user, $text.' ещё раз', 'web-chat'));
        $after = $user->fresh()->note;
        $this->assertSame(1, substr_count($after, '[auto-hw-pause:2026-08-06]'));
    }

    public function test_disabled_flag_skips_write(): void
    {
        config(['support.homework_pause_note.enabled' => false]);
        $user = User::factory()->create(['note' => null]);
        $ok = app(HomeworkPauseNoteRecorder::class)->recordIfMatches(
            $user,
            'домашка в застое, больничный ребёнка',
            'web-chat',
        );
        $this->assertFalse($ok);
        $this->assertNull($user->fresh()->note);
    }

    public function test_web_chat_record_incoming_writes_note(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 15:00:00', 'Europe/Moscow'));
        config(['app.timezone' => 'Europe/Moscow']);

        $user = User::factory()->create(['note' => '']);
        app(StudentChatService::class)->recordIncoming(
            $user,
            'Намасте, с домашкой застой — ребёнок на больничном',
        );

        $user->refresh();
        $this->assertStringContainsString('[auto-hw-pause:2026-08-06]', (string) $user->note);
        $this->assertStringContainsString('web-chat', (string) $user->note);
    }

    public function test_telegram_support_sync_writes_note_for_linked_user(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 16:00:00', 'Europe/Moscow'));
        config(['app.timezone' => 'Europe/Moscow']);

        $user = User::factory()->create(['note' => 'keep-me']);
        $account = TelegramSupportAccount::create(['name' => 'support']);
        TelegramSupportChat::create([
            'telegram_chat_id' => 88001,
            'linked_user_id' => $user->id,
            'type' => 'private',
        ]);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => 88001,
                'telegram_message_id' => 42,
                'telegram_user_id' => 90042,
                'direction' => 'incoming',
                'chat_type' => 'private',
                'text' => 'С ДЗ пока застой, выбило из колеи с ребенком',
                'sent_at' => '2026-08-06 16:00:00',
            ],
        ]);

        $user->refresh();
        $this->assertStringContainsString('keep-me', (string) $user->note);
        $this->assertStringContainsString('[auto-hw-pause:2026-08-06]', (string) $user->note);
        $this->assertStringContainsString('telegram-support', (string) $user->note);
    }
}
