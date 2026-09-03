<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StoryPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Doubles\FakeStoriesMadelineProtoClient;
use Tests\TestCase;

/**
 * stories:publish-story (H3964, Phase 2): флаг OFF — ноль действий и сессия
 * не открывается вовсе; persona-полоса издаётся MadelineProto-двойником
 * (text/photo/video), канальная не трогается; студенческие медиа заперты
 * визой; repeat-движок перепланирует копии; --test-text шлёт и удаляет тем
 * же кодом; проба лимита останавливается на первом FLOOD.
 */
class StoriesPublishStoryTest extends TestCase
{
    use RefreshDatabase;

    private string $photo;

    protected function setUp(): void
    {
        parent::setUp();

        FakeStoriesMadelineProtoClient::reset();

        $this->photo = storage_path('app/testing/h3964/smoke.jpg');
        @mkdir(dirname($this->photo), 0775, true);
        file_put_contents($this->photo, 'jpeg-bytes');

        config([
            'features.telegram_story_stories' => false,
            'services.telegram_story.subprocess_lane' => false,
            'services.telegram_support.api_id' => 12345,
            'services.telegram_support.api_hash' => 'test-hash',
            'services.telegram_support.client_class' => FakeStoriesMadelineProtoClient::class,
            'services.telegram_support.session' => storage_path('app/testing/h3964/session.madeline'),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->photo);

        parent::tearDown();
    }

    private function personaPost(array $overrides = []): StoryPost
    {
        return StoryPost::query()->create(array_merge([
            'kind' => StoryPost::KIND_TEXT,
            'lane' => StoryPost::LANE_PERSONA,
            'payload' => 'Намасте! Сегодня в 19:00 разбор песен Бхагавадгиты.',
            'source' => StoryPost::SOURCE_MANUAL,
            'status' => StoryPost::STATUS_APPROVED,
            'publish_at' => now()->subHour(),
        ], $overrides));
    }

    /** Путь для аргумента командной строки: artisan-парсер съедает `\`. */
    private function cliPhoto(): string
    {
        return str_replace('\\', '/', $this->photo);
    }

    private function personaPhotoPost(array $overrides = []): StoryPost
    {
        return $this->personaPost(array_merge([
            'kind' => StoryPost::KIND_PHOTO,
            'media_path' => $this->photo,
            'payload' => 'Фото-сториз персоны',
        ], $overrides));
    }

    /** @test */
    public function flag_off_is_a_full_noop_even_the_client_is_never_opened(): void
    {
        $post = $this->personaPost();

        $this->artisan('stories:publish-story')
            ->expectsOutputToContain('no-op')
            ->assertSuccessful();

        self::assertSame([], FakeStoriesMadelineProtoClient::$sentStories, 'ноль HTTP/MTProto при OFF');
        self::assertSame(0, FakeStoriesMadelineProtoClient::$constructions, 'MadelineProto-сессия не открывается');
        $this->assertSame(StoryPost::STATUS_APPROVED, $post->fresh()->status);
    }

    /** @test */
    public function unconfigured_madelineproto_fails_loud_without_touching_the_session(): void
    {
        config([
            'features.telegram_story_stories' => true,
            'services.telegram_support.api_id' => null,
        ]);

        $post = $this->personaPost();

        $exitCode = Artisan::call('stories:publish-story');
        $out = Artisan::output();
        self::assertSame(1, $exitCode, "exit={$exitCode}, out:\n".$out);
        self::assertSame(0, FakeStoriesMadelineProtoClient::$constructions);
        $this->assertSame(StoryPost::STATUS_APPROVED, $post->fresh()->status);
    }

    /** @test */
    public function empty_queue_never_opens_the_shared_session(): void
    {
        config(['features.telegram_story_stories' => true]);

        $this->artisan('stories:publish-story')
            ->expectsOutputToContain('очередь persona пуста')
            ->assertSuccessful();

        self::assertSame(0, FakeStoriesMadelineProtoClient::$constructions, 'пустая очередь — ноль запусков общей сессии');
    }

    /** @test */
    public function persona_text_rows_are_skipped_text_user_stories_do_not_exist_in_mtproto(): void
    {
        config(['features.telegram_story_stories' => true]);

        $post = $this->personaPost();

        $this->artisan('stories:publish-story')->assertSuccessful();

        self::assertSame([], FakeStoriesMadelineProtoClient::$sentStories, 'текстовых user-сториз в схеме нет');
        $fresh = $post->fresh();
        $this->assertSame(StoryPost::STATUS_APPROVED, $fresh->status, 'строка остаётся на кураторе');
        $this->assertStringContainsString('MEDIA_FILE_INVALID', (string) $fresh->journal);
    }

    /** @test */
    public function publishes_persona_photo_story_and_marks_the_row(): void
    {
        config(['features.telegram_story_stories' => true]);

        $post = $this->personaPhotoPost();

        $this->artisan('stories:publish-story')->assertSuccessful();

        self::assertCount(1, FakeStoriesMadelineProtoClient::$sentStories);
        $sent = FakeStoriesMadelineProtoClient::$sentStories[0];
        self::assertSame('me', $sent['peer'], 'сториз кладётся на СВОЙ профиль персоны');
        self::assertSame('inputMediaUploadedPhoto', $sent['media']['_']);
        self::assertSame($this->photo, FakeStoriesMadelineProtoClient::$uploads[0]);

        $fresh = $post->fresh();
        $this->assertSame(StoryPost::STATUS_PUBLISHED, $fresh->status);
        $this->assertNotNull($fresh->posted_at);
        $this->assertSame('story:4210', $fresh->telegram_message_id);
    }

    /** @test */
    public function channel_lane_rows_are_not_taken_by_the_story_publisher(): void
    {
        config(['features.telegram_story_stories' => true]);

        $channelPost = $this->personaPost(['lane' => StoryPost::LANE_CHANNEL]);

        $this->artisan('stories:publish-story')->assertSuccessful();

        self::assertSame([], FakeStoriesMadelineProtoClient::$sentStories);
        $this->assertSame(StoryPost::STATUS_APPROVED, $channelPost->fresh()->status);
    }

    /** @test */
    public function repeat_engine_reschedules_copies_until_the_series_cap(): void
    {
        config(['features.telegram_story_stories' => true]);

        $post = $this->personaPhotoPost([
            'repeat_rule' => ['every_days' => 1, 'times' => 2],
        ]);

        $this->artisan('stories:publish-story')->assertSuccessful();

        $fresh = $post->fresh();
        $this->assertSame(StoryPost::STATUS_PUBLISHED, $fresh->status);
        $this->assertSame(1, (int) $fresh->repeat_count, 'первая публикация серии');

        $copies = StoryPost::query()->whereKeyNot($post->id)->get();
        self::assertCount(1, $copies, 'times=2 → ровно одна копия');
        $copy = $copies->sole();
        $this->assertSame(StoryPost::STATUS_APPROVED, $copy->status);
        $this->assertTrue($copy->publish_at->between(now()->addDay()->subMinute(), now()->addDay()->addMinute()));
        $this->assertSame(1, (int) $copy->repeat_count, 'копия несёт счётчик серии');
        $this->assertNull($copy->source_key, 'копия без dedup-ключа — unique(source, source_key) не стреляет');

        // Второй выход серии: копия публикуется, третьей копии нет.
        $this->travel(1)->day();
        $this->artisan('stories:publish-story')->assertSuccessful();

        $this->assertSame(StoryPost::STATUS_PUBLISHED, $copy->fresh()->status);
        $this->assertSame(2, (int) $copy->fresh()->repeat_count);
        $this->assertSame(2, StoryPost::query()->count(), 'серия погасла: оригинал + одна копия, третьей нет');
        $this->assertSame(2, count(FakeStoriesMadelineProtoClient::$sentStories));
    }

    /** @test */
    public function student_media_without_visa_is_never_published(): void
    {
        config(['features.telegram_story_stories' => true]);
        config(['features.telegram_story_student_media_visa' => false]);

        $media = storage_path('app/testing/h3964/homework.jpg');
        @mkdir(dirname($media), 0775, true);
        file_put_contents($media, 'jpeg-bytes');

        $post = $this->personaPost([
            'kind' => StoryPost::KIND_PHOTO,
            'media_path' => $media,
            'source' => StoryPost::SOURCE_HOMEWORK,
        ]);

        $this->artisan('stories:publish-story')->assertSuccessful();

        self::assertSame([], FakeStoriesMadelineProtoClient::$sentStories, 'студенческое медиа не публикуется до визы');
        $fresh = $post->fresh();
        $this->assertSame(StoryPost::STATUS_APPROVED, $fresh->status, 'строка остаётся на кураторе');
        $this->assertStringContainsString('виз', (string) $fresh->journal);
    }

    /** @test */
    public function student_media_publishes_only_after_the_visa_flag(): void
    {
        config(['features.telegram_story_stories' => true]);
        config(['features.telegram_story_student_media_visa' => true]);

        $media = storage_path('app/testing/h3964/homework.jpg');
        @mkdir(dirname($media), 0775, true);
        file_put_contents($media, 'jpeg-bytes');

        $this->personaPost([
            'kind' => StoryPost::KIND_PHOTO,
            'media_path' => $media,
            'source' => StoryPost::SOURCE_HOMEWORK,
        ]);

        $this->artisan('stories:publish-story')->assertSuccessful();

        self::assertCount(1, FakeStoriesMadelineProtoClient::$sentStories);
        self::assertCount(1, FakeStoriesMadelineProtoClient::$uploads);
        self::assertSame('inputMediaUploadedPhoto', FakeStoriesMadelineProtoClient::$sentStories[0]['media']['_']);
    }

    /** @test */
    public function unreadable_media_is_retired_from_the_queue(): void
    {
        config(['features.telegram_story_stories' => true]);

        $post = $this->personaPost([
            'kind' => StoryPost::KIND_VIDEO,
            'media_path' => storage_path('app/testing/h3964/never-exists.mp4'),
        ]);

        $this->artisan('stories:publish-story')->assertSuccessful();

        self::assertSame([], FakeStoriesMadelineProtoClient::$sentStories);
        $this->assertSame(StoryPost::STATUS_SKIPPED, $post->fresh()->status, 'битая строка не ретраится ежечасно');
    }

    /** @test */
    public function test_photo_mode_sends_and_deletes_by_the_same_code(): void
    {
        config(['features.telegram_story_stories' => true]);

        $this->artisan('stories:publish-story --test-photo='.$this->cliPhoto())
            ->expectsOutputToContain('Deleted story id=4210')
            ->assertSuccessful();

        self::assertCount(1, FakeStoriesMadelineProtoClient::$sentStories);
        self::assertCount(1, FakeStoriesMadelineProtoClient::$deletedStories);
        self::assertSame([['peer' => 'me', 'id' => [4210]]], FakeStoriesMadelineProtoClient::$deletedStories);
    }

    /** @test */
    public function limit_probe_stops_at_the_first_flood_and_stays_green(): void
    {
        config(['features.telegram_story_stories' => true]);
        FakeStoriesMadelineProtoClient::$floodAfter = 2;

        $this->artisan('stories:publish-story --test-photo='.$this->cliPhoto().' --probe-attempts=10')
            ->expectsOutputToContain('FLOOD')
            ->assertSuccessful();

        self::assertCount(2, FakeStoriesMadelineProtoClient::$sentStories, 'две прошли, третья упёрлась в FLOOD');
        self::assertCount(2, FakeStoriesMadelineProtoClient::$deletedStories, 'пробные сториз удаляются');
    }

    /** @test */
    public function delete_story_mode_removes_artifacts(): void
    {
        config(['features.telegram_story_stories' => true]);

        $this->artisan('stories:publish-story --delete-story=4230')->assertSuccessful();

        self::assertSame([['peer' => 'me', 'id' => [4230]]], FakeStoriesMadelineProtoClient::$deletedStories);
    }
}
