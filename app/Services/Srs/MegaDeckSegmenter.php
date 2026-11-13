<?php

declare(strict_types=1);

namespace App\Services\Srs;

use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Support\IastToSlp1;
use Illuminate\Support\Collection;

/**
 * H7966 (трек А, Q18) — сегментация мега-деков по частотному рангу:
 * топ-500 / 500–2000 / остальное, три подколоды на исходный дек.
 *
 * Источник ранга — kosha «kosha-lemma-frequency» (DCS lemma frequency,
 * CC BY-SA 4.0): слим-фикстура database/seeders/data/deck_frequency_rank.tsv
 * (SLP1 → rank_all ≤ 5000; хвост рангов на границу не влияет и отброшен
 * билдером). Ключ карточки — IastToSlp1::keyFor(fields.iast); без ключа
 * карточка уходит в «rest» (не выдумываем ранг).
 *
 * Инвариант (own-data canary 23k-переезда): НЕ создаются и НЕ удаляются
 * srs_cards и srs_review_states — меняется только srs_cards.deck_id.
 * Идемпотентно: при уже разбитом деке (0 карточек + дети) — no-op.
 */
final class MegaDeckSegmenter
{
    /** Бандлы в порядке приоритета: slug-суффикс, подпись, верхняя граница ранга (включительно). */
    public const BANDS = [
        'top500' => ['suffix' => 'top-500', 'label' => 'топ-500', 'max_rank' => 500],
        'top2000' => ['suffix' => '500-2000', 'label' => '500–2000', 'max_rank' => 2000],
        'rest' => ['suffix' => 'rest', 'label' => 'остальное', 'max_rank' => PHP_INT_MAX],
    ];

    private const FIXTURE_DIR = 'deck_frequency_rank';

    /** @var array<string, int>|null */
    private ?array $ranks = null;

    /**
     * @param  array<string, int>|null  $rankOverride  slp1 → rank (тесты/экспертный проход; null = фикстура)
     * @return array<string, int> количество карточек per band после переезда
     */
    public function segment(SrsDeck $deck, ?array $rankOverride = null): array
    {
        $ranks = $rankOverride ?? $this->ranks();

        // Идемпотентность: дек уже разбит — не трогаем.
        if ($deck->children()->exists() && ! $deck->cards()->exists()) {
            return [];
        }

        $bands = array_fill_keys(array_keys(self::BANDS), []);
        $restKey = 'rest';

        $deck->cards()->chunkById(500, function (Collection $cards) use (&$bands, $ranks): void {
            foreach ($cards as $card) {
                $band = $this->bandFor($card, $ranks);
                $bands[$band][] = (int) $card->id;
            }
        });

        $children = [];
        foreach (self::BANDS as $band => $meta) {
            $children[$band] = $this->childDeck($deck, $band, $meta);
        }

        $moved = [];
        foreach (self::BANDS as $band => $meta) {
            $ids = $bands[$band];
            $moved[$band] = 0;
            if ($ids === []) {
                continue;
            }
            // Ключевое движение: только deck_id, id карточек и review-состояния нетронуты.
            $moved[$band] = SrsCard::query()
                ->where('deck_id', $deck->id)
                ->whereIn('id', $ids)
                ->update(['deck_id' => $children[$band]->id]);
        }

        foreach ($children as $child) {
            $child->refresh();
            $child->forceFill(['size_tier' => SrsDeck::sizeTierFor($child->cards()->count())])->save();
        }

        return $moved;
    }

    /** slp1 → rank из закоммиченных фикстур-партиций (один раз на процесс). */
    public function ranks(): array
    {
        if ($this->ranks !== null) {
            return $this->ranks;
        }

        $this->ranks = [];
        $paths = glob(database_path('seeders/data/'.self::FIXTURE_DIR.'/*.tsv')) ?: [];
        sort($paths);
        foreach ($paths as $path) {
            $rows = array_map(
                fn (string $line): array => explode("\t", $line),
                file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
            );
            array_shift($rows); // заголовок slp1\trank
            foreach ($rows as $row) {
                if (count($row) === 2) {
                    $this->ranks[$row[0]] = (int) $row[1];
                }
            }
        }

        return $this->ranks;
    }

    /** @param  array<string, int>  $ranks */
    private function bandFor(SrsCard $card, array $ranks): string
    {
        $fields = (array) $card->fields;
        $key = IastToSlp1::keyFor($fields['iast'] ?? null);
        $rank = $key !== null ? ($ranks[$key] ?? PHP_INT_MAX) : PHP_INT_MAX;

        foreach (self::BANDS as $band => $meta) {
            if ($rank <= $meta['max_rank']) {
                return $band;
            }
        }

        return 'rest';
    }

    /**
     * @param  array{suffix: string, label: string, max_rank: int}  $meta
     */
    private function childDeck(SrsDeck $parent, string $band, array $meta): SrsDeck
    {
        $slug = sprintf('%s-%s', $parent->slug, $meta['suffix']);

        return SrsDeck::query()->firstOrCreate(
            ['slug' => $slug, 'user_id' => null],
            [
                'note_type_id' => $parent->note_type_id,
                'parent_deck_id' => $parent->id,
                'name' => $parent->name.' · '.$meta['label'],
                'language' => $parent->language,
                'visibility' => $parent->visibility,
                'topic' => $parent->topic,
                'difficulty' => $parent->difficulty,
                'description' => sprintf(
                    'Сегмент «%s» колоды «%s» по частоте DCS (H7966, Q18).',
                    $meta['label'],
                    $parent->name,
                ),
                'tags' => array_values(array_unique(array_merge(
                    (array) $parent->tags,
                    ['band:'.$band],
                ))),
            ],
        );
    }
}
