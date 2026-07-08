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
 * Куратор-команда `/долги [группа]` поверх DebtorsReport (S4, H250):
 * авторизация по роли, тишина для посторонних/студентов, сводка +
 * фильтр по группе, `/группа`-заглушка, лог использования.
 */
class DebtorsBotCommandTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.bot_webhook_secret', 'test-tg');
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-tg');

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->course = Course::factory()->create(['is_active' => true]);
        CourseBlock::factory()->for($this->course)->current()->create(['number' => 1]);
        Tariff::factory()->for($this->course)->block(1)->create();

        $this->group = Group::create(['name' => 'Группа Альфа']);
        $this->group->courses()->attach($this->course->id);
    }

    private function debtor(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->group->users()->attach($user->id);

        return $user;
    }

    private function send(int $chatId, string $text): void
    {
        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => $chatId], 'text' => $text],
        ])->assertOk();
    }

    /** @test */
    public function curator_gets_debtors_summary(): void
    {
        $this->debtor('Иван Должников');
        $curator = User::factory()->create(['telegram_id' => 111, 'role' => Roles::ADMIN]);

        $this->send(111, '/долги');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.telegram.org')) {
                return false;
            }
            $body = $request->data();

            return ($body['chat_id'] ?? null) == 111
                && str_contains($body['text'], 'Должников: 1')
                && str_contains($body['text'], 'Иван Должников');
        });

        $this->assertDatabaseHas('activity_events', [
            'user_id' => $curator->id,
            'event_type' => ActivityEvent::TYPE_CURATOR_BOT_COMMAND,
        ]);
    }

    /** @test */
    public function curator_can_filter_by_group_name(): void
    {
        $this->debtor('Пётр Групповой');
        User::factory()->create(['telegram_id' => 222, 'role' => Roles::ADMIN]);

        $this->send(222, '/долги Альфа');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request->data()['text'], 'Пётр Групповой'));
    }

    /** @test */
    public function unknown_group_name_replies_not_found(): void
    {
        User::factory()->create(['telegram_id' => 223, 'role' => Roles::ADMIN]);

        $this->send(223, '/долги Несуществующая');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request->data()['text'], 'не найдена'));
    }

    /** @test */
    public function group_stub_replies_soon(): void
    {
        User::factory()->create(['telegram_id' => 224, 'role' => Roles::ADMIN]);

        $this->send(224, '/группа');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request->data()['text'], 'скоро'));
    }

    /** @test */
    public function unauthorized_linked_student_gets_silence(): void
    {
        User::factory()->create(['telegram_id' => 333, 'role' => null]);

        $this->send(333, '/долги');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));
    }

    /** @test */
    public function unlinked_chat_id_gets_silence_not_the_usual_link_account_prompt(): void
    {
        $this->send(444, '/долги');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));
    }

    /** @test */
    public function no_debtors_replies_positively(): void
    {
        User::factory()->create(['telegram_id' => 555, 'role' => Roles::ADMIN]);

        $this->send(555, '/долги');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request->data()['text'], 'нет'));
    }
}
