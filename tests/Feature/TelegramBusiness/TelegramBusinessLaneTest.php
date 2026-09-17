<?php

declare(strict_types=1);

namespace Tests\Feature\TelegramBusiness;

use App\Jobs\ProcessTelegramBusinessUpdate;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\TelegramBusiness\BusinessSupportReplyDrainer;
use App\Services\TelegramBusiness\TelegramBusinessNormalizer;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * H5065 — полоса Telegram Business: бот управляет чатом и отвечает ОТ ИМЕНИ
 * аккаунта.
 *
 * Набор проверяет не «эндпоинт отвечает 200», а четыре свойства, каждое из
 * которых по отдельности делает полосу бесполезной или опасной:
 *  1. секрет fail-closed (пустой секрет — это 403, а не «пропускаем проверку»);
 *  2. владелец аккаунта не считается студентом (иначе бот отвечает на
 *     собственные сообщения школы);
 *  3. без строки подключения сообщение пропускается — иначе классификация
 *     «кто автор» невозможна и автоответ уйдёт наугад;
 *  4. ответ уходит с `business_connection_id` — то есть от имени аккаунта,
 *     а не от бота, и только после клейма TelegramSendGuard.
 */
class TelegramBusinessLaneTest extends TestCase
{
    use RefreshDatabase;

    private const STUDENT_ID = 555000111;

    private const OWNER_ID = 777000222;

    private const CONNECTION_ID = 'bconn-test-1';

    private const MATERIALS_QUESTION = 'куда загружать домашнее задание и в каком формате';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.telegram_business_bot' => true,
            'features.support_dm_auto_reply' => true,
            'features.support_dm_auto_reply_live_faq' => true,
            'features.support_auto_reply_templates' => false,
            'services.telegram_business.token' => 'business-test-token',
            'services.telegram_business.secret' => 'business-test-secret',
            'services.telegram_business.account_name' => 'telegram-business',
            'support.faq_rag.path' => base_path('tests/fixtures/faq_live_f_corpus.md'),
            'support.faq_rag.extra_paths' => [],
            'support.faq_rag.live_categories' => ['F'],
            'support.faq_rag.shadow_min_score' => 0.5,
            'support.faq_rag.shadow_min_score_by_category' => [],
        ]);

        TelegramSupportAccount::query()->create([
            'name' => 'telegram-business',
            'is_enabled' => true,
            'auto_reply_enabled' => true,
        ]);
    }

    public function test_webhook_is_not_found_while_the_flag_is_off(): void
    {
        config(['features.telegram_business_bot' => false]);

        $this->postJson('/api/webhooks/telegram-business', ['update_id' => 1])->assertNotFound();
    }

    public function test_webhook_rejects_a_wrong_or_missing_secret(): void
    {
        $this->postJson('/api/webhooks/telegram-business', ['update_id' => 1])->assertForbidden();
        $this->postJson('/api/webhooks/telegram-business', ['update_id' => 1], [
            'X-Telegram-Bot-Api-Secret-Token' => 'nope',
        ])->assertForbidden();
    }

    public function test_webhook_accepts_the_right_secret_and_queues_the_update(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->postJson(
            '/api/webhooks/telegram-business',
            $this->connectionUpdate(),
            ['X-Telegram-Bot-Api-Secret-Token' => 'business-test-secret'],
        )->assertOk();

        $this->assertDatabaseHas('telegram_business_connections', [
            'business_connection_id' => self::CONNECTION_ID,
            'owner_telegram_user_id' => self::OWNER_ID,
            'can_reply' => true,
            'is_enabled' => true,
        ]);
    }

    public function test_business_connection_update_is_recorded_and_revocation_keeps_the_row(): void
    {
        $this->runUpdate($this->connectionUpdate());

        $connection = TelegramBusinessConnection::query()->firstOrFail();
        $this->assertTrue($connection->can_reply);
        $this->assertNull($connection->disabled_at);

        // Владелец отозвал право ответа: строка остаётся (история подключений),
        // но usable() обязан перестать её отдавать — иначе бот продолжит
        // пытаться отвечать от имени аккаунта.
        $this->runUpdate([
            'update_id' => 101,
            'business_connection' => [
                'id' => self::CONNECTION_ID,
                'user' => ['id' => self::OWNER_ID],
                'user_chat_id' => self::OWNER_ID,
                'date' => time(),
                'rights' => ['can_reply' => false],
                'is_enabled' => true,
            ],
        ]);

        $connection->refresh();
        $this->assertFalse($connection->can_reply);
        $this->assertNull(TelegramBusinessConnection::usable(self::CONNECTION_ID));
    }

    public function test_legacy_top_level_can_reply_still_counts_when_rights_are_absent(): void
    {
        // Старые payload'ы (и тесты вокруг них) несут право верхним полем без
        // объекта rights: полоса обязана остаться рабочей, а не «право не
        // найдено».
        $this->runUpdate([
            'update_id' => 150,
            'business_connection' => [
                'id' => self::CONNECTION_ID,
                'user' => ['id' => self::OWNER_ID],
                'user_chat_id' => self::OWNER_ID,
                'date' => time(),
                'can_reply' => true,
                'is_enabled' => true,
            ],
        ]);

        $this->assertNotNull(TelegramBusinessConnection::usable(self::CONNECTION_ID));
    }

    public function test_student_message_is_ingested_and_answered_from_the_account(): void
    {
        $this->fakeTelegram();
        $this->runUpdate($this->connectionUpdate());
        $this->runUpdate($this->studentMessageUpdate(200));

        $incoming = TelegramSupportMessage::query()->where('direction', 'incoming')->firstOrFail();
        $this->assertSame(self::MATERIALS_QUESTION, (string) $incoming->text);
        // Подключение едет вместе с входящим: из него дренаж выводит, ОТ ЧЬЕГО
        // имени отвечать. Без него ответ встал бы в очередь и не ушёл.
        $this->assertSame(
            self::CONNECTION_ID,
            (string) (($incoming->raw_payload ?? [])['business_connection_id'] ?? ''),
        );

        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->first();
        $this->assertNotNull($outgoing, 'автоответ обязан быть поставлен в очередь');
        $this->assertGreaterThan(0, (int) $outgoing->telegram_message_id, 'после досыла placeholder заменён настоящим id');
        $this->assertFalse((bool) (($outgoing->raw_payload ?? [])['pending_delivery'] ?? true));

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $body = $request->data();

            return ($body['business_connection_id'] ?? null) === self::CONNECTION_ID
                && (int) ($body['chat_id'] ?? 0) === self::STUDENT_ID
                && str_contains((string) ($body['text'] ?? ''), 'Источник');
        });
    }

    public function test_owner_message_is_not_treated_as_a_student_question(): void
    {
        $this->fakeTelegram();
        $this->runUpdate($this->connectionUpdate());
        $this->runUpdate($this->ownerMessageUpdate(300));

        $this->assertSame(
            0,
            TelegramSupportMessage::query()->where('direction', 'incoming')->count(),
            'сообщение владельца — не вопрос студента',
        );
        $this->assertSame(
            1,
            TelegramSupportMessage::query()->where('direction', 'outgoing')->count(),
            'ответ человека виден в ленте как исходящее',
        );
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage'));
    }

    public function test_message_without_a_known_connection_is_skipped(): void
    {
        $this->fakeTelegram();
        // Подключение не приходило: отличить студента от владельца нельзя.
        $this->runUpdate($this->studentMessageUpdate(400));

        $this->assertSame(0, TelegramSupportMessage::query()->count());
    }

    public function test_duplicate_update_id_is_processed_once(): void
    {
        $this->fakeTelegram();
        Redis::shouldReceive('set')->andReturn(true, false, true, true, true, true);

        $update = $this->connectionUpdate();
        $this->runUpdate($update);
        $this->runUpdate($update);

        $this->assertSame(1, TelegramBusinessConnection::query()->count());
    }

    public function test_api_rejection_leaves_the_reply_pending_for_a_retry(): void
    {
        // Telegram ответил отказом: доставки не было, клейм снят, попытка
        // посчитана — сообщение остаётся ждущим, а не «отправленным».
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400),
        ]);

        $this->runUpdate($this->connectionUpdate());
        $this->runUpdate($this->studentMessageUpdate(500));

        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->first();
        $this->assertNotNull($outgoing);
        $this->assertLessThan(0, (int) $outgoing->telegram_message_id, 'placeholder остался: доставки не было');
        $this->assertTrue((bool) (($outgoing->raw_payload ?? [])['pending_delivery'] ?? false));
        $this->assertSame(1, (int) (($outgoing->raw_payload ?? [])['delivery_attempts'] ?? 0));
    }

    private function fakeTelegram(): void
    {
        Http::fake([
            'api.telegram.org/*sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 4242]], 200),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }

    private function runUpdate(array $update): void
    {
        app(ProcessTelegramBusinessUpdate::class, ['update' => $update])
            ->handle(
                app(TelegramBusinessNormalizer::class),
                app(TelegramSupportSyncService::class),
                app(BusinessSupportReplyDrainer::class),
            );
    }

    /** @return array<string, mixed> */
    private function connectionUpdate(): array
    {
        return [
            'update_id' => 100,
            'business_connection' => [
                'id' => self::CONNECTION_ID,
                'user' => ['id' => self::OWNER_ID, 'first_name' => 'Owner'],
                'user_chat_id' => self::OWNER_ID,
                'date' => time(),
                // Право ответа — в объекте rights (BusinessBotRights.can_reply),
                // как требует документация Business-ботов. Верхнеуровневого
                // can_reply здесь НЕТ намеренно: если код снова начнёт читать
                // только его, полоса окажется «подключена, но не может отвечать».
                'rights' => ['can_reply' => true, 'can_read_messages' => true],
                'is_enabled' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function studentMessageUpdate(int $updateId): array
    {
        $this->ensureStudent();

        return [
            'update_id' => $updateId,
            'business_message' => [
                'message_id' => $updateId,
                'date' => time(),
                'business_connection_id' => self::CONNECTION_ID,
                'chat' => ['id' => self::STUDENT_ID, 'type' => 'private', 'first_name' => 'Студент'],
                'from' => ['id' => self::STUDENT_ID, 'first_name' => 'Студент'],
                'text' => self::MATERIALS_QUESTION,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function ownerMessageUpdate(int $updateId): array
    {
        return [
            'update_id' => $updateId,
            'business_message' => [
                'message_id' => $updateId,
                'date' => time(),
                'business_connection_id' => self::CONNECTION_ID,
                'chat' => ['id' => self::STUDENT_ID, 'type' => 'private', 'first_name' => 'Студент'],
                'from' => ['id' => self::OWNER_ID, 'first_name' => 'Owner'],
                'text' => 'Добрый день! Отвечаю лично.',
            ],
        ];
    }

    private function ensureStudent(): User
    {
        return User::factory()->create(['telegram_id' => self::STUDENT_ID]);
    }
}
