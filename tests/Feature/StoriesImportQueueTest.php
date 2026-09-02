<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StoryPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * stories:import-queue (H3930, Phase 1): парсер файлов очереди
 * Uprava/content/queue → draft-строки story_posts. Дедуп по имени файла,
 * слот «утро/вечер» → 09:00/19:00, файлы без разделителя скипаются.
 */
class StoriesImportQueueTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/stories-import-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /** @test */
    public function imports_new_queue_file_as_draft_with_morning_slot(): void
    {
        file_put_contents($this->dir.'/2026-09-05-ANN9.md', <<<'MD'
# 05-09-2026 — анонс #N

| Поле | Значение |
|---|---|
| Слот | утро/день — средний (без тяжёлых слов, не с дедлайнами) |

- - - текст поста - - -

Осень 2026: набор групп открыт.

Запись: samskrte.ru/online
MD);

        $this->artisan('stories:import-queue', ['--path' => $this->dir])
            ->assertSuccessful();

        $row = StoryPost::query()->sole();
        $this->assertSame(StoryPost::KIND_TEXT, $row->kind);
        $this->assertSame(StoryPost::SOURCE_QUEUE, $row->source);
        $this->assertSame('2026-09-05-ANN9.md', $row->source_key);
        $this->assertSame(StoryPost::STATUS_DRAFT, $row->status);
        $this->assertSame('2026-09-05 09:00', $row->publish_at->format('Y-m-d H:i'));
        $this->assertStringContainsString('Осень 2026: набор групп открыт.', (string) $row->payload);
        $this->assertStringNotContainsString('Слот', (string) $row->payload);
    }

    /** @test */
    public function evening_slot_maps_to_19_and_em_dash_separator_is_supported(): void
    {
        file_put_contents($this->dir.'/2026-09-06-A2.md', <<<'MD'
| Слот | вечер — разбор строфы |

— — — текст поста — — —

Два словаря совпадают на 94 753 словах.
MD);

        $this->artisan('stories:import-queue', ['--path' => $this->dir])
            ->assertSuccessful();

        $row = StoryPost::query()->sole();
        $this->assertSame('2026-09-06 19:00', $row->publish_at->format('Y-m-d H:i'));
        $this->assertSame('Два словаря совпадают на 94 753 словах.', $row->payload);
    }

    /** @test */
    public function second_run_does_not_duplicate_or_clobber(): void
    {
        file_put_contents($this->dir.'/2026-09-07-C2.md', "— — — текст поста — — —\n\nПервый текст.\n");

        $this->artisan('stories:import-queue', ['--path' => $this->dir])->assertSuccessful();

        $row = StoryPost::query()->sole();
        $row->forceFill(['status' => StoryPost::STATUS_APPROVED])->save();

        $this->artisan('stories:import-queue', ['--path' => $this->dir])->assertSuccessful();

        $this->assertSame(1, StoryPost::query()->count());
        $this->assertSame(StoryPost::STATUS_APPROVED, $row->fresh()->status);
    }

    /** @test */
    public function files_without_separator_or_matching_name_are_skipped(): void
    {
        file_put_contents($this->dir.'/README.md', '# Очередь');
        file_put_contents($this->dir.'/2026-09-08-A1.md', "# Шапка без разделителя\n\nТекст есть, разделителя нет.\n");
        file_put_contents($this->dir.'/just-notes.md', "- - - текст поста - - -\n\nНе файл очереди.\n");

        $this->artisan('stories:import-queue', ['--path' => $this->dir])->assertSuccessful();

        $this->assertSame(0, StoryPost::query()->count());
    }
}
