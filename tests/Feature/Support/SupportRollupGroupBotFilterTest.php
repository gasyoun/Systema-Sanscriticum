<?php

namespace Tests\Feature\Support;

use App\Models\SupportDailyRollup;
use App\Models\TelegramSupportChat;
use App\Services\Support\SupportParityReportBuilder;
use App\Services\TelegramSupport\SupportDailyRollupAggregator;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H6645 — фильтр бот-автопостов учебных групп в TG-rollup метрике.
 *
 * Контракт (явный, задокументирован в класс-докблоке агрегатора): входящие
 * от ботов (username с суффиксом «bot») в group/supergroup не считаются
 * поддержкой — это автопостинг; приватные DM фильтр не затрагивает;
 * человеческий групповой трафик остаётся в метрике; день только из
 * автопостов — не разговор поддержки (строки нет, устаревший снимок
 * до-H6645 пересчётом убирается). SLA-отклик меряется от человеческого
 * вопроса, не от автопоста.
 */
class SupportRollupGroupBotFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_bot_autopost_only_group_day_is_not_a_support_conversation_and_recompute_removes_stale_row(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => 50001,
                'telegram_message_id' => 1,
                'telegram_user_id' => 900001,
                'contact_name' => 'Study Group Bot',
                'contact_username' => 'StudyGroupBot',
                'chat_type' => 'supergroup',
                'chat_title' => 'Учебная группа',
                'direction' => 'incoming',
                'text' => 'Анонс: занятие завтра в 18:00.',
                'sent_at' => '2026-10-13 09:00:00',
            ],
        ]);

        $chat = TelegramSupportChat::where('telegram_chat_id', 50001)->firstOrFail();

        // День только из бот-автопостов не создаёт разговор поддержки.
        $this->assertSame(0, SupportDailyRollup::where('telegram_support_chat_id', $chat->id)->count());

        // Пересчёт старого снимка (до H6645) убирает устаревшую строку — иначе
        // окно SLA-паритета не сходилось бы с правилом.
        DB::table('support_daily_rollups')->insert([
            'telegram_support_chat_id' => $chat->id,
            'conversation_date' => '2026-10-13',
            'incoming_count' => 1,
            'is_unanswered' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(SupportDailyRollupAggregator::class)->aggregateDate('2026-10-13');

        $this->assertSame(0, SupportDailyRollup::where('telegram_support_chat_id', $chat->id)->count());
    }

    public function test_group_bot_autopost_excluded_but_human_group_chatter_still_counts(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => 50002,
                'telegram_message_id' => 1,
                'telegram_user_id' => 900001,
                'contact_username' => 'StudyGroupBot',
                'chat_type' => 'supergroup',
                'direction' => 'incoming',
                'text' => 'Анонс дня.',
                'sent_at' => '2026-10-12 09:00:00',
            ],
            [
                'telegram_chat_id' => 50002,
                'telegram_message_id' => 2,
                'telegram_user_id' => 900002,
                'contact_name' => 'Nina Student',
                'chat_type' => 'supergroup',
                'direction' => 'incoming',
                'text' => 'Когда урок?',
                'sent_at' => '2026-10-12 10:00:00',
            ],
        ]);

        $conversation = SupportDailyRollup::whereDate('conversation_date', '2026-10-12')
            ->whereHas('chat', fn ($query) => $query->where('telegram_chat_id', 50002))
            ->firstOrFail();

        // Автопост не в счёте, человеческое — в счёте; день без ответа —
        // честный unanswered, а не замётанный.
        $this->assertSame(1, $conversation->incoming_count);
        $this->assertTrue($conversation->is_unanswered);
    }

    public function test_first_response_measured_from_human_ask_not_bot_autopost(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => 50003,
                'telegram_message_id' => 1,
                'telegram_user_id' => 900001,
                'contact_username' => 'StudyGroupBot',
                'chat_type' => 'supergroup',
                'direction' => 'incoming',
                'text' => 'Анонс: расписание.',
                'sent_at' => '2026-10-11 09:00:00',
            ],
            [
                'telegram_chat_id' => 50003,
                'telegram_message_id' => 2,
                'telegram_user_id' => 900002,
                'chat_type' => 'supergroup',
                'direction' => 'incoming',
                'text' => 'Просьба: перенесите занятие.',
                'sent_at' => '2026-10-11 10:00:00',
            ],
            [
                'telegram_chat_id' => 50003,
                'telegram_message_id' => 3,
                'direction' => 'outgoing',
                'text' => 'Перенесли, увидите в кабинете.',
                'sent_at' => '2026-10-11 10:05:00',
            ],
        ]);

        $conversation = SupportDailyRollup::whereDate('conversation_date', '2026-10-11')
            ->whereHas('chat', fn ($query) => $query->where('telegram_chat_id', 50003))
            ->firstOrFail();

        $this->assertSame(1, $conversation->incoming_count);
        $this->assertSame(1, $conversation->outgoing_count);
        $this->assertFalse($conversation->is_unanswered);
        // SLA-паритет: отклик от человеческого вопроса (10:00), не от автопоста (09:00).
        $this->assertSame(300, $conversation->first_response_seconds);
    }

    public function test_private_chat_sender_with_bot_like_username_is_not_filtered(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => 50004,
                'telegram_message_id' => 1,
                'telegram_user_id' => 900003,
                'contact_username' => 'nina_bot',
                'chat_type' => 'private',
                'direction' => 'incoming',
                'text' => 'Как оплатить курс?',
                'sent_at' => '2026-10-12 12:00:00',
            ],
        ]);

        $conversation = SupportDailyRollup::whereDate('conversation_date', '2026-10-12')
            ->whereHas('chat', fn ($query) => $query->where('telegram_chat_id', 50004))
            ->firstOrFail();

        // Приватные DM фильтр не затрагивает: в DM бот-входящие невозможны,
        // а человеческий username с суффиксом «bot» не должен теряться.
        $this->assertSame(1, $conversation->incoming_count);
        $this->assertTrue($conversation->is_unanswered);
    }

    public function test_parity_channel_mix_shows_zero_unanswered_with_live_group_incomings(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);

        $botDay = now()->subDays(2)->timezone('Europe/Moscow')->startOfDay();

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            // День группы: только автопосты — в parity не должен дать unanswered.
            [
                'telegram_chat_id' => 50005,
                'telegram_message_id' => 1,
                'telegram_user_id' => 900001,
                'contact_username' => 'StudyGroupBot',
                'chat_type' => 'supergroup',
                'direction' => 'incoming',
                'text' => 'Анонс недели.',
                'sent_at' => $botDay->copy()->setTime(9, 0)->format('Y-m-d H:i:s'),
            ],
            [
                'telegram_chat_id' => 50005,
                'telegram_message_id' => 2,
                'telegram_user_id' => 900001,
                'contact_username' => 'StudyGroupBot',
                'chat_type' => 'supergroup',
                'direction' => 'incoming',
                'text' => 'Материалы занятия загружены.',
                'sent_at' => $botDay->copy()->setTime(9, 5)->format('Y-m-d H:i:s'),
            ],
            // Живое человеческое DM с ответом — канал живой, а не вымерший.
            [
                'telegram_chat_id' => 50006,
                'telegram_message_id' => 1,
                'telegram_user_id' => 900002,
                'chat_type' => 'private',
                'direction' => 'incoming',
                'text' => 'Здравствуйте!',
                'sent_at' => $botDay->copy()->setTime(10, 0)->format('Y-m-d H:i:s'),
            ],
            [
                'telegram_chat_id' => 50006,
                'telegram_message_id' => 2,
                'direction' => 'outgoing',
                'text' => 'Здравствуйте! Чем помочь?',
                'sent_at' => $botDay->copy()->setTime(10, 2)->format('Y-m-d H:i:s'),
            ],
        ]);

        $report = app(SupportParityReportBuilder::class)->build(14);

        $telegram = $report['channel_mix']['telegram'];

        // Групповые входящие живы, но unanswered = 0: автопосты шумом не считаются.
        $this->assertGreaterThan(0, $telegram['incoming']);
        $this->assertSame(0, $telegram['unanswered']);
        $this->assertSame(0, $telegram['unresolved_after_hours']);
    }
}
