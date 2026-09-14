<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;

/**
 * Резолвит три остановки «пути обучения» на главной (H431, Phase 1) в
 * реальные курсы — тем же паттерном title-LIKE, что и onramp-рекомендации
 * квиза «С чего начать» (config/onramp.php), чтобы не хардкодить слаги
 * когорт, которые ротируются от потока к потоку. Курс не нашёлся → шаг
 * рендерится без ссылки на курс (не битая ссылка, а мягкая деградация —
 * CTA ведёт в общий каталог).
 */
class TrajectoryPaths
{
    /**
     * @return list<array{key: string, title: string, description: string, course: ?Course, url: string, freeUrl: ?string, nextDateLabel: ?string}>
     */
    public static function resolve(): array
    {
        $catalogUrl = route('shop.index');

        return array_map(function (array $step) use ($catalogUrl) {
            $course = Course::query()
                ->where('is_visible', true)
                ->where('title', 'LIKE', '%'.str_replace(['%', '_'], ['\%', '\_'], $step['pattern']).'%')
                ->orderBy('id')
                ->first();

            $freeUrl = null;
            $nextDateLabel = null;

            if ($course) {
                $freeUrl = $course->previewLesson
                    ? route('shop.course.preview', $course->slug)
                    : null;

                $nextSchedule = $course->upcomingSchedules(1)->first();
                if ($nextSchedule?->start) {
                    $nextDateLabel = 'Ближайший поток: '.$nextSchedule->start->locale('ru')->translatedFormat('d F Y');
                }
            }

            return [
                'key' => $step['key'],
                'title' => $step['title'],
                'description' => $step['description'],
                'course' => $course,
                'url' => $course ? route('shop.course.show', $course->slug) : $catalogUrl,
                'freeUrl' => $freeUrl,
                'nextDateLabel' => $nextDateLabel,
            ];
        }, config('trajectory.steps', []));
    }
}
