<?php

namespace App\Support;

use App\Models\Lesson;
use App\Models\Schedule;

class BeginnerPilotOffer
{
    public static function forView(): array
    {
        $lesson = Lesson::query()->whereKey(config('beginner_pilot.preview_lesson_id'))
            ->where('is_free', true)->where('is_published', true)->first();
        $previewUrl = null;
        if ($lesson) {
            foreach ([$lesson->rutube_url, $lesson->youtube_url] as $candidate) {
                $host = parse_url((string) $candidate, PHP_URL_HOST);
                if (parse_url((string) $candidate, PHP_URL_SCHEME) === 'https'
                    && in_array($host, ['rutube.ru', 'www.youtube.com', 'youtube.com'], true)) {
                    $previewUrl = $candidate;
                    break;
                }
            }
        }

        $schedule = Schedule::find(config('marathon.schedule_id'));
        $available = $schedule && $schedule->start?->isFuture()
            && $schedule->end && $schedule->end->gt($schedule->start)
            && (int) config('beginner_pilot.staffed_schedule_id') === $schedule->id
            && filled(config('beginner_pilot.staffing_confirmed_at'));

        return [
            'previewUrl' => $previewUrl,
            'previewTitle' => $previewUrl ? $lesson->title : null,
            'price' => (int) config('marathon.paid_track_price'),
            'supportAvailable' => (bool) $available,
            'scheduleLabel' => $available
                ? $schedule->start->timezone('Europe/Moscow')->format('d.m.Y H:i')
                    .'–'.$schedule->end->timezone('Europe/Moscow')->format('H:i').' мск'
                : null,
        ];
    }
}
