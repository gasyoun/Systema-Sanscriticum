<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportDmAutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3766 B5 — покатегорийный порог теневой автоотправки.
 *
 * Пины здесь два: (1) арифметика — категорийный порог строже общего и
 * применяется именно к своей категории; (2) сами числа из config/support.php,
 * выведенные `php artisan faq:score-floor` на 100-вопросном наборе. Второе
 * важно потому, что понижение этих чисел напрямую увеличивает риск отправить
 * студенту неверный ответ, и такая правка обязана быть осознанной.
 */
class ShadowScoreFloorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.support_dm_auto_reply' => true,
            'features.support_dm_auto_reply_shadow' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
            'support.faq_rag.path' => base_path('tests/fixtures/faq_shadow_corpus.md'),
            'support.faq_rag.shadow_min_score' => 0.5,
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    public function test_configured_floors_are_the_ones_derived_on_the_100q_set(): void
    {
        $floors = config('support.faq_rag.shadow_min_score_by_category');

        $this->assertSame(
            ['A' => 18.1, 'B' => 14.8, 'C' => 19.4, 'F' => 15.7],
            $floors,
            'пороги выводит `php artisan faq:score-floor --min-coverage=0.20`; менять их вручную нельзя',
        );

        $this->assertArrayNotHasKey('D', $floors, 'D (деньги) исключена рулингом R3, порога у неё быть не должно');
        $this->assertArrayNotHasKey('E', $floors, 'E (доступы) исключена рулингом R3, порога у неё быть не должно');
    }

    public function test_category_floor_locks_the_shadow_even_when_the_global_floor_is_open(): void
    {
        config(['support.faq_rag.shadow_min_score_by_category' => ['B' => 1000.0]]);

        $this->handleRecordingQuestion();

        $this->assertSame(0, $this->shadowEvents(), 'категорийный порог обязан перекрывать общий');
    }

    public function test_a_floor_for_another_category_does_not_lock_this_one(): void
    {
        config(['support.faq_rag.shadow_min_score_by_category' => ['A' => 1000.0]]);

        $this->handleRecordingQuestion();

        $this->assertSame(1, $this->shadowEvents(), 'порог чужой категории не должен запирать эту');
    }

    private function handleRecordingQuestion(): void
    {
        $user = User::factory()->create();
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support']);
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9601],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );

        $incoming = TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9601,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => 'где посмотреть запись пропущенного урока',
            'sent_at' => now(),
        ]);

        app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');
    }

    private function shadowEvents(): int
    {
        return SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_SHADOW_WOULD_SEND)
            ->count();
    }
}
