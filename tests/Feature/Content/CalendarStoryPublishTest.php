<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\ContentCalendarPublication;
use App\Models\ContentCalendarSlot;
use App\Models\TelegramBusinessConnection;
use App\Models\VkUserToken;
use App\Services\Access\TelegramAdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

/** Картинка для сторис слота → сторис Telegram Business и VK (26-10-2026). */
class CalendarStoryPublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.content_calendar_autopilot' => true,
            'content_calendar.tg_bot_token' => 'BOT',
            'content_calendar.tg_public_username' => 'samskrte',
            'content_calendar.destinations' => [
                'tg_opoveshchenie' => ['platform' => 'telegram', 'label' => 'TG О', 'chat_id' => '-1001762848803'],
                'tg_story_business' => ['platform' => 'tg_business_story', 'label' => 'Сторис TG', 'enabled' => true],
                'vk_story_ors' => ['platform' => 'vk_story', 'label' => 'Сторис VK', 'group_id' => 88831040],
            ],
            'video-anons.vk.authorized_group_ids' => [88831040],
            'services.telegram_business.token' => 'BIZ',
        ]);
        Redis::shouldReceive('set')->andReturn(true);
        Redis::shouldReceive('expire')->andReturn(true);
        $this->mock(TelegramAdminNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyAdmins')->andReturn([]));
        Storage::fake(ContentCalendarSlot::MEDIA_DISK);
        Storage::disk(ContentCalendarSlot::MEDIA_DISK)->put('content-calendar/stories/st.jpg', 'STORYJPEG');

        TelegramBusinessConnection::create([
            'business_connection_id' => 'biz-conn',
            'is_enabled' => true,
            'rights' => ['can_manage_stories' => true],
        ]);
        VkUserToken::create([
            'client_id' => '54821902', 'access_token' => 'vk-access', 'refresh_token' => 'r',
            'scope' => 'wall offline', 'expires_at' => now()->addHour(),
        ]);

        Http::fake(function (Request $r) {
            return match (true) {
                str_contains($r->url(), 'botBOT/sendMessage') => Http::response(['ok' => true, 'result' => ['message_id' => 677]]),
                str_contains($r->url(), 'botBIZ/postStory') => Http::response(['ok' => true, 'result' => ['id' => 31]]),
                str_contains($r->url(), 'stories.getPhotoUploadServer') => Http::response(['response' => ['upload_url' => 'https://up.vk.example/st']]),
                str_contains($r->url(), 'up.vk.example') => Http::response(['response' => ['upload_result' => 'UPRES']]),
                str_contains($r->url(), 'stories.save') => Http::response(['response' => ['count' => 1, 'items' => [['id' => 456239001]]]]),
                default => Http::response(['error' => 'unexpected '.$r->url()], 500),
            };
        });
    }

    private function slot(array $overrides = []): ContentCalendarSlot
    {
        return ContentCalendarSlot::create(array_merge([
            'slot_date' => now()->toDateString(),
            'slot_type' => ContentCalendarSlot::TYPE_MANUAL,
            'status' => ContentCalendarSlot::STATUS_SCHEDULED,
            'publish_at' => now()->subMinutes(5),
            'body' => '<p>🌙 Кашмирский шиваизм</p><p>Подробнее: https://samskrte.ru/ga/ksh3-ors-c</p>',
            'story_media_path' => 'content-calendar/stories/st.jpg',
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private static function fields(Request $r): array
    {
        $out = [];
        foreach ($r->data() as $part) {
            $out[$part['name']] = $part['contents'];
        }

        return $out;
    }

    public function test_story_goes_to_business_account_after_the_post_with_link_area(): void
    {
        $slot = $this->slot(['destinations' => ['tg_opoveshchenie', 'tg_story_business']]);

        $this->artisan('content:publish-due')->assertSuccessful();

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->url())->filter(fn ($u) => str_contains($u, 'api.telegram.org'))->values();
        $this->assertStringContainsString('sendMessage', $sent[0]);
        $this->assertStringContainsString('postStory', $sent[1]);

        Http::assertSent(function (Request $r): bool {
            if (! str_contains($r->url(), 'postStory')) {
                return false;
            }
            $f = self::fields($r);

            return $f['business_connection_id'] === 'biz-conn'
                && json_decode($f['content'], true) === ['type' => 'photo', 'photo' => 'attach://photo']
                && $f['photo'] === 'STORYJPEG'
                && $f['caption'] === '🌙 Кашмирский шиваизм'
                && str_contains($f['areas'], 'https://samskrte.ru/ga/ksh3-ors-c');
        });
        $story = $slot->publications()->where('destination', 'tg_story_business')->sole();
        $this->assertSame(ContentCalendarPublication::STATUS_PUBLISHED, $story->status);
        $this->assertSame('31', $story->external_id);
        $this->assertSame(ContentCalendarSlot::STATUS_PUBLISHED, $slot->refresh()->status);
    }

    public function test_without_samskrte_link_story_points_to_the_published_channel_post(): void
    {
        $this->slot(['body' => '<p>Просто текст</p>', 'destinations' => ['tg_opoveshchenie', 'tg_story_business']]);

        $this->artisan('content:publish-due')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'postStory')
            && str_contains(self::fields($r)['areas'], 'https://t.me/samskrte/677'));
    }

    public function test_slot_without_story_picture_publishes_no_story(): void
    {
        $slot = $this->slot(['story_media_path' => null, 'destinations' => null]);

        $this->artisan('content:publish-due')->assertSuccessful();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'postStory') || str_contains($r->url(), 'stories.'));
        $this->assertSame(['tg_opoveshchenie'], $slot->targetDestinations());
        $this->assertSame(ContentCalendarSlot::STATUS_PUBLISHED, $slot->refresh()->status);
    }

    public function test_vk_story_needs_stories_scope(): void
    {
        $slot = $this->slot(['destinations' => ['vk_story_ors']]);

        $this->artisan('content:publish-due')->assertSuccessful();

        $vk = $slot->publications()->sole();
        $this->assertSame(ContentCalendarPublication::STATUS_FAILED, $vk->status);
        $this->assertStringContainsString('нет права «stories»', (string) $vk->error);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'stories.'));
    }

    public function test_vk_story_is_uploaded_and_saved_with_stories_scope(): void
    {
        VkUserToken::query()->update(['scope' => 'wall stories offline']);
        $slot = $this->slot(['destinations' => ['vk_story_ors']]);

        $this->artisan('content:publish-due')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'stories.getPhotoUploadServer')
            && (int) $r['group_id'] === 88831040 && $r['link_url'] === 'https://samskrte.ru/ga/ksh3-ors-c');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'stories.save') && $r['upload_results'] === 'UPRES');
        $vk = $slot->publications()->sole();
        $this->assertSame(ContentCalendarPublication::STATUS_PUBLISHED, $vk->status);
        $this->assertSame('https://vk.com/story-88831040_456239001', $vk->permalink);
    }
}
