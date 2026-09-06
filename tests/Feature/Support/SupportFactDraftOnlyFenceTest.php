<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Course;
use App\Models\FollowUpTask;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;
use App\Models\Tariff;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportAnswerFactResolver;
use App\Services\Support\SupportDmAutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3999, рулинг A1 — ЗАБОР КОДОМ, А НЕ КОНФИГОМ.
 *
 * Самый важный тест волны 1. Человек может вписать в `support.facts.live_types`
 * что угодно — деньги, доступ и сертификат всё равно не уходят студенту сами:
 *  1. {@see SupportAnswerFactResolver::NEVER_AUTO_TYPES} вырезается из списка
 *     живых типов в {@see SupportDmAutoReply};
 *  2. {@see SupportAnswerSuggestion::isDraftOnly()} перепроверяет тип и политику
 *     ещё раз — уже на самом черновике, поэтому кнопки под ним нет;
 *  3. расхождение с названной студентом суммой не отвечает вообще ничем и
 *     заводит follow-up финансовому лиду.
 */
class SupportFactDraftOnlyFenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-06 12:00:00');

        config([
            'features.support_dm_auto_reply' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
            // Человек «открыл» ВСЁ, включая запрещённое. Забор должен выстоять.
            'support.facts.live_types' => [
                SupportAnswerFactResolver::TYPE_ZOOM,
                SupportAnswerFactResolver::TYPE_SCHEDULE,
                SupportAnswerFactResolver::TYPE_RECORDING,
                SupportAnswerFactResolver::TYPE_HOMEWORK,
                SupportAnswerFactResolver::TYPE_BALANCE,
                SupportAnswerFactResolver::TYPE_ACCESS,
                SupportAnswerFactResolver::TYPE_CERTIFICATE,
            ],
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: User, 1: Course} */
    private function paidStudent(): array
    {
        $course = Course::factory()->create(['title' => 'Санскрит с нуля']);
        $group = Group::factory()->create(['status' => 'active']);
        $course->groups()->attach($group->id);

        $student = User::factory()->create(['name' => 'Студент Тест']);
        $student->groups()->attach($group->id);

        Tariff::factory()->create(['course_id' => $course->id, 'price' => 12000, 'is_active' => true]);

        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_1',
            'status' => 'paid',
            'is_conditional' => false,
        ]));

        return [$student, $course];
    }

    private function incoming(User $user, string $text): TelegramSupportMessage
    {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support']);
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9301],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9301,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now(),
        ]);
    }

    public function test_a_money_answer_never_reaches_the_student_even_with_balance_declared_live(): void
    {
        [$student] = $this->paidStudent();
        $incoming = $this->incoming($student, 'сколько мне ещё осталось доплатить за курс?');

        $result = app(SupportDmAutoReply::class)->handle($incoming, $student->id, 'private');

        $this->assertSame('hinted', $result['status']);
        $this->assertSame(
            0,
            TelegramSupportMessage::query()->where('direction', 'outgoing')->count(),
            'Деньги студенту не уходят ни при каком составе live_types.',
        );

        $draft = SupportAnswerSuggestion::query()->latest('id')->first();
        $this->assertNotNull($draft);
        $this->assertSame(SupportAnswerFactResolver::POLICY_DRAFT_ONLY, $draft->sendPolicy());
        $this->assertTrue($draft->isDraftOnly());
    }

    public function test_the_draft_only_hint_carries_no_send_button(): void
    {
        [$student] = $this->paidStudent();
        $incoming = $this->incoming($student, 'какой у меня остаток по оплате?');

        app(SupportDmAutoReply::class)->handle($incoming, $student->id, 'private');

        Http::assertSent(function ($request): bool {
            $text = (string) ($request['text'] ?? '');
            if (! str_contains($text, 'Сложный вопрос')) {
                return false;
            }

            // Ни клавиатуры, ни обещания «кнопка ниже отправит» — вместо них
            // куратора отправляют в очередь черновиков.
            return ! isset($request['reply_markup'])
                && str_contains($text, 'кнопки под ним нет')
                && ! str_contains($text, 'Кнопка ниже отправит');
        });
    }

    public function test_a_draft_only_fact_never_enters_the_shadow_sample(): void
    {
        config(['features.support_dm_auto_reply_shadow' => true]);

        [$student] = $this->paidStudent();
        $incoming = $this->incoming($student, 'какой у меня остаток по оплате?');

        app(SupportDmAutoReply::class)->handle($incoming, $student->id, 'private');

        // Точность недели считается по строкам, которые ДЕЙСТВИТЕЛЬНО ушли бы
        // при открытом типе. Деньги не уйдут никогда — в знаменателе им не место.
        $this->assertSame(
            0,
            SupportAiReplyEvent::query()
                ->where('event_type', SupportDmAutoReply::EVENT_FACT_SHADOW_WOULD_SEND)
                ->count(),
        );
    }

    public function test_a_would_send_fact_enters_the_shadow_sample(): void
    {
        config([
            'features.support_dm_auto_reply_shadow' => true,
            // Тип ДЗ ещё НЕ живой — ровно то состояние, которое неделя и меряет.
            'support.facts.live_types' => [
                SupportAnswerFactResolver::TYPE_ZOOM,
                SupportAnswerFactResolver::TYPE_SCHEDULE,
                SupportAnswerFactResolver::TYPE_RECORDING,
            ],
        ]);

        [$student, $course] = $this->paidStudent();
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

        $incoming = $this->incoming($student, 'что с моей домашкой, проверили?');

        app(SupportDmAutoReply::class)->handle($incoming, $student->id, 'private');

        $this->assertSame(
            0,
            TelegramSupportMessage::query()->where('direction', 'outgoing')->count(),
            'Тип не открыт — студенту по-прежнему не уходит ничего.',
        );

        $event = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_FACT_SHADOW_WOULD_SEND)
            ->first();

        $this->assertNotNull($event, 'Без теневого события неделя измерений даёт пустой знаменатель.');
        $this->assertSame(SupportAnswerFactResolver::TYPE_HOMEWORK, $event->meta['fact_type']);
    }

    public function test_a_disputed_figure_answers_nothing_and_opens_a_finance_follow_up(): void
    {
        config(['features.support_follow_up_tasks' => true]);

        $financeLead = User::factory()->create(['name' => 'Финлид']);
        config(['support.escalation.finance_lead_user_id' => $financeLead->id]);

        [$student] = $this->paidStudent();
        $incoming = $this->incoming($student, 'я оплатила 30 000, а доступа нет');

        $result = app(SupportDmAutoReply::class)->handle($incoming, $student->id, 'private');

        $this->assertSame('hinted', $result['status']);
        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());

        $this->assertSame(
            1,
            SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_BALANCE_DISPUTE)->count(),
        );

        $task = FollowUpTask::query()->latest('id')->first();
        $this->assertNotNull($task, 'Расхождение по деньгам обязано оставить задачу человеку.');
        $this->assertSame($financeLead->id, (int) $task->assigned_to);

        // Черновика нет вовсе — ни с расчётной суммой, ни со статьёй из FAQ:
        // «студенту не уходит ничего» включает и канреплай в один тап.
        $this->assertSame(0, SupportAnswerSuggestion::query()->count());
    }

    public function test_a_dispute_without_the_follow_up_flag_still_hints_instead_of_throwing(): void
    {
        config(['features.support_follow_up_tasks' => false]);

        [$student] = $this->paidStudent();
        $incoming = $this->incoming($student, 'я оплатила 30 000, а доступа нет');

        $result = app(SupportDmAutoReply::class)->handle($incoming, $student->id, 'private');

        $this->assertSame('hinted', $result['status']);
        $this->assertSame(0, FollowUpTask::query()->count());
        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
    }

    public function test_a_pre_h3999_draft_without_a_policy_label_is_still_fenced_by_its_fact_type(): void
    {
        [$student] = $this->paidStudent();
        $incoming = $this->incoming($student, 'остаток?');

        // Черновик, заведённый до H3999: политики в facts нет вовсе.
        $legacy = SupportAnswerSuggestion::create([
            'user_id' => $student->id,
            'source_type' => SupportAnswerSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE,
            'source_id' => $incoming->id,
            'category' => SupportAnswerSuggestion::CATEGORY_PAYMENT,
            'draft_text' => 'К оплате остаётся 12 000 ₽.',
            'status' => SupportAnswerSuggestion::STATUS_PENDING,
            'facts' => ['fact_type' => SupportAnswerFactResolver::TYPE_BALANCE],
        ]);

        $this->assertSame(SupportAnswerFactResolver::POLICY_AUTO, $legacy->sendPolicy());
        $this->assertTrue(
            $legacy->isDraftOnly(),
            'Тип факта закрывает автоотправку даже у черновика без метки политики.',
        );
    }
}
