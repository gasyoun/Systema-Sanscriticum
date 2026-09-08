<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use danog\MadelineProto\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Support\Doubles\FakeMadelineProtoClient;
use Tests\TestCase;

/**
 * H4416: регрессия DM-аутеджа 31-08…08-09.
 *
 * Root cause: getDialogIds() аккаунта-персоны возвращает 3199 диалогов в
 * порядке, далёком от свежести, а прежний код брал первые dialog_limit штук —
 * личные DM перестали попадать в опрос (статус «ok» каждую минуту), группы
 * выживали только через tech_group_peers. Теперь опрашиваемых пиров собирает
 * союз: активные известные чаты из БД + allowlist + legacy MP-окно.
 */
class TelegramSupportPeerWindowTest extends TestCase
{
    use RefreshDatabase;

    private function skipWithoutMadelineProto(): void
    {
        if (! class_exists(Settings::class)) {
            $this->markTestSkipped('danog/madelineproto не установлен (опциональная зависимость).');
        }
    }

    private function supportConfig(): void
    {
        config([
            'app.timezone' => 'Europe/Moscow',
            'services.telegram_support.enabled' => true,
            'services.telegram_support.api_id' => '12345',
            'services.telegram_support.api_hash' => 'hash',
            'services.telegram_support.client_class' => FakeMadelineProtoClient::class,
            'services.telegram_support.history_limit' => 50,
            'services.telegram_support.dialog_limit' => 20,
            'services.telegram_support.profile_backfill_limit' => 20,
            'services.telegram_support.known_chat_window_days' => 14,
            'services.telegram_support.known_chat_poll_limit' => 120,
            'services.telegram_support.tech_group_peers' => [],
        ]);

        FakeMadelineProtoClient::reset();
        FakeMadelineProtoClient::$lastHistoryRequests = [];
    }

    private int $accountId = 0;

    private function seedAccount(): void
    {
        $this->accountId = (int) TelegramSupportAccount::create([
            'name' => 'support',
            'is_enabled' => true,
        ])->id;
    }

    private function seedIncoming(int $chatId, int $messageId, string $text, string $sentAt): void
    {
        $chat = TelegramSupportChat::firstOrCreate(['telegram_chat_id' => $chatId], ['type' => 'private']);

        TelegramSupportMessage::create([
            'telegram_support_account_id' => $this->accountId,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => $messageId,
            'direction' => 'incoming',
            'role' => 'student',
            'responder_type' => 'none',
            'text' => $text,
            'sent_at' => $sentAt,
        ]);
    }

    public function test_chat_active_in_db_window_is_polled_even_when_mp_dialogs_stale(): void
    {
        $this->skipWithoutMadelineProto();
        $this->supportConfig();
        $this->seedAccount();

        // MP-топ возвращает только старый чат 3001; свежий DM Елены (77701)
        // в MP-окно не попадает — но активен в БД внутри окна.
        FakeMadelineProtoClient::$histories = [
            3001 => [],
            77701 => [
                ['id' => 9001, 'date' => strtotime('2026-09-08 12:00:00'), 'message' => 'Прошу уточнить стоимость за 3 блок', 'peer_id' => 77701, 'from_id' => ['user_id' => 5967791864]],
            ],
        ];

        $this->seedIncoming(77701, 8900, 'старое сообщение', '2026-09-05 10:00:00');

        $result = app(TelegramSupportSyncService::class)->sync();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['synced']);

        $polledPeers = array_map(
            fn (array $request): int => (int) $request['peer'],
            FakeMadelineProtoClient::$lastHistoryRequests,
        );

        $this->assertContains(77701, $polledPeers, 'Известный активный DM-чат обязан попасть в опрос.');
        $this->assertContains(3001, $polledPeers, 'Legacy MP-окно сохраняется для бренд-новых чатов.');

        $message = TelegramSupportMessage::query()
            ->where('telegram_chat_id', 77701)
            ->where('telegram_message_id', 9001)
            ->first();
        $this->assertNotNull($message, 'Свежий DM из известного чата обязан быть записан.');
        $this->assertSame('incoming', $message->direction);
    }

    public function test_chat_outside_window_and_mp_dialogs_is_polled_only_by_catch_up(): void
    {
        $this->skipWithoutMadelineProto();
        $this->supportConfig();
        $this->seedAccount();

        // 20 «свежих для MP» чатов заполняют legacy-окно целиком; 55501 —
        // 21-й, в MP-топ-20 не попадает (как в проде с 3199 диалогами).
        FakeMadelineProtoClient::$histories = [];
        for ($i = 3001; $i <= 3020; $i++) {
            FakeMadelineProtoClient::$histories[$i] = [];
        }
        FakeMadelineProtoClient::$histories[55501] = [
            ['id' => 7001, 'date' => strtotime('2026-07-01 10:00:00'), 'message' => 'вернулся после паузы', 'peer_id' => 55501, 'from_id' => ['user_id' => 7002]],
        ];

        // Последняя активность 20 дней назад — за пределами окна 14 дней.
        $this->seedIncoming(55501, 7000, 'было давно', '2026-08-19 10:00:00');

        app(TelegramSupportSyncService::class)->sync();

        $polledPeers = array_map(
            fn (array $request): int => (int) $request['peer'],
            FakeMadelineProtoClient::$lastHistoryRequests,
        );
        $this->assertNotContains(55501, $polledPeers, 'Чат за окном и вне MP-топа не опрашивается минутным заходом.');

        FakeMadelineProtoClient::$lastHistoryRequests = [];

        $result = app(TelegramSupportSyncService::class)->sync('support', 30);

        $this->assertSame('ok', $result['status']);
        $polledPeers = array_map(
            fn (array $request): int => (int) $request['peer'],
            FakeMadelineProtoClient::$lastHistoryRequests,
        );
        $this->assertContains(55501, $polledPeers, 'Catch-up обязан опросить чат, оживший в пределах его окна.');

        $message = TelegramSupportMessage::query()
            ->where('telegram_chat_id', 55501)
            ->where('telegram_message_id', 7001)
            ->first();
        $this->assertNotNull($message, 'Catch-up обязан донести сообщение ожившего чата.');
    }

    public function test_tech_group_allowlist_peer_is_polled_once(): void
    {
        $this->skipWithoutMadelineProto();
        $this->supportConfig();
        $this->seedAccount();

        config(['services.telegram_support.tech_group_peers' => ['-1003671345641']]);

        FakeMadelineProtoClient::$histories = [
            '-1003671345641' => [],
        ];

        app(TelegramSupportSyncService::class)->sync();

        $polledPeers = array_map(
            fn (array $request): int|string => $request['peer'],
            FakeMadelineProtoClient::$lastHistoryRequests,
        );
        $this->assertSame(
            1,
            count(array_filter($polledPeers, fn ($p): bool => (string) $p === '-1003671345641')),
            'Allowlist-пир опрашивается ровно один раз (дедуп союза).',
        );
    }

    public function test_zero_window_disables_db_union_legacy_behaviour(): void
    {
        $this->skipWithoutMadelineProto();
        $this->supportConfig();
        $this->seedAccount();
        config(['services.telegram_support.known_chat_window_days' => 0]);

        FakeMadelineProtoClient::$histories = [
            3001 => [],
        ];

        $this->seedIncoming(77701, 8900, 'старое сообщение', '2026-09-05 10:00:00');

        app(TelegramSupportSyncService::class)->sync();

        $polledPeers = array_map(
            fn (array $request): int => (int) $request['peer'],
            FakeMadelineProtoClient::$lastHistoryRequests,
        );
        $this->assertNotContains(77701, $polledPeers, 'Окно 0 = наследное поведение: БД-союз выключен.');
        $this->assertContains(3001, $polledPeers, 'MP-окно остаётся единственным источником.');
    }

    public function test_poll_count_is_logged_for_observability(): void
    {
        $this->skipWithoutMadelineProto();
        $this->supportConfig();
        $this->seedAccount();

        FakeMadelineProtoClient::$histories = [
            3001 => [],
        ];
        $this->seedIncoming(77701, 8900, 'старое сообщение', '2026-09-05 10:00:00');

        $log = Log::spy();

        $result = app(TelegramSupportSyncService::class)->sync();

        $this->assertSame('ok', $result['status']);
        $log->shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            return $message === 'Telegram support sync finished'
                && isset($context['peers_polled'])
                && $context['peers_polled'] >= 1;
        });
    }
}
