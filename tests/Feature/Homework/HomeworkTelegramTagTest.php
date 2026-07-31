<?php

declare(strict_types=1);

namespace Tests\Feature\Homework;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\LessonTelegramHook;
use App\Models\User;
use App\Services\HomeworkTelegramTagService;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Волна 2: #ДЗ / /dz из Telegram-чата группы → homework_prompt (C→B→A→G).
 */
class HomeworkTelegramTagTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-100555666777';

    private Course $course;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.bot_webhook_secret', 'test-tg');
        config()->set('services.telegram.student_bot_token', 'TESTTOKEN');
        config()->set('homework.tg_tag.enabled', true);
        config()->set('homework.tg_tag.lookback_hours', 72);
        config()->set('homework.tg_tag.post_open_invite', true);
        config()->set('homework.auto_open.generic_prompt', 'Домашнее задание');

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-tg');
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 9001],
        ], 200)]);

        $this->course = Course::factory()->create(['slug' => 'hindi-pilot', 'title' => 'Хинди']);
        $this->group = Group::create([
            'name' => 'Хинди А',
            'telegram_chat_id' => self::CHAT_ID,
        ]);
        $this->group->courses()->attach($this->course->id);
    }

    private function staff(int $telegramId = 424242): User
    {
        return User::factory()->create([
            'telegram_id' => $telegramId,
            'role' => Roles::TEACHER,
        ]);
    }

    private function postGroup(string $text, int $fromId = 424242, ?int $replyTo = null): void
    {
        $message = [
            'message_id' => 100,
            'chat' => ['id' => (int) self::CHAT_ID, 'type' => 'supergroup'],
            'from' => ['id' => $fromId],
            'text' => $text,
        ];
        if ($replyTo !== null) {
            $message['reply_to_message'] = ['message_id' => $replyTo];
        }

        $this->postJson('/api/telegram/webhook', ['message' => $message])->assertOk();
    }

    private function assertPrompt(Lesson $lesson, string $prompt): void
    {
        $lesson->refresh();
        $this->assertTrue((bool) $lesson->homework_enabled);
        $this->assertSame($prompt, $lesson->homework_prompt);
    }

    /** @test */
    public function extract_prompt_supports_hash_and_slash(): void
    {
        $svc = app(HomeworkTelegramTagService::class);

        $this->assertSame('Упр. 3', $svc->extractPrompt('#ДЗ Упр. 3'));
        $this->assertSame('Упр. 3', $svc->extractPrompt('#дз Упр. 3'));
        $this->assertSame('dialog', $svc->extractPrompt('/dz dialog'));
        $this->assertSame('dialog', $svc->extractPrompt('/dz@MyBot dialog'));
        $this->assertSame('', $svc->extractPrompt('#ДЗ'));
        $this->assertNull($svc->extractPrompt('просто сообщение'));
    }

    /** @test */
    public function c_reply_to_open_invite_updates_that_lesson(): void
    {
        $this->staff();
        $lesson = Lesson::factory()->for($this->course)->create([
            'group_id' => $this->group->id,
            'title' => 'Урок 5',
            'homework_enabled' => true,
            'homework_prompt' => 'Домашнее задание',
        ]);

        LessonTelegramHook::create([
            'lesson_id' => $lesson->id,
            'group_id' => $this->group->id,
            'telegram_chat_id' => self::CHAT_ID,
            'telegram_message_id' => 55,
            'purpose' => LessonTelegramHook::PURPOSE_OPEN_INVITE,
        ]);

        $this->postGroup('#ДЗ Выучите диалог на с. 12', replyTo: 55);

        $this->assertPrompt($lesson, 'Выучите диалог на с. 12');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'sendMessage')
            && str_contains((string) ($req->data()['text'] ?? ''), 'Обновила ДЗ'));
    }

    /** @test */
    public function b_single_open_placeholder_is_used(): void
    {
        $this->staff();
        $lesson = Lesson::factory()->for($this->course)->create([
            'group_id' => $this->group->id,
            'title' => 'Урок 6',
            'homework_enabled' => true,
            'homework_prompt' => 'Домашнее задание',
            'recording_attached_at' => now()->subHour(),
        ]);

        $this->postGroup('#ДЗ Запишите 5 предложений');

        $this->assertPrompt($lesson, 'Запишите 5 предложений');
    }

    /** @test */
    public function a_newest_recording_within_lookback(): void
    {
        $this->staff();
        Carbon::setTestNow(Carbon::parse('2026-07-31 20:00', 'Europe/Moscow'));

        $old = Lesson::factory()->for($this->course)->create([
            'group_id' => $this->group->id,
            'title' => 'Старый',
            'homework_enabled' => false,
            'recording_attached_at' => now()->subDays(10),
        ]);
        $fresh = Lesson::factory()->for($this->course)->create([
            'group_id' => $this->group->id,
            'title' => 'Свежий',
            'homework_enabled' => false,
            'recording_attached_at' => now()->subHours(2),
        ]);

        $this->postGroup('#ДЗ Новое задание');

        $this->assertFalse((bool) $old->fresh()->homework_enabled);
        $this->assertPrompt($fresh, 'Новое задание');

        Carbon::setTestNow();
    }

    /** @test */
    public function g_ambiguous_sends_buttons_then_callback_applies(): void
    {
        $this->staff(777);

        $a = Lesson::factory()->for($this->course)->create([
            'group_id' => $this->group->id,
            'title' => 'Урок A',
            'homework_enabled' => true,
            'homework_prompt' => 'Домашнее задание',
        ]);
        $b = Lesson::factory()->for($this->course)->create([
            'group_id' => $this->group->id,
            'title' => 'Урок B',
            'homework_enabled' => true,
            'homework_prompt' => 'Домашнее задание',
        ]);

        $this->postGroup('#ДЗ Общее условие', fromId: 777);

        // Prompt not applied yet — waiting for button.
        $this->assertSame('Домашнее задание', $a->fresh()->homework_prompt);
        $this->assertSame('Домашнее задание', $b->fresh()->homework_prompt);

        Http::assertSent(function ($req) {
            $data = $req->data();
            $markup = $data['reply_markup'] ?? null;
            if (! is_string($markup)) {
                return false;
            }

            return str_contains($markup, HomeworkTelegramTagService::CALLBACK_PREFIX)
                && str_contains((string) ($data['text'] ?? ''), 'Несколько уроков');
        });

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'cb1',
                'from' => ['id' => 777],
                'data' => HomeworkTelegramTagService::CALLBACK_PREFIX.$b->id,
                'message' => ['chat' => ['id' => (int) self::CHAT_ID]],
            ],
        ])->assertOk();

        $this->assertSame('Домашнее задание', $a->fresh()->homework_prompt);
        $this->assertPrompt($b, 'Общее условие');
    }

    /** @test */
    public function student_tag_is_silently_ignored(): void
    {
        User::factory()->create(['telegram_id' => 111, 'role' => null]);
        $lesson = Lesson::factory()->for($this->course)->create([
            'group_id' => $this->group->id,
            'homework_enabled' => true,
            'homework_prompt' => 'Домашнее задание',
        ]);

        $this->postGroup('#ДЗ Хакерское', fromId: 111);

        $this->assertSame('Домашнее задание', $lesson->fresh()->homework_prompt);
    }

    /** @test */
    public function unknown_chat_explains_missing_group_link(): void
    {
        $this->staff();
        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => -1999888777, 'type' => 'supergroup'],
                'from' => ['id' => 424242],
                'text' => '#ДЗ что-то',
            ],
        ])->assertOk();

        Http::assertSent(fn ($req) => str_contains((string) ($req->data()['text'] ?? ''), 'не привязан'));
    }

    /** @test */
    public function empty_prompt_after_tag_is_rejected(): void
    {
        $this->staff();
        Lesson::factory()->for($this->course)->create([
            'group_id' => $this->group->id,
            'homework_enabled' => true,
            'homework_prompt' => 'Домашнее задание',
        ]);

        $this->postGroup('#ДЗ');

        Http::assertSent(fn ($req) => str_contains((string) ($req->data()['text'] ?? ''), 'нужен текст'));
    }

    /** @test */
    public function generic_open_posts_invite_and_stores_hook(): void
    {
        config([
            'homework.auto_open.enabled' => true,
            'homework.auto_open.generic_course_slugs' => ['hindi-pilot'],
            'homework.auto_open.generic_immediate' => true,
            'homework.auto_open.notify_channels' => [],
        ]);

        $lesson = Lesson::factory()->for($this->course)->create([
            'group_id' => $this->group->id,
            'title' => 'Урок invite',
        ]);

        $lesson->update(['youtube_url' => 'https://youtu.be/invite-test']);

        $this->assertTrue((bool) $lesson->fresh()->homework_enabled);
        $this->assertDatabaseHas('lesson_telegram_hooks', [
            'lesson_id' => $lesson->id,
            'telegram_chat_id' => self::CHAT_ID,
            'telegram_message_id' => 9001,
            'purpose' => LessonTelegramHook::PURPOSE_OPEN_INVITE,
        ]);
    }

    /** @test */
    public function non_tag_group_message_does_not_spam_link_account(): void
    {
        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => (int) self::CHAT_ID, 'type' => 'supergroup'],
                'from' => ['id' => 999],
                'text' => 'просто болтовня',
            ],
        ])->assertOk();

        Http::assertNotSent(fn ($req) => str_contains((string) ($req->data()['text'] ?? ''), 'привяжите'));
    }

    /** @test */
    public function feature_flag_off_does_not_apply_prompt(): void
    {
        config(['homework.tg_tag.enabled' => false]);
        $this->staff();
        $lesson = Lesson::factory()->for($this->course)->create([
            'group_id' => $this->group->id,
            'homework_enabled' => true,
            'homework_prompt' => 'Домашнее задание',
        ]);

        $this->postGroup('#ДЗ Не должно примениться');

        $this->assertSame('Домашнее задание', $lesson->fresh()->homework_prompt);
    }
}
