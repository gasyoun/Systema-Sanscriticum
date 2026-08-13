<?php

declare(strict_types=1);

namespace App\Services\Learning;

use App\Models\VisualDcsRelease;
use RuntimeException;

/**
 * Read-only adapter over the currently promoted VisualDCS release.
 */
final class ExternalLearningCatalog
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    private ?string $cacheReleaseId = null;

    public function promoted(): ?VisualDcsRelease
    {
        return VisualDcsRelease::query()->promoted()->latest('promoted_at')->first();
    }

    public function isEmpty(): bool
    {
        return $this->promoted() === null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $surface, bool $previewOnly, bool $includeAttested): array
    {
        $payload = $this->payload($surface);
        $items = $this->items($surface, $payload);
        $out = [];
        foreach ($items as $item) {
            $tier = (string) ($item['tier'] ?? 'full');
            if ($previewOnly && $tier !== 'full') {
                continue;
            }
            if (! $includeAttested && $tier === 'attested') {
                continue;
            }
            $out[] = $item;
        }

        if ($previewOnly) {
            $limit = max(0, (int) config('visualdcs.preview_limit', 5));

            return array_slice($out, 0, $limit);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $objectId): ?array
    {
        foreach ((array) config('visualdcs.surfaces', []) as $surface) {
            foreach ($this->list($surface, false, true) as $item) {
                if (($item['id'] ?? null) === $objectId) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function mustFind(string $objectId): array
    {
        $item = $this->find($objectId);
        if ($item === null) {
            throw new RuntimeException('Unknown VisualDCS object: '.$objectId);
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $surface): array
    {
        $bundle = $this->bundle();
        $file = (string) (config('visualdcs.payload_files.'.$surface) ?? '');
        if ($file === '' || ! isset($bundle[$file])) {
            return [];
        }

        return $bundle[$file];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function items(string $surface, array $payload): array
    {
        return match ($surface) {
            'verb' => $this->verbItems($payload),
            'nominal' => $this->nominalItems($payload),
            'passage' => $this->passageItems($payload),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function verbItems(array $payload): array
    {
        $out = [];
        foreach ($payload['roots'] ?? [] as $root) {
            if (! is_array($root) || ! isset($root['rootId'])) {
                continue;
            }
            $out[] = [
                'id' => 'vdcs:v1:verb:'.$root['rootId'],
                'surface' => 'verb',
                'tier' => (string) ($root['tier'] ?? 'attested'),
                'title' => (string) $root['rootId'],
                'rank' => (int) ($root['rank'] ?? 0),
                'totalTokens' => (int) ($root['totalTokens'] ?? 0),
                'cells' => $root['cells'] ?? [],
                'raw' => $root,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function nominalItems(array $payload): array
    {
        $out = [];
        foreach ($payload['lemmas'] ?? [] as $lemma) {
            if (! is_array($lemma) || ! isset($lemma['lemmaId'])) {
                continue;
            }
            $out[] = [
                'id' => 'vdcs:v1:nominal:'.$lemma['lemmaId'],
                'surface' => 'nominal',
                'tier' => (string) ($lemma['tier'] ?? 'attested'),
                'title' => (string) ($lemma['lemma'] ?? $lemma['lemmaId']),
                'lemmaId' => (string) $lemma['lemmaId'],
                'rank' => (int) ($lemma['rank'] ?? 0),
                'tokens' => (int) ($lemma['tokens'] ?? 0),
                'domGender' => (string) ($lemma['domGender'] ?? ''),
                'cells' => $lemma['cells'] ?? [],
                'raw' => $lemma,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function passageItems(array $payload): array
    {
        $linksByPassage = [];
        foreach ($payload['links'] ?? [] as $link) {
            if (! is_array($link) || ! isset($link['passageId'])) {
                continue;
            }
            $linksByPassage[$link['passageId']][] = $link;
        }

        $out = [];
        foreach ($payload['passages'] ?? [] as $passage) {
            if (! is_array($passage) || ! isset($passage['passageId'])) {
                continue;
            }
            $id = (string) $passage['passageId'];
            $linked = (int) ($passage['linkedFormCount'] ?? 0);
            $out[] = [
                'id' => $id,
                'surface' => 'passage',
                'tier' => $linked > 0 ? 'full' : 'attested',
                'title' => (string) ($passage['title'] ?? $id),
                'src' => (string) ($passage['src'] ?? ''),
                'txt' => (string) ($passage['txt'] ?? ''),
                'genre' => (string) ($passage['genre'] ?? ''),
                'diff' => (int) ($passage['diff'] ?? 0),
                'linkedFormCount' => $linked,
                'links' => $linksByPassage[$id] ?? [],
                'raw' => $passage,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function bundle(): array
    {
        $release = $this->promoted();
        if (! $release) {
            $this->cacheReleaseId = null;

            return $this->cache = [];
        }

        if ($this->cache !== null && $this->cacheReleaseId === $release->release_id) {
            return $this->cache;
        }

        $this->cacheReleaseId = $release->release_id;
        $root = $release->storage_path;
        $out = [];
        foreach ((array) config('visualdcs.payload_files', []) as $file) {
            $path = rtrim($root, '/\\').DIRECTORY_SEPARATOR.$file;
            if (! is_file($path)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($path), true);
            $out[$file] = is_array($decoded) ? $decoded : [];
        }

        return $this->cache = $out;
    }
}
