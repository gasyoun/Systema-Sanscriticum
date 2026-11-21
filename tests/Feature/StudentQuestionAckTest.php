<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ComposeStudentQuestionDraft;
use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\LessonTelegramHook;
use App\Models\MarketingSetting;
use App\Models\Teacher;
use App\Models\TelegramChatPost;
use App\Models\TelegramScheduleReschedule;
use App\Models\TelegramStudentQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * H8527: студент не молчит — canned-подсказка на reply-вопрос к посту бота,
 * нераспознанные вопросы и предложения переносов → стопочка (никогда не
 * мутации: см. StudentMutationPinTest).
 */
class StudentQuestionAckTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-1003994169966';

    private const STUDENT_TG_ID = 777001;

    private const TEACHER_TG_ID = 5487293147;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 777]]),
        ]);
        Redis::shouldReceive('set')->andReturn(true);

        MarketingSetting::flushCached();
        MarketingSetting::create(['zapisi_bot_token' => 'ZAPISITOKEN']);

        $course = Course::factory()->create(['slug' => 'grammar-63', 'title' => 'Грамматика по Кочергиной гр.63']);
        $group = Group::create(['name' => 'Грамматика по Кочергиной гр.63', 'telegram_chat_id' => self::CHAT_ID]);
        $course->groups()->attach($group->id);
        $this->group = $group;

        User::factory()->create([
            'telegram_id' => self::STUDENT_TG_ID,
            'role' => 'student',
        ]);
    }

    private Group $group;

    private function homeworkHook(int $messageId = 55): void
    {
        $lesson = Lesson::factory()->for($this->group->courses->first())->create([
            'group_id' => $this->group->id,
            'title' => 'Урок 5',
        ]);
        LessonTelegramHook::create([
            'lesson_id' => $lesson->id,
            'group_id' => $this->group->id,
            'telegram_chat_id' => self::CHAT_ID,
            'telegram_message_id' => $messageId,
            'purpose' => LessonTelegramHook::PURPOSE_OPEN_INVITE,
        ]);
    }

    private function message(string $text, ?int $replyTo = null, ?array $replied = null, ?int $fromId = null, ?bool $fromIsBot = null, int $messageId = 100): array
    {
        $message = [
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup'],
                'message_id' => $messageId,
                'from' => array_filter([
                    'id' => $fromId ?? self::STUDENT_TG_ID,
                    'is_bot' => $fromIsBot,
                ], fn ($value) => $value !== null),
                'text' => $text,
                'date' => now()->timestamp,
            ],
        ];
        if ($replyTo !== null) {
            $message['message']['reply_to_message'] = $replied ?? [
                'message_id' => $replyTo,
                'from' => ['id' => 500, 'is_bot' => true],
                'text' => 'Текст поста бота',
            ];
        }

        return $message;
    }

    private function process(array $update): void
    {
        (new ProcessTelegramZapisiUpdate($update))->handle();
    }

    public function test_homework_reply_gets_one_line_hint_and_no_queue_row(): void
    {
        $this->homeworkHook();

        $this->process($this->message(
            'Поясните, пожалуйста, что за домашнее задание',
            replyTo: 55,
            replied: [
                'message_id' => 55,
                'from' => ['id' => 500, 'is_bot' => true],
                'text' => '📝 Приём ДЗ открыт: Урок 5 (05.12.2026)',
            ],
        ));

        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Это пост о приёме ДЗ')
            && ($request['reply_to_message_id'] ?? null) === 100);
        Http::assertSentCount(1);
        $this->assertSame(0, TelegramStudentQuestion::count());
        Queue::assertNotPushed(ComposeStudentQuestionDraft::class);
    }

    public function test_unknown_bot_post_reply_goes_to_queue_with_draft(): void
    {
        $this->process($this->message(
            'а когда будет запись этого занятия?',
            replyTo: 56,
            replied: [
                'message_id' => 56,
                'from' => ['id' => 500, 'is_bot' => true],
                'text' => 'Какой-то пост бота без маркеров',
            ],
        ));

        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Вопрос зафиксировал'));
        $question = TelegramStudentQuestion::query()->sole();
        $this->assertSame('question', $question->kind);
        $this->assertSame('unknown', $question->post_kind);
        $this->assertSame('new', $question->status);
        $this->assertSame((string) self::STUDENT_TG_ID, $question->author_telegram_id);
        $this->assertSame(56, $question->reply_to_message_id);
        Queue::assertPushed(ComposeStudentQuestionDraft::class);
    }

    public function test_reminder_post_reply_gets_reminder_hint(): void
    {
        TelegramChatPost::create([
            'schedule_id' => null,
            'chat_id' => self::CHAT_ID,
            'message_id' => 57,
            'kind' => TelegramChatPost::KIND_ZAPISI_REMINDER,
        ]);

        $this->process($this->message(
            'а ссылка где?',
            replyTo: 57,
            replied: [
                'message_id' => 57,
                'from' => ['id' => 500, 'is_bot' => true],
                'text' => '🔔 Скоро занятие',
            ],
        ));

        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Это напоминание о занятии'));
        $this->assertSame(0, TelegramStudentQuestion::count());
    }

    public function test_student_reschedule_proposal_only_goes_to_queue(): void
    {
        $this->process($this->message('может на другой день недели перенести?'));

        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Предложение по переносу зафиксировал'));
        $question = TelegramStudentQuestion::query()->sole();
        $this->assertSame('proposal', $question->kind);
        $this->assertNull($question->post_kind);
        Queue::assertNotPushed(ComposeStudentQuestionDraft::class);
        $this->assertSame(0, TelegramScheduleReschedule::count());
    }

    public function test_staff_message_is_ignored(): void
    {
        $teacher = Teacher::create(['name' => 'Препод Свой']);
        $this->group->courses->first()->update(['teacher_id' => $teacher->id]);
        User::factory()->create([
            'telegram_id' => self::TEACHER_TG_ID,
            'role' => 'teacher',
            'teacher_id' => $teacher->id,
        ]);

        $this->process($this->message('а что это за пост такой?', replyTo: 58, fromId: self::TEACHER_TG_ID));

        Http::assertNothingSent();
        $this->assertSame(0, TelegramStudentQuestion::count());
    }

    public function test_student_to_student_reply_is_silent(): void
    {
        $this->process($this->message(
            'а ты знаешь что это?',
            replyTo: 59,
            replied: [
                'message_id' => 59,
                'from' => ['id' => 888, 'is_bot' => false],
                'text' => 'Случайное сообщение одногруппника',
            ],
        ));

        Http::assertNothingSent();
        $this->assertSame(0, TelegramStudentQuestion::count());
    }

    public function test_flag_off_is_silent(): void
    {
        config()->set('services.telegram_zapisi.student_question_ack_detect', false);

        $this->process($this->message('вопрос на пост бота?', replyTo: 60));

        Http::assertNothingSent();
        $this->assertSame(0, TelegramStudentQuestion::count());
    }

    public function test_bot_own_message_is_ignored(): void
    {
        $this->process($this->message('перенести 24.12 и 31.12 на 26.12 и 28.12', fromIsBot: true));

        Http::assertNothingSent();
        $this->assertSame(0, TelegramStudentQuestion::count());
        $this->assertSame(0, TelegramScheduleReschedule::count());
    }

    public function test_duplicate_message_creates_one_queue_row_and_one_ack(): void
    {
        // Повтор апдейта не плодит строк стопочки и повторных черновиков:
        // дедуп строки — DB-level (unique chat+message), повторное ack-отправление
        // гасится Redis-клеймом (TelegramSendGuard::claimKey, как у clarify).
        Redis::shouldReceive('set')->andReturn(true);
        $update = $this->message('а что здесь имелось в виду?', replyTo: 61);

        $this->process($update);
        $this->process($update);

        $this->assertSame(1, TelegramStudentQuestion::count());
        Queue::assertPushedTimes(ComposeStudentQuestionDraft::class, 1);
    }

    public function test_unknown_sender_stays_silent(): void
    {
        $this->process($this->message('перенести 24.12 на 26.12', fromId: 999999));

        Http::assertNothingSent();
        $this->assertSame(0, TelegramStudentQuestion::count());
        $this->assertSame(0, TelegramScheduleReschedule::count());
    }
}
