<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Course;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\MessageTemplate;
use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportDmAutoReply;
use App\Support\TelegramSendGuard;
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

    /**
     * H3999: подсказка с черновиком ИЗ ФАКТОВ студента тоже несёт кнопку.
     *
     * До волны 1 кнопка появлялась только там, где сработал FAQ-ретривер, —
     * то есть примерно на 2 % трафика ЛС (FINDINGS §635). Черновик из фактов
     * кабинета — основная масса вопросов, и он обязан быть отправляем в один
     * тап так же, как статья.
     */
    public function test_a_fact_derived_draft_also_carries_a_send_button(): void
    {
        $account = TelegramSupportAccount::firstOrCreate(
            ['name' => 'support'],
            ['hint_recipients' => [self::CURATOR_TG]],
        );
        $account->forceFill(['hint_recipients' => [self::CURATOR_TG]])->save();

        $course = Course::factory()->create(['title' => 'Санскрит с нуля']);
        $group = Group::factory()->create(['status' => 'active']);
        $course->groups()->attach($group->id);

        $student = User::factory()->create();
        $student->groups()->attach($group->id);

        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'title' => 'Урок 3',
            'is_published' => true,
        ]);
        HomeworkSubmission::create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'status' => HomeworkSubmission::STATUS_SUBMITTED,
            'last_activity_at' => now()->subHour(),
        ]);

        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9602],
            ['linked_user_id' => $student->id, 'last_message_at' => now()],
        );
        $incoming = TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9602,
            'telegram_message_id' => 4243,
            'direction' => 'incoming',
            'text' => 'что с моей домашкой, проверили?',
            'sent_at' => now(),
        ]);

        app(SupportDmAutoReply::class)->handle($incoming, $student->id, 'private');

        $suggestion = SupportAnswerSuggestion::query()
            ->where('source_id', $incoming->id)
            ->firstOrFail();

        $this->assertSame('facts', $suggestion->facts['kind']);
        $this->assertSame('homework', $suggestion->facts['fact_type']);
        $this->assertFalse($suggestion->isDraftOnly());

        Http::assertSent(function ($request) use ($suggestion): bool {
            $keyboard = $request->data()['reply_markup'] ?? null;

            return is_string($keyboard)
                && str_contains($keyboard, SupportDmAutoReply::SEND_CALLBACK_PREFIX.$suggestion->id);
        });

        $this->tap($suggestion, self::CURATOR_TG);

        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->get();
        $this->assertCount(1, $outgoing);
        $this->assertStringContainsString('Урок 3', (string) $outgoing->first()->text);
    }

    /**
     * H3999: черновик draft_only (деньги/доступ/сертификат) нажатием из
     * Telegram НЕ отправляется — даже если куратор тапнул старую кнопку из
     * ленты. Отказ происходит ДО клейма {@see TelegramSendGuard}:
     * иначе занятый клейм закрыл бы отправку того же текста из очереди.
     */
    public function test_a_draft_only_suggestion_refuses_the_telegram_tap(): void
    {
        $account = TelegramSupportAccount::firstOrCreate(
            ['name' => 'support'],
            ['hint_recipients' => [self::CURATOR_TG]],
        );

        $student = User::factory()->create();
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9603],
            ['linked_user_id' => $student->id, 'last_message_at' => now()],
        );
        $incoming = TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9603,
            'telegram_message_id' => 4244,
            'direction' => 'incoming',
            'text' => 'какой у меня остаток?',
            'sent_at' => now(),
        ]);

        $suggestion = SupportAnswerSuggestion::create([
            'user_id' => $student->id,
            'source_type' => SupportAnswerSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE,
            'source_id' => $incoming->id,
            'category' => SupportAnswerSuggestion::CATEGORY_PAYMENT,
            'draft_text' => 'К оплате остаётся 12 000 ₽.',
            'status' => SupportAnswerSuggestion::STATUS_PENDING,
            'facts' => [
                'kind' => 'facts',
                'fact_type' => 'balance',
                'send_policy' => 'draft_only',
            ],
        ]);

        $this->tap($suggestion, self::CURATOR_TG);

        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());

        $suggestion->refresh();
        $this->assertSame(
            SupportAnswerSuggestion::STATUS_PENDING,
            $suggestion->status,
            'Черновик остаётся в очереди — отказ кнопки его не закрывает.',
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), 'answerCallbackQuery')
            && str_contains((string) ($request['text'] ?? ''), 'очередь черновиков'));
    }

    /**
     * H3999: третий источник черновика — выверенный шаблон категории.
     *
     * Ни FAQ не попал, ни фактов у вопроса нет (студент не привязан к группе),
     * но канреплай категории существует — и куратор всё равно отвечает в один
     * тап, а не копирует текст руками.
     */
    public function test_a_template_derived_draft_also_carries_a_send_button(): void
    {
        $account = TelegramSupportAccount::firstOrCreate(
            ['name' => 'support'],
            ['hint_recipients' => [self::CURATOR_TG]],
        );
        $account->forceFill(['hint_recipients' => [self::CURATOR_TG]])->save();

        // Корпуса FAQ нет (парсер на отсутствующем файле возвращает []), фактов
        // у непривязанного студента тоже — остаётся ровно один источник
        // черновика, шаблон категории.
        config(['support.faq_rag.path' => base_path('tests/fixtures/faq_corpus_absent.md')]);

        MessageTemplate::create([
            'title' => 'Как сдавать домашние задания',
            'body' => 'Домашние задания загружаются в кабинете на странице урока.',
            'category' => 'support',
            'suggester_category' => SupportAnswerSuggestion::CATEGORY_MATERIALS,
            'is_active' => true,
        ]);

        // Ни одной активной группы: резолверы фактов возвращают null, и
        // единственный оставшийся источник — шаблон категории.
        $student = User::factory()->create();
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9604],
            ['linked_user_id' => $student->id, 'last_message_at' => now()],
        );
        $incoming = TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9604,
            'telegram_message_id' => 4245,
            'direction' => 'incoming',
            'text' => 'проверили ли мою домашнюю работу',
            'sent_at' => now(),
        ]);

        app(SupportDmAutoReply::class)->handle($incoming, $student->id, 'private');

        $suggestion = SupportAnswerSuggestion::query()
            ->where('source_id', $incoming->id)
            ->firstOrFail();

        $this->assertSame('template', $suggestion->facts['kind']);
        $this->assertFalse($suggestion->isDraftOnly());

        $this->tap($suggestion, self::CURATOR_TG);

        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->get();
        $this->assertCount(1, $outgoing);
        $this->assertStringContainsString('загружаются в кабинете', (string) $outgoing->first()->text);
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
