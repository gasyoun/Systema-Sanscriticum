<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Course;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3999: реплей теневой выборки по истории ЛС.
 *
 * Главное свойство — команда НИЧЕГО не меняет: ни исходящих, ни черновиков, ни
 * событий. Замер, который сам себе портит выборку, к выходу волны не годится.
 */
class SupportFactShadowReplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-06 12:00:00');

        Http::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function incoming(?User $user, string $text, int $chatId, string $sentAt): void
    {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support']);
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => $chatId],
            ['linked_user_id' => $user?->id, 'last_message_at' => $sentAt, 'type' => 'private'],
        );

        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => $sentAt,
        ]);
    }

    private function student(): User
    {
        $course = Course::factory()->create(['title' => 'Санскрит с нуля']);
        $group = Group::factory()->create(['status' => 'active']);
        $course->groups()->attach($group->id);

        $student = User::factory()->create();
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
            'last_activity_at' => now()->subDay(),
        ]);

        return $student;
    }

    public function test_the_replay_reports_both_halves_of_the_fraction(): void
    {
        $student = $this->student();

        $this->incoming($student, 'какой у меня остаток по оплате?', 7201, '2026-09-05 10:00:00');
        $this->incoming($student, 'что с моей домашкой, проверили?', 7201, '2026-09-04 10:00:00');
        // Непривязанный контакт: фактов о нём не существует — он попадает в
        // знаменатель «входящих», но не в «с привязкой к кабинету».
        $this->incoming(null, 'здравствуйте, как поступить?', 7202, '2026-09-03 10:00:00');

        Artisan::call('support:fact-shadow-replay', ['--days' => 30]);
        $out = Artisan::output();

        $this->assertStringContainsString('входящих в ЛС 3, из них с привязкой к кабинету 2', $out);
        $this->assertStringContainsString('balance', $out);
        $this->assertStringContainsString('homework', $out);
    }

    public function test_the_replay_writes_nothing_at_all(): void
    {
        $student = $this->student();
        $this->incoming($student, 'какой у меня остаток по оплате?', 7203, '2026-09-05 10:00:00');

        Artisan::call('support:fact-shadow-replay', ['--days' => 30]);

        $this->assertSame(0, TelegramSupportMessage::query()->where('direction', 'outgoing')->count());
        $this->assertSame(0, SupportAnswerSuggestion::query()->count());
        $this->assertSame(0, SupportAiReplyEvent::query()->count());
        Http::assertNothingSent();
    }

    public function test_messages_outside_the_window_are_not_counted(): void
    {
        $student = $this->student();
        $this->incoming($student, 'какой у меня остаток по оплате?', 7204, '2026-06-01 10:00:00');

        Artisan::call('support:fact-shadow-replay', ['--days' => 30]);
        $out = Artisan::output();

        $this->assertStringContainsString('входящих в ЛС 0', $out);
        $this->assertStringContainsString('считать нечего', $out);
    }
}
