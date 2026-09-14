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
 * фильтр по группе, лог использования. (Ростер `/группа`/`/кто` — S6,
 * см. RosterBotCommandTest.) H3912: подсказка ближайших групп,
 * просрочка в списке, работа в чате «Отдел заботы».
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
    public function unknown_group_name_suggests_nearest_groups(): void
    {
        $this->debtor('Пётр Подсказка');
        User::factory()->create(['telegram_id' => 225, 'role' => Roles::ADMIN]);

        $this->send(225, '/долги Альфаа');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request->data()['text'], 'Группа «Альфаа» не найдена')
            && str_contains($request->data()['text'], 'Похожие группы')
            && str_contains($request->data()['text'], 'Группа Альфа'));
    }

    /** @test */
    public function group_command_routes_to_roster(): void
    {
        // /группа больше не заглушка — теперь настоящий ростер (S6, RosterBotCommand).
        $this->debtor('Мария Ростер');
        User::factory()->create(['telegram_id' => 224, 'role' => Roles::ADMIN]);

        $this->send(224, '/группа Альфа');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request->data()['text'], 'Группа «Группа Альфа»')
            && str_contains($request->data()['text'], 'Мария Ростер'));
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

    /** @test */
    public function debtors_list_shows_overdue_days(): void
    {
        $this->debtor('Анна Просрочка');
        User::factory()->create(['telegram_id' => 226, 'role' => Roles::ADMIN]);

        CourseBlock::query()
            ->where('course_id', $this->course->id)
            ->where('is_current', true)
            ->update(['starts_at' => now()->subDays(20)]);

        $this->send(226, '/долги Альфа');

        // 20 дней → floor(20/7) = 2 нед (DebtorsReport::formatOverdue).
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request->data()['text'], 'Анна Просрочка')
            && str_contains($request->data()['text'], 'просрочено 2 нед'));
    }

    /** @test */
    public function curator_command_in_care_chat_replies_to_chat(): void
    {
        config()->set('recording_gap.care_telegram_chat_id', '-100999');

        $this->debtor('Игорь Забота');
        User::factory()->create(['telegram_id' => 227, 'role' => Roles::ADMIN]);

        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => -100999, 'type' => 'supergroup'],
                'from' => ['id' => 227],
                'text' => '/долги Альфа',
            ],
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && ($request->data()['chat_id'] ?? null) === -100999
            && str_contains($request->data()['text'], 'Игорь Забота'));
    }

    /** @test */
    public function care_chat_command_from_non_curator_gets_silence(): void
    {
        config()->set('recording_gap.care_telegram_chat_id', '-100999');

        $this->debtor('Сергей Сторонний');
        User::factory()->create(['telegram_id' => 228, 'role' => null]);

        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => -100999, 'type' => 'supergroup'],
                'from' => ['id' => 228],
                'text' => '/долги Альфа',
            ],
        ])->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));
    }

    /** @test */
    public function curator_command_in_regular_group_chat_gets_silence(): void
    {
        config()->set('recording_gap.care_telegram_chat_id', '-100999');

        $this->debtor('Тамара Обычная');
        User::factory()->create(['telegram_id' => 229, 'role' => Roles::ADMIN]);

        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => -100888, 'type' => 'supergroup'],
                'from' => ['id' => 229],
                'text' => '/долги Альфа',
            ],
        ])->assertOk();

        // Вне «Отдела заботы» куратор-команды в группах по-прежнему молчат —
        // долговой список не должен светиться в студенческих чатах.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));
    }
}
