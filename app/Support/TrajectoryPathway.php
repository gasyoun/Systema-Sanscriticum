<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\Tariff;

/**
 * Catalogue pathway for /online/put (H2764 / R18).
 *
 * Reuses {@see TrajectoryPaths::resolve()} so cohort slugs stay un-hardcoded.
 * Adds the shop's visible «от N ₽» (R06) and the Bühler intake label
 * «осень 2027» (R09) when FlagshipLanding matches that flagship.
 */
final class TrajectoryPathway
{
    public const BUHLER_NEXT_INTAKE_LABEL = 'Следующий набор: осень 2027';

    /**
     * @return list<array{
     *     key: string,
     *     title: string,
     *     description: string,
     *     course: ?Course,
     *     url: string,
     *     freeUrl: ?string,
     *     nextDateLabel: ?string,
     *     priceLabel: ?string
     * }>
     */
    public static function resolve(): array
    {
        $steps = TrajectoryPaths::resolve();
        $courseIds = [];
        foreach ($steps as $step) {
            if (($step['course'] ?? null) instanceof Course) {
                $courseIds[] = (int) $step['course']->id;
            }
        }

        $minPrices = $courseIds === []
            ? collect()
            : Tariff::query()
                ->where('is_active', true)
                ->whereIn('course_id', array_values(array_unique($courseIds)))
                ->selectRaw('course_id, MIN(price) as min_price')
                ->groupBy('course_id')
                ->pluck('min_price', 'course_id');

        return array_map(function (array $step) use ($minPrices): array {
            $course = $step['course'] ?? null;
            $priceLabel = null;

            if ($course instanceof Course) {
                $min = $minPrices->get($course->id);
                if ($min !== null && (float) $min > 0) {
                    $priceLabel = 'от '.number_format((float) $min, 0, '.', ' ').' ₽';
                }

                if (FlagshipLanding::keyFor($course) === 'buhler') {
                    $step['nextDateLabel'] = self::BUHLER_NEXT_INTAKE_LABEL;
                }
            }

            $step['priceLabel'] = $priceLabel;

            return $step;
        }, $steps);
    }
}
