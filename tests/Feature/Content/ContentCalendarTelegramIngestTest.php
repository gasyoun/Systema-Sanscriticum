<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Jobs\IngestCalendarChannelPostJob;
use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Jobs\PublishCalendarSlotJob;
use App\Models\ContentCalendarPublication;
use App\Models\ContentCalendarSlot;
use App\Models\MarketingSetting;
use App\Models\VkUserToken;
use App\Services\Access\TelegramAdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Пост, вышедший в TG-канале «Оповещение», → слот календаря → остальные
 * площадки (22-10-2026).
 */
class ContentCalendarTelegramIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.content_calendar_autopilot' => true,
            'content_calendar.tg_ingest_enabled' => true,
            'content_calendar.tg_bot_token' => 'BOT',
            'content_calendar.destinations' => [
                'tg_opoveshchenie' => ['platform' => 'telegram', 'label' => 'TG Оповещение', 'chat_id' => '@samskrte'],
                'tg_ors' => ['platform' => 'telegram', 'label' => 'TG ОРС', 'chat_id' => '-1001111'],
                'vk_ors' => ['platform' => 'vk', 'label' => 'VK ОРС', 'group_id' => 88831040],
            ],
            'video-anons.vk.authorized_group_ids' => [88831040],
        ]);
        Redis::shouldReceive('set')->andReturn(true);
        Redis::shouldReceive('expire')->andReturn(true);
        $this->mock(TelegramAdminNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyAdmins')->andReturn([]));
        Storage::fake(ContentCalendarSlot::MEDIA_DISK);

        VkUserToken::create([
            'client_id' => '54821902', 'access_token' => 'vk-access', 'refresh_token' => 'r',
            'scope' => 'wall offline', 'expires_at' => now()->addHour(),
        ]);

        Http::fake(function (Request $r) {
            return match (true) {
                str_contains($r->url(), '/getFile') => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/file_1.jpg']]),
                str_contains($r->url(), '/file/botBOT/') => Http::response('JPEGDATA'),
                str_contains($r->url(), '/copyMessages') => Http::response(['ok' => true, 'result' => [['message_id' => 501], ['message_id' => 502]]]),
                str_contains($r->url(), '/copyMessage') => Http::response(['ok' => true, 'result' => ['message_id' => 500]]),
                str_contains($r->url(), 'wall.post') => Http::response(['response' => ['post_id' => 900]]),
                default => Http::response(['error' => 'unexpected '.$r->url()], 500),
            };
        });
    }

    /** @param  array<string, mixed>  $post  поля channel_post поверх поста @samskrte #77 */
    private function channelPost(array $post): void
    {
        IngestCalendarChannelPostJob::dispatchSync(array_merge([
            'message_id' => 77,
            'date' => now()->timestamp,
            'chat' => ['id' => -1009999, 'username' => 'samskrte', 'type' => 'channel'],
        ], $post));
    }

    public function test_zapisi_bot_update_stream_feeds_ingest_only_with_flag_on(): void
    {
        Bus::fake([IngestCalendarChannelPostJob::class]);
        $store = storage_path('framework/testing/cc-ingest-'.uniqid());
        config(['services.telegram_harvest.store_path' => $store]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($store));
        $update = ['update_id' => 5001, 'channel_post' => [
            'message_id' => 77, 'date' => now()->timestamp, 'text' => 'x',
            'chat' => ['id' => -1009999, 'username' => 'samskrte', 'type' => 'channel'],
        ]];

        (new ProcessTelegramZapisiUpdate($update))->handle();
        Bus::assertDispatched(IngestCalendarChannelPostJob::class, fn (IngestCalendarChannelPostJob $j) => $j->post['message_id'] === 77);

        config(['content_calendar.tg_ingest_enabled' => false]);
        (new ProcessTelegramZapisiUpdate(['update_id' => 5002] + $update))->handle();
        Bus::assertDispatchedTimes(IngestCalendarChannelPostJob::class, 1);
    }

    public function test_flag_off_ingests_nothing(): void
    {
        config(['content_calendar.tg_ingest_enabled' => false]);
        $this->channelPost(['text' => 'x']);

        $this->assertSame(0, ContentCalendarSlot::count());
    }

    public function test_without_override_the_zapisi_bot_token_is_used(): void
    {
        config(['content_calendar.tg_bot_token' => '']);
        MarketingSetting::create(['zapisi_bot_token' => 'ZAPISI']);

        $this->channelPost(['text' => 'Ом']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/botZAPISI/copyMessage'));
    }

    public function test_channel_post_becomes_slot_and_is_copied_to_other_destinations(): void
    {
        $this->channelPost([
            'text' => "Ом намах\nподробнее",
            'entities' => [['type' => 'bold', 'offset' => 0, 'length' => 2], ['type' => 'text_link', 'offset' => 9, 'length' => 9, 'url' => 'https://samskrte.ru/k']],
        ]);

        $slot = ContentCalendarSlot::sole();
        $this->assertSame(ContentCalendarSlot::TYPE_TG_CHANNEL, $slot->slot_type);
        $this->assertSame(ContentCalendarSlot::STATUS_PUBLISHED, $slot->status);
        $this->assertSame('<p><strong>Ом</strong> намах<br><a href="https://samskrte.ru/k">подробнее</a></p>', $slot->body);
        $this->assertStringStartsWith('Из канала: Ом намах', $slot->title);

        $source = $slot->publications()->where('destination', 'tg_opoveshchenie')->sole();
        $this->assertSame('77', $source->external_id);
        $this->assertSame('https://t.me/samskrte/77', $source->permalink);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/copyMessage')
            && $r['chat_id'] === '-1001111' && $r['from_chat_id'] === '@samskrte' && (int) $r['message_id'] === 77);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'copyMessage') && $r['chat_id'] === '@samskrte');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'wall.post') && $r['message'] === "Ом намах\nподробнее (https://samskrte.ru/k)");
        $this->assertSame(3, $slot->publications()->where('status', ContentCalendarPublication::STATUS_PUBLISHED)->count());
    }

    public function test_album_parts_merge_into_one_slot_and_copy_together(): void
    {
        config(['features.content_calendar_autopilot' => false]); // разнос — вручную ниже

        $photo = [['file_id' => 'small', 'file_size' => 100], ['file_id' => 'big', 'file_size' => 2000]];
        $this->channelPost(['message_id' => 11, 'media_group_id' => 'g1', 'photo' => $photo]);
        $this->channelPost(['message_id' => 10, 'media_group_id' => 'g1', 'photo' => $photo, 'caption' => 'Подпись альбома']);

        $slot = ContentCalendarSlot::sole();
        $this->assertSame([10, 11], $slot->meta['tg_source']['message_ids']);
        $this->assertSame('<p>Подпись альбома</p>', $slot->body);
        $this->assertSame('content-calendar/tg-1009999-11.jpg', $slot->media_path);
        Storage::disk(ContentCalendarSlot::MEDIA_DISK)->assertExists($slot->media_path);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/getFile') && $r['file_id'] === 'big');

        config(['features.content_calendar_autopilot' => true]);
        PublishCalendarSlotJob::dispatchSync($slot->id);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/copyMessages') && $r['message_ids'] === '[10,11]');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'wall.post') && $r['attachments'] === $slot->shareUrl());
    }

    public function test_oversized_media_is_not_downloaded(): void
    {
        config(['features.content_calendar_autopilot' => false]);

        $this->channelPost(['video' => ['file_id' => 'v', 'file_size' => 60 * 1024 * 1024], 'caption' => 'Большое видео']);

        $slot = ContentCalendarSlot::sole();
        $this->assertNull($slot->media_path);
        $this->assertStringContainsString('больше 20 МБ', $slot->meta['tg_media_note']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/getFile'));
    }

    public function test_own_crosspost_and_foreign_chats_are_ignored(): void
    {
        $adminSlot = ContentCalendarSlot::create([
            'slot_date' => now()->toDateString(), 'slot_type' => ContentCalendarSlot::TYPE_MANUAL,
            'status' => ContentCalendarSlot::STATUS_PUBLISHED, 'body' => '<p>из админки</p>',
        ]);
        ContentCalendarPublication::create([
            'content_calendar_slot_id' => $adminSlot->id, 'destination' => 'tg_opoveshchenie',
            'status' => ContentCalendarPublication::STATUS_PUBLISHED, 'external_id' => '77',
        ]);

        $this->channelPost(['text' => 'наш же кросспост']);
        $this->channelPost(['message_id' => 5, 'text' => 'чужой канал', 'chat' => ['id' => -100555, 'username' => 'other', 'type' => 'channel']]);

        $this->assertSame(1, ContentCalendarSlot::count());
    }

    public function test_prohibited_channel_post_is_held_and_not_spread(): void
    {
        $this->channelPost(['text' => 'Ученица Мария Иванова пишет: лучше, чем Окаруто']);

        $slot = ContentCalendarSlot::sole();
        $this->assertSame(ContentCalendarSlot::STATUS_DRAFT, $slot->status);
        $this->assertStringContainsString('prohibition-hold', $slot->meta['prohibition_hold']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'copyMessage') || str_contains($r->url(), 'wall.post'));
    }
}
