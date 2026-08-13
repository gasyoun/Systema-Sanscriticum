<?php

declare(strict_types=1);

namespace App\Services\GrammarLab;

use App\Models\GrammarExercise;
use App\Models\GrammarLabImport;
use App\Models\GrammarTopic;
use App\Models\GrammarTopicSource;
use App\Models\GrammarTopicVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Idempotent G1 bundle import (H2493).
 *
 * Source bundle is authoritative for topic content. Learner bookmarks/views
 * are never deleted. Re-import updates by stable topic_id + content_hash.
 * Removed published IDs become status=retired (tombstone).
 */
final class GrammarLabImporter
{
    /**
     * @return array{
     *   dry_run: bool,
     *   published: int,
     *   upserted: int,
     *   unchanged: int,
     *   retired: int,
     *   exercises: int,
     *   bundle_sha256: string,
     *   manifest_sha256: string,
     *   bundle_version: string,
     *   vendor_dir: string
     * }
     */
    public function import(string $vendorDir, bool $dryRun = false): array
    {
        $vendorDir = rtrim($vendorDir, '\\/');
        $manifestPath = $vendorDir.DIRECTORY_SEPARATOR.'manifest.json';
        $bundlePath = $vendorDir.DIRECTORY_SEPARATOR.'grammar_lab.json';
        if (! is_file($manifestPath) || ! is_file($bundlePath)) {
            throw new RuntimeException("Grammar Lab vendor incomplete under {$vendorDir}");
        }

        $manifest = $this->readJson($manifestPath);
        $bundle = $this->readJson($bundlePath);
        $this->assertPin($manifest, $bundle);

        $expectedBundle = $this->feedSha($manifest, 'grammar_lab_bundle');
        $actualBundle = hash_file('sha256', $bundlePath);
        if ($expectedBundle !== '' && $expectedBundle !== $actualBundle) {
            throw new RuntimeException("grammar_lab.json sha256 mismatch (expected {$expectedBundle}, got {$actualBundle})");
        }

        $topics = $bundle['topics'] ?? [];
        if (! is_array($topics) || $topics === []) {
            throw new RuntimeException('bundle has no topics[]');
        }

        $published = [];
        foreach ($topics as $topic) {
            if (is_array($topic) && ($topic['status'] ?? '') === 'published') {
                $published[(string) $topic['id']] = $topic;
            }
        }

        $report = [
            'dry_run' => $dryRun,
            'published' => count($published),
            'upserted' => 0,
            'unchanged' => 0,
            'retired' => 0,
            'exercises' => 0,
            'bundle_sha256' => $actualBundle,
            'manifest_sha256' => hash_file('sha256', $manifestPath),
            'bundle_version' => (string) ($bundle['bundle_version'] ?? ''),
            'vendor_dir' => $vendorDir,
        ];

        if ($dryRun) {
            $existing = GrammarTopic::query()->pluck('content_hash', 'topic_id');
            foreach ($published as $id => $topic) {
                $hash = $this->contentHash($topic);
                if (($existing[$id] ?? null) === $hash) {
                    $report['unchanged']++;
                } else {
                    $report['upserted']++;
                }
            }
            $report['retired'] = GrammarTopic::query()
                ->where('status', 'published')
                ->whereNotIn('topic_id', array_keys($published))
                ->count();
            $report['exercises'] = count($bundle['exercises'] ?? []);

            return $report;
        }

        return DB::transaction(function () use ($published, $bundle, $report) {
            $import = GrammarLabImport::query()->create([
                'bundle_version' => $report['bundle_version'],
                'schema_version' => (string) ($bundle['schema_version'] ?? ''),
                'manifest_sha256' => $report['manifest_sha256'],
                'bundle_sha256' => $report['bundle_sha256'],
                'published_topic_count' => $report['published'],
                'upserted_count' => 0,
                'unchanged_count' => 0,
                'retired_count' => 0,
                'pin_tag' => (string) (config('grammar_lab.pin.tag') ?? ''),
                'imported_at' => now(),
            ]);

            foreach ($published as $id => $topic) {
                $hash = $this->contentHash($topic);
                $row = GrammarTopic::query()->where('topic_id', $id)->first();
                if ($row && $row->content_hash === $hash && $row->status === 'published') {
                    $report['unchanged']++;

                    continue;
                }

                $this->upsertTopic($row, $topic, $hash, $import->id);
                $report['upserted']++;
            }

            $retired = GrammarTopic::query()
                ->where('status', 'published')
                ->whereNotIn('topic_id', array_keys($published))
                ->get();
            foreach ($retired as $row) {
                $row->status = 'retired';
                $row->retired_at = now();
                $row->save();
                $report['retired']++;
            }

            $publisher = app(ExercisePublisher::class);
            foreach ($bundle['exercises'] ?? [] as $exercise) {
                if (! is_array($exercise) || ! isset($exercise['id'])) {
                    continue;
                }
                $row = $publisher->ingest($exercise);
                $report['exercises']++;
                if ($row->status === GrammarExercise::STATUS_PUBLISHED) {
                    $report['published_exercises'] = ($report['published_exercises'] ?? 0) + 1;
                } elseif ($row->status === GrammarExercise::STATUS_REJECTED) {
                    $report['rejected_exercises'] = ($report['rejected_exercises'] ?? 0) + 1;
                }
            }
            $sample = $publisher->drawReviewSample();
            $report['sample_size'] = count($sample['sampled']);
            $report['sample_path'] = $sample['path'];

            $import->upserted_count = $report['upserted'];
            $import->unchanged_count = $report['unchanged'];
            $import->retired_count = $report['retired'];
            $import->save();

            return $report;
        });
    }

    public function vendorDir(): string
    {
        return resource_path((string) config('grammar_lab.vendor_rel', 'data/grammar_lab'));
    }

    /**
     * @param  array<string, mixed>  $topic
     */
    private function upsertTopic(?GrammarTopic $row, array $topic, string $hash, int $importId): GrammarTopic
    {
        $id = (string) $topic['id'];
        $slug = $this->slugFromId($id);
        $attrs = [
            'topic_id' => $id,
            'slug' => $slug,
            'status' => 'published',
            'title_ru' => (string) $topic['title_ru'],
            'cluster' => $topic['cluster'] ?? null,
            'difficulty' => $topic['difficulty'] ?? null,
            'summary_ru' => (string) ($topic['summary_ru'] ?? ''),
            'alignment_note' => $topic['alignment_note'] ?? null,
            'aliases' => $topic['aliases'] ?? [],
            'prerequisites' => $topic['prerequisites'] ?? [],
            'dictionary_evidence' => $topic['dictionary_evidence'] ?? [],
            'corpus_evidence' => $topic['corpus_evidence'] ?? [],
            'exercise_ids' => $topic['exercise_ids'] ?? [],
            'provenance' => $topic['provenance'] ?? [],
            'payload' => $topic,
            'content_hash' => $hash,
            'import_id' => $importId,
            'retired_at' => null,
        ];

        if ($row === null) {
            $row = GrammarTopic::query()->create($attrs);
        } else {
            $row->fill($attrs)->save();
        }

        GrammarTopicVersion::query()->create([
            'grammar_topic_id' => $row->id,
            'topic_id' => $id,
            'content_hash' => $hash,
            'status' => 'published',
            'import_id' => $importId,
            'payload' => $topic,
            'recorded_at' => now(),
        ]);

        GrammarTopicSource::query()->where('grammar_topic_id', $row->id)->delete();
        foreach ($topic['sources'] ?? [] as $source) {
            if (! is_array($source)) {
                continue;
            }
            $anchorType = (string) ($source['anchor_type'] ?? '');
            GrammarTopicSource::query()->create([
                'grammar_topic_id' => $row->id,
                'topic_id' => $id,
                'family' => $this->sourceFamily($anchorType),
                'anchor_type' => $anchorType,
                'anchor_id' => (string) ($source['anchor_id'] ?? ''),
                'anchor_key_slp1' => $source['anchor_key_slp1'] ?: null,
                'source_dataset' => (string) ($source['source_dataset'] ?? ''),
                'link_type' => $source['link_type'] ?? null,
                'confidence' => $source['confidence'] ?? null,
                'payload' => $source,
            ]);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $exercise
     */
    private function upsertExercise(array $exercise): void
    {
        app(ExercisePublisher::class)->ingest($exercise);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $bundle
     */
    private function assertPin(array $manifest, array $bundle): void
    {
        $minSchema = (string) config('grammar_lab.pin.schema_version', '1.0.0');
        $schema = (string) ($bundle['schema_version'] ?? $manifest['schema_version'] ?? '');
        if ($schema === '' || version_compare($schema, $minSchema, '<')) {
            throw new RuntimeException("schema_version {$schema} is below pin {$minSchema}");
        }
        $expectedBundle = (string) config('grammar_lab.pin.bundle_version', '1.0.0');
        $got = (string) ($bundle['bundle_version'] ?? '');
        if ($got !== '' && $got !== $expectedBundle) {
            // Accept equal-or-newer patch on the same major.minor.
            $expParts = explode('.', $expectedBundle.'.0.0');
            $gotParts = explode('.', $got.'.0.0');
            if ((int) $gotParts[0] !== (int) $expParts[0]) {
                throw new RuntimeException("bundle_version {$got} incompatible with pin {$expectedBundle}");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function feedSha(array $manifest, string $id): string
    {
        foreach ($manifest['feeds'] ?? [] as $feed) {
            if (is_array($feed) && ($feed['id'] ?? null) === $id) {
                return (string) ($feed['sha256'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function contentHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function slugFromId(string $id): string
    {
        $pos = strrpos($id, ':');

        return $pos === false ? $id : substr($id, $pos + 1);
    }

    private function sourceFamily(string $anchorType): string
    {
        if (str_starts_with($anchorType, 'whitney')) {
            return 'whitney';
        }
        if (str_starts_with($anchorType, 'zalizniak')) {
            return 'zalizniak';
        }

        return 'other';
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            throw new RuntimeException("invalid JSON: {$path}");
        }

        return $data;
    }
}
