<?php

declare(strict_types=1);

namespace Tests\Feature\Promises;

use App\Models\ChatMessage;
use App\Models\MarketingSetting;
use App\Models\PaymentPromiseSuggestion;
use App\Models\PromiseSuggestionDetectionCursor;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Promises\PaymentPromiseDeferralDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentPromiseSuggestionDetectorTest extends TestCase
{
    use RefreshDatabase;

    private function enableFlag(): void
    {
        MarketingSetting::create(['promise_suggestion_detection_enabled' => true]);
        MarketingSetting::flushCached();
    }

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

    public function test_flag_off_creates_zero_suggestions(): void
    {
        // No MarketingSetting row → default OFF
        Http::fake();
        $user = User::factory()->create();
        $this->webMessage($user, 'Оплачу 20 августа');

        $result = app(PaymentPromiseDeferralDetector::class)->run();

        $this->assertSame(0, $result['scanned']);
        $this->assertSame(0, $result['created']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_promise_suggestions', 0);
    }

    public function test_flag_explicitly_false_skips_scan(): void
    {
        MarketingSetting::create(['promise_suggestion_detection_enabled' => false]);
        MarketingSetting::flushCached();
        Http::fake();
        $user = User::factory()->create();
        $this->webMessage($user, 'Оплачу после зарплаты');

        $result = app(PaymentPromiseDeferralDetector::class)->run();

        $this->assertSame(0, $result['scanned']);
        $this->assertDatabaseCount('payment_promise_suggestions', 0);
    }

    public function test_regex_and_llm_create_pending_suggestion(): void
    {
        $this->enableFlag();
        $this->fakeExtraction([
            'is_payment_deferral' => true,
            'date' => '2026-08-20',
            'amount' => 5000,
            'courses_hint' => null,
            'reason' => 'Обещает оплатить 20 августа',
            'confidence' => 0.9,
        ]);
        $user = User::factory()->create();
        $this->webMessage($user, 'Оплачу 20 августа');

        $result = app(PaymentPromiseDeferralDetector::class)->run();

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('payment_promise_suggestions', [
            'user_id' => $user->id,
            'source_type' => PaymentPromiseSuggestion::SOURCE_CHAT_MESSAGE,
            'status' => PaymentPromiseSuggestion::STATUS_PENDING,
        ]);
    }

    public function test_dedup_by_source_type_and_source_id(): void
    {
        $this->enableFlag();
        $this->fakeExtraction([
            'is_payment_deferral' => true,
            'date' => '2026-09-01',
            'amount' => null,
            'reason' => 'Перенос оплаты',
            'confidence' => 0.85,
        ]);
        $user = User::factory()->create();
        $this->webMessage($user, 'Перенесу оплату на сентябрь');

        app(PaymentPromiseDeferralDetector::class)->run();
        // Second run: cursor advanced, same message not rescanned; also explicit dedup.
        // Reset cursor to re-scan same message id and verify unique constraint path.
        PromiseSuggestionDetectionCursor::query()->delete();
        app(PaymentPromiseDeferralDetector::class)->run();

        $this->assertDatabaseCount('payment_promise_suggestions', 1);
    }

    public function test_skips_unlinked_telegram_contact(): void
    {
        $this->enableFlag();
        $this->fakeExtraction([
            'is_payment_deferral' => true,
            'date' => '2026-08-20',
            'reason' => 'Оплата позже',
            'confidence' => 0.9,
        ]);
        $account = TelegramSupportAccount::create(['name' => 'support']);
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 99001,
            'linked_user_id' => null,
        ]);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 99001,
            'telegram_message_id' => 1,
            'direction' => 'incoming',
            'text' => 'Оплачу 20 августа',
            'sent_at' => now(),
        ]);

        $result = app(PaymentPromiseDeferralDetector::class)->run();

        $this->assertSame(0, $result['created']);
        $this->assertDatabaseCount('payment_promise_suggestions', 0);
    }

    public function test_telegram_linked_message_is_scanned(): void
    {
        $this->enableFlag();
        $this->fakeExtraction([
            'is_payment_deferral' => true,
            'date' => '2026-08-25',
            'reason' => 'После зарплаты',
            'confidence' => 0.8,
        ]);
        $user = User::factory()->create();
        $account = TelegramSupportAccount::create(['name' => 'support']);
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 99002,
            'linked_user_id' => $user->id,
        ]);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 99002,
            'telegram_message_id' => 2,
            'direction' => 'incoming',
            'text' => 'Оплачу после зарплаты',
            'sent_at' => now(),
        ]);

        $result = app(PaymentPromiseDeferralDetector::class)->run();

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('payment_promise_suggestions', [
            'user_id' => $user->id,
            'source_type' => PaymentPromiseSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE,
            'status' => PaymentPromiseSuggestion::STATUS_PENDING,
        ]);
    }

    public function test_unrelated_message_skips_llm(): void
    {
        $this->enableFlag();
        Http::fake();
        $user = User::factory()->create();
        $this->webMessage($user, 'Когда начинается следующий урок?');

        app(PaymentPromiseDeferralDetector::class)->run();

        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_promise_suggestions', 0);
    }
}
