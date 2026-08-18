<?php

declare(strict_types=1);

namespace App\Services\Learning;

use App\Models\VisualDcsRelease;
use App\Models\VisualDcsUnit;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Read-only adapter over the currently promoted VisualDCS release.
 *
 * H2869 step-9 repair: читает `visualdcs_units` (материализуются при импорте,
 * см. VisualDcsUnitMaterializer), а НЕ payload-файлы релиза. Прежняя версия
 * декодировала все три JSON (26 МБ, опубликованный масштаб 7 689 + 31 753
 * единиц) на каждом запросе и валила php-fpm по памяти. Формы возвращаемых
 * массивов сохранены 1-в-1 — контроллеры и вьюхи не меняются.
 */
final class ExternalLearningCatalog
{
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
        $query = $this->unitQuery($surface, $previewOnly, $includeAttested, null);
        if ($query === null) {
            return 0;
        }

        $total = $query->count();
        if ($previewOnly) {
            return min($total, $this->previewLimit());
        }

        return $total;
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
        $page = max(1, $page);

        $query = $this->unitQuery($surface, $previewOnly, $includeAttested, $q);
        if ($query === null) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'perPage' => $per];
        }

        $total = $query->count();
        if ($previewOnly) {
            // Прежняя семантика: превью — первые N подходящих единиц, всё
            // остальное для гостя не существует.
            $total = min($total, $this->previewLimit());
            $per = min($per, $this->previewLimit());
        }

        $offset = ($page - 1) * $per;
        $limit = $previewOnly ? max(0, min($per, $total - $offset)) : $per;

        $items = [];
        if ($limit > 0) {
            $units = $query->orderBy('sort_order')->skip($offset)->take($limit)->get();
            foreach ($units as $unit) {
                $items[] = $this->summaryRow($unit);
            }
        }

        return [
            'items' => $items,
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
        $release = $this->promoted();
        if (! $release) {
            return null;
        }

        $unit = VisualDcsUnit::query()
            ->where('visualdcs_release_id', $release->id)
            ->where('unit_id', $objectId)
            ->first();
        if (! $unit) {
            return null;
        }

        return array_merge($this->summaryRow($unit), (array) $unit->detail);
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
     * @return Builder<VisualDcsUnit>|null
     */
    private function unitQuery(string $surface, bool $previewOnly, bool $includeAttested, ?string $q): ?Builder
    {
        $release = $this->promoted();
        if (! $release) {
            return null;
        }

        $query = VisualDcsUnit::query()
            ->where('visualdcs_release_id', $release->id)
            ->where('surface', $surface);

        if ($previewOnly) {
            $query->where('tier', 'full');
        } elseif (! $includeAttested) {
            $query->where('tier', '!=', 'attested');
        }

        if ($q !== null && $q !== '') {
            $escaped = addcslashes(mb_strtolower($q), '\\%_');
            $query->where('title_lower', 'like', '%'.$escaped.'%');
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryRow(VisualDcsUnit $unit): array
    {
        return array_merge(
            [
                'id' => $unit->unit_id,
                'surface' => $unit->surface,
                'tier' => $unit->tier,
                'title' => $unit->title,
            ],
            (array) $unit->summary,
        );
    }

    private function previewLimit(): int
    {
        return max(0, (int) config('visualdcs.preview_limit', 5));
    }
}
