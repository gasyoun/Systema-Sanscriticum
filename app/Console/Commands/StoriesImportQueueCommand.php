<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StoryPost;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Импорт готовых постов из очереди Uprava content/queue (H3930, Phase 1).
 *
 * Формат файла: YYYY-MM-DD-<код>.md (см. Uprava/content/queue/README.md) —
 * шапка-таблица, затем строка-разделитель и чистый текст поста. Разделитель
 * встречается в двух вариантах дефиса — «- - - текст поста - - -» и
 * «— — — текст поста — — —».
 *
 * Дедуп по (source=queue, source_key=имя файла): существующая строка НЕ
 * перезаписывается — рука агента/MG могла уже утвердить её в Filament.
 * Новые строки ложатся status=draft; слот «утро» → 09:00, «вечер» → 19:00,
 * иначе config('services.telegram_story.default_publish_hour').
 */
final class StoriesImportQueueCommand extends Command
{
    protected $signature = 'stories:import-queue
                            {--path= : Каталог с файлами очереди (по умолчанию services.telegram_story.queue_path)}';

    protected $description = 'Import Uprava content/queue post files into story_posts (draft)';

    public function handle(): int
    {
        $path = (string) ($this->option('path') ?: (string) config('services.telegram_story.queue_path', ''));
        if ($path === '' || ! is_dir($path)) {
            $this->error("Queue path not found: {$path} (pass --path or set TELEGRAM_STORY_QUEUE_PATH)");

            return self::FAILURE;
        }

        $defaultHour = max(0, min(23, (int) config('services.telegram_story.default_publish_hour', 9)));
        $imported = 0;
        $skippedExisting = 0;
        $skippedName = 0;

        foreach (glob(rtrim($path, '/\\').'/*.md') ?: [] as $file) {
            $basename = basename($file);
            if ($basename === 'README.md' || ! preg_match('/^(\d{4}-\d{2}-\d{2})-([A-Za-z0-9]+)\.md$/', $basename)) {
                $skippedName++;

                continue;
            }

            $parsed = $this->parseFile($file);
            if ($parsed === null) {
                $this->warn("Skip {$basename}: no «текст поста» separator found.");

                continue;
            }

            [$date, $text, $hour] = $parsed;
            $exists = StoryPost::query()
                ->where('source', StoryPost::SOURCE_QUEUE)
                ->where('source_key', $basename)
                ->exists();
            if ($exists) {
                $skippedExisting++;

                continue;
            }

            StoryPost::query()->create([
                'kind' => StoryPost::KIND_TEXT,
                'payload' => $text,
                'source' => StoryPost::SOURCE_QUEUE,
                'source_key' => $basename,
                'status' => StoryPost::STATUS_DRAFT,
                'publish_at' => Carbon::parse($date)->setHour($hour ?? $defaultHour)->setMinute(0)->setSecond(0),
            ]);
            $imported++;
            $this->line("Imported {$basename} → draft, publish {$date} ".sprintf('%02d', $hour ?? $defaultHour).':00');
        }

        $this->info("Import done: imported={$imported}, already={$skippedExisting}, unmatched_files={$skippedName}.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string, 2: int|null}|null [YYYY-MM-DD, текст, час из слота]
     */
    private function parseFile(string $file): ?array
    {
        try {
            $content = file_get_contents($file);
        } catch (Throwable) {
            return null;
        }
        if ($content === false || $content === '') {
            return null;
        }

        // Пост — всё ПОСЛЕ строки-разделителя. Разделитель встречается как
        // «- - - текст поста - - -», «— — — текст поста — — —» и слитно
        // «---текст поста---», поэтому токены дефиса с пробелами: (?:[-—]\s*){3}.
        // Построчный разбор (не byte-offset): preg offsets байтовые, mb_substr
        // по ним режет мимо на кириллице.
        $lines = preg_split('/\R/u', $content) ?: [];
        $sepIndex = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(?:[-—]\s*){3}\s*текст поста\s*(?:[-—]\s*){3}\s*$/u', $line) === 1) {
                $sepIndex = $i;

                break;
            }
        }
        if ($sepIndex === null) {
            return null;
        }

        $text = trim(implode("\n", array_slice($lines, $sepIndex + 1)));
        if ($text === '') {
            return null;
        }

        $basename = basename($file);
        preg_match('/^(\d{4}-\d{2}-\d{2})-/', $basename, $d);
        $date = $d[1] ?? now()->toDateString();

        $hour = null;
        if (preg_match('/^\|\s*Слот\s*\|\s*([^|\n]+)/mu', $content, $slot)) {
            $slotText = mb_strtolower($slot[1]);
            if (mb_stripos($slotText, 'утро') !== false) {
                $hour = 9;
            } elseif (mb_stripos($slotText, 'вечер') !== false) {
                $hour = 19;
            }
        }

        return [$date, $text, $hour];
    }
}
