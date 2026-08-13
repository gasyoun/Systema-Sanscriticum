<?php

declare(strict_types=1);

namespace App\Services\GrammarLab;

/**
 * Reproducible 20% expert-review sample (H2494).
 *
 * Sort by exercise_id, HMAC-SHA256(seed|id), take ceil(fraction * n).
 * Same seed + same IDs always produce the same sample.
 */
final class ExerciseSampler
{
    /**
     * @param  list<string>  $exerciseIds
     * @return list<string>
     */
    public function draw(array $exerciseIds, ?float $fraction = null, ?string $seed = null): array
    {
        $ids = array_values(array_unique(array_filter($exerciseIds, fn ($id) => is_string($id) && $id !== '')));
        sort($ids, SORT_STRING);
        $n = count($ids);
        if ($n === 0) {
            return [];
        }

        $fraction = $fraction ?? (float) config('grammar_lab.publication.sample_fraction', 0.2);
        $seed = $seed ?? (string) config('grammar_lab.publication.sample_seed', 'grammar-lab-g3-v1');
        $take = max(1, (int) ceil($n * $fraction));
        $take = min($take, $n);

        $ranked = [];
        foreach ($ids as $id) {
            $ranked[] = ['id' => $id, 'key' => hash_hmac('sha256', $id, $seed)];
        }
        usort($ranked, function (array $a, array $b): int {
            $cmp = strcmp($a['key'], $b['key']);

            return $cmp !== 0 ? $cmp : strcmp($a['id'], $b['id']);
        });

        return array_map(fn (array $row) => $row['id'], array_slice($ranked, 0, $take));
    }

    /**
     * @param  list<string>  $exerciseIds
     * @param  array<string, string>  $decisions
     * @return array<string, mixed>
     */
    public function manifest(array $exerciseIds, array $sampled, array $decisions = []): array
    {
        return [
            'schema_version' => '1.0.0',
            'sample_fraction' => (float) config('grammar_lab.publication.sample_fraction', 0.2),
            'sample_seed' => (string) config('grammar_lab.publication.sample_seed', 'grammar-lab-g3-v1'),
            'drawn_on' => now()->toIso8601String(),
            'pool_ids' => array_values($exerciseIds),
            'exercise_ids' => array_values($sampled),
            'decisions' => $decisions,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function write(array $manifest, ?string $path = null): string
    {
        $path = $path ?? storage_path('app/grammar_lab_sample.json');
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }
        file_put_contents(
            $path,
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );

        return $path;
    }
}
