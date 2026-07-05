<?php

declare(strict_types=1);

namespace Tests\Feature\Reminders;

use App\Models\ChatMessage;
use App\Models\MarketingSetting;
use App\Models\ReminderSuggestion;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Reminders\ReminderRequestDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReminderRequestDetectorTest extends TestCase
{
    use RefreshDatabase;

    private function fakeExtraction(array $payload): void
    {
        config(['services.openrouter.api_key' => 'test-key']);
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode($payload)]]],
            ], 200),
        ]);
    }

    private function webMessage(User $user, string $text): ChatMessage
    {
        return ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => $text,
            'is_read' => false,
        ]);
    }

    public function test_regex_prefilter_skips_unrelated_message_without_calling_llm(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $this->webMessage($user, 'Когда начинается курс?');

        app(ReminderRequestDetector::class)->run();

        Http::assertNothingSent();
        $this->assertDatabaseCount('reminder_suggestions', 0);
    }

    public function test_candidate_message_creates_suggestion_when_llm_confirms(): void
    {
        $this->fakeExtraction([
            'is_reminder_request' => true,
            'date' => '2026-07-12',
            'reason' => 'Просит написать после 12-го про отзыв о курсе',
            'confidence' => 0.9,
        ]);
        $user = User::factory()->create();
        $this->webMessage($user, 'Напомните мне, пожалуйста, после 12-го числа');

        $result = app(ReminderRequestDetector::class)->run();

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('reminder_suggestions', [
            'user_id' => $user->id,
            'source_type' => ReminderSuggestion::SOURCE_CHAT_MESSAGE,
            'status' => ReminderSuggestion::STATUS_PENDING,
        ]);
    }

    public function test_low_confidence_does_not_create_suggestion(): void
    {
        $this->fakeExtraction([
            'is_reminder_request' => true,
            'date' => null,
            'reason' => 'Неясная просьба',
            'confidence' => 0.2,
        ]);
        $user = User::factory()->create();
        $this->webMessage($user, 'Напомните мне как-нибудь');

        app(ReminderRequestDetector::class)->run();

        $this->assertDatabaseCount('reminder_suggestions', 0);
    }

    public function test_dedup_does_not_create_second_suggestion_for_same_message(): void
    {
        $this->fakeExtraction([
            'is_reminder_request' => true,
            'date' => null,
            'reason' => 'Просит напомнить через неделю',
            'confidence' => 0.8,
        ]);
        $user = User::factory()->create();
        $this->webMessage($user, 'Напишите мне через неделю');

        app(ReminderRequestDetector::class)->run();
        app(ReminderRequestDetector::class)->run();

        $this->assertDatabaseCount('reminder_suggestions', 1);
    }

    public function test_telegram_incoming_message_is_scanned_too(): void
    {
        $this->fakeExtraction([
            'is_reminder_request' => true,
            'date' => null,
            'reason' => 'Просит напомнить, когда вернётся',
            'confidence' => 0.75,
        ]);
        $user = User::factory()->create();
        $account = TelegramSupportAccount::create(['name' => 'support']);
        $chat = TelegramSupportChat::create(['telegram_chat_id' => 9800, 'linked_user_id' => $user->id]);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9800,
            'telegram_message_id' => 1,
            'direction' => 'incoming',
            'text' => 'Напиши мне, когда я вернусь из поездки',
            'sent_at' => now(),
        ]);

        $result = app(ReminderRequestDetector::class)->run();

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('reminder_suggestions', [
            'user_id' => $user->id,
            'source_type' => ReminderSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE,
        ]);
    }

    public function test_disabled_flag_skips_scan_entirely(): void
    {
        MarketingSetting::create(['reminder_detection_enabled' => false]);
        Http::fake();
        $user = User::factory()->create();
        $this->webMessage($user, 'Напомните мне после 12-го');

        $result = app(ReminderRequestDetector::class)->run();

        $this->assertSame(0, $result['scanned']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('reminder_suggestions', 0);
    }
}
