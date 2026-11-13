<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SrsDeck;
use Illuminate\Database\Seeder;

/**
 * H7966 (трек А, Q9) — офлайн-проход гибридной разметки: метаданные источника
 * (тема/сложность) проставляются детерминированно по известным slug системных
 * колод; всё остальное остаётся null и уточняется учителем в Filament до
 * публикации (ручное авторство студентов не трогаем — дефолт 3 грилла).
 *
 * size_tier считается по cards_count для ВСЕХ дек (не только мега) —
 * discovery-фильтр размера живёт на обеих поверхностях.
 * Идемпотентно. Запуск: php artisan db:seed --class=SrsTaxonomySeeder
 */
class SrsTaxonomySeeder extends Seeder
{
    /**
     * slug => [topic, difficulty]. Известные системные деки; словарь тем —
     * SrsDeck::TOPICS, словарь сложностей — SrsDeck::DIFFICULTIES.
     */
    private const CURATED = [
        'sanskrit-core' => ['lexicon', 'intermediate'],
        'sanskrit-cyrillic-only' => ['lexicon', 'beginner'],
        'sanskrit-roots-frequency' => ['roots', 'intermediate'],
        'sanskrit-ligatures-frequency' => ['script', 'beginner'],
        'kosha-b1-nala' => ['lexicon', 'intermediate'],
    ];

    public function run(): void
    {
        foreach (self::CURATED as $slug => [$topic, $difficulty]) {
            SrsDeck::query()
                ->where('slug', $slug)
                ->whereNull('user_id')
                ->update(['topic' => $topic, 'difficulty' => $difficulty]);
        }

        // Сегменты наследуют таксономию родителя (на случай частичного запуска).
        SrsDeck::query()
            ->whereNotNull('parent_deck_id')
            ->whereNull('topic')
            ->with('parent')
            ->get()
            ->each(function (SrsDeck $deck): void {
                $deck->forceFill([
                    'topic' => $deck->parent?->topic,
                    'difficulty' => $deck->difficulty ?? $deck->parent?->difficulty,
                ])->save();
            });

        // Размерная tier по фактическому количеству карточек.
        SrsDeck::query()->withCount('cards')->each(function (SrsDeck $deck): void {
            $tier = SrsDeck::sizeTierFor((int) $deck->cards_count);
            if ($deck->size_tier !== $tier) {
                $deck->forceFill(['size_tier' => $tier])->save();
            }
        });
    }
}
