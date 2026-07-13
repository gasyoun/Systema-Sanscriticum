<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Tariff;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Куратор-команды `/группа` и `/кто` (S6, H816 PR 3): ростер группы с долговым
 * маркером, поиск студента, роль-гейт (тишина посторонним), лог использования.
 */
class RosterBotCommandTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.bot_webhook_secret', 'test-tg');
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-tg');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->course = Course::factory()->create(['is_active' => true, 'title' => 'Йога-сутры']);
        CourseBlock::factory()->for($this->course)->current()->create(['number' => 1]);
        Tariff::factory()->for($this->course)->block(1)->create();

        $this->group = Group::create(['name' => 'Группа Альфа']);
        $this->group->courses()->attach($this->course->id);
    }

    private function studentInGroup(string $name, ?string $username = null): User
    {
        $user = User::factory()->create(['name' => $name, 'telegram_username' => $username]);
        $this->group->users()->attach($user->id);

        return $user;
    }

    private function curator(int $chatId): User
    {
        return User::factory()->create(['telegram_id' => $chatId, 'role' => Roles::ADMIN]);
    }

    private function send(int $chatId, string $text): void
    {
        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => $chatId], 'text' => $text],
        ])->assertOk();
    }

    private function assertReplyContains(int $chatId, string ...$needles): void
    {
        Http::assertSent(function ($request) use ($chatId, $needles) {
            if (! str_contains($request->url(), 'api.telegram.org')) {
                return false;
            }
            $body = $request->data();
            if (($body['chat_id'] ?? null) != $chatId) {
                return false;
            }
            foreach ($needles as $needle) {
                if (! str_contains($body['text'], $needle)) {
                    return false;
                }
            }

            return true;
        });
    }

    /** @test */
    public function group_roster_lists_active_students_with_debt_marker(): void
    {
        $this->studentInGroup('Иван Должник', 'ivan_dolg'); // без оплаты → долг
        $curator = $this->curator(111);

        $this->send(111, '/группа Альфа');

        // Ростер: имя группы, курс, студент с ⚠️ (числится должником) и @username.
        $this->assertReplyContains(111, 'Группа «Группа Альфа»', 'Йога-сутры', 'Иван Должник', '@ivan_dolg', '⚠️');
        $this->assertDatabaseHas('activity_events', [
            'user_id' => $curator->id,
            'event_type' => ActivityEvent::TYPE_CURATOR_BOT_COMMAND,
        ]);
    }

    /** @test */
    public function group_roster_without_course_marks_students_clear(): void
    {
        $bare = Group::create(['name' => 'Группа Бета']); // без курса → долга быть не может
        $student = User::factory()->create(['name' => 'Ольга Чистая']);
        $bare->users()->attach($student->id);
        $this->curator(112);

        $this->send(112, '/группа Бета');

        $this->assertReplyContains(112, 'Группа «Группа Бета»', 'Ольга Чистая', '✅');
    }

    /** @test */
    public function group_without_argument_lists_groups(): void
    {
        $this->curator(113);

        $this->send(113, '/группа');

        $this->assertReplyContains(113, 'Группы', 'Группа Альфа');
    }

    /** @test */
    public function unknown_group_replies_not_found(): void
    {
        $this->curator(114);

        $this->send(114, '/группа Несуществующая');

        $this->assertReplyContains(114, 'не найдена');
    }

    /** @test */
    public function who_finds_single_student_and_shows_groups(): void
    {
        $this->studentInGroup('Пётр Уникум', 'petr_uniq');
        $this->curator(115);

        $this->send(115, '/кто Уникум');

        $this->assertReplyContains(115, 'Пётр Уникум', '@petr_uniq', 'Активные группы', 'Группа Альфа');
    }

    /** @test */
    public function who_lists_multiple_matches(): void
    {
        $this->studentInGroup('Анна Иванова');
        $this->studentInGroup('Борис Иванов');
        $this->curator(116);

        $this->send(116, '/кто Иванов');

        $this->assertReplyContains(116, 'Найдено несколько', 'Анна Иванова', 'Борис Иванов');
    }

    /** @test */
    public function who_not_found_replies_plainly(): void
    {
        $this->curator(117);

        $this->send(117, '/кто Зупумбий');

        $this->assertReplyContains(117, 'не найден');
    }

    /** @test */
    public function who_by_username_strips_at_sign(): void
    {
        $this->studentInGroup('Женя Тег', 'zhenya_tag');
        $this->curator(118);

        $this->send(118, '/кто @zhenya_tag');

        $this->assertReplyContains(118, 'Женя Тег', '@zhenya_tag');
    }

    /** @test */
    public function unauthorized_student_gets_silence(): void
    {
        User::factory()->create(['telegram_id' => 333, 'role' => null]);

        $this->send(333, '/группа Альфа');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));
    }

    /** @test */
    public function unlinked_chat_gets_silence(): void
    {
        $this->send(444, '/кто Иван');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));
    }
}
