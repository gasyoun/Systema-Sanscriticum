<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\SupportAiReplyEvent;
use App\Models\Tariff;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\PendingSupportReplyDrainer;
use App\Services\Support\SupportDmAutoReply;
use App\Services\Telegram\MadelineSessionContext;
use App\Services\Telegram\MadelineSyncPhase;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use danog\MadelineProto\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\Doubles\FakeMadelineProtoClient;
use Tests\TestCase;

/**
 * H3380: проба автоответов на втором аккаунте (rusamskrtam) — шаблонные
 * ответы D/E/F по привязке S9, ack на остальное, пер-аккаунтный гейт и
 * пер-сессийные замок/фазы.
 */
class AutoReplyTrialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.support_dm_auto_reply' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    private function account(bool $autoReply): TelegramSupportAccount
    {
        return TelegramSupportAccount::create([
            'name' => 'rusamskrtam',
            'auto_reply_enabled' => $autoReply,
        ]);
    }

    private function incoming(User $user, string $text, ?TelegramSupportAccount $account = null): TelegramSupportMessage
    {
        $account ??= $this->account(true);
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9101],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9101,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now(),
        ]);
    }

    private function boundTemplate(string $category): MessageTemplate
    {
        return MessageTemplate::query()->create([
            'title' => 'Поддержка · тест '.$category,
            'body' => "Намасте, {name}!\n\nТестовый шаблон категории {$category}.",
            'category' => MessageTemplate::CATEGORY_SUPPORT,
            'suggester_category' => $category,
            'is_active' => true,
        ]);
    }

    /**
     * Прод, 30-09-2026: чат видят два аккаунта (обычный + полоса
     * telegram-business с бОльшим id). Ответ брал max(account_id) и застревал
     * в полосе с «нет business_connection_id у чата». Отвечаем тем аккаунтом,
     * куда пришёл вопрос.
     */
    public function test_auto_reply_goes_out_through_the_account_that_received_the_question(): void
    {
        config(['features.support_auto_reply_templates' => true]);
        $this->boundTemplate('D');

        $user = User::factory()->create(['name' => 'Студент Тест']);
        $support = $this->account(true);
        $incoming = $this->incoming($user, 'сколько стоит курс и как оплатить', $support);

        // Та же переписка, но записанная второй полосой (id больше).
        $business = TelegramSupportAccount::create(['name' => 'telegram-business', 'auto_reply_enabled' => false]);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $business->id,
            'telegram_support_chat_id' => $incoming->telegram_support_chat_id,
            'telegram_chat_id' => 9101,
            'telegram_message_id' => random_int(1_000_001, 2_000_000),
            'direction' => 'incoming',
            'text' => 'сколько стоит курс и как оплатить',
            'sent_at' => now(),
        ]);
        $this->assertGreaterThan($support->id, $business->id);

        $result = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame('sent', $result['status']);
        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->firstOrFail();
        $this->assertSame($support->id, (int) $outgoing->telegram_support_account_id);
    }

    public function test_bound_template_auto_replies_on_gated_account(): void
    {
        config(['features.support_auto_reply_templates' => true]);
        $this->boundTemplate('D');

        $user = User::factory()->create(['name' => 'Студент Тест']);
        $incoming = $this->incoming($user, 'сколько стоит курс и как оплатить');

        $result = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame('sent', $result['status']);
        $this->assertSame('D', $result['category']);

        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->first();
        $this->assertNotNull($outgoing);
        $this->assertStringContainsString('Намасте, Студент!', (string) $outgoing->text);

        $event = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SENT)->first();
        $this->assertNotNull($event);
        $this->assertSame('template', $event->meta['kind']);
        $this->assertArrayHasKey('template_id', $event->meta);
    }

    /**
     * Инцидент 01-10-2026 (чат Рады): «Перевела вам деньги для продолжение
     * работы над Рамаяной. Это октябрьской оплаты.» — отчёт об оплате, а не
     * вопрос; бот автоотправил на него шаблон D2 «куда оплатить». Отчёт шаблона
     * не получает: студенту тишина, куратору подсказка как раньше.
     */
    public function test_payment_report_gets_no_template(): void
    {
        config(['features.support_auto_reply_templates' => true]);
        $this->boundTemplate('D');

        $user = User::factory()->create(['name' => 'Рада']);
        $incoming = $this->incoming($user, 'Перевела вам деньги для продолжение работы над Рамаяной. Это октябрьской оплаты.');

        $result = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame('hinted', $result['status']);
        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
        $this->assertSame(0, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SENT)->count());
        $this->assertSame(1, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->count());
    }

    /**
     * Тот же инцидент, второй стоп: куратор ответил студенту за минуту до
     * бота — шаблонная ветка отныне стоит за тем же cooldown-инвариантом,
     * что ack и LLM (recentOutgoingInChat), и двойного ответа не создаёт.
     */
    public function test_template_does_not_double_answer_after_a_recent_outgoing(): void
    {
        config(['features.support_auto_reply_templates' => true]);
        $this->boundTemplate('D');

        $user = User::factory()->create(['name' => 'Студент Тест']);
        $account = $this->account(true);
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9101],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9101,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'outgoing',
            'text' => 'Спасибо, Рада!',
            'sent_at' => now()->subMinute(),
        ]);

        $result = app(SupportDmAutoReply::class)
            ->handle($this->incoming($user, 'сколько стоит курс и как оплатить', $account), $user->id, 'private');

        $this->assertSame('hinted', $result['status']);
        $this->assertSame(1, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
        $this->assertSame(0, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SENT)->count());
    }

    /**
     * H3768, класс инцидента 01-10-2026: живой FAQ-ответ (F выше порога) —
     * за тем же cooldown-инвариантом, что ack/LLM/шаблонная ветка. Первое
     * сообщение уходит из FAQ, повторное в том же чате бот уже не перебивает:
     * свежее исходящее есть — человек в диалоге.
     */
    public function test_live_faq_answer_does_not_repeat_after_a_recent_outgoing(): void
    {
        config([
            'features.support_dm_auto_reply_live_faq' => true,
            'features.support_auto_reply_templates' => false,
            'support.faq_rag.path' => base_path('tests/fixtures/faq_live_f_corpus.md'),
            'support.faq_rag.extra_paths' => [],
            'support.faq_rag.live_categories' => ['F'],
            'support.faq_rag.shadow_min_score' => 0.5,
            'support.faq_rag.shadow_min_score_by_category' => [],
        ]);

        $user = User::factory()->create(['name' => 'Студент Тест']);
        $account = $this->account(true);
        $question = 'куда загружать домашнее задание и в каком формате';

        $first = app(SupportDmAutoReply::class)
            ->handle($this->incoming($user, $question, $account), $user->id, 'private');
        $this->assertSame('sent', $first['status']);
        $this->assertSame(
            'faq_rag',
            SupportAiReplyEvent::query()
                ->where('event_type', SupportDmAutoReply::EVENT_SENT)
                ->latest('id')
                ->first()
                ?->meta['kind'],
        );

        $second = app(SupportDmAutoReply::class)
            ->handle($this->incoming($user, $question, $account), $user->id, 'private');

        $this->assertSame('hinted', $second['status']);
        $this->assertSame(1, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
        $this->assertSame(1, SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SENT)->count());
    }

    /** features.support_block_pay_link: «как оплатить 4» → курс и чекаут блока 4, не /login. */
    public function test_pay_template_links_the_named_debt_block(): void
    {
        config(['features.support_auto_reply_templates' => true, 'features.support_block_pay_link' => true]);
        MessageTemplate::query()->create([
            'title' => 'Поддержка · D2 — куда оплатить',
            'body' => "Намасте, {name}!\n\nОплатить курс «{course}»:\n{pay_link}",
            'category' => MessageTemplate::CATEGORY_SUPPORT,
            'suggester_category' => 'D',
            'is_active' => true,
        ]);

        $user = User::factory()->create(['name' => 'Студент Тест']);
        $course = Course::factory()->create(['is_active' => true, 'title' => 'Лейтан']);
        foreach ([1, 2, 3, 4, 5] as $n) {
            $block = CourseBlock::factory()->for($course);
            ($n === 5 ? $block->current() : $block)->create(['number' => $n]);
            Tariff::create(['course_id' => $course->id, 'title' => 'Блок '.$n, 'type' => 'block', 'block_number' => $n, 'price' => 8000, 'is_active' => true]);
        }
        foreach ([1, 2] as $n) {
            Payment::create([
                'user_id' => $user->id, 'course_id' => $course->id, 'amount' => 8000,
                'tariff' => 'block_'.$n, 'status' => 'paid', 'start_block' => $n, 'end_block' => $n, 'is_conditional' => false,
            ]);
        }

        $result = app(SupportDmAutoReply::class)
            ->handle($this->incoming($user, 'Я не понимаю, как оплатить 4 Лейтана'), $user->id, 'private');

        $this->assertSame('sent', $result['status']);
        $outgoing = (string) TelegramSupportMessage::query()->where('direction', 'outgoing')->value('text');
        $tariff4 = Tariff::where('course_id', $course->id)->where('block_number', 4)->firstOrFail();
        $this->assertStringContainsString('«Лейтан»', $outgoing);
        $this->assertStringContainsString(route('checkout.show', $tariff4), $outgoing);
    }

    public function test_account_gate_off_falls_through_to_hint(): void
    {
        config(['features.support_auto_reply_templates' => true]);
        $this->boundTemplate('D');

        $user = User::factory()->create();
        $incoming = $this->incoming($user, 'сколько стоит курс и как оплатить', $this->account(false));

        $result = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame('hinted', $result['status']);
        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
    }

    public function test_templates_flag_off_uses_ack_when_enabled(): void
    {
        config([
            'features.support_auto_reply_templates' => false,
            'features.support_auto_ack' => true,
        ]);

        $user = User::factory()->create();
        $incoming = $this->incoming($user, 'куда оплатить курс и где ссылка');

        $result = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame('sent', $result['status']);
        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->first();
        $this->assertNotNull($outgoing);
        $this->assertStringContainsString('Ответим в течение рабочего дня', (string) $outgoing->text);

        $event = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SENT)->first();
        $this->assertSame('ack', $event?->meta['kind']);
    }

    public function test_ack_respects_cooldown_window(): void
    {
        config([
            'features.support_auto_reply_templates' => false,
            'features.support_auto_ack' => true,
            'services.telegram_support.auto_ack_cooldown_hours' => 6,
        ]);

        $user = User::factory()->create();
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9101],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );
        $account = $this->account(true);

        $first = app(SupportDmAutoReply::class)->handle(
            $this->incoming($user, 'когда оплата', $account), $user->id, 'private');
        $this->assertSame('sent', $first['status']);

        // Второе сообщение сразу после ack'а — окно активно: ack не повторяется.
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9101,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => 'ну что там с оплатой',
            'sent_at' => now(),
        ]);
        $secondIncoming = TelegramSupportMessage::query()
            ->where('telegram_chat_id', 9101)
            ->where('direction', 'incoming')
            ->orderByDesc('id')
            ->first();

        $second = app(SupportDmAutoReply::class)->handle($secondIncoming, $user->id, 'private');

        $this->assertSame('hinted', $second['status']);
        $acks = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_SENT)
            ->get()
            ->filter(fn (SupportAiReplyEvent $e): bool => ($e->meta['kind'] ?? null) === 'ack');
        $this->assertSame(1, $acks->count());
    }

    public function test_pure_greeting_gets_warm_reply_not_ack(): void
    {
        config([
            'features.support_auto_reply_templates' => false,
            'features.support_auto_ack' => true,
        ]);

        $user = User::factory()->create();
        $incoming = $this->incoming($user, 'Намо намах!');

        $result = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame('sent', $result['status']);
        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->first();
        $this->assertStringContainsString('Напишите, по какому курсу', (string) $outgoing->text);
        $this->assertStringNotContainsString('Получили ваше сообщение', (string) $outgoing->text);

        $event = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SENT)->first();
        $this->assertSame('greeting', $event?->meta['kind']);
    }

    public function test_second_greeting_in_cooldown_is_silent(): void
    {
        config(['features.support_auto_ack' => true]);

        $user = User::factory()->create();
        app(SupportDmAutoReply::class)
            ->handle($this->incoming($user, 'Намасте'), $user->id, 'private');

        $second = app(SupportDmAutoReply::class)
            ->handle($this->incoming($user, 'Здравствуйте!'), $user->id, 'private');

        $this->assertSame('skip', $second['status']);
        $this->assertSame(1, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
    }

    public function test_greeting_with_question_goes_normal_pipeline(): void
    {
        config(['features.support_auto_ack' => true]);
        $this->boundTemplate('D');

        $user = User::factory()->create();
        $incoming = $this->incoming($user, 'Намасте! Сколько стоит курс и как оплатить?');
        config(['features.support_auto_reply_templates' => true]);

        $result = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame('sent', $result['status']);
        $this->assertSame('D', $result['category']);
    }

    public function test_thanks_alone_is_silently_skipped(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $incoming = $this->incoming($user, 'Спасибо большое!');

        $result = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame('skip', $result['status']);
        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
        $this->assertSame(0, SupportAiReplyEvent::query()->count());
    }

    public function test_stale_backlog_message_is_skipped_silently(): void
    {
        config([
            'features.support_auto_reply_templates' => true,
            'features.support_auto_ack' => true,
        ]);
        $this->boundTemplate('D');

        $user = User::factory()->create();
        $account = $this->account(true);
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9101],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );

        // Первичный history-забор: месяцы старых входящих.
        $staleMessages = [];
        foreach (['А это свободный доступ или есть цена?', 'Доброе утро, оплатила 1 блок'] as $i => $text) {
            $staleMessages[] = TelegramSupportMessage::create([
                'telegram_support_account_id' => $account->id,
                'telegram_support_chat_id' => $chat->id,
                'telegram_chat_id' => 9101,
                'telegram_message_id' => 500 + $i,
                'direction' => 'incoming',
                'text' => $text,
                'sent_at' => now()->subDays(30 - $i),
            ]);
        }

        foreach ($staleMessages as $stale) {
            $result = app(SupportDmAutoReply::class)->handle($stale, $user->id, 'private');
            $this->assertSame('stale_skip', $result['status']);
        }

        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());

        // H3392: студенту и куратору по-прежнему тишина; в журнале остаются
        // только дедуп-маркеры dm_stale_skip — данные недельного отчёта пробы.
        $this->assertSame(
            [SupportDmAutoReply::EVENT_STALE_SKIP],
            SupportAiReplyEvent::query()->distinct()->pluck('event_type')->all(),
        );
        $this->assertSame(2, SupportAiReplyEvent::query()->count());

        // Повторный проход синка того же сообщения маркер не дублирует.
        app(SupportDmAutoReply::class)->handle($staleMessages[0], $user->id, 'private');
        $this->assertSame(2, SupportAiReplyEvent::query()->count());

        // А свежее сообщение в том же чате отвечает как обычно.
        $fresh = $this->incoming($user, 'сколько стоит курс и как оплатить', $account);
        $result = app(SupportDmAutoReply::class)->handle($fresh, $user->id, 'private');
        $this->assertSame('sent', $result['status']);
    }

    public function test_session_context_scopes_lock_and_phase_keys(): void
    {
        $this->assertSame('madeline-session', MadelineSessionContext::lockName());
        $this->assertSame('', MadelineSessionContext::phaseSuffix());

        MadelineSessionContext::useSession('/tmp/h3380/session-rusamskrtam.madeline');
        try {
            $this->assertStringStartsWith('madeline-session-', MadelineSessionContext::lockName());
            $this->assertNotSame('', MadelineSessionContext::phaseSuffix());

            MadelineSyncPhase::mark('client_start');
            $this->assertSame('client_start', MadelineSyncPhase::current());
        } finally {
            Config::set(
                'services.telegram_support.session',
                storage_path('app/telegram-support/session.madeline'),
            );
            MadelineSessionContext::useSession(storage_path('app/telegram-support/session.madeline'));
        }
    }

    public function test_sync_command_refuses_disabled_named_account(): void
    {
        TelegramSupportAccount::create([
            'name' => 'rusamskrtam',
            'session_path' => 'storage/app/telegram-support/rusamskrtam/session.madeline',
            'is_enabled' => false,
        ]);

        $this->artisan('telegram-support:sync', ['--account' => 'rusamskrtam'])
            ->expectsOutputToContain('отключен (is_enabled=0)')
            ->assertExitCode(1);
    }

    public function test_drainer_is_scoped_to_own_account(): void
    {
        $this->skipWithoutMadelineProto();
        FakeMadelineProtoClient::reset();

        config([
            'services.telegram_support.enabled' => true,
            'services.telegram_support.api_id' => '12345',
            'services.telegram_support.api_hash' => 'hash',
            'services.telegram_support.client_class' => FakeMadelineProtoClient::class,
        ]);

        $own = $this->account(true);
        $foreign = TelegramSupportAccount::create(['name' => 'support']);
        $chat = TelegramSupportChat::create(['telegram_chat_id' => 5001]);

        $makePending = fn (TelegramSupportAccount $acc) => TelegramSupportMessage::create([
            'telegram_support_account_id' => $acc->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 5001,
            'telegram_message_id' => -random_int(100, 999),
            'direction' => 'outgoing',
            'role' => 'human',
            'responder_type' => 'ai',
            'text' => 'тест доставки '.$acc->name,
            'raw_payload' => ['pending_delivery' => true],
            'sent_at' => now(),
        ]);
        $ownPending = $makePending($own);
        $foreignPending = $makePending($foreign);

        $stats = app(PendingSupportReplyDrainer::class)
            ->drain(app(TelegramSupportSyncService::class), $own->id);

        $this->assertSame(1, $stats['delivered']);

        $this->assertGreaterThan(0, $ownPending->refresh()->telegram_message_id);
        $this->assertLessThan(0, $foreignPending->refresh()->telegram_message_id);
        $this->assertTrue((bool) $foreignPending->raw_payload['pending_delivery']);
    }

    private function skipWithoutMadelineProto(): void
    {
        if (! class_exists(Settings::class)) {
            $this->markTestSkipped('danog/madelineproto не установлен (опциональная зависимость).');
        }
    }
}
