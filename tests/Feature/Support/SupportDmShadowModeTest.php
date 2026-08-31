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
 * H3765 A3: теневой режим.
 *
 * Главное утверждение набора — инвариант §5 контракта плана: пока тень
 * собирает данные, студент не видит НИ ОДНОГО лишнего сообщения. Всё
 * остальное здесь — про условия, при которых событие вообще пишется.
 */
class SupportDmShadowModeTest extends TestCase
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
            // Живой корпус меняется при каждом экспорте из ORS-FAQ; тесты ворот
            // тени берут свой, крошечный и неподвижный.
            'support.faq_rag.path' => base_path('tests/fixtures/faq_shadow_corpus.md'),
            // Ворота проверяем порогом, а не абсолютным скором: пропускающий
            // порог здесь ниже любого попадания, запирающий — выше любого.
            'support.faq_rag.shadow_min_score' => 0.5,
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    private const RECORDING_QUESTION = 'где посмотреть запись пропущенного урока';

    private function incoming(?User $user, string $text): TelegramSupportMessage
    {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support']);
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9501],
            ['linked_user_id' => $user?->id, 'last_message_at' => now()],
        );

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9501,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now(),
        ]);
    }

    public function test_shadow_records_the_would_send_and_sends_nothing_to_the_student(): void
    {
        $user = User::factory()->create();

        $result = app(SupportDmAutoReply::class)->handle(
            $this->incoming($user, self::RECORDING_QUESTION),
            $user->id,
            'private',
        );

        $this->assertSame('hinted', $result['status']);

        $shadow = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_SHADOW_WOULD_SEND)
            ->first();

        $this->assertNotNull($shadow, 'тень обязана записать «отправил бы»');
        $this->assertGreaterThan(0.0, (float) $shadow->meta['score']);
        $this->assertSame('политика-и-поддержка/записи-уроков-и-пропуски', $shadow->meta['chunk_id']);
        $this->assertStringContainsString('Записи занятий появляются', (string) $shadow->meta['draft']);
        $this->assertStringContainsString('Источник', (string) $shadow->meta['draft'], 'цитата FAQ обязательна (R3)');
        $this->assertArrayNotHasKey('question', $shadow->meta, 'текст студента в событие не кладём');

        $this->assertSame(
            0,
            TelegramSupportMessage::query()->where('direction', 'outgoing')->count(),
            'инвариант §5: в тени студенту не уходит ничего',
        );
    }

    public function test_shadow_off_records_nothing(): void
    {
        config(['features.support_dm_auto_reply_shadow' => false]);
        $user = User::factory()->create();

        app(SupportDmAutoReply::class)->handle(
            $this->incoming($user, self::RECORDING_QUESTION),
            $user->id,
            'private',
        );

        $this->assertSame(0, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SHADOW_WOULD_SEND)->count());
    }

    public function test_score_below_the_floor_is_not_recorded(): void
    {
        config(['support.faq_rag.shadow_min_score' => 1000.0]);
        $user = User::factory()->create();

        app(SupportDmAutoReply::class)->handle(
            $this->incoming($user, self::RECORDING_QUESTION),
            $user->id,
            'private',
        );

        $this->assertSame(0, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SHADOW_WOULD_SEND)->count());
    }

    public function test_money_category_never_enters_the_shadow(): void
    {
        // Рулинг R3: D (деньги) исключена, каким бы ни был скор.
        $user = User::factory()->create();

        app(SupportDmAutoReply::class)->handle(
            $this->incoming($user, 'сколько стоит курс и можно ли рассрочку'),
            $user->id,
            'private',
        );

        $this->assertSame(0, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SHADOW_WOULD_SEND)->count());
    }

    public function test_unlinked_student_is_not_shadowed(): void
    {
        app(SupportDmAutoReply::class)->handle(
            $this->incoming(null, self::RECORDING_QUESTION),
            null,
            'private',
        );

        $this->assertSame(0, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SHADOW_WOULD_SEND)->count());
    }

    public function test_repeated_sync_pass_does_not_duplicate_the_shadow_event(): void
    {
        $user = User::factory()->create();
        $incoming = $this->incoming($user, self::RECORDING_QUESTION);

        app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');
        app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame(1, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SHADOW_WOULD_SEND)->count());
    }
}
