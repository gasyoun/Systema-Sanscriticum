<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\ContentCalendarPublication;
use App\Models\ContentCalendarSlot;
use App\Models\ContentCandidate;
use App\Models\VkUserToken;
use App\Services\Access\TelegramAdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * content:publish-due — кросспостинг слота календаря в TG-каналы и
 * VK-сообщества (H1568 E1/E3; 22-10-2026). Ни одного живого вызова: Http::fake.
 */
class PublishDueContentCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $notified = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.content_calendar_autopilot' => true,
            'content_calendar.tg_bot_token' => 'BOT',
            'content_calendar.destinations' => [
                'tg_a' => ['platform' => 'telegram', 'label' => 'TG A', 'chat_id' => '@chan_a'],
                'tg_b' => ['platform' => 'telegram', 'label' => 'TG B', 'chat_id' => '-1001234'],
                'tg_off' => ['platform' => 'telegram', 'label' => 'TG off', 'chat_id' => ''],
                'vk_a' => ['platform' => 'vk', 'label' => 'VK A', 'group_id' => 111],
                'vk_b' => ['platform' => 'vk', 'label' => 'VK B', 'group_id' => 222],
            ],
            'video-anons.vk.authorized_group_ids' => [111, 222],
        ]);

        Redis::shouldReceive('set')->andReturn(true);
        Redis::shouldReceive('expire')->andReturn(true);

        $this->mock(TelegramAdminNotifier::class, function (MockInterface $mock): void {
            $mock->shouldReceive('notifyAdmins')->andReturnUsing(function (string $text): array {
                $this->notified[] = $text;

                return [];
            });
        });

        VkUserToken::create([
            'client_id' => '54821902',
            'access_token' => 'vk-access',
            'refresh_token' => 'vk-refresh',
            'scope' => 'wall offline',
            'expires_at' => now()->addHour(),
        ]);
    }

    private function slot(array $overrides = []): ContentCalendarSlot
    {
        return ContentCalendarSlot::create(array_merge([
            'channel' => ContentCalendarSlot::CHANNEL_VK_WALL,
            'slot_date' => now()->toDateString(),
            'slot_type' => ContentCalendarSlot::TYPE_EVERGREEN,
            'status' => ContentCalendarSlot::STATUS_SCHEDULED,
            'publish_at' => now()->subHour(),
            'source_kind' => 'vk_ors_post',
            'source_ref' => 'wall-88831040_1',
            'body' => '<p><strong>Слово</strong> дня: <a href="https://samskrte.ru/x">тут</a></p>',
        ], $overrides));
    }

    /** @param  array<string, callable>  $overrides  url-фрагмент => fn(Request) */
    private function fakeApis(array $overrides = []): void
    {
        Http::fake(function (Request $request) use ($overrides) {
            foreach ($overrides as $fragment => $handler) {
                if (str_contains($request->url(), $fragment)) {
                    return $handler($request);
                }
            }
            if (str_contains($request->url(), 'api.telegram.org')) {
                return Http::response(['ok' => true, 'result' => ['message_id' => 55]]);
            }
            if (str_contains($request->url(), 'api.vk.com/method/wall.post')) {
                return Http::response(['response' => ['post_id' => 900]]);
            }

            return Http::response(['error' => 'unexpected '.$request->url()], 500);
        });
    }

    /** @return array<string, mixed> поля multipart- или form-запроса */
    private static function fields(Request $request): array
    {
        if ($request->isMultipart()) {
            $out = [];
            foreach ($request->data() as $part) {
                $out[$part['name']] = $part['contents'];
            }

            return $out;
        }

        return $request->data();
    }

    private static function sentTo(string $fragment): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), $fragment))->count();
    }

    public function test_noop_when_autopilot_flag_off(): void
    {
        config(['features.content_calendar_autopilot' => false]);
        Http::fake();

        $slot = $this->slot();
        $this->artisan('content:publish-due')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(ContentCalendarSlot::STATUS_SCHEDULED, $slot->fresh()->status);
    }

    public function test_crossposts_due_slot_to_every_enabled_destination(): void
    {
        $this->fakeApis();

        $due = $this->slot();
        $candidate = ContentCandidate::create([
            'type' => ContentCandidate::TYPE_VK_POST,
            'status' => ContentCandidate::STATUS_SCHEDULED,
            'calendar_slot_id' => $due->id,
            'body' => 'x',
        ]);
        $notYetDue = $this->slot(['publish_at' => now()->addHour()]);
        $alreadyPublished = $this->slot(['status' => ContentCalendarSlot::STATUS_PUBLISHED]);

        $this->artisan('content:publish-due')->assertSuccessful();

        $this->assertSame(2, self::sentTo('sendMessage'), 'tg_off без chat_id не публикуется');
        $this->assertSame(2, self::sentTo('wall.post'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'botBOT/sendMessage')
            && $r['chat_id'] === '@chan_a'
            && $r['parse_mode'] === 'HTML'
            && $r['text'] === '<b>Слово</b> дня: <a href="https://samskrte.ru/x">тут</a>');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'wall.post')
            && (int) $r['owner_id'] === -111
            && (int) $r['from_group'] === 1
            && $r['access_token'] === 'vk-access'
            && $r['message'] === 'Слово дня: тут (https://samskrte.ru/x)'
            && $r['attachments'] === '');

        $due->refresh();
        $this->assertSame(ContentCalendarSlot::STATUS_PUBLISHED, $due->status);
        $this->assertNotNull($due->meta['autopilot_published_at'] ?? null);
        $this->assertSame(ContentCandidate::STATUS_PUBLISHED, $candidate->fresh()->status);
        $this->assertEqualsCanonicalizing(
            ['https://t.me/chan_a/55', 'https://t.me/c/1234/55', 'https://vk.com/wall-111_900', 'https://vk.com/wall-222_900'],
            $due->publications()->pluck('permalink')->all()
        );
        $this->assertSame(ContentCalendarSlot::STATUS_SCHEDULED, $notYetDue->fresh()->status);
        $this->assertSame(0, $alreadyPublished->publications()->count());
        $this->assertSame([], $this->notified);
    }

    public function test_unchecked_destinations_are_skipped(): void
    {
        $this->fakeApis();

        $slot = $this->slot(['destinations' => ['tg_b', 'vk_a', 'tg_off']]);
        $this->artisan('content:publish-due')->assertSuccessful();

        $this->assertSame(1, self::sentTo('sendMessage'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage') && $r['chat_id'] === '-1001234');
        $this->assertSame(1, self::sentTo('wall.post'));
        $this->assertEqualsCanonicalizing(['tg_b', 'vk_a'], $slot->publications()->pluck('destination')->all());
        $this->assertSame(ContentCalendarSlot::STATUS_PUBLISHED, $slot->fresh()->status);
    }

    public function test_failed_destination_is_retried_alone_on_next_tick(): void
    {
        $tgDown = true;
        $this->fakeApis([
            'sendMessage' => function (Request $r) use (&$tgDown) {
                return $tgDown && $r['chat_id'] === '@chan_a'
                    ? Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)
                    : Http::response(['ok' => true, 'result' => ['message_id' => 56]]);
            },
        ]);

        $slot = $this->slot();
        $this->artisan('content:publish-due')->assertSuccessful();

        $slot->refresh();
        $this->assertSame(ContentCalendarSlot::STATUS_SCHEDULED, $slot->status, 'pending площадка держит слот');
        $pending = $slot->publications()->where('destination', 'tg_a')->first();
        $this->assertSame(ContentCalendarPublication::STATUS_PENDING, $pending->status);
        $this->assertStringContainsString('chat not found', $pending->error);
        $this->assertSame(2, self::sentTo('sendMessage'));
        $this->assertSame(2, self::sentTo('wall.post'));

        $tgDown = false;
        $this->artisan('content:publish-due')->assertSuccessful();

        $this->assertSame(3, self::sentTo('sendMessage'), 'второй тик — только tg_a');
        $this->assertSame(2, self::sentTo('wall.post'), 'VK не дублируется');
        $this->assertSame(ContentCalendarSlot::STATUS_PUBLISHED, $slot->fresh()->status);
        $this->assertSame(2, $pending->fresh()->attempts);
    }

    public function test_vk_error_inside_http_200_fails_after_max_attempts_and_notifies(): void
    {
        $this->fakeApis([
            'wall.post' => fn (Request $r) => (int) $r['owner_id'] === -222
                ? Http::response(['error' => ['error_code' => 214, 'error_msg' => 'Access to adding post denied']])
                : Http::response(['response' => ['post_id' => 901]]),
        ]);

        $slot = $this->slot();
        foreach (range(1, 3) as $tick) {
            $this->artisan('content:publish-due')->assertSuccessful();
        }

        $failed = $slot->publications()->where('destination', 'vk_b')->first();
        $this->assertSame(ContentCalendarPublication::STATUS_FAILED, $failed->status);
        $this->assertSame(3, $failed->attempts);
        $this->assertStringContainsString('214 Access to adding post denied', $failed->error);
        $this->assertSame(ContentCalendarSlot::STATUS_PUBLISHED, $slot->fresh()->status, 'остальные площадки опубликованы');
        $this->assertCount(1, $this->notified);
        $this->assertStringContainsString('❌ VK B', $this->notified[0]);
    }

    public function test_unauthorized_vk_group_is_refused_without_retries(): void
    {
        config(['video-anons.vk.authorized_group_ids' => [111]]);
        $this->fakeApis();

        $slot = $this->slot();
        $this->artisan('content:publish-due')->assertSuccessful();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'wall.post') && (int) $r['owner_id'] === -222);
        $refused = $slot->publications()->where('destination', 'vk_b')->first();
        $this->assertSame(ContentCalendarPublication::STATUS_FAILED, $refused->status);
        $this->assertSame(1, $refused->attempts);
        $this->assertStringContainsString('VK_PUBLISH_AUTHORIZED_GROUPS', $refused->error);
    }

    public function test_photo_goes_to_telegram_and_degrades_to_text_in_vk_without_photos_scope(): void
    {
        Storage::fake(ContentCalendarSlot::MEDIA_DISK);
        Storage::disk(ContentCalendarSlot::MEDIA_DISK)->put('content-calendar/cover.jpg', 'JPEGDATA');
        $this->fakeApis();

        $slot = $this->slot(['media_path' => 'content-calendar/cover.jpg', 'destinations' => ['tg_a', 'vk_a']]);
        $this->artisan('content:publish-due')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendPhoto')
            && self::fields($r)['chat_id'] === '@chan_a'
            && self::fields($r)['parse_mode'] === 'HTML'
            && self::fields($r)['photo'] === 'JPEGDATA');
        $this->assertSame(0, self::sentTo('photos.getWallUploadServer'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'wall.post') && $r['attachments'] === '');

        $vk = $slot->publications()->where('destination', 'vk_a')->first();
        $this->assertSame(ContentCalendarPublication::STATUS_PUBLISHED, $vk->status);
        $this->assertStringContainsString('нет права «photos»', $vk->warning);
        $this->assertCount(1, $this->notified);
    }

    public function test_photo_is_uploaded_to_vk_when_token_has_photos_scope(): void
    {
        VkUserToken::query()->update(['scope' => 'wall photos video offline']);
        Storage::fake(ContentCalendarSlot::MEDIA_DISK);
        Storage::disk(ContentCalendarSlot::MEDIA_DISK)->put('content-calendar/cover.jpg', 'JPEGDATA');
        $this->fakeApis([
            'photos.getWallUploadServer' => fn () => Http::response(['response' => ['upload_url' => 'https://pu.vk.example/upload']]),
            'pu.vk.example' => fn () => Http::response(['server' => 7, 'photo' => '[{"p":1}]', 'hash' => 'h']),
            'photos.saveWallPhoto' => fn (Request $r) => Http::response(['response' => [['owner_id' => -111, 'id' => 5]]]),
        ]);

        $slot = $this->slot(['media_path' => 'content-calendar/cover.jpg', 'destinations' => ['vk_a']]);
        $this->artisan('content:publish-due')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'photos.saveWallPhoto')
            && (int) $r['group_id'] === 111 && $r['hash'] === 'h');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'wall.post') && $r['attachments'] === 'photo-111_5');
        $this->assertNull($slot->publications()->first()->warning);
    }

    public function test_prohibited_slot_is_held_as_draft_and_never_sent(): void
    {
        $this->fakeApis();

        $bad = $this->slot(['body' => '<p>Ученица Мария Иванова пишет: лучше, чем <strong>Окаруто</strong></p>']);
        $good = $this->slot(['body' => 'Курс стоит 12 000 ₽ — слово дня', 'destinations' => ['tg_a']]);

        $this->artisan('content:publish-due')->assertSuccessful();

        $this->assertSame(1, self::sentTo('sendMessage'));
        $this->assertSame(0, $bad->publications()->count());
        $held = $bad->fresh();
        $this->assertSame(ContentCalendarSlot::STATUS_DRAFT, $held->status, 'held for a human edit, not retried hourly');
        $this->assertStringContainsString('prohibition-hold §2.8: crm_personal_data', $held->meta['prohibition_hold']);
        $this->assertStringContainsString('competitors («Окаруто»)', $held->meta['prohibition_hold'], 'checklist sees text without markup');
        $this->assertSame(ContentCalendarSlot::STATUS_PUBLISHED, $good->fresh()->status);
        $this->assertStringContainsString('price_or_live_date', $good->fresh()->meta['prohibition_warn'][0]);
    }

    public function test_without_configured_destinations_tick_touches_nothing(): void
    {
        config(['content_calendar.destinations' => [
            'tg_a' => ['platform' => 'telegram', 'label' => 'TG A', 'chat_id' => ''],
            'vk_a' => ['platform' => 'vk', 'label' => 'VK A', 'group_id' => 0],
        ]]);
        Http::fake();

        $slot = $this->slot(['publish_at' => now()->subDays(40)]);
        $this->artisan('content:publish-due')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(ContentCalendarSlot::STATUS_SCHEDULED, $slot->fresh()->status);
        $this->assertNull($slot->fresh()->meta);
    }

    public function test_long_overdue_slot_is_held_not_burst_published(): void
    {
        $this->fakeApis();

        $stale = $this->slot(['publish_at' => now()->subDays(30)]);
        $recent = $this->slot(['publish_at' => now()->subHours(3), 'destinations' => ['tg_a']]);

        $this->artisan('content:publish-due')->assertSuccessful();

        $this->assertSame(1, self::sentTo('api.telegram.org'));
        $this->assertSame(0, $stale->publications()->count());
        $held = $stale->fresh();
        $this->assertSame(ContentCalendarSlot::STATUS_DRAFT, $held->status);
        $this->assertStringContainsString('поставьте новую дату', $held->meta['stale_hold']);
        $this->assertSame(ContentCalendarSlot::STATUS_PUBLISHED, $recent->fresh()->status);
    }

    public function test_vk_waits_for_token_instead_of_failing_when_token_expired(): void
    {
        VkUserToken::query()->update(['expires_at' => now()->subMinute()]);
        $this->fakeApis();

        $slot = $this->slot(['destinations' => ['vk_a']]);
        $this->artisan('content:publish-due')->assertSuccessful();

        $this->assertSame(0, self::sentTo('wall.post'));
        $pub = $slot->publications()->first();
        $this->assertSame(ContentCalendarPublication::STATUS_PENDING, $pub->status);
        $this->assertStringContainsString('нет действующего VK-токена', $pub->error);
    }
}
