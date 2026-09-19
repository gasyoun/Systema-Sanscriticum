<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
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
 * Инцидент 19-09-2026: студентка просила «ссылку для оплаты», а бот ответил
 * ссылкой на запись урока. Две независимые причины, и здесь запинены обе:
 *
 *  1. КЛАССИФИКАТОР. Вежливая оговорка «иногда буду… смотреть в записи»
 *     матчится рукой B раньше платёжной руки — категория D украдена. Узкая
 *     платёжная рука поднята наверх (SupportAnswerSuggester), и просьба об
 *     оплате теперь доходит до куратора как подсказка, а не уходит фактом.
 *
 *  2. ЗАБОР В КОДЕ. Даже когда категория B (двойной вопрос в одном
 *     сообщении: «хочу оплатить. и где запись?»), денежное намерение в тексте
 *     запрещает автоответ фактом LMS — деньги решает человек (R3), ровно как
 *     в llmRefusalReason() для LLM-ветки. Контроль ниже доказывает, что
 *     запись без денежного слова уходит как раньше: гасит её именно забор, а
 *     не сломанная фикстура.
 */
class SupportDmMoneyIntentFenceTest extends TestCase
{
    use RefreshDatabase;

    private const INCIDENT_TEXT = "Добрый день! Пришлите, пожалуйста ссылку для оплаты.\nХотела сразу предупредить, что иногда буду отсутствовать на занятии и смотреть в записи.";

    private const MIXED_TEXT = 'Хочу оплатить следующий блок. И где посмотреть запись вчерашнего урока?';

    private const RECORDING_TEXT = 'где посмотреть запись вчерашнего урока?';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.support_dm_auto_reply' => true,
            'features.support_dm_auto_reply_live_faq' => false,
            'features.support_auto_reply_templates' => false,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
            'support.faq_rag.path' => base_path('tests/fixtures/faq_shadow_corpus.md'),
            'support.faq_rag.shadow_min_score' => 1000.0,
            'support.faq_rag.shadow_min_score_by_category' => [],
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        TelegramSupportAccount::query()->create([
            'name' => 'support',
            'is_enabled' => true,
            'auto_reply_enabled' => true,
        ]);
    }

    public function test_control_recording_question_still_auto_answers_with_the_link(): void
    {
        $user = $this->studentWithRecordedLesson();

        $result = app(SupportDmAutoReply::class)->handle(
            $this->incoming($user, self::RECORDING_TEXT),
            $user->id,
            'private',
        );

        $this->assertSame('sent', $result['status'], 'контроль: без денежного слова факт записи уходит как раньше');

        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->first();
        $this->assertNotNull($outgoing);
        $this->assertStringContainsString('https://youtu.be/fence-check', (string) $outgoing->text);

        $sent = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SENT)->first();
        $this->assertSame('facts', $sent->meta['kind']);
    }

    public function test_money_intent_suppresses_the_recording_fact_answer(): void
    {
        $user = $this->studentWithRecordedLesson();

        $result = app(SupportDmAutoReply::class)->handle(
            $this->incoming($user, self::MIXED_TEXT),
            $user->id,
            'private',
        );

        // Категория здесь B (слова «ссылка» нет — платёжная рука не матчится),
        // резолвер даёт черновик записи, но денежное намерение в тексте
        // запрещает отправку: студенту — ничего, куратору — подсказка.
        $this->assertSame('hinted', $result['status']);
        $this->assertSame(
            0,
            TelegramSupportMessage::query()->where('direction', 'outgoing')->count(),
            'денежное намерение в тексте = факты LMS студенту не уходят',
        );
    }

    public function test_incident_text_routes_to_the_curator_not_a_recording_link(): void
    {
        $user = $this->studentWithRecordedLesson();

        $result = app(SupportDmAutoReply::class)->handle(
            $this->incoming($user, self::INCIDENT_TEXT),
            $user->id,
            'private',
        );

        $this->assertSame('hinted', $result['status']);
        $this->assertSame(
            0,
            TelegramSupportMessage::query()->where('direction', 'outgoing')->count(),
            'просьба ссылки для оплаты — вопрос куратора, не автоответ',
        );

        $outgoingTexts = TelegramSupportMessage::query()
            ->where('direction', 'outgoing')
            ->pluck('text')
            ->implode("\n");
        $this->assertStringNotContainsString('youtu.be', $outgoingTexts);
    }

    /** Студент активной группы с опубликованным уроком, у которого есть запись. */
    private function studentWithRecordedLesson(): User
    {
        $course = Course::factory()->create(['title' => 'Продленка санскрита']);
        $group = Group::factory()->create();
        $group->courses()->attach($course->id);

        $student = User::factory()->create();
        $student->groups()->attach($group->id);

        Lesson::factory()->create([
            'course_id' => $course->id,
            'title' => 'Продленка санскрита пт. 9.00 #2',
            'is_published' => true,
            'lesson_date' => now(),
            'youtube_url' => 'https://youtu.be/fence-check',
        ]);

        return $student;
    }

    private function incoming(User $user, string $text): TelegramSupportMessage
    {
        $account = TelegramSupportAccount::query()->where('name', 'support')->firstOrFail();
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9601],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9601,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now(),
        ]);
    }
}
