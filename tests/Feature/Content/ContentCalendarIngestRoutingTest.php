<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Jobs\IngestCalendarChannelPostJob;
use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Jobs\PublishCalendarSlotJob;
use App\Jobs\ReleaseRoutedCalendarSlotJob;
use App\Models\ContentCalendarSlot;
use App\Services\Access\TelegramAdminNotifier;
use App\Services\Content\CalendarIngestRouting;
use App\Services\Content\CalendarPublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Выбор площадок для поста из TG-канала кнопками в служебном чате
 * (31-10-2026): без выбора через 15 мин — все площадки.
 */
class ContentCalendarIngestRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '-1005550000';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');

        config([
            'features.content_calendar_autopilot' => true,
            'content_calendar.tg_ingest_enabled' => true,
            'content_calendar.tg_bot_token' => 'BOT',
            'content_calendar.tg_ingest_routing_chat' => self::CHAT,
            'content_calendar.destinations' => [
                'tg_opoveshchenie' => ['platform' => 'telegram', 'label' => 'TG Оповещение', 'chat_id' => '@samskrte'],
                'tg_ors' => ['platform' => 'telegram', 'label' => 'TG ОРС', 'chat_id' => '-1001111'],
                'vk_ors' => ['platform' => 'vk', 'label' => 'VK ОРС', 'group_id' => 88831040],
            ],
        ]);
        Redis::shouldReceive('set')->andReturn(true);
        Redis::shouldReceive('expire')->andReturn(true);
        $this->mock(TelegramAdminNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyAdmins')->andReturn([]));
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 4242]])]);
    }

    private function ingest(): ContentCalendarSlot
    {
        IngestCalendarChannelPostJob::dispatchSync([
            'message_id' => 77,
            'date' => now()->timestamp,
            'chat' => ['id' => -1009999, 'username' => 'samskrte', 'type' => 'channel'],
            'text' => 'Старт курса 5.11',
        ]);

        return ContentCalendarSlot::sole();
    }

    private function tap(ContentCalendarSlot $slot, string $data, string $chat = self::CHAT): void
    {
        app(CalendarIngestRouting::class)->handleCallback([
            'id' => 'cb1',
            'data' => $data,
            'from' => ['id' => 1, 'first_name' => 'Иван'],
            'message' => ['message_id' => 4242, 'chat' => ['id' => (int) $chat]],
        ]);
    }

    public function test_channel_post_waits_for_choice_with_all_destinations_checked(): void
    {
        Bus::fake([PublishCalendarSlotJob::class, ReleaseRoutedCalendarSlotJob::class]);

        $slot = $this->ingest();

        $this->assertTrue(CalendarIngestRouting::isPending($slot));
        $this->assertSame(['tg_opoveshchenie', 'tg_ors', 'vk_ors'], $slot->destinations);
        $this->assertSame(4242, $slot->meta['routing']['message_id']);
        Bus::assertNotDispatched(PublishCalendarSlotJob::class);
        Bus::assertDispatched(ReleaseRoutedCalendarSlotJob::class);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/sendMessage')
            && $r['chat_id'] === self::CHAT
            && $r['reply_markup']['inline_keyboard'][0][0]['text'] === '✅ TG ОРС'
            && $r['reply_markup']['inline_keyboard'][1][0]['callback_data'] === "ccr:go:{$slot->id}");

        // Почасовой тик тоже не трогает слот, пока выбор не сделан.
        $this->assertSame(0, app(CalendarPublishService::class)->publishDue()['dispatched']);
    }

    public function test_toggle_and_go_publishes_only_checked_destinations(): void
    {
        Bus::fake([PublishCalendarSlotJob::class, ReleaseRoutedCalendarSlotJob::class]);
        $slot = $this->ingest();

        $this->tap($slot, "ccr:t:{$slot->id}:vk_ors");
        $this->assertSame(['tg_opoveshchenie', 'tg_ors'], $slot->fresh()->destinations);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/editMessageReplyMarkup')
            && $r['reply_markup']['inline_keyboard'][0][1]['text'] === '⬜ VK ОРС');

        $this->tap($slot, "ccr:go:{$slot->id}");
        $slot->refresh();
        $this->assertFalse(CalendarIngestRouting::isPending($slot));
        $this->assertSame(['tg_opoveshchenie', 'tg_ors'], $slot->targetDestinations());
        Bus::assertDispatchedTimes(PublishCalendarSlotJob::class, 1);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/editMessageText')
            && str_contains($r['text'], 'Разнесён: ОРС: TG — Иван'));

        // Повторный тап и таймаут ничего не делают.
        $this->tap($slot, "ccr:go:{$slot->id}");
        (new ReleaseRoutedCalendarSlotJob($slot->id))->handle(app(CalendarIngestRouting::class));
        Bus::assertDispatchedTimes(PublishCalendarSlotJob::class, 1);
    }

    public function test_only_source_keeps_post_in_channel(): void
    {
        Bus::fake([PublishCalendarSlotJob::class, ReleaseRoutedCalendarSlotJob::class]);
        $slot = $this->ingest();

        $this->tap($slot, "ccr:no:{$slot->id}");

        $slot->refresh();
        $this->assertSame(ContentCalendarSlot::STATUS_PUBLISHED, $slot->status);
        $this->assertSame('none', $slot->meta['routing']['decided']);
        Bus::assertNotDispatched(PublishCalendarSlotJob::class);
    }

    public function test_no_choice_releases_to_all_destinations_after_timeout(): void
    {
        Bus::fake([PublishCalendarSlotJob::class, ReleaseRoutedCalendarSlotJob::class]);
        $slot = $this->ingest();

        (new ReleaseRoutedCalendarSlotJob($slot->id))->handle(app(CalendarIngestRouting::class));

        $slot->refresh();
        $this->assertSame('auto', $slot->meta['routing']['decided']);
        Bus::assertDispatched(PublishCalendarSlotJob::class);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/editMessageText')
            && str_contains($r['text'], 'Выбора не было'));
    }

    public function test_taps_from_other_chats_are_ignored(): void
    {
        Bus::fake([PublishCalendarSlotJob::class, ReleaseRoutedCalendarSlotJob::class]);
        $slot = $this->ingest();

        $this->tap($slot, "ccr:go:{$slot->id}", '-1001234');

        $this->assertTrue(CalendarIngestRouting::isPending($slot->fresh()));
        Bus::assertNotDispatched(PublishCalendarSlotJob::class);
    }

    public function test_without_routing_chat_post_is_spread_at_once(): void
    {
        Bus::fake([PublishCalendarSlotJob::class, ReleaseRoutedCalendarSlotJob::class]);
        config(['content_calendar.tg_ingest_routing_chat' => '']);

        $slot = $this->ingest();

        $this->assertFalse(CalendarIngestRouting::isPending($slot));
        $this->assertNull($slot->destinations);
        Bus::assertDispatched(PublishCalendarSlotJob::class);
        Bus::assertNotDispatched(ReleaseRoutedCalendarSlotJob::class);
    }

    public function test_failed_offer_falls_back_to_spreading_at_once(): void
    {
        Bus::fake([PublishCalendarSlotJob::class, ReleaseRoutedCalendarSlotJob::class]);
        // Бот не настроен — сообщение с выбором не уходит.
        config(['content_calendar.tg_bot_token' => '']);

        $slot = $this->ingest();

        $this->assertArrayNotHasKey('routing', $slot->meta ?? []);
        Bus::assertDispatched(PublishCalendarSlotJob::class);
    }

    public function test_zapisi_callback_reaches_routing(): void
    {
        Bus::fake([PublishCalendarSlotJob::class, ReleaseRoutedCalendarSlotJob::class]);
        $slot = $this->ingest();
        config(['services.telegram_harvest.store_path' => storage_path('framework/testing/ccr-'.uniqid())]);

        (new ProcessTelegramZapisiUpdate(['update_id' => 7001, 'callback_query' => [
            'id' => 'cb', 'data' => "ccr:go:{$slot->id}", 'from' => ['id' => 1, 'first_name' => 'Иван'],
            'message' => ['message_id' => 4242, 'chat' => ['id' => (int) self::CHAT]],
        ]]))->handle();

        Bus::assertDispatched(PublishCalendarSlotJob::class);
    }
}
