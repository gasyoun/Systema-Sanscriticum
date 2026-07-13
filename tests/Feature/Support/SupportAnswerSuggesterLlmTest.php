<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\ChatMessage;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\MarketingSetting;
use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;
use App\Models\Tariff;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportAnswerEventLogger;
use App\Services\Support\SupportAnswerSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FAQ-суггестер v2 (S5): LLM-черновики для категорий D (оплата), E (доступ),
 * F (материалы). Внешний LLM ФЕЙКается (Http::fake) — тест никогда не ходит в сеть.
 */
class SupportAnswerSuggesterLlmTest extends TestCase
{
    use RefreshDatabase;

    private const DRAFT = 'Черновик ответа, сформулированный ИИ по фактам.';

    private function enableSuggester(): void
    {
        config(['features.support_answer_suggester' => true]);
        MarketingSetting::create(['support_answer_suggester_enabled' => true]);
        MarketingSetting::flushCached();
    }

    private function enableLlm(): void
    {
        config([
            'features.support_ai_assist' => true,
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.model' => 'deepseek/deepseek-chat',
        ]);
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'model' => 'deepseek/deepseek-chat',
                'choices' => [['message' => ['content' => self::DRAFT]]],
                'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 60],
            ], 200),
        ]);
    }

    private function studentWithTariff(): User
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'Группа Йога-сутры']);
        $user->groups()->attach($group->id);

        $course = Course::factory()->create(['title' => 'Йога-сутры Патанджали']);
        $group->courses()->attach($course->id);

        Tariff::create([
            'course_id' => $course->id,
            'title' => 'Полный курс',
            'type' => 'course',
            'price' => 4800,
            'is_active' => true,
        ]);

        return $user;
    }

    private function webMessage(User $user, string $text): void
    {
        ChatMessage::create(['user_id' => $user->id, 'role' => 'user', 'text' => $text, 'is_read' => false]);
    }

    public function test_payment_question_creates_llm_draft_from_facts(): void
    {
        $this->enableSuggester();
        $this->enableLlm();
        $user = $this->studentWithTariff();
        $this->webMessage($user, 'Подскажите, сколько стоит полный курс?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(1, $result['created']);
        $suggestion = SupportAnswerSuggestion::first();
        $this->assertSame(SupportAnswerSuggestion::CATEGORY_PAYMENT, $suggestion->category);
        $this->assertSame(self::DRAFT, $suggestion->draft_text);
        $this->assertSame('payment', $suggestion->facts['type']);
        $this->assertSame(4800.0, (float) $suggestion->facts['tariffs'][0]['final_price']);

        // Записан отдельный LLM-события (учитывается дневным cap).
        $this->assertDatabaseHas('support_ai_reply_events', [
            'event_type' => SupportAnswerEventLogger::EVENT_LLM_DRAFTED,
        ]);
        // Грунтовка: цена из кода реально ушла в промпт (тело — JSON с \u-эскейпами
        // кириллицы, поэтому смотрим декодированный payload, а не сырое тело).
        Http::assertSent(fn ($request) => str_contains($this->userPromptOf($request), '4800'));
    }

    public function test_access_question_creates_llm_draft(): void
    {
        $this->enableSuggester();
        $this->enableLlm();
        $user = $this->studentWithTariff();
        // Не «как войти …» — это ушло бы в A (Zoom). Чистый E-триггер про группу.
        $this->webMessage($user, 'Подскажите, в какой я группе занимаюсь?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(1, $result['created']);
        $this->assertSame(SupportAnswerSuggestion::CATEGORY_ACCESS, SupportAnswerSuggestion::first()->category);
    }

    public function test_materials_question_creates_llm_draft(): void
    {
        $this->enableSuggester();
        $this->enableLlm();
        $user = $this->studentWithTariff();
        $group = $user->activeGroups->first();
        $course = Course::factory()->create();
        Lesson::create([
            'title' => 'Урок 1',
            'is_published' => true,
            'course_id' => $course->id,
            'group_id' => $group->id,
            'lesson_date' => now()->subDay(),
            'block_number' => 1,
        ]);
        $this->webMessage($user, 'Где взять методичку и домашнее задание?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(1, $result['created']);
        $this->assertSame(SupportAnswerSuggestion::CATEGORY_MATERIALS, SupportAnswerSuggestion::first()->category);
    }

    public function test_category_detected_but_no_draft_when_llm_disabled(): void
    {
        $this->enableSuggester();
        // support_ai_assist остаётся выключенным.
        Http::fake();
        $user = $this->studentWithTariff();
        $this->webMessage($user, 'Сколько стоит полный курс?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(0, $result['created']);
        $this->assertDatabaseCount('support_answer_suggestions', 0);
        Http::assertNothingSent();
    }

    public function test_no_lms_facts_creates_no_draft(): void
    {
        $this->enableSuggester();
        $this->enableLlm();
        $user = User::factory()->create(); // без группы/тарифа
        $this->webMessage($user, 'Сколько стоит курс?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(0, $result['created']);
        Http::assertNothingSent();
    }

    public function test_daily_cap_blocks_further_llm_drafts(): void
    {
        $this->enableSuggester();
        $this->enableLlm();
        MarketingSetting::query()->update(['support_ai_daily_cap' => 1]);
        MarketingSetting::flushCached();
        // Один LLM-черновик уже сделан сегодня — предел исчерпан.
        SupportAiReplyEvent::create([
            'event_type' => SupportAnswerEventLogger::EVENT_LLM_DRAFTED,
            'meta' => ['feature' => 'support_answer_suggester_llm'],
        ]);

        $user = $this->studentWithTariff();
        $this->webMessage($user, 'Сколько стоит полный курс?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(0, $result['created']);
        $this->assertDatabaseCount('support_answer_suggestions', 0);
        Http::assertNothingSent();
    }

    public function test_private_telegram_text_excluded_from_prompt_when_flag_off(): void
    {
        $this->enableSuggester();
        $this->enableLlm();
        config(['features.support_ai_include_telegram' => false]);
        $user = $this->studentWithTariff();
        $secret = 'Мой личный вопрос про рассрочку и цену на полный курс';
        $this->telegramIncoming($user, $secret);

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(1, $result['created']); // черновик из ФАКТОВ строится
        // Но сырой приватный текст ЛС в промпт не попал.
        Http::assertSent(fn ($request) => ! str_contains($this->userPromptOf($request), $secret));
    }

    public function test_private_telegram_text_included_when_flag_on(): void
    {
        $this->enableSuggester();
        $this->enableLlm();
        config(['features.support_ai_include_telegram' => true]);
        $user = $this->studentWithTariff();
        $secret = 'Сколько стоит полный курс, интересует рассрочка';
        $this->telegramIncoming($user, $secret);

        app(SupportAnswerSuggester::class)->run();

        Http::assertSent(fn ($request) => str_contains($this->userPromptOf($request), $secret));
    }

    /** Содержимое user-сообщения, ушедшего в LLM (декодированный payload). */
    private function userPromptOf($request): string
    {
        foreach (($request->data()['messages'] ?? []) as $message) {
            if (($message['role'] ?? null) === 'user') {
                return (string) ($message['content'] ?? '');
            }
        }

        return '';
    }

    private function telegramIncoming(User $user, string $text): void
    {
        $account = TelegramSupportAccount::create(['name' => 'support']);
        $chat = TelegramSupportChat::create(['telegram_chat_id' => 9911, 'linked_user_id' => $user->id]);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9911,
            'telegram_message_id' => 1,
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now(),
        ]);
    }
}
