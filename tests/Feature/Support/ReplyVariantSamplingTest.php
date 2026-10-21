<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\MessageTemplate;
use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\AutoReplyWeeklyReport;
use App\Services\Support\ReplyVariantSampler;
use App\Services\Support\SupportDmAutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H6767: сэмплинг вариантов ответа суфлёра (features.support_reply_variant_sampling,
 * default OFF). Пулы ack/template, равномерный выбор, провенанс в meta
 * dm_auto_sent, разведочный срез в недельном отчёте. Privacy-забор: в meta,
 * отчёте и stdout — только id/счётчики, ни одного текста.
 */
class ReplyVariantSamplingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.support_dm_auto_reply' => true,
            'features.support_auto_ack' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    private function account(bool $autoReply = true): TelegramSupportAccount
    {
        return TelegramSupportAccount::create([
            'name' => 'rusamskrtam'.$this->autoIncrement(),
            'auto_reply_enabled' => $autoReply,
        ]);
    }

    private static int $chatSeq = 9101;

    private function autoIncrement(): int
    {
        return self::$chatSeq++;
    }

    private function incoming(User $user, string $text, int $chatId, ?TelegramSupportAccount $account = null): TelegramSupportMessage
    {
        $account ??= $this->account();
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => $chatId],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now(),
        ]);
    }

    private function boundTemplate(string $category, string $body): MessageTemplate
    {
        return MessageTemplate::query()->create([
            'title' => 'Поддержка · тест '.$category.' '.md5($body),
            'body' => $body,
            'category' => MessageTemplate::CATEGORY_SUPPORT,
            'suggester_category' => $category,
            'is_active' => true,
        ]);
    }

    /** Флаг OFF: ack — прежняя единственная строка байт-в-байт, meta без variant_*. */
    public function test_flag_off_ack_is_byte_identical_and_meta_has_no_variant_keys(): void
    {
        config([
            'features.support_reply_variant_sampling' => false,
            'services.telegram_support.auto_ack_variants' => ['Намасте! Вариант из пула.'],
        ]);

        $user = User::factory()->create();
        $incoming = $this->incoming($user, 'куда оплатить курс и где ссылка', 9101);

        $result = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame('sent', $result['status']);
        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->firstOrFail();
        $this->assertSame(
            (string) config('services.telegram_support.auto_ack_text'),
            (string) $outgoing->text,
        );

        $meta = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_SENT)
            ->firstOrFail()
            ->meta;
        $this->assertArrayNotHasKey('variant_kind', $meta);
        $this->assertArrayNotHasKey('variant_id', $meta);
        $this->assertArrayNotHasKey('variant_pool_size', $meta);
    }

    /** Флаг OFF: template-ветка — прежний «последний updated_at выигрывает». */
    public function test_flag_off_template_path_still_latest_wins(): void
    {
        config(['features.support_reply_variant_sampling' => false, 'features.support_auto_reply_templates' => true]);

        $older = $this->boundTemplate('D', 'Намасте, {name}! Старый шаблон.');
        $newer = $this->boundTemplate('D', 'Намасте, {name}! Новый шаблон.');
        // Query-builder update: ровно то, что написано, без тачей Eloquent.
        MessageTemplate::query()->whereKey($older->id)->update(['updated_at' => now()->subDay()]);
        MessageTemplate::query()->whereKey($newer->id)->update(['updated_at' => now()]);
        $this->assertTrue(
            MessageTemplate::query()->find($newer->id)->updated_at
                ->gt(MessageTemplate::query()->find($older->id)->updated_at),
        );

        $user = User::factory()->create(['name' => 'Студент Тест']);
        $incoming = $this->incoming($user, 'сколько стоит курс и как оплатить', 9101);

        $result = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame('sent', $result['status']);
        $meta = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_SENT)
            ->firstOrFail()
            ->meta;
        $this->assertSame($newer->id, $meta['template_id']);
        $this->assertArrayNotHasKey('variant_kind', $meta);
    }

    /** Пустой пул при флаге ON — fallback на единственный auto_ack_text (вариант 0 из 1). */
    public function test_flag_on_empty_pool_falls_back_to_single_ack_text(): void
    {
        config([
            'features.support_reply_variant_sampling' => true,
            'services.telegram_support.auto_ack_variants' => [],
        ]);

        $user = User::factory()->create();
        $incoming = $this->incoming($user, 'куда оплатить курс и где ссылка', 9101);

        $result = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame('sent', $result['status']);
        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->firstOrFail();
        $this->assertSame((string) config('services.telegram_support.auto_ack_text'), (string) $outgoing->text);

        $meta = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_SENT)
            ->firstOrFail()
            ->meta;
        $this->assertSame('ack', $meta['variant_kind']);
        $this->assertSame(0, $meta['variant_id']);
        $this->assertSame(1, $meta['variant_pool_size']);
        $this->assertSame('ack', $meta['kind']);
    }

    /** Пул из одних пустых строк ведёт себя как пустой пул. */
    public function test_blank_pool_entries_are_ignored(): void
    {
        $sampler = ReplyVariantSampler::seeded(7);

        $picked = $sampler->pickText(['  ', ''], 'Фолбэк');

        $this->assertSame('Фолбэк', $picked['text']);
        $this->assertSame(0, $picked['variant_id']);
        $this->assertSame(1, $picked['pool_size']);
    }

    /** Сеемый RNG: детерминизм последовательности + покрытие пула (разведочная раскраска). */
    public function test_seeded_pick_is_deterministic_and_covers_pool(): void
    {
        $pool = ['Вариант А', 'Вариант Б', 'Вариант В'];

        $first = [];
        $samplerA = ReplyVariantSampler::seeded(42);
        for ($i = 0; $i < 90; $i++) {
            $first[] = $samplerA->pickText($pool, 'фолбэк')['variant_id'];
        }

        $samplerB = ReplyVariantSampler::seeded(42);
        for ($i = 0; $i < 90; $i++) {
            $this->assertSame($first[$i], $samplerB->pickText($pool, 'фолбэк')['variant_id'], "шаг $i");
        }

        foreach ([0, 1, 2] as $variantId) {
            $this->assertContains($variantId, $first, 'вариант '.$variantId.' не выбрался ни разу');
        }
    }

    /** Интеграция: сеемый сэмплер в контейнере → meta несёт ожидаемый первый выбор. */
    public function test_seeded_sampler_reaches_dm_auto_sent_meta(): void
    {
        config(['features.support_reply_variant_sampling' => true]);
        config(['services.telegram_support.auto_ack_variants' => ['Пул №1', 'Пул №2', 'Пул №3']]);

        $expected = ReplyVariantSampler::seeded(123)->pickText(
            ['Пул №1', 'Пул №2', 'Пул №3'],
            (string) config('services.telegram_support.auto_ack_text'),
        );
        $this->app->instance(ReplyVariantSampler::class, ReplyVariantSampler::seeded(123));

        $user = User::factory()->create();
        $incoming = $this->incoming($user, 'куда оплатить курс и где ссылка', 9101);

        $result = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');

        $this->assertSame('sent', $result['status']);
        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->firstOrFail();
        $this->assertSame($expected['text'], (string) $outgoing->text);

        $meta = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_SENT)
            ->firstOrFail()
            ->meta;
        $this->assertSame('ack', $meta['variant_kind']);
        $this->assertSame($expected['variant_id'], $meta['variant_id']);
        $this->assertSame(3, $meta['variant_pool_size']);
    }

    /** Template-пул при флаге ON: выбор по ВСЕМ привязанным строкам, провенанс в meta. */
    public function test_flag_on_template_pool_picks_across_all_matching_rows(): void
    {
        config(['features.support_reply_variant_sampling' => true, 'features.support_auto_reply_templates' => true]);

        $t1 = $this->boundTemplate('D', 'Намасте, {name}! Шаблон один.');
        $t2 = $this->boundTemplate('D', 'Намасте, {name}! Шаблон два.');
        $t3 = $this->boundTemplate('F', 'Намасте, {name}! Шаблон чужой категории.');
        $t1->update(['updated_at' => now()->subDays(3)]);
        $t2->update(['updated_at' => now()->subDays(2)]);
        $t3->update(['updated_at' => now()]);

        $this->app->instance(ReplyVariantSampler::class, ReplyVariantSampler::seeded(5));

        $picked = [];

        for ($i = 0; $i < 24; $i++) {
            $user = User::factory()->create(['name' => 'Студент '.$i]);
            $incoming = $this->incoming($user, 'сколько стоит курс и как оплатить', 20000 + $i);

            $result = app(SupportDmAutoReply::class)->handle($incoming, $user->id, 'private');
            $this->assertSame('sent', $result['status'], 'итерация '.$i);

            $meta = SupportAiReplyEvent::query()
                ->where('event_type', SupportDmAutoReply::EVENT_SENT)
                ->latest('id')
                ->firstOrFail()
                ->meta;

            $this->assertSame('template', $meta['variant_kind']);
            $this->assertSame(2, $meta['variant_pool_size'], 'пул — только строки категории D');
            $this->assertContains($meta['variant_id'], [$t1->id, $t2->id]);
            $picked[] = (int) $meta['variant_id'];
        }

        $this->assertContains($t1->id, $picked, 'строка t1 не выбралась ни разу');
        $this->assertContains($t2->id, $picked, 'строка t2 не выбралась ни разу');
        $this->assertNotContains($t3->id, $picked, 'чужая категория не должна попадать в пул');
    }

    /** Недельный отчёт: срез по вариантам из variant_* meta, только счётчики. */
    public function test_weekly_report_renders_variant_aggregates(): void
    {
        $user = User::factory()->create();
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9101],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );
        $account = $this->account();

        $makeSend = function (int $chatId, int $variantId, string $kind, string $text) use ($account): TelegramSupportMessage {
            $chat = TelegramSupportChat::firstOrCreate(
                ['telegram_chat_id' => $chatId],
                ['linked_user_id' => null, 'last_message_at' => now()],
            );
            $outgoing = TelegramSupportMessage::create([
                'telegram_support_account_id' => $account->id,
                'telegram_support_chat_id' => $chat->id,
                'telegram_chat_id' => $chatId,
                'telegram_message_id' => random_int(1, 1_000_000),
                'direction' => 'outgoing',
                'responder_type' => 'ai',
                'role' => 'bot',
                'text' => $text,
                'sent_at' => now()->subHours(2),
            ]);
            SupportAiReplyEvent::create([
                'telegram_support_message_id' => $outgoing->id,
                'event_type' => SupportDmAutoReply::EVENT_SENT,
                'meta' => [
                    'via' => SupportDmAutoReply::VIA,
                    'kind' => $kind,
                    'category' => null,
                    'variant_kind' => $kind,
                    'variant_id' => $variantId,
                    'variant_pool_size' => 2,
                ],
            ]);

            return $outgoing;
        };

        $ackZero = $makeSend(9101, 0, 'ack', 'ACK_TEXT_SHOULD_NOT_LEAK');
        $makeSend(9102, 0, 'ack', 'ACK_TEXT_SHOULD_NOT_LEAK');
        $makeSend(9103, 1, 'ack', 'ACK_TEXT_SHOULD_NOT_LEAK');
        $makeSend(9104, 7, 'template', 'TEMPLATE_TEXT_SHOULD_NOT_LEAK');

        // Прокси: в 9101 студент написал снова через час и куратор ответил;
        // в 9102 — ничего; в 9104 — исходящее от другого бота (не куратор).
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $ackZero->telegram_support_account_id,
            'telegram_support_chat_id' => $ackZero->telegram_support_chat_id,
            'telegram_chat_id' => 9101,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => 'спасибо, а ещё вопрос',
            'sent_at' => now()->subHour(),
        ]);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $ackZero->telegram_support_account_id,
            'telegram_support_chat_id' => $ackZero->telegram_support_chat_id,
            'telegram_chat_id' => 9101,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'outgoing',
            'responder_type' => 'human',
            'role' => 'human',
            'text' => 'Куратор здесь',
            'sent_at' => now()->subMinutes(30),
        ]);

        $report = app(AutoReplyWeeklyReport::class)->build(7);

        $this->assertSame(
            [
                'ack' => [
                    '0' => ['sent' => 2, 'student_followup_48h' => 1, 'curator_answer_48h' => 1],
                    '1' => ['sent' => 1, 'student_followup_48h' => 0, 'curator_answer_48h' => 0],
                ],
                'template' => [
                    '7' => ['sent' => 1, 'student_followup_48h' => 0, 'curator_answer_48h' => 0],
                ],
            ],
            $report['variants'],
        );

        $this->assertStringContainsString('Варианты ack', $report['text']);
        $this->assertStringContainsString('#0 ×2', $report['text']);
        $this->assertStringContainsString('#7 ×1', $report['text']);
        $this->assertStringNotContainsString('SHOULD_NOT_LEAK', $report['text']);
        $this->assertStringNotContainsString('спасибо, а ещё вопрос', $report['text']);
        $this->assertStringNotContainsString('Куратор здесь', $report['text']);
    }

    /** Недельный отчёт: ни одного variant_* в мете — среза нет и строки нет (OFF-инвариант). */
    public function test_weekly_report_without_variant_meta_has_no_variant_section(): void
    {
        $user = User::factory()->create();
        $account = $this->account();
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9101],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );
        $outgoing = TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9101,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'outgoing',
            'responder_type' => 'ai',
            'role' => 'bot',
            'text' => 'Обычный автоответ',
            'sent_at' => now()->subHours(2),
        ]);
        SupportAiReplyEvent::create([
            'telegram_support_message_id' => $outgoing->id,
            'event_type' => SupportDmAutoReply::EVENT_SENT,
            'meta' => ['via' => SupportDmAutoReply::VIA, 'kind' => 'ack', 'category' => null],
        ]);

        $report = app(AutoReplyWeeklyReport::class)->build(7);

        $this->assertSame([], $report['variants']);
        $this->assertStringNotContainsString('Варианты', $report['text']);
    }
}
