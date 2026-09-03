<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StoryPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * stories:from-harvest (H3964, юнит 5): перепись harvest-медиа из raw-стора
 * только читает; завод черновика дедупится по md5(пути); неизвестное
 * расширение требует явного --kind.
 */
class StoriesFromHarvestTest extends TestCase
{
    use RefreshDatabase;

    private string $store;

    private string $media;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = storage_path('app/testing/h3964/harvest-store');
        $this->media = storage_path('app/testing/h3964/harvest-media/group-photo.jpg');

        File::deleteDirectory(storage_path('app/testing/h3964'));
        File::ensureDirectoryExists(dirname($this->media));
        file_put_contents($this->media, 'jpeg-bytes');

        config([
            'services.telegram_harvest.store_path' => $this->store,
            'services.telegram_story.default_publish_hour' => 9,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/testing/h3964'));

        parent::tearDown();
    }

    private function writeHarvestRecord(string $mediaPath, string $sentAt = '2026-09-01T10:00:00+03:00'): void
    {
        $record = [
            '_' => 'message',
            'id' => 77,
            'peer' => '@sanskrit_group',
            'sent_at' => $sentAt,
            'text' => 'фото с festivals',
            'access_level' => 'corpus',
            'media_local_path' => $mediaPath,
        ];
        // Layout писаря: {store}/{lane}/{peerKey}/{YYYY-MM-DD}.jsonl
        File::ensureDirectoryExists($this->store.'/corpus/-100123');
        File::append(
            $this->store.'/corpus/-100123/2026-09-01.jsonl',
            json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
        );
    }

    /** Путь для аргумента командной строки: artisan-парсер съедает `\`. */
    private function cliPath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /** @test */
    public function list_reads_the_raw_store_without_writing_anything(): void
    {
        $this->writeHarvestRecord($this->media);

        $this->artisan('stories:from-harvest --list')
            ->expectsOutputToContain('group-photo.jpg')
            ->assertSuccessful();

        $this->assertSame(0, StoryPost::query()->count(), '--list ничего не заводит');
    }

    /** @test */
    public function creates_a_persona_lane_photo_draft_deduped_by_path_hash(): void
    {
        $this->writeHarvestRecord($this->media);

        $this->artisan("stories:from-harvest {$this->cliPath($this->media)} --caption=\"Фото с фестиваля\"")
            ->expectsOutputToContain('черновик')
            ->assertSuccessful();

        $post = StoryPost::query()->sole();
        $cliMedia = $this->cliPath($this->media);
        $this->assertSame(StoryPost::KIND_PHOTO, $post->kind);
        $this->assertSame(StoryPost::LANE_PERSONA, $post->lane);
        $this->assertSame(StoryPost::SOURCE_HARVEST, $post->source);
        $this->assertSame(StoryPost::STATUS_DRAFT, $post->status);
        $this->assertSame('harvest:'.md5($cliMedia), $post->source_key);
        $this->assertSame('Фото с фестиваля', $post->payload);
        $this->assertSame($cliMedia, $post->media_path);
        $this->assertNotNull($post->publish_at, 'слот по умолчанию: завтра в default_publish_hour');

        // Повторный вызов с тем же файлом не дублирует черновик.
        $this->artisan("stories:from-harvest {$this->cliPath($this->media)}")->assertSuccessful();
        $this->assertSame(1, StoryPost::query()->count());
    }

    /** @test */
    public function unknown_extension_fails_loud_until_kind_is_given(): void
    {
        $weird = storage_path('app/testing/h3964/harvest-media/clip.bin');
        file_put_contents($weird, 'bytes');

        $exitCode = Artisan::call('stories:from-harvest '.$this->cliPath($weird));
        $out = Artisan::output();
        self::assertSame(1, $exitCode, "exit={$exitCode}, out:\n".$out);
        $this->assertSame(0, StoryPost::query()->count());

        $exitCode = Artisan::call('stories:from-harvest '.$this->cliPath($weird).' --kind=video');
        $out = Artisan::output();
        self::assertSame(0, $exitCode, "exit={$exitCode}, out:\n".$out);
        $this->assertSame(StoryPost::KIND_VIDEO, StoryPost::query()->sole()->kind);
    }
}
