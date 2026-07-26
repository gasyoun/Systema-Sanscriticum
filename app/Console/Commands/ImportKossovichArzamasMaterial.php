<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Import the second Arzamas-style longread («Россия и санскритский словарь:
 * Коссович против Бётлингка») into the articles table from the repo-controlled
 * pack under docs/materials/kossovich-arzamas/.
 *
 * Thin sibling of ImportPwgArzamasMaterial (H1696 explicitly sanctions a second
 * thin command over parameterizing the first): SOURCE.md is rendered to
 * body.html by build_body.py; this command only upserts the already-rendered
 * payload — it never accepts remote or user-supplied HTML (ARCHITECTURE §5).
 *
 * Idempotent: upsert by slug. Re-import refreshes content; it never unpublishes
 * an already-published article. `--publish` flips is_published on (the Article
 * model auto-stamps published_at).
 */
class ImportKossovichArzamasMaterial extends Command
{
    protected $signature = 'materials:import-kossovich-arzamas
        {--publish : set is_published=true (final run, after gates are green)}
        {--body= : override body.html path (tests only)}
        {--meta= : override meta.json path (tests only)}';

    protected $description = 'Upsert the Kossovich Arzamas longread article from docs/materials/kossovich-arzamas/';

    private const MIN_H2 = 12;

    private const WORDS_PER_MINUTE = 200;

    public function handle(): int
    {
        try {
            $bodyPath = $this->option('body') ?: base_path('docs/materials/kossovich-arzamas/body.html');
            $metaPath = $this->option('meta') ?: base_path('docs/materials/kossovich-arzamas/meta.json');

            $body = $this->readFile($bodyPath);
            $meta = json_decode($this->readFile($metaPath), true, 512, JSON_THROW_ON_ERROR);

            foreach (['slug', 'title', 'author_name'] as $required) {
                if (empty($meta[$required])) {
                    throw new RuntimeException("meta.json is missing required field '{$required}'");
                }
            }

            $h2Count = substr_count($body, '<h2');
            if ($h2Count < self::MIN_H2) {
                throw new RuntimeException(
                    "body.html has only {$h2Count} <h2> chapters (H1696 goal needs >= ".self::MIN_H2.')'
                );
            }

            $data = [
                'title' => $meta['title'],
                'subtitle' => $meta['subtitle'] ?? null,
                'excerpt' => $meta['excerpt'] ?? null,
                'body' => $body,
                'author_name' => $meta['author_name'],
                'reading_time' => $this->readingTime($body),
                'meta_title' => $meta['meta_title'] ?? null,
                'meta_description' => isset($meta['meta_description'])
                    ? mb_substr($meta['meta_description'], 0, 500)
                    : null,
            ];

            if ($coverPath = $this->stageCover($meta)) {
                $data['cover_path'] = $coverPath;
            }

            if ($this->option('publish')) {
                $data['is_published'] = true;
            }

            $existing = Article::where('slug', $meta['slug'])->first();
            if ($existing) {
                $existing->update($data);
                $verb = 'updated';
            } else {
                $existing = Article::create($data + ['slug' => $meta['slug'], 'is_published' => (bool) $this->option('publish')]);
                $verb = 'created';
            }

            $this->info(sprintf(
                '%s article #%d [%s]: %d h2, reading_time %d min, is_published=%s',
                $verb,
                $existing->id,
                $meta['slug'],
                $h2Count,
                $data['reading_time'],
                $existing->is_published ? 'true' : 'false',
            ));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function readFile(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("File not found: {$path} (run build_body.py first?)");
        }

        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            throw new RuntimeException("File is empty or unreadable: {$path}");
        }

        return $contents;
    }

    private function readingTime(string $body): int
    {
        preg_match_all('/[\p{L}\p{N}]+/u', strip_tags($body), $matches);

        return max(1, (int) round(count($matches[0]) / self::WORDS_PER_MINUTE));
    }

    /**
     * Copy the repo-controlled cover into the public storage disk the Article
     * cover accessor expects. A missing source is a warning, not a failure —
     * every cover consumer is null-guarded (hub card falls back to a gradient).
     */
    private function stageCover(array $meta): ?string
    {
        $source = $meta['cover_source'] ?? null;
        $target = $meta['cover_storage_path'] ?? null;
        if (! $source || ! $target) {
            return null;
        }

        $absolute = public_path($source);
        if (! is_file($absolute)) {
            $this->warn("Cover source missing, keeping cover_path unset: {$absolute}");

            return null;
        }

        Storage::disk('public')->put($target, (string) file_get_contents($absolute));

        return $target;
    }
}
