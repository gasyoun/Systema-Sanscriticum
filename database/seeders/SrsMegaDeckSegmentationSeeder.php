<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SrsDeck;
use App\Services\Srs\MegaDeckSegmenter;
use Illuminate\Database\Seeder;

/**
 * H7966 (трек А, Q18) — сегментация двух мега-деков (sanskrit-core,
 * sanskrit-cyrillic-only) на частотные подколоды топ-500 / 500–2000 /
 * остальное. Запуск: php artisan db:seed --class=SrsMegaDeckSegmentationSeeder
 *
 * Инвариант (H7966 acceptance): переезд меняет только srs_cards.deck_id —
 * id карточек и каждая строка srs_review_states сохраняются. Доказвается
 * tests/Feature/Srs/SrsMegaDeckSegmentationTest.php. Идемпотентно.
 */
class SrsMegaDeckSegmentationSeeder extends Seeder
{
    public const MEGA_DECK_SLUGS = ['sanskrit-core', 'sanskrit-cyrillic-only'];

    public function run(): void
    {
        $segmenter = app(MegaDeckSegmenter::class);

        foreach (self::MEGA_DECK_SLUGS as $slug) {
            $deck = SrsDeck::query()
                ->where('slug', $slug)
                ->whereNull('user_id')
                ->first();

            if ($deck === null) {
                continue; // мега-дека нет (пустая БД/тест) — не выдумываем
            }

            $moved = $segmenter->segment($deck);

            $this->command?->info(sprintf(
                '[%s] %s → %s',
                $slug,
                'сегментировано',
                json_encode($moved, JSON_UNESCAPED_UNICODE),
            ));
        }

        // Размерная tier пересчитывается по всем декам после переезда.
        SrsDeck::query()->withCount('cards')->each(function (SrsDeck $deck): void {
            $tier = SrsDeck::sizeTierFor((int) $deck->cards_count);
            if ($deck->size_tier !== $tier) {
                $deck->forceFill(['size_tier' => $tier])->save();
            }
        });
    }
}
