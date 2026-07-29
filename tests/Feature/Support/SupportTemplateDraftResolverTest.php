<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\ChatMessage;
use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\MessageTemplate;
use App\Models\SupportAnswerSuggestion;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Support\SupportAnswerEventLogger;
use App\Services\Support\SupportAnswerSuggester;
use App\Services\Support\SupportAnswerSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Шаблонный резолвер черновиков ПЕРЕД LLM-суггестером (H1838, тикет S9):
 * привязанная категория D/E/F берёт черновик из MessageTemplate с подстановкой
 * плейсхолдеров — LLM не вызывается вовсе (Http::assertNothingSent);
 * непривязанная — прежний LLM-путь без изменений. Внешний LLM ФЕЙКается.
 */
class SupportTemplateDraftResolverTest extends TestCase
{
    use RefreshDatabase;

    private const LLM_DRAFT = 'Черновик ответа, сформулированный ИИ по фактам.';

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
                'choices' => [['message' => ['content' => self::LLM_DRAFT]]],
                'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 60],
            ], 200),
        ]);
    }

    private function enableTemplateDrafts(): void
    {
        config(['features.support_template_drafts' => true]);
    }

    private function bindTemplate(string $suggesterCategory, string $body, bool $active = true): MessageTemplate
    {
        return MessageTemplate::create([
            'title' => 'Шаблон '.$suggesterCategory,
            'body' => $body,
            'category' => MessageTemplate::CATEGORY_SUPPORT,
            'suggester_category' => $suggesterCategory,
            'is_active' => $active,
        ]);
    }

    private function studentWithTariff(string $groupName = 'Группа Йога-сутры'): User
    {
        $user = User::factory()->create(['name' => 'Арджуна']);
        $group = Group::create(['name' => $groupName]);
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

    public function test_bound_category_builds_draft_from_template_and_never_calls_llm(): void
    {
        $this->enableSuggester();
        $this->enableLlm();
        $this->enableTemplateDrafts();
        $template = $this->bindTemplate(SupportAnswerSuggestion::CATEGORY_PAYMENT, 'Здравствуйте, {name}! Оплатить можно в личном кабинете: {pay_link}');
        $user = $this->studentWithTariff();
        $this->webMessage($user, 'Подскажите, сколько стоит полный курс?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(1, $result['created']);
        $suggestion = SupportAnswerSuggestion::first();
        $this->assertSame(SupportAnswerSuggestion::CATEGORY_PAYMENT, $suggestion->category);
        // Плейсхолдеры подставлены под получателя, литералов не осталось.
        $this->assertStringContainsString('Арджуна', $suggestion->draft_text);
        $this->assertStringNotContainsString('{name}', $suggestion->draft_text);
        $this->assertStringNotContainsString('{pay_link}', $suggestion->draft_text);
        // Провенанс черновика — шаблон, для A/B-сравнения с LLM.
        $this->assertSame('template', $suggestion->facts['type']);
        $this->assertSame($template->id, $suggestion->facts['template_id']);

        // LLM не вызывался вовсе; событие — шаблонное, не LLM-ное.
        Http::assertNothingSent();
        $this->assertDatabaseHas('support_ai_reply_events', [
            'event_type' => SupportAnswerEventLogger::EVENT_TEMPLATE_DRAFTED,
        ]);
        $this->assertDatabaseMissing('support_ai_reply_events', [
            'event_type' => SupportAnswerEventLogger::EVENT_LLM_DRAFTED,
        ]);
    }

    public function test_unbound_category_still_calls_llm_unchanged(): void
    {
        $this->enableSuggester();
        $this->enableLlm();
        $this->enableTemplateDrafts();
        // Привязан только E — вопрос категории D должен уйти прежним LLM-путём.
        $this->bindTemplate(SupportAnswerSuggestion::CATEGORY_ACCESS, 'Ваша группа указана в кабинете.');
        $user = $this->studentWithTariff();
        $this->webMessage($user, 'Подскажите, сколько стоит полный курс?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(1, $result['created']);
        $suggestion = SupportAnswerSuggestion::first();
        $this->assertSame(self::LLM_DRAFT, $suggestion->draft_text);
        $this->assertSame('payment', $suggestion->facts['type']);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('support_ai_reply_events', [
            'event_type' => SupportAnswerEventLogger::EVENT_LLM_DRAFTED,
        ]);
    }

    public function test_flag_off_ignores_binding_and_uses_llm(): void
    {
        $this->enableSuggester();
        $this->enableLlm();
        // features.support_template_drafts остаётся выключенным (дефолт).
        $this->bindTemplate(SupportAnswerSuggestion::CATEGORY_PAYMENT, 'Оплата — в кабинете: {pay_link}');
        $user = $this->studentWithTariff();
        $this->webMessage($user, 'Сколько стоит полный курс?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(1, $result['created']);
        $this->assertSame(self::LLM_DRAFT, SupportAnswerSuggestion::first()->draft_text);
        Http::assertSentCount(1);
    }

    public function test_inactive_template_falls_back_to_llm(): void
    {
        $this->enableSuggester();
        $this->enableLlm();
        $this->enableTemplateDrafts();
        $this->bindTemplate(SupportAnswerSuggestion::CATEGORY_PAYMENT, 'Оплата — в кабинете.', active: false);
        $user = $this->studentWithTariff();
        $this->webMessage($user, 'Сколько стоит полный курс?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(1, $result['created']);
        $this->assertSame(self::LLM_DRAFT, SupportAnswerSuggestion::first()->draft_text);
        Http::assertSentCount(1);
    }

    public function test_abc_fact_categories_are_untouched_by_binding(): void
    {
        $this->enableSuggester();
        $this->enableLlm();
        $this->enableTemplateDrafts();
        // Привязку к фактовой категории A админка не предлагает, но даже прямая
        // строка в БД не должна перехватить фактовый путь A/B/C.
        MessageTemplate::create([
            'title' => 'Не должен сработать',
            'body' => 'Шаблонный текст.',
            'category' => MessageTemplate::CATEGORY_SUPPORT,
            'suggester_category' => SupportAnswerSuggestion::CATEGORY_ZOOM,
            'is_active' => true,
        ]);
        $user = $this->studentWithTariff();
        $this->webMessage($user, 'Дайте, пожалуйста, ссылку на zoom');

        $result = app(SupportAnswerSuggester::class)->run();

        // Фактов Zoom (расписания) у студента нет — черновика нет вовсе;
        // главное: шаблон фактовую категорию не перехватил.
        $this->assertSame(0, $result['created']);
        $this->assertDatabaseCount('support_answer_suggestions', 0);
        Http::assertNothingSent();
    }

    public function test_template_draft_resolution_logs_events_like_llm_drafts(): void
    {
        $this->enableSuggester();
        $this->enableLlm();
        $this->enableTemplateDrafts();
        $this->bindTemplate(SupportAnswerSuggestion::CATEGORY_PAYMENT, 'Оплатить можно в кабинете: {pay_link}');
        $user = $this->studentWithTariff();
        $this->webMessage($user, 'Сколько стоит полный курс?');
        app(SupportAnswerSuggester::class)->run();
        $suggestion = SupportAnswerSuggestion::first();

        app(SupportAnswerSuggestionService::class)->accept($suggestion, null);

        // Принятие шаблонного черновика идёт тем же журналом, что и LLM-ного —
        // deflection-отчёт сравнивает принимаемость по suggestion_id → facts.type.
        $this->assertSame(SupportAnswerSuggestion::STATUS_ACCEPTED, $suggestion->fresh()->status);
        $this->assertDatabaseHas('support_ai_reply_events', [
            'event_type' => SupportAnswerEventLogger::EVENT_ACCEPTED,
        ]);
    }

    public function test_template_drafts_do_not_consume_llm_daily_cap(): void
    {
        $this->enableSuggester();
        $this->enableLlm();
        $this->enableTemplateDrafts();
        MarketingSetting::query()->update(['support_ai_daily_cap' => 1]);
        MarketingSetting::flushCached();
        $this->bindTemplate(SupportAnswerSuggestion::CATEGORY_PAYMENT, 'Оплата — в кабинете: {pay_link}');

        $first = $this->studentWithTariff();
        $second = $this->studentWithTariff('Группа Веданта-сутры');
        $this->webMessage($first, 'Сколько стоит полный курс?');
        $this->webMessage($second, 'Как оплатить курс в рассрочку?');

        $result = app(SupportAnswerSuggester::class)->run();

        // Оба черновика собраны из шаблона: cap=1 не помеха — LLM не вызывался.
        $this->assertSame(2, $result['created']);
        Http::assertNothingSent();
    }
}
