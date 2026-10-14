<?php

declare(strict_types=1);

namespace Tests\Feature\OpenLessonCards;

use App\Jobs\ProcessTelegramMagnetUpdate;
use App\Models\BotSubscriber;
use App\Models\Course;
use App\Models\MarketingSetting;
use App\Models\OpenLessonCardSend;
use App\Models\OpenLessonRsvp;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Services\OpenLessonCards\OpenLessonCardBuilder;
use App\Services\OpenLessonCards\OpenLessonCardSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Карточки открытых занятий в @samskrte_bot: фото + «✅ Я иду» + нижнее
 * меню; анонс / утро / ссылка Zoom; только занятия с галочкой bot_card_enabled.
 */
class OpenLessonCardsTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'Europe/Moscow'));
        config([
            'features.open_lesson_cards' => true,
            'open_lesson_cards.test_chat_ids' => [],
            'services.telegram.bot_token' => 'ADMIN-BOT',
            'services.telegram.admin_id' => '777',
        ]);
        MarketingSetting::create(['tg_bot_username' => 'samskrte_bot', 'tg_bot_token' => 'MAGNET-TOKEN']);

        $teacher = Teacher::factory()->create(['name' => 'Марцис Гасунс']);
        $this->course = Course::factory()->create(['title' => 'Йога-васиштха', 'teacher_id' => $teacher->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lesson(string $start, array $attrs = []): Schedule
    {
        return Schedule::create($attrs + [
            'title' => 'Йога-васиштха: открытое занятие',
            'start' => Carbon::parse($start, 'Europe/Moscow'),
            'course_id' => $this->course->id,
            'link' => 'https://zoom.us/j/123',
            'bot_card_enabled' => true,
        ]);
    }

    private function fakeTelegram(int $status = 200): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(
            $status === 200
                ? ['ok' => true, 'result' => ['message_id' => 55, 'photo' => [['file_id' => 'small'], ['file_id' => 'BIG-FILE-ID']]]]
                : ['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'],
            $status,
        )]);
    }

    /** @return list<Request> */
    private function sent(string $method, ?string $chatId = null): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/botMAGNET-TOKEN/'.$method)
            && ($chatId === null || (string) ($this->field($r, 'chat_id')) === $chatId))
            ->map(fn (array $pair) => $pair[0])
            ->values()
            ->all();
    }

    /** Поле запроса и для JSON, и для multipart (sendPhoto с файлом). */
    private function field(Request $request, string $name): mixed
    {
        if ($request->isMultipart()) {
            foreach ($request->data() as $part) {
                if (($part['name'] ?? null) === $name) {
                    return $part['contents'];
                }
            }

            return null;
        }

        return $request[$name] ?? null;
    }

    public function test_caption_reads_like_a_conference_card(): void
    {
        $schedule = $this->lesson('2026-10-17 19:00');
        $builder = app(OpenLessonCardBuilder::class);

        $announce = $builder->caption($schedule, OpenLessonCardSend::STAGE_ANNOUNCE);
        $this->assertStringContainsString('🎙 Суббота, 17 октября — открытое занятие «Йога-васиштха: открытое занятие»', $announce);
        $this->assertStringContainsString('📅 17 октября, в 19:00 (МСК), онлайн в Zoom. Участие бесплатное.', $announce);
        $this->assertStringContainsString('Ведёт <b>Марцис Гасунс</b>.', $announce);
        $this->assertStringContainsString('Ссылку на подключение пришлём в этот чат перед началом.', $announce);

        Carbon::setTestNow(Carbon::parse('2026-10-17 09:00:00', 'Europe/Moscow'));
        $morning = $builder->caption($schedule, OpenLessonCardSend::STAGE_MORNING);
        $this->assertStringContainsString('🎙 Сегодня открытое занятие', $morning);
        $this->assertStringContainsString('📅 Сегодня, 17 октября, в 19:00 (МСК)', $morning);
    }

    public function test_caption_escapes_html_and_drops_note_over_telegram_limit(): void
    {
        $schedule = $this->lesson('2026-10-17 19:00', ['title' => 'A <b>&</b> B', 'card_note' => str_repeat('я', 1100)]);

        $caption = app(OpenLessonCardBuilder::class)->caption($schedule, OpenLessonCardSend::STAGE_ANNOUNCE);

        $this->assertStringContainsString('«A &lt;b&gt;&amp;&lt;/b&gt; B»', $caption);
        $this->assertStringNotContainsString('яяя', $caption);
        $this->assertLessThanOrEqual(OpenLessonCardBuilder::CAPTION_LIMIT, mb_strlen(strip_tags($caption)));
    }

    public function test_announce_goes_to_active_subscribers_once_and_only_for_ticked_lessons(): void
    {
        $this->fakeTelegram();
        $ticked = $this->lesson('2026-10-17 19:00');
        $this->lesson('2026-10-17 20:00', ['bot_card_enabled' => false]);
        BotSubscriber::create(['chat_id' => '1001']);
        BotSubscriber::create(['chat_id' => '1002', 'blocked_at' => now()]);

        $this->artisan('open-lessons:cards --stage=announce')->assertSuccessful();
        $this->artisan('open-lessons:cards --stage=announce')->assertSuccessful();

        $cards = $this->sent('sendMessage', '1001');
        // Карточка + разовое сообщение с нижним меню.
        $this->assertCount(2, $cards);
        $markup = json_decode((string) $cards[0]['reply_markup'], true);
        $this->assertSame('✅ Я иду', $markup['inline_keyboard'][0][0]['text']);
        $this->assertSame('ol:go:'.$ticked->id, $markup['inline_keyboard'][0][0]['callback_data']);
        $menu = json_decode((string) $cards[1]['reply_markup'], true);
        $this->assertSame('🎙 Ближайшие занятия', $menu['keyboard'][0][0]['text']);
        $this->assertTrue($menu['is_persistent']);

        $this->assertSame([], $this->sent('sendMessage', '1002'));
        $this->assertSame(1, OpenLessonCardSend::query()->where('stage', 'announce')->count());
    }

    public function test_flag_off_sends_nothing(): void
    {
        config(['features.open_lesson_cards' => false]);
        $this->fakeTelegram();
        $this->lesson('2026-10-17 19:00');
        BotSubscriber::create(['chat_id' => '1001']);

        $this->artisan('open-lessons:cards --stage=announce')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_test_chat_ids_restrict_the_audience(): void
    {
        config(['open_lesson_cards.test_chat_ids' => ['555']]);
        $this->fakeTelegram();
        $this->lesson('2026-10-17 19:00');
        BotSubscriber::create(['chat_id' => '1001']);

        $this->artisan('open-lessons:cards --stage=announce')->assertSuccessful();

        $this->assertSame([], $this->sent('sendMessage', '1001'));
        $this->assertNotEmpty($this->sent('sendMessage', '555'));
    }

    public function test_im_going_records_rsvp_and_flips_the_button(): void
    {
        $this->fakeTelegram();
        $schedule = $this->lesson('2026-10-17 19:00');
        $update = ['callback_query' => [
            'id' => 'cb1',
            'data' => 'ol:go:'.$schedule->id,
            'from' => ['id' => 1001],
            'message' => ['message_id' => 55, 'chat' => ['id' => 1001, 'type' => 'private']],
        ]];

        (new ProcessTelegramMagnetUpdate($update))->handle();
        (new ProcessTelegramMagnetUpdate($update))->handle();

        $this->assertSame(1, OpenLessonRsvp::query()->whereNotNull('going_at')->count());
        $this->assertNotEmpty($this->sent('answerCallbackQuery'));
        $edit = $this->sent('editMessageReplyMarkup', '1001')[0];
        $markup = json_decode((string) $edit['reply_markup'], true);
        $this->assertSame('✅ Вы записаны', $markup['inline_keyboard'][0][0]['text']);
        $this->assertSame('Не смогу', $markup['inline_keyboard'][0][1]['text']);
        // До начала далеко — ссылку сейчас не шлём.
        $this->assertSame([], OpenLessonCardSend::query()->where('stage', 'zoom')->pluck('id')->all());
    }

    public function test_declined_chat_is_skipped_in_the_morning_card(): void
    {
        $this->fakeTelegram();
        $schedule = $this->lesson('2026-10-14 19:00');
        BotSubscriber::create(['chat_id' => '1001']);
        BotSubscriber::create(['chat_id' => '1002']);
        OpenLessonRsvp::create(['schedule_id' => $schedule->id, 'chat_id' => '1002', 'declined_at' => now()]);

        Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'Europe/Moscow'));
        $this->artisan('open-lessons:cards --stage=morning')->assertSuccessful();

        $this->assertNotEmpty($this->sent('sendMessage', '1001'));
        $this->assertSame([], $this->sent('sendMessage', '1002'));
        $this->assertStringContainsString('Сегодня открытое занятие', (string) $this->sent('sendMessage', '1001')[0]['text']);
    }

    public function test_early_lesson_gets_its_morning_card_the_evening_before(): void
    {
        $this->fakeTelegram();
        $this->lesson('2026-10-15 10:00');
        BotSubscriber::create(['chat_id' => '1001']);

        Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'Europe/Moscow'));
        $this->artisan('open-lessons:cards --stage=morning')->assertSuccessful();
        $this->assertSame([], $this->sent('sendMessage', '1001'));

        Carbon::setTestNow(Carbon::parse('2026-10-14 19:00:00', 'Europe/Moscow'));
        $this->artisan('open-lessons:cards --stage=morning')->assertSuccessful();
        $this->assertStringContainsString('Завтра открытое занятие', (string) $this->sent('sendMessage', '1001')[0]['text']);
    }

    public function test_zoom_link_goes_only_to_going_chats_and_only_once(): void
    {
        $this->fakeTelegram();
        $schedule = $this->lesson('2026-10-14 12:25');
        OpenLessonRsvp::create(['schedule_id' => $schedule->id, 'chat_id' => '1001', 'going_at' => now()]);
        OpenLessonRsvp::create(['schedule_id' => $schedule->id, 'chat_id' => '1002', 'declined_at' => now()]);

        $this->artisan('open-lessons:cards --stage=zoom')->assertSuccessful();
        $this->artisan('open-lessons:cards --stage=zoom')->assertSuccessful();

        $zoom = $this->sent('sendMessage', '1001');
        $this->assertCount(1, $zoom);
        $markup = json_decode((string) $zoom[0]['reply_markup'], true);
        $this->assertSame('https://zoom.us/j/123', $markup['inline_keyboard'][0][0]['url']);
        $this->assertSame([], $this->sent('sendMessage', '1002'));
        $this->assertNotNull(OpenLessonRsvp::query()->where('chat_id', '1001')->value('zoom_sent_at'));
    }

    public function test_missing_join_link_alerts_admin_and_sends_nothing_to_people(): void
    {
        $this->fakeTelegram();
        $schedule = $this->lesson('2026-10-14 12:25', ['link' => null]);
        OpenLessonRsvp::create(['schedule_id' => $schedule->id, 'chat_id' => '1001', 'going_at' => now()]);

        $this->artisan('open-lessons:cards --stage=zoom')->assertSuccessful();

        $this->assertSame([], $this->sent('sendMessage', '1001'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/botADMIN-BOT/sendMessage')
            && str_contains((string) $r['text'], 'нет ссылки Zoom'));
    }

    public function test_late_im_going_gets_the_link_right_away(): void
    {
        $this->fakeTelegram();
        $schedule = $this->lesson('2026-10-14 12:20');

        (new ProcessTelegramMagnetUpdate(['callback_query' => [
            'id' => 'cb1',
            'data' => 'ol:go:'.$schedule->id,
            'from' => ['id' => 1001],
            'message' => ['message_id' => 55, 'chat' => ['id' => 1001, 'type' => 'private']],
        ]]))->handle();

        $this->assertNotNull(OpenLessonRsvp::query()->where('chat_id', '1001')->value('zoom_sent_at'));
    }

    public function test_blocked_bot_marks_subscriber_and_stops_sending(): void
    {
        $this->fakeTelegram(403);
        $this->lesson('2026-10-17 19:00');
        BotSubscriber::create(['chat_id' => '1001']);

        $this->artisan('open-lessons:cards --stage=announce')->assertSuccessful();

        $this->assertNotNull(BotSubscriber::query()->where('chat_id', '1001')->value('blocked_at'));
    }

    public function test_start_remembers_subscriber_and_deep_link_opens_the_card(): void
    {
        $this->fakeTelegram();
        $schedule = $this->lesson('2026-10-17 19:00');

        (new ProcessTelegramMagnetUpdate(['message' => [
            'chat' => ['id' => 2002, 'type' => 'private'],
            'from' => ['id' => 2002, 'first_name' => 'Аня'],
            'text' => '/start ol_'.$schedule->id,
        ]]))->handle();

        $this->assertSame('Аня', BotSubscriber::query()->where('chat_id', '2002')->value('first_name'));
        $card = $this->sent('sendMessage', '2002')[0];
        $this->assertStringContainsString('открытое занятие', (string) $card['text']);
    }

    public function test_photo_is_uploaded_once_then_reused_by_file_id(): void
    {
        Storage::fake('public');
        $image = imagecreatetruecolor(32, 18);
        ob_start();
        imagewebp($image);
        Storage::disk('public')->put('open-lesson-cards/banner.webp', (string) ob_get_clean());
        Cache::flush();

        $this->fakeTelegram();
        $schedule = $this->lesson('2026-10-17 19:00', ['card_image_path' => 'open-lesson-cards/banner.webp']);
        $sender = app(OpenLessonCardSender::class);

        $this->assertSame(OpenLessonCardSender::SENT, $sender->sendCard($schedule, '1001', OpenLessonCardBuilder::STAGE_MANUAL));
        $this->assertSame(OpenLessonCardSender::SENT, $sender->sendCard($schedule, '1002', OpenLessonCardBuilder::STAGE_MANUAL));

        $first = $this->sent('sendPhoto', '1001')[0];
        $this->assertTrue($first->isMultipart());
        $this->assertStringContainsString('Участие бесплатное', (string) $this->field($first, 'caption'));
        $second = $this->sent('sendPhoto', '1002')[0];
        $this->assertSame('BIG-FILE-ID', $second['photo']);
    }

    public function test_menu_buttons_and_foreign_callbacks(): void
    {
        $this->fakeTelegram();
        $this->lesson('2026-10-17 19:00');

        (new ProcessTelegramMagnetUpdate(['message' => [
            'chat' => ['id' => 1001, 'type' => 'private'],
            'text' => '🎙 Ближайшие занятия',
        ]]))->handle();

        $list = $this->sent('sendMessage', '1001')[0];
        $markup = json_decode((string) $list['reply_markup'], true);
        $this->assertStringStartsWith('ol:card:', $markup['inline_keyboard'][0][0]['callback_data']);

        // Чужой callback (ждун) наш сервис не трогает.
        $this->assertFalse(app(\App\Services\OpenLessonCards\OpenLessonBotService::class)->handleUpdate([
            'callback_query' => ['id' => 'x', 'data' => 'wl:page:0'],
        ]));
    }
}
