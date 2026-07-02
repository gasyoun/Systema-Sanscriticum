<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StudentGroupsInfoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('services.openrouter.api_key', 'test-key');

        Http::fake([
            'openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => 'ИИ-ответ']]]], 200),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            'api.vk.com/*' => Http::response(['response' => 1], 200),
        ]);

        // Вебхук TG теперь fail-closed — задаём секрет и шлём его во всех запросах.
        config()->set('services.telegram.bot_webhook_secret', 'test-tg');
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-tg');
    }

    public function test_my_groups_replies_from_db_without_calling_ai(): void
    {
        $course = Course::factory()->create(['title' => 'Грамматика по Кочергиной']);
        $group = Group::create(['name' => 'Грамматика гр.58']);
        $course->groups()->attach($group->id);

        $user = User::factory()->create(['telegram_id' => 777001, 'name' => 'Студент']);
        $user->groups()->attach($group->id);

        Schedule::create([
            'title' => 'Урок 1',
            'group_id' => $group->id,
            'start' => now()->addDays(2)->setTime(14, 0),
            'link' => 'https://zoom.us/j/777',
        ]);

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 777001], 'text' => 'мои группы'],
        ])->assertOk();

        // Ответ собран из БД и отправлен в Telegram — с названием группы, курсом и ссылкой.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.telegram.org')) {
                return false;
            }
            $text = $request->data()['text'] ?? '';

            // Ссылка теперь трекинговая (учёт посещаемости): /class/{id}/join, а не сырой Zoom-URL.
            return str_contains($text, 'Грамматика гр.58')
                && str_contains($text, 'Грамматика по Кочергиной')
                && str_contains($text, '/class/')
                && str_contains($text, '/join');
        });

        // ИИ не дёргали.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openrouter.ai'));

        // Ответ бота сохранён в диалог.
        $this->assertDatabaseHas('chat_messages', ['user_id' => $user->id, 'role' => 'user', 'text' => 'мои группы']);
        $this->assertDatabaseHas('chat_messages', ['user_id' => $user->id, 'role' => 'bot']);
    }

    public function test_my_groups_for_student_without_groups(): void
    {
        $user = User::factory()->create(['telegram_id' => 777002, 'name' => 'Новичок']);

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 777002], 'text' => '/groups'],
        ])->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.telegram.org')) {
                return false;
            }

            return str_contains($request->data()['text'] ?? '', 'не состоите ни в одной');
        });
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openrouter.ai'));
    }
}
