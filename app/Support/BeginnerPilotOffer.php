<?php

namespace App\Support;

use App\Models\Lesson;
use App\Models\Schedule;
use Illuminate\Support\Carbon;

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

        $videoId = (string) config('beginner_pilot.preview_youtube_id');
        $clipUrl = $lesson && preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)
            && $lesson->youtube_url === 'https://www.youtube.com/embed/'.$videoId
            ? 'https://www.youtube.com/embed/'.$videoId.'?start='.(int) config('beginner_pilot.preview_start_seconds').'&end='.(int) config('beginner_pilot.preview_end_seconds').'&rel=0'
            : null;

        $available = self::registrationAvailable();
        $sessionScheduled = self::supportAvailable();

        $schedule = $sessionScheduled ? Schedule::find(config('marathon.schedule_id')) : null;

        return [
            'previewUrl' => $previewUrl,
            'clipUrl' => $clipUrl,
            'clipWatchUrl' => $clipUrl ? 'https://www.youtube.com/watch?v='.$videoId.'&t='.(int) config('beginner_pilot.preview_start_seconds').'s' : null,
            'previewTitle' => $previewUrl ? $lesson->title : null,
            'price' => (int) config('marathon.paid_track_price'),
            'supportAvailable' => $available,
            'sessionScheduled' => $sessionScheduled,
            'scheduleLabel' => $sessionScheduled
                ? $schedule->start->timezone('Europe/Moscow')->format('d.m.Y H:i')
                    .'–'.$schedule->end->timezone('Europe/Moscow')->format('H:i').' мск'
                : null,
        ];
    }

    public static function supportAvailable(): bool
    {
        $schedule = Schedule::find(config('marathon.schedule_id'));

        return (bool) ($schedule && $schedule->start?->isFuture()
            && $schedule->end && $schedule->end->gt($schedule->start)
            && str_starts_with((string) $schedule->link, 'https://')
            && (int) config('beginner_pilot.staffed_schedule_id') === $schedule->id
            && filled(config('beginner_pilot.staffing_confirmed_at'))
            && config('beginner_pilot.staffed_schedule_start') === $schedule->start->toIso8601String()
            && config('beginner_pilot.staffed_schedule_end') === $schedule->end->toIso8601String());
    }

    public static function registrationCutoff(): ?Carbon
    {
        $schedule = Schedule::find(config('marathon.schedule_id'));

        return $schedule?->start?->copy()->timezone('Europe/Moscow')->startOfDay()->subDays(2);
    }

    public static function registrationAvailable(): bool
    {
        return self::supportAvailable()
            && now()->diffInMinutes(self::registrationCutoff(), false) >= 1;
    }
}
