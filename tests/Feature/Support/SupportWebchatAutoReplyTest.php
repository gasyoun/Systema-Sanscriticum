<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Events\ChatMessageSent;
use App\Models\ChatMessage;
use App\Models\SupportAiReplyEvent;
use App\Models\SupportConversation;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportDmAutoReply;
use App\Services\Support\SupportWebchatAutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * H5450 — первые 45 секунд в вебчате samskrte.ru: ack + живой FAQ-ответ
 * за двумя default-OFF флагами.
 *
 * Пинятся все границы, за которыми исходящего быть не должно: OFF-инвариант
 * (ответ эндпоинта байт-в-байт прежний), один ack на серию, F-ответ только
 * выше порога и с цитатой, денежный/доступный запрет В КОДЕ, гостевой тред
 * без фактов LMS, веб-срез недельного отчёта отдельной строкой.
 */
class SupportWebchatAutoReplyTest extends TestCase
{
    use RefreshDatabase;

    private const MATERIALS_QUESTION = 'куда загружать домашнее задание и в каком формате';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            // Крошечный неподвижный корпус: живой faq.md меняется при каждом
            // экспорте из ORS-FAQ, и порог на нём был бы плавающим.
            'support.faq_rag.path' => base_path('tests/fixtures/faq_live_f_corpus.md'),
            'support.faq_rag.extra_paths' => [],
            'support.faq_rag.live_categories' => ['F'],
            'support.faq_rag.shadow_min_score' => 0.5,
            'support.faq_rag.shadow_min_score_by_category' => [],
            'support.webchat_auto_reply.ack_cooldown_hours' => 6,
            'features.support_webchat_auto_ack' => false,
            'features.support_webchat_live_faq' => false,
        ]);

        Event::fake([ChatMessageSent::class]);
    }

    /** OFF = прежнее поведение: ни ключа в ответе, ни bot-строки, ни события. */
    public function test_off_flags_keep_the_endpoint_byte_identical(): void
    {
        $response = $this->postJson('/chat/message', ['text' => self::MATERIALS_QUESTION]);

        $response->assertOk();
        $this->assertSame(
            ['ok', 'conversation_id', 'message'],
            array_keys($response->json()),
            'при OFF ответ эндпоинта обязан остаться прежним построчно',
        );
        $this->assertSame(1, ChatMessage::query()->count(), 'только сообщение посетителя, bot-строк нет');
        $this->assertSame(0, SupportAiReplyEvent::query()->count());
    }

    /** ack: мгновенный bot-ответ со ссылкой на FAQ + телеметрия веб-полосы. */
    public function test_ack_sends_one_bot_message_with_faq_link(): void
    {
        config(['features.support_webchat_auto_ack' => true]);

        $response = $this->postJson('/chat/message', ['text' => 'Здравствуйте, есть вопрос по курсу']);

        $response->assertOk();
        $bot = $response->json('auto_replies.0');
        $this->assertNotNull($bot, 'ack обязан вернуться виджету в ответе');
        $this->assertSame('bot', $bot['role']);

        $row = ChatMessage::query()->where('role', 'bot')->firstOrFail();
        $this->assertStringContainsString('куратор ответит', $row->text);
        $this->assertStringContainsString('/faq/dz', $row->text, 'ack несёт ссылку на FAQ');
        $this->assertNull($row->user_id, 'гостевой bot-ответ без пользователя');

        Event::assertDispatchedTimes(ChatMessageSent::class, 2, 'своё сообщение + ack бродкастятся');

        $event = SupportAiReplyEvent::query()->firstOrFail();
        $this->assertSame('dm_auto_sent', $event->event_type);
        $this->assertNull($event->telegram_support_message_id, 'веб-событие без TG-привязки');
        $this->assertSame(SupportWebchatAutoReply::VIA, $event->meta['via']);
        $this->assertSame('ack', $event->meta['kind']);
        $this->assertTrue($event->meta['guest']);
    }

    /** Один ack на серию: второе сообщение в cooldown-окне бота не будит. */
    public function test_ack_fires_once_per_series_within_cooldown(): void
    {
        config(['features.support_webchat_auto_ack' => true]);

        $this->postJson('/chat/message', ['text' => 'Здравствуйте, есть вопрос']);
        $this->postJson('/chat/message', ['text' => 'И ещё: а когда созвон?']);

        $this->assertSame(1, ChatMessage::query()->where('role', 'bot')->count());
    }

    /** Окно истекло (и ack бота, и ответ куратора за границей) — ack заново. */
    public function test_ack_renews_after_the_cooldown_window_expires(): void
    {
        config(['features.support_webchat_auto_ack' => true]);

        $this->postJson('/chat/message', ['text' => 'Первый вопрос']);
        $this->assertSame(1, ChatMessage::query()->where('role', 'bot')->count());

        // Ответ куратора — старый: отодвигаем его до момента первого вопроса.
        $thread = SupportConversation::query()->firstOrFail();
        $curator = ChatMessage::create([
            'support_conversation_id' => $thread->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'curator',
            'text' => 'Здравствуйте! Уже смотрю.',
            'is_read' => true,
        ]);
        $curator->created_at = now()->subHours(13);
        $curator->save();

        // Теперь всё исходящее (ack бота T0 и куратор) старше окна 6 ч.
        $this->travel(13)->hours();

        $this->postJson('/chat/message', ['text' => 'Спасибо, ещё вопрос!']);

        $this->assertSame(2, ChatMessage::query()->where('role', 'bot')->count());
    }

    /** Категория F выше порога: цитата раздела FAQ, и ack уже не дублируется. */
    public function test_category_f_above_the_floor_is_answered_with_a_cited_faq_draft(): void
    {
        config(['features.support_webchat_live_faq' => true]);

        $response = $this->postJson('/chat/message', ['text' => self::MATERIALS_QUESTION]);

        $response->assertOk();
        $bot = $response->json('auto_replies.0');
        $this->assertNotNull($bot, 'категория F выше порога обязана уйти посетителю сама');

        $row = ChatMessage::query()->where('role', 'bot')->firstOrFail();
        $this->assertStringContainsString('Источник', $row->text, 'рулинг R3: ответ всегда несёт ссылку на раздел FAQ');

        $this->assertSame(1, ChatMessage::query()->where('role', 'bot')->count(), 'FAQ-ответ замещает ack, двух бот-строк быть не должно');

        $event = SupportAiReplyEvent::query()->firstOrFail();
        $this->assertSame('faq_rag', $event->meta['kind']);
        $this->assertSame('F', $event->meta['category']);
        $this->assertGreaterThan(0.0, (float) $event->meta['score']);
        $this->assertArrayHasKey('floor', $event->meta, 'порог пишем в событие: иначе задним числом не проверить');
        $this->assertNotSame('', (string) $event->meta['chunk_id']);
    }

    /** Ниже порога FAQ молчит; при включённом ack посетителю уходит ack. */
    public function test_score_below_the_floor_falls_back_to_ack_only(): void
    {
        config([
            'features.support_webchat_auto_ack' => true,
            'features.support_webchat_live_faq' => true,
            'support.faq_rag.shadow_min_score_by_category' => ['F' => 1000.0],
        ]);

        $response = $this->postJson('/chat/message', ['text' => self::MATERIALS_QUESTION]);

        $response->assertOk();
        $this->assertSame(0, $this->webEventCount('faq_rag'), 'ниже порога автоответа из FAQ быть не должно');
        $this->assertSame(1, $this->webEventCount('ack'));
        $bot = ChatMessage::query()->where('role', 'bot')->firstOrFail();
        $this->assertStringContainsString('куратор ответит', $bot->text, 'ниже порога — ack, а не цитата');
    }

    /** Конфиг может только сузить живые категории до allowlist {F} в коде. */
    public function test_config_cannot_extend_live_categories_beyond_the_code_allowlist(): void
    {
        config([
            'features.support_webchat_live_faq' => true,
            'support.faq_rag.live_categories' => ['A', 'D', 'F'],
        ]);

        $this->postJson('/chat/message', ['text' => 'не могу подключиться к Zoom, ссылка не работает']);
        $this->postJson('/chat/message', ['text' => 'не могу зайти в личный кабинет, забыла пароль']);

        $this->assertSame(0, $this->webEventCount('faq_rag'), 'A и E в веб-автоответе не живут ни при каком конфиге');
    }

    /** Рулинг R3: деньги не автоотвечает никогда — конфиг не умеет это снять. */
    public function test_money_intent_is_refused_even_when_configured_live(): void
    {
        config([
            'features.support_webchat_auto_ack' => true,
            'features.support_webchat_live_faq' => true,
            'support.faq_rag.live_categories' => ['D', 'E', 'F'],
        ]);

        $response = $this->postJson('/chat/message', ['text' => 'сколько стоит курс и как оплатить блок?']);

        $response->assertOk();
        $this->assertSame(0, $this->webEventCount('faq_rag'), 'деньги (D) не уходит посетителю ни при каком конфиге');
        $this->assertSame(1, $this->webEventCount('ack'), 'денежному вопросу положен ack, но не автоответ');
    }

    /** Гостевой тред: только ack/FAQ из публичного корпуса, фактов LMS нет. */
    public function test_guest_thread_receives_no_lms_facts(): void
    {
        config(['features.support_webchat_live_faq' => true]);

        $this->postJson('/chat/message', ['text' => self::MATERIALS_QUESTION]);
        $this->postJson('/chat/message', ['text' => 'и когда ближайший созвон группы?']);

        $kinds = SupportAiReplyEvent::query()
            ->get()
            ->map(fn (SupportAiReplyEvent $e): string => (string) ($e->meta['kind'] ?? ''))
            ->all();

        $this->assertNotContains('facts', $kinds, 'гостю не уходят факты LMS — только публичный FAQ');
        foreach (SupportAiReplyEvent::query()->get() as $event) {
            $this->assertSame(SupportWebchatAutoReply::VIA, $event->meta['via']);
        }
    }

    /** Благодарность не требует работы: болванка «приняли» на «спасибо» — спам. */
    public function test_pure_thanks_gets_no_ack(): void
    {
        config(['features.support_webchat_auto_ack' => true]);

        $response = $this->postJson('/chat/message', ['text' => 'Спасибо, большое!']);

        $response->assertOk();
        $this->assertSame(0, ChatMessage::query()->where('role', 'bot')->count());
        $this->assertSame(0, SupportAiReplyEvent::query()->count());
    }

    /** Студентский тред: bot-ответ пишется под пользователем, guest=false. */
    public function test_student_thread_answers_under_their_user(): void
    {
        config(['features.support_webchat_auto_ack' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/chat/message', ['text' => 'Здравствуйте, есть вопрос по курсу']);

        $response->assertOk();
        $bot = ChatMessage::query()->where('role', 'bot')->firstOrFail();
        $this->assertSame($user->id, $bot->user_id);

        $event = SupportAiReplyEvent::query()->firstOrFail();
        $this->assertFalse($event->meta['guest']);
        $this->assertSame($user->id, SupportConversation::findOrFail($event->meta['conversation_id'])->user_id);
    }

    /** Веб-срез недельного отчёта: отдельной строкой, TG-полоса не задета. */
    public function test_weekly_report_shows_the_web_slice_and_keeps_tg_line_intact(): void
    {
        config(['features.support_webchat_auto_ack' => true]);

        // Веб-событие: ack гостю.
        $this->postJson('/chat/message', ['text' => 'Здравствуйте, есть вопрос']);

        // TG-событие: ack в личке (чужой канал, свой знаменатель).
        $account = TelegramSupportAccount::query()->create(['name' => 'support', 'is_enabled' => true]);
        $chat = TelegramSupportChat::query()->create([
            'telegram_chat_id' => 54501,
            'last_message_at' => now(),
        ]);
        $tgMessage = TelegramSupportMessage::query()->create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 54501,
            'telegram_message_id' => 54502,
            'direction' => 'incoming',
            'text' => 'Намасте!',
            'sent_at' => now(),
        ]);
        SupportAiReplyEvent::query()->create([
            'telegram_support_message_id' => $tgMessage->id,
            'event_type' => SupportDmAutoReply::EVENT_SENT,
            'meta' => ['via' => SupportDmAutoReply::VIA, 'kind' => 'ack'],
        ]);

        Artisan::call('support:auto-reply-weekly', ['--dry' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Веб-чат (samskrte.ru): 1 (ack 1)', $output, $output);
        $this->assertStringContainsString('Автоответов: 1 (ack 1)', $output, 'TG-строка считает только TG-события: '.$output);
        $this->assertSame(2, SupportAiReplyEvent::query()->count());
    }

    /** Неделя без веб-событий не получает веб-строку — прежний текст целиком. */
    public function test_weekly_report_without_web_events_has_no_web_line(): void
    {
        Artisan::call('support:auto-reply-weekly', ['--dry' => true]);
        $output = Artisan::output();

        $this->assertStringNotContainsString('Веб-чат (samskrte.ru)', $output, $output);
    }

    private function webEventCount(string $kind): int
    {
        return SupportAiReplyEvent::query()
            ->get()
            ->filter(fn (SupportAiReplyEvent $e): bool => ($e->meta['via'] ?? '') === SupportWebchatAutoReply::VIA
                && ($e->meta['kind'] ?? '') === $kind)
            ->count();
    }
}
