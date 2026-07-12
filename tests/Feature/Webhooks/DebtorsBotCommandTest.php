<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Куратор-команды `/долги [группа]`, `/группа [название]`, `/кто <имя>`
 * поверх DebtorsReport/ClassRoster-паттерна (S4/S6, H250/H816):
 * авторизация по роли, тишина для посторонних/студентов, сводка +
 * фильтр по группе, ростер группы с цвет-статусом долга, поиск
 * студента, лог использования.
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
    public function group_command_without_arg_lists_active_groups(): void
    {
        $this->debtor('Кто-то');
        User::factory()->create(['telegram_id' => 224, 'role' => Roles::ADMIN]);

        $this->send(224, '/группа');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request->data()['text'], 'Группа Альфа'));
    }

    /** @test */
    public function group_command_with_arg_shows_roster_with_debt_colors(): void
    {
        $debtor = $this->debtor('Иван Должников');
        $payer = User::factory()->create(['name' => 'Анна Платёжная']);
        $this->group->users()->attach($payer->id);
        Payment::create([
            'user_id' => $payer->id,
            'course_id' => $this->course->id,
            'amount' => 5000,
            'status' => 'paid',
            'tariff' => 'full',
        ]);
        User::factory()->create(['telegram_id' => 225, 'role' => Roles::ADMIN]);

        $this->send(225, '/группа Альфа');

        Http::assertSent(function ($request) use ($debtor, $payer) {
            if (! str_contains($request->url(), 'api.telegram.org')) {
                return false;
            }
            $text = $request->data()['text'];

            return str_contains($text, 'Группа Альфа')
                && str_contains($text, 'Учеников: 2')
                && str_contains($text, "🔴 {$debtor->name}")
                && str_contains($text, "🟢 {$payer->name}");
        });
    }

    /** @test */
    public function group_command_unknown_name_replies_not_found(): void
    {
        User::factory()->create(['telegram_id' => 226, 'role' => Roles::ADMIN]);

        $this->send(226, '/группа Несуществующая');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request->data()['text'], 'не найдена'));
    }

    /** @test */
    public function who_command_finds_student_by_name_and_shows_groups(): void
    {
        $student = $this->debtor('Сергей Искомый');
        User::factory()->create(['telegram_id' => 227, 'role' => Roles::ADMIN]);

        $this->send(227, '/кто Искомый');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request->data()['text'], $student->name)
            && str_contains($request->data()['text'], 'Группа Альфа'));
    }

    /** @test */
    public function who_command_finds_student_by_exact_telegram_username(): void
    {
        $student = User::factory()->create(['name' => 'Тот Самый', 'telegram_username' => 'tot_samy']);
        $this->group->users()->attach($student->id);
        User::factory()->create(['telegram_id' => 228, 'role' => Roles::ADMIN]);

        $this->send(228, '/кто @tot_samy');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request->data()['text'], 'Тот Самый'));
    }

    /** @test */
    public function who_command_unknown_name_replies_not_found(): void
    {
        User::factory()->create(['telegram_id' => 229, 'role' => Roles::ADMIN]);

        $this->send(229, '/кто Никого-Такого');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request->data()['text'], 'не найден'));
    }

    /** @test */
    public function who_command_ambiguous_name_asks_to_narrow(): void
    {
        User::factory()->create(['name' => 'Иван Первый']);
        User::factory()->create(['name' => 'Иван Второй']);
        User::factory()->create(['telegram_id' => 230, 'role' => Roles::ADMIN]);

        $this->send(230, '/кто Иван');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request->data()['text'], 'уточните'));
    }

    /** @test */
    public function who_command_without_arg_shows_usage(): void
    {
        User::factory()->create(['telegram_id' => 231, 'role' => Roles::ADMIN]);

        $this->send(231, '/кто');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request->data()['text'], 'Укажите имя'));
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
