<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DistributeVideoAnonsJob;
use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Services\Anons\VideoAnons\VideoAnonsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * H6546/H6547: лейн «видео-анонс» — фильтр, роутинг, копии off (вердикт MG
 * 12-10 вечер), и триггерная точка в ProcessTelegramZapisiUpdate.
 */
class VideoAnonsTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_CHAT = -100111;

    private const COURSE_CHATS = [-100111, -100222, -100333];

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'video-anons.enabled' => true,
            'video-anons.stories_enabled' => true,
            'video-anons.copies_enabled' => false,
            'video-anons.author_ids' => [427307131, 1243819140],
            'video-anons.author_usernames' => ['ivanmolochko', 'edgars_leitans'],
            'video-anons.course_authors' => [],
            'video-anons.courses' => [
                'leitana' => ['title' => 'Курс Эдгара Лейтана', 'chat_ids' => self::COURSE_CHATS],
            ],
        ]);
        $this->store = storage_path('framework/testing/video-anons-'.uniqid());
        config(['services.telegram_harvest.store_path' => $this->store]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);
        parent::tearDown();
    }

    /**
     * Форма видео-сообщения 1067 (живая проба 12-10-2026): video без подписи
     * от @ivanmolochko в чате учеников курса Лейтана.
     *
     * @return array<string, mixed>
     */
    private function videoMessage(int $authorId = 1243819140, ?string $username = 'ivanmolochko', int $messageId = 1067): array
    {
        return [
            'message_id' => $messageId,
            'date' => 1791820906,
            'chat' => ['id' => self::SOURCE_CHAT, 'type' => 'supergroup', 'title' => 'Понедельник 20:00 (53) | Чат учеников'],
            'from' => array_filter([
                'id' => $authorId,
                'first_name' => 'Ivan',
                'username' => $username,
            ]),
            'video' => ['file_id' => 'FID-1067', 'mime_type' => 'video/mp4', 'file_size' => 8500000],
        ];
    }

    private function downloadedMedia(int $messageId = 1067): string
    {
        $date = CarbonImmutable::createFromTimestamp(1791820906, config('app.timezone'))->toDateString();
        $dir = $this->store.'/zapisi_media/'.self::SOURCE_CHAT.'/'.$date;
        File::ensureDirectoryExists($dir);
        $path = $dir.'/'.$messageId.'_video.mp4';
        File::put($path, 'fake-mp4-bytes');

        return $path;
    }

    public function test_copies_disabled_dispatches_with_empty_targets_but_media_path(): void
    {
        Bus::fake();
        $mediaPath = $this->downloadedMedia();

        (new VideoAnonsService)->handle($this->videoMessage());

        Bus::assertDispatched(DistributeVideoAnonsJob::class, function (DistributeVideoAnonsJob $job) use ($mediaPath): bool {
            return $job->targetChatIds === [] && $job->mediaPath === $mediaPath;
        });
    }

    public function test_copies_enabled_dispatches_to_other_course_chats(): void
    {
        Bus::fake();
        config(['video-anons.copies_enabled' => true]);

        (new VideoAnonsService)->handle($this->videoMessage());

        Bus::assertDispatched(DistributeVideoAnonsJob::class, function (DistributeVideoAnonsJob $job): bool {
            return $job->sourceChatId === self::SOURCE_CHAT
                && $job->sourceMessageId === 1067
                && $job->courseKey === 'leitana'
                && $job->targetChatIds === [-100222, -100333]
                && $job->laneKey === 'video-anons:lane:-100111:1067';
        });
    }

    public function test_author_outside_whitelist_is_ignored(): void
    {
        Bus::fake();

        (new VideoAnonsService)->handle($this->videoMessage(authorId: 999, username: 'randomuser'));

        Bus::assertNotDispatched(DistributeVideoAnonsJob::class);
    }

    public function test_video_note_circle_is_not_a_trigger(): void
    {
        Bus::fake();
        $message = $this->videoMessage();
        unset($message['video']);
        $message['video_note'] = ['file_id' => 'FID-CIRCLE'];

        (new VideoAnonsService)->handle($message);

        Bus::assertNotDispatched(DistributeVideoAnonsJob::class);
    }

    public function test_chat_outside_map_is_ignored(): void
    {
        Bus::fake();
        $message = $this->videoMessage();
        $message['chat']['id'] = -100999;

        (new VideoAnonsService)->handle($message);

        Bus::assertNotDispatched(DistributeVideoAnonsJob::class);
    }

    public function test_duplicate_message_is_suppressed(): void
    {
        Bus::fake();
        Redis::shouldReceive('set')->twice()->andReturn(true, false);

        (new VideoAnonsService)->handle($this->videoMessage());
        (new VideoAnonsService)->handle($this->videoMessage());

        Bus::assertDispatchedTimes(DistributeVideoAnonsJob::class, 1);
    }

    public function test_disabled_lane_is_ignored(): void
    {
        Bus::fake();
        config(['video-anons.enabled' => false]);

        (new VideoAnonsService)->handle($this->videoMessage());

        Bus::assertNotDispatched(DistributeVideoAnonsJob::class);
    }

    public function test_course_teacher_id_from_course_authors_is_allowed(): void
    {
        Bus::fake();
        config(['video-anons.course_authors' => ['leitana' => [777]]]);

        (new VideoAnonsService)->handle($this->videoMessage(authorId: 777, username: null));

        Bus::assertDispatched(DistributeVideoAnonsJob::class);
    }

    public function test_canonical_map_resolves_every_leitana_chat(): void
    {
        $service = new VideoAnonsService;

        // Каноническая карта из конфига: все шесть чатов курса Лейтана
        // резолвятся в один курс (источник — groups.telegram_chat_id 12-10 +
        // карточки Uprava tools/welcome_post/cards/).
        config(['video-anons.courses' => config('video-anons.courses')]);

        foreach (config('video-anons.courses.leitana.chat_ids') as $chatId) {
            $course = $service->courseForChat((int) $chatId);
            $this->assertNotNull($course, "chat {$chatId} должен резолвиться в курс");
            $this->assertSame('leitana', $course['key']);
        }

        $this->assertNull($service->courseForChat(-1009876543));
    }

    /**
     * Триггерная точка: вебхук-джоб (живая проба 12-10 — видео 1067 собрано
     * за 1 с) диспатчит лейн на video от белого списка.
     */
    public function test_process_zapisi_update_job_triggers_lane(): void
    {
        Bus::fake();
        Redis::shouldReceive('set')->andReturn(true);

        (new ProcessTelegramZapisiUpdate([
            'update_id' => 9001,
            'message' => $this->videoMessage(),
        ]))->handle();

        Bus::assertDispatched(DistributeVideoAnonsJob::class);
    }

    public function test_process_zapisi_update_job_ignores_non_whitelisted_video(): void
    {
        Bus::fake();
        Redis::shouldReceive('set')->andReturn(true);

        (new ProcessTelegramZapisiUpdate([
            'update_id' => 9002,
            'message' => $this->videoMessage(authorId: 555, username: 'student_ivan'),
        ]))->handle();

        Bus::assertNotDispatched(DistributeVideoAnonsJob::class);
    }

    public function test_media_path_resolves_downloaded_video_file(): void
    {
        $service = new VideoAnonsService;
        $this->assertNull($service->mediaPathFor(self::SOURCE_CHAT, 1067, 1791820906));

        $path = $this->downloadedMedia();
        $this->assertSame($path, $service->mediaPathFor(self::SOURCE_CHAT, 1067, 1791820906));
    }

    public function test_tracked_vanons_link_is_registered(): void
    {
        // Вечнозелёный /ga/-ключ сторис-лейна резолвится и несёт UTM кампании.
        $links = config('tracked_links.links');
        $this->assertArrayHasKey('vanons-tg-v1', $links);
        $this->assertSame('video_anons_evergreen', $links['vanons-tg-v1']['utm']['utm_campaign']);
        $this->assertArrayHasKey('vanons', config('tracked_links.story_campaigns'));

        Http::fake(['*' => Http::response('moved', 302)]);
        $response = $this->get('/ga/vanons-tg-v1');
        $response->assertRedirect();
    }
}
