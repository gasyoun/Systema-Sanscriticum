<?php

declare(strict_types=1);

namespace Tests\Feature\OpenLessonCards;

use App\Jobs\ProcessTelegramMagnetUpdate;
use App\Models\BotSubscriber;
use App\Models\Course;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\SubscriberMagnet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «Воронка» @samskrte_bot по образцу @AlpinaAIconfbot: /start из трёх
 * сообщений, меню из пяти кнопок с единым призывом «Участвовать», путь
 * «Участвовать» — подписка на канал (проверка) → анкета → лид → подарки.
 */
class OpenLessonFunnelTest extends TestCase
{
    use RefreshDatabase;

    private string $memberStatus = 'member';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'Europe/Moscow'));
        config([
            'features.open_lesson_cards' => true,
            'features.waitlist_voting' => false,
            'services.telegram.marketers_chat_id' => null,
        ]);
        MarketingSetting::create(['tg_bot_username' => 'samskrte_bot', 'tg_bot_token' => 'MAGNET-TOKEN']);
        SubscriberMagnet::create(['title' => 'Деванагари за вечер', 'description' => 'Прописи и таблица', 'url' => 'https://samskrte.ru/m/devanagari', 'is_active' => true]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/getChatMember')) {
                return Http::response(['ok' => true, 'result' => ['status' => $this->memberStatus]]);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function update(array $update): void
    {
        (new ProcessTelegramMagnetUpdate($update))->handle();
    }

    private function text(string $text, int $chatId = 1001): void
    {
        $this->update(['message' => [
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'from' => ['id' => $chatId, 'first_name' => 'Аня', 'username' => 'anya'],
            'text' => $text,
        ]]);
    }

    private function press(string $data, int $chatId = 1001): void
    {
        $this->update(['callback_query' => [
            'id' => 'cb-'.$data,
            'data' => $data,
            'from' => ['id' => $chatId, 'first_name' => 'Аня', 'username' => 'anya'],
            'message' => ['message_id' => 9, 'chat' => ['id' => $chatId, 'type' => 'private']],
        ]]);
    }

    /** @return list<array<string, mixed>> */
    private function messages(string $chatId = '1001'): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage') && (string) $r['chat_id'] === $chatId)
            ->map(fn (array $pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    private function lastMessage(string $chatId = '1001'): array
    {
        $all = $this->messages($chatId);

        return end($all) ?: [];
    }

    private function inline(array $message): array
    {
        return json_decode((string) ($message['reply_markup'] ?? '{}'), true)['inline_keyboard'] ?? [];
    }

    public function test_start_sends_lesson_card_gifts_and_menu(): void
    {
        $course = Course::factory()->create(['title' => 'Йога-сутры']);
        Schedule::create(['title' => 'Открытое занятие по Йога-сутрам', 'start' => now()->addDays(2), 'course_id' => $course->id, 'bot_card_enabled' => true]);

        $this->text('/start');

        $sent = $this->messages();
        $this->assertCount(3, $sent);
        $this->assertStringContainsString('открытое занятие «Открытое занятие по Йога-сутрам»', $sent[0]['text']);
        $this->assertStringContainsString('Деванагари за вечер', $sent[1]['text']);
        $this->assertStringContainsString('политику конфиденциальности', $sent[1]['text']);
        $this->assertSame('ol:join', $this->inline($sent[1])[0][0]['callback_data']);
        $this->assertSame('Остальные разделы — в меню внизу 👇', $sent[2]['text']);

        $menu = json_decode((string) $sent[2]['reply_markup'], true)['keyboard'];
        $this->assertSame([['🎙 Ближайшие занятия'], ['📚 Программа', '🎁 Подарки'], ['🌐 Сайт', '✍️ Участвовать']],
            array_map(fn (array $row): array => array_column($row, 'text'), $menu));
    }

    public function test_flag_off_keeps_the_waitlist_welcome(): void
    {
        config(['features.open_lesson_cards' => false]);

        $this->text('/start');

        $this->assertStringContainsString('Общества ревнителей санскрита', $this->messages()[0]['text']);
    }

    /** Обкатка на себе: пока заданы тестовые чаты, остальные видят прежний бот. */
    public function test_test_chats_alone_see_the_new_bot(): void
    {
        config(['open_lesson_cards.test_chat_ids' => ['555']]);

        $this->text('/start', 1001);
        $this->text('🎁 Подарки', 1001);
        $this->text('/start', 555);

        $outsider = $this->messages('1001');
        $this->assertCount(1, $outsider);
        $this->assertStringContainsString('Общества ревнителей санскрита', $outsider[0]['text']);
        $this->assertSame('Остальные разделы — в меню внизу 👇', $this->lastMessage('555')['text']);
    }

    public function test_every_menu_section_ends_with_the_join_call(): void
    {
        $course = Course::factory()->create(['title' => 'Санскрит с нуля']);
        Schedule::create(['title' => 'Занятие 1', 'start' => now()->addDays(3), 'course_id' => $course->id]);

        foreach (['📚 Программа', '🎁 Подарки', '🌐 Сайт'] as $label) {
            $this->text($label);
            $message = $this->lastMessage();
            $this->assertStringContainsString('Участие бесплатное.', $message['text'], $label);
            $rows = $this->inline($message);
            $this->assertSame('ol:join', end($rows)[0]['callback_data'], $label);
        }

        $this->assertStringContainsString('Санскрит с нуля', $this->messages()[0]['text']);
    }

    public function test_join_flow_creates_one_lead_and_delivers_gifts(): void
    {
        $this->text('/start');
        $this->text('✍️ Участвовать');
        $this->assertSame('https://t.me/samskrte', $this->inline($this->messages()[2])[0][0]['url']);
        $this->assertSame('ol:sub', $this->inline($this->lastMessage())[0][0]['callback_data']);

        // Не подписан — согласие не фиксируем, шаг не двигаем.
        $this->memberStatus = 'left';
        $this->press('ol:sub');
        $this->assertNull(BotSubscriber::query()->where('chat_id', '1001')->value('consent_at'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/answerCallbackQuery') && ($r['show_alert'] ?? false) === true);

        // Подписался — можно и словом.
        $this->memberStatus = 'member';
        $this->text('подписался');
        $this->assertStringContainsString('1/3', $this->lastMessage()['text']);

        $this->text('Анна Петрова');
        $this->press('ol:q:int:texts');
        $this->press('ol:q:time:evening');

        $lead = Lead::query()->where('telegram_chat_id', 1001)->sole();
        $this->assertSame('Анна Петрова', $lead->name);
        $this->assertSame('@anya', $lead->contact);
        $this->assertTrue((bool) $lead->is_promo_agreed);
        $this->assertSame('samskrte_bot', $lead->utm_source);
        $note = LeadNote::query()->where('lead_id', $lead->id)->sole();
        $this->assertStringContainsString('Чтение текстов', $note->body);
        $this->assertStringContainsString('Вечером', $note->body);

        $gifts = $this->lastMessage();
        $this->assertStringContainsString('Готово', $gifts['text']);
        $this->assertSame('https://samskrte.ru/m/devanagari', $this->inline($gifts)[0][0]['url']);

        // Повторное «Участвовать» — сразу подарки, второго лида нет.
        $this->text('✍️ Участвовать');
        $this->assertStringContainsString('Анкета уже заполнена', $this->lastMessage()['text']);
        $this->assertSame(1, Lead::query()->count());
    }

    public function test_existing_lead_is_updated_not_duplicated(): void
    {
        $lead = Lead::create(['name' => 'Анна', 'contact' => '+79990000000', 'telegram_chat_id' => 1001, 'status' => 'new']);

        $this->text('/start');
        $this->text('✍️ Участвовать');
        $this->press('ol:sub');
        $this->press('ol:q:name');
        $this->press('ol:q:int:start');
        $this->press('ol:q:time:any');

        $this->assertSame(1, Lead::query()->count());
        $this->assertTrue((bool) $lead->fresh()->is_promo_agreed);
        $this->assertSame('+79990000000', $lead->fresh()->contact);
        $this->assertSame($lead->id, BotSubscriber::query()->where('chat_id', '1001')->value('lead_id'));
    }

    public function test_quiz_buttons_out_of_order_are_ignored(): void
    {
        $this->text('/start');
        $this->press('ol:q:time:evening');

        $this->assertSame(0, Lead::query()->count());
        $this->assertNull(BotSubscriber::query()->where('chat_id', '1001')->value('join_time'));
    }

    public function test_bot_profile_command_sets_descriptions(): void
    {
        $this->artisan('open-lessons:bot-profile')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/setMyDescription') && str_contains((string) $r['description'], 'Нажмите «Начать»'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/setMyShortDescription'));
    }
}
