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
     * Count matching units without materialising cells. Hub uses this so a
     * published v1 pin (7,689 / 31,753) cannot inflate the HTML.
     */
    public function count(string $surface, bool $previewOnly, bool $includeAttested): int
    {
        return count($this->summaries($surface, $previewOnly, $includeAttested, null));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $surface, bool $previewOnly, bool $includeAttested): array
    {
        return $this->page($surface, $previewOnly, $includeAttested, 1, PHP_INT_MAX)['items'];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int}
     */
    public function page(
        string $surface,
        bool $previewOnly,
        bool $includeAttested,
        int $page,
        ?int $perPage = null,
        ?string $q = null,
    ): array {
        $per = $perPage ?? max(1, (int) config('visualdcs.page_size', 50));
        $all = $this->summaries($surface, $previewOnly, $includeAttested, $q);
        $total = count($all);
        $page = max(1, $page);
        $slice = array_slice($all, ($page - 1) * $per, $per);

        return [
            'items' => $slice,
            'total' => $total,
            'page' => $page,
            'perPage' => $per,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $objectId): ?array
    {
        $surface = $this->surfaceForId($objectId);
        if ($surface === null) {
            return null;
        }

        $payload = $this->payload($surface);
        $needle = match ($surface) {
            'verb' => ['roots' => []],
            'nominal' => ['lemmas' => []],
            'passage' => ['passages' => [], 'links' => $payload['links'] ?? []],
            default => null,
        };
        if ($needle === null) {
            return null;
        }

        if ($surface === 'verb') {
            foreach ($payload['roots'] ?? [] as $root) {
                if (is_array($root) && 'vdcs:v1:verb:'.($root['rootId'] ?? '') === $objectId) {
                    $needle['roots'] = [$root];
                    break;
                }
            }
        } elseif ($surface === 'nominal') {
            foreach ($payload['lemmas'] ?? [] as $lemma) {
                if (is_array($lemma) && 'vdcs:v1:nominal:'.($lemma['lemmaId'] ?? '') === $objectId) {
                    $needle['lemmas'] = [$lemma];
                    break;
                }
            }
        } else {
            foreach ($payload['passages'] ?? [] as $passage) {
                if (is_array($passage) && ($passage['passageId'] ?? null) === $objectId) {
                    $needle['passages'] = [$passage];
                    break;
                }
            }
        }

        $items = $this->items($surface, $needle, detail: true);

        return $items[0] ?? null;
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
     * @return list<array<string, mixed>>
     */
    private function summaries(string $surface, bool $previewOnly, bool $includeAttested, ?string $q): array
    {
        $items = $this->items($surface, $this->payload($surface), detail: false);
        $out = [];
        $needle = $q !== null && $q !== '' ? mb_strtolower($q) : null;
        foreach ($items as $item) {
            $tier = (string) ($item['tier'] ?? 'full');
            if ($previewOnly && $tier !== 'full') {
                continue;
            }
            if (! $includeAttested && $tier === 'attested') {
                continue;
            }
            if ($needle !== null && ! str_contains(mb_strtolower((string) $item['title']), $needle)) {
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

    private function surfaceForId(string $objectId): ?string
    {
        if (str_starts_with($objectId, 'vdcs:v1:verb:')) {
            return 'verb';
        }
        if (str_starts_with($objectId, 'vdcs:v1:nominal:')) {
            return 'nominal';
        }
        if (str_starts_with($objectId, 'vdcs:v1:passage:')) {
            return 'passage';
        }

        return null;
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
    private function items(string $surface, array $payload, bool $detail): array
    {
        return match ($surface) {
            'verb' => $this->verbItems($payload, $detail),
            'nominal' => $this->nominalItems($payload, $detail),
            'passage' => $this->passageItems($payload, $detail),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function verbItems(array $payload, bool $detail): array
    {
        $out = [];
        foreach ($payload['roots'] ?? [] as $root) {
            if (! is_array($root) || ! isset($root['rootId'])) {
                continue;
            }
            $row = [
                'id' => 'vdcs:v1:verb:'.$root['rootId'],
                'surface' => 'verb',
                'tier' => (string) ($root['tier'] ?? 'attested'),
                'title' => (string) $root['rootId'],
                'rank' => (int) ($root['rank'] ?? 0),
                'totalTokens' => (int) ($root['totalTokens'] ?? 0),
            ];
            if ($detail) {
                $row['cells'] = $root['cells'] ?? [];
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function nominalItems(array $payload, bool $detail): array
    {
        $out = [];
        foreach ($payload['lemmas'] ?? [] as $lemma) {
            if (! is_array($lemma) || ! isset($lemma['lemmaId'])) {
                continue;
            }
            $row = [
                'id' => 'vdcs:v1:nominal:'.$lemma['lemmaId'],
                'surface' => 'nominal',
                'tier' => (string) ($lemma['tier'] ?? 'attested'),
                'title' => (string) ($lemma['lemma'] ?? $lemma['lemmaId']),
                'lemmaId' => (string) $lemma['lemmaId'],
                'rank' => (int) ($lemma['rank'] ?? 0),
                'tokens' => (int) ($lemma['tokens'] ?? 0),
                'domGender' => (string) ($lemma['domGender'] ?? ''),
            ];
            if ($detail) {
                $row['cells'] = $lemma['cells'] ?? [];
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function passageItems(array $payload, bool $detail): array
    {
        $linksByPassage = [];
        if ($detail) {
            foreach ($payload['links'] ?? [] as $link) {
                if (! is_array($link) || ! isset($link['passageId'])) {
                    continue;
                }
                $linksByPassage[$link['passageId']][] = $link;
            }
        }

        $out = [];
        foreach ($payload['passages'] ?? [] as $passage) {
            if (! is_array($passage) || ! isset($passage['passageId'])) {
                continue;
            }
            $id = (string) $passage['passageId'];
            $linked = (int) ($passage['linkedFormCount'] ?? 0);
            $row = [
                'id' => $id,
                'surface' => 'passage',
                'tier' => $linked > 0 ? 'full' : 'attested',
                'title' => (string) ($passage['title'] ?? $id),
                'src' => (string) ($passage['src'] ?? ''),
                'linkedFormCount' => $linked,
            ];
            if ($detail) {
                $row['txt'] = (string) ($passage['txt'] ?? '');
                $row['genre'] = (string) ($passage['genre'] ?? '');
                $row['diff'] = (int) ($passage['diff'] ?? 0);
                $row['links'] = $linksByPassage[$id] ?? [];
            }
            $out[] = $row;
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
