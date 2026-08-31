<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportDmAutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3765 A5: кнопка «Отправить как есть» под подсказкой куратору.
 *
 * Проверяем три вещи, каждая из которых уже ломалась в этом контуре:
 * авторизацию нажавшего, отправку РОВНО ОДИН раз (двойной тап + клейм
 * TelegramSendGuard) и отказ на протухшем черновике.
 */
class SupportHintSendButtonTest extends TestCase
{
    use RefreshDatabase;

    private const CURATOR_TG = '777001';

    private const OUTSIDER_TG = '777999';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.support_dm_auto_reply' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
            'support.faq_rag.path' => base_path('tests/fixtures/faq_shadow_corpus.md'),
            // Вебхук fail-closed: без секрета любой апдейт получает 403.
            'services.telegram.bot_webhook_secret' => 'test-tg',
        ]);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-tg');

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    /**
     * Подсказка порождается настоящим конвейером — так тест заодно доказывает,
     * что кнопка вообще появляется, а не только что обработчик её понимает.
     *
     * @return array{0: SupportAnswerSuggestion, 1: User}
     */
    private function hintWithButton(): array
    {
        $account = TelegramSupportAccount::firstOrCreate(
            ['name' => 'support'],
            ['hint_recipients' => [self::CURATOR_TG]],
        );
        $account->forceFill(['hint_recipients' => [self::CURATOR_TG]])->save();

        $student = User::factory()->create();
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9601],
            ['linked_user_id' => $student->id, 'last_message_at' => now()],
        );

        $incoming = TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9601,
            'telegram_message_id' => 4242,
            'direction' => 'incoming',
            'text' => 'где посмотреть запись пропущенного урока',
            'sent_at' => now(),
        ]);

        app(SupportDmAutoReply::class)->handle($incoming, $student->id, 'private');

        $suggestion = SupportAnswerSuggestion::query()
            ->where('source_type', SupportAnswerSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE)
            ->where('source_id', $incoming->id)
            ->firstOrFail();

        return [$suggestion, $student];
    }

    private function tap(SupportAnswerSuggestion $suggestion, string $fromId): void
    {
        $this->postJson('/api/telegram/webhook', [
            'update_id' => random_int(1, 1_000_000_000),
            'callback_query' => [
                'id' => 'cb-'.random_int(1, 1_000_000),
                'data' => SupportDmAutoReply::SEND_CALLBACK_PREFIX.$suggestion->id,
                'from' => ['id' => $fromId],
                'message' => ['chat' => ['id' => $fromId]],
            ],
        ])->assertOk();
    }

    public function test_hint_carries_an_inline_send_button(): void
    {
        [$suggestion] = $this->hintWithButton();

        $this->assertSame(SupportAnswerSuggestion::STATUS_PENDING, $suggestion->status);
        $this->assertStringContainsString('Записи занятий появляются', (string) $suggestion->draft_text);

        Http::assertSent(function ($request) use ($suggestion): bool {
            $keyboard = $request->data()['reply_markup'] ?? null;

            return is_string($keyboard)
                && str_contains($keyboard, SupportDmAutoReply::SEND_CALLBACK_PREFIX.$suggestion->id);
        });
    }

    public function test_curator_tap_queues_the_draft_to_the_student_once(): void
    {
        [$suggestion, $student] = $this->hintWithButton();

        $this->tap($suggestion, self::CURATOR_TG);

        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->get();
        $this->assertCount(1, $outgoing);
        $this->assertStringContainsString('Записи занятий появляются', (string) $outgoing->first()->text);

        $suggestion->refresh();
        $this->assertSame(SupportAnswerSuggestion::STATUS_ACCEPTED, $suggestion->status);
        $this->assertNotNull($suggestion->resolved_at);
        $this->assertSame($student->id, (int) $suggestion->user_id);

        $this->assertSame(
            1,
            SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINT_SEND_TAPPED)->count(),
        );
    }

    public function test_second_tap_sends_nothing_more(): void
    {
        [$suggestion] = $this->hintWithButton();

        $this->tap($suggestion, self::CURATOR_TG);
        $this->tap($suggestion, self::CURATOR_TG);

        $this->assertSame(1, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
        $this->assertSame(
            1,
            SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINT_SEND_TAPPED)->count(),
        );
    }

    public function test_outsider_tap_sends_nothing(): void
    {
        [$suggestion] = $this->hintWithButton();

        $this->tap($suggestion, self::OUTSIDER_TG);

        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
        $suggestion->refresh();
        $this->assertSame(SupportAnswerSuggestion::STATUS_PENDING, $suggestion->status);
    }

    public function test_stale_draft_refuses_and_expires_instead_of_answering_a_week_late(): void
    {
        [$suggestion] = $this->hintWithButton();

        config(['support.hint_send_button_max_age_days' => 7]);
        $suggestion->forceFill(['created_at' => now()->subDays(9)])->save();

        $this->tap($suggestion, self::CURATOR_TG);

        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
        $suggestion->refresh();
        $this->assertSame(SupportAnswerSuggestion::STATUS_EXPIRED, $suggestion->status);
    }
}
