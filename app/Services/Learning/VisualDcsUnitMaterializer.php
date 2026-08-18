<?php

declare(strict_types=1);

namespace App\Services\Learning;

use App\Models\VisualDcsRelease;
use App\Models\VisualDcsUnit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * H2869 step-9 repair: разворачивает payload-файлы релиза в строки
 * `visualdcs_units` ОДИН раз, при импорте (CLI, лимит памяти снят).
 *
 * Зачем: на проде запросное json_decode опубликованного релиза (11 МБ verb +
 * 15,6 МБ nominal) раздувается в сотни МБ PHP-массивов и убивает php-fpm
 * (memory_limit=128M) — все три поверхности отдавали 500 при первом же флипе
 * флагов. Это тот же класс, что и стандарт репозитория «ничего тяжёлого на
 * пути запроса»: тяжёлая работа уходит с запроса в команду импорта.
 */
final class VisualDcsUnitMaterializer
{
    private const CHUNK = 500;

    /**
     * Идемпотентно: если у релиза уже столько строк, сколько даёт payload,
     * повторный вызов ничего не пишет. При расхождении строится заново.
     */
    public function ensureMaterialized(VisualDcsRelease $release): int
    {
        $rows = $this->rows($release);
        $existing = VisualDcsUnit::query()
            ->where('visualdcs_release_id', $release->id)
            ->count();

        if ($existing === count($rows)) {
            return $existing;
        }

        DB::transaction(function () use ($release, $rows) {
            VisualDcsUnit::query()
                ->where('visualdcs_release_id', $release->id)
                ->delete();
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                VisualDcsUnit::query()->insert($chunk);
            }
        });

        return count($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(VisualDcsRelease $release): array
    {
        $rows = [];
        $now = now();
        foreach ((array) config('visualdcs.payload_files', []) as $surface => $file) {
            $payload = $this->decode($release, (string) $file);
            $units = match ($surface) {
                'verb' => $this->verbUnits($payload),
                'nominal' => $this->nominalUnits($payload),
                'passage' => $this->passageUnits($payload),
                default => [],
            };
            foreach ($units as $order => $unit) {
                $rows[] = [
                    'visualdcs_release_id' => $release->id,
                    'surface' => $surface,
                    'unit_id' => $unit['id'],
                    'tier' => $unit['tier'],
                    'title' => mb_substr($unit['title'], 0, 512),
                    'title_lower' => mb_substr(mb_strtolower($unit['title']), 0, 512),
                    'sort_order' => $order,
                    'summary' => json_encode($unit['summary'], JSON_UNESCAPED_UNICODE),
                    'detail' => json_encode($unit['detail'], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(VisualDcsRelease $release, string $file): array
    {
        $path = rtrim((string) $release->storage_path, '/\\').DIRECTORY_SEPARATOR.$file;
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Payload is not valid JSON: {$path}");
        }

        return $decoded;
    }

    /**
     * Формы строк 1-в-1 повторяют прежние verbItems/nominalItems/passageItems
     * из ExternalLearningCatalog — контроллеры и вьюхи не меняются.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{id: string, tier: string, title: string, summary: array<string, mixed>, detail: array<string, mixed>}>
     */
    private function verbUnits(array $payload): array
    {
        $out = [];
        foreach ($payload['roots'] ?? [] as $root) {
            if (! is_array($root) || ! isset($root['rootId'])) {
                continue;
            }
            $out[] = [
                'id' => 'vdcs:v1:verb:'.$root['rootId'],
                'tier' => (string) ($root['tier'] ?? 'attested'),
                'title' => (string) $root['rootId'],
                'summary' => [
                    'rank' => (int) ($root['rank'] ?? 0),
                    'totalTokens' => (int) ($root['totalTokens'] ?? 0),
                ],
                'detail' => ['cells' => $root['cells'] ?? []],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{id: string, tier: string, title: string, summary: array<string, mixed>, detail: array<string, mixed>}>
     */
    private function nominalUnits(array $payload): array
    {
        $out = [];
        foreach ($payload['lemmas'] ?? [] as $lemma) {
            if (! is_array($lemma) || ! isset($lemma['lemmaId'])) {
                continue;
            }
            $out[] = [
                'id' => 'vdcs:v1:nominal:'.$lemma['lemmaId'],
                'tier' => (string) ($lemma['tier'] ?? 'attested'),
                'title' => (string) ($lemma['lemma'] ?? $lemma['lemmaId']),
                'summary' => [
                    'lemmaId' => (string) $lemma['lemmaId'],
                    'rank' => (int) ($lemma['rank'] ?? 0),
                    'tokens' => (int) ($lemma['tokens'] ?? 0),
                    'domGender' => (string) ($lemma['domGender'] ?? ''),
                ],
                'detail' => ['cells' => $lemma['cells'] ?? []],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{id: string, tier: string, title: string, summary: array<string, mixed>, detail: array<string, mixed>}>
     */
    private function passageUnits(array $payload): array
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
                'tier' => $linked > 0 ? 'full' : 'attested',
                'title' => (string) ($passage['title'] ?? $id),
                'summary' => [
                    'src' => (string) ($passage['src'] ?? ''),
                    'linkedFormCount' => $linked,
                ],
                'detail' => [
                    'txt' => (string) ($passage['txt'] ?? ''),
                    'genre' => (string) ($passage['genre'] ?? ''),
                    'diff' => (int) ($passage['diff'] ?? 0),
                    'links' => $linksByPassage[$id] ?? [],
                ],
            ];
        }

        return $out;
    }
}
