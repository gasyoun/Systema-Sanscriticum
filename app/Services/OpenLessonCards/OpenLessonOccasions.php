<?php

declare(strict_types=1);

namespace App\Services\OpenLessonCards;

use App\Models\Course;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Какие занятия идут в бот карточкой. Только строки расписания с галочкой
 * bot_card_enabled — автоотбора нет сознательно (пробное платное, разовые
 * курсы бывают платными; карточка обещает «бесплатно» и раздаёт Zoom).
 */
class OpenLessonOccasions
{
    /** @return Builder<Schedule> */
    public function query(): Builder
    {
        return Schedule::query()
            ->where('bot_card_enabled', true)
            ->with(['course.teacher', 'course.teachers', 'group']);
    }

    /** @return Collection<int, Schedule> */
    public function between(Carbon $from, Carbon $to): Collection
    {
        return $this->query()
            ->whereBetween('start', [$from, $to])
            ->orderBy('start')
            ->orderBy('id')
            ->get();
    }

    /** Занятие с галочкой, которое ещё не закончилось (для deep-link и кнопок). */
    public function find(int $scheduleId): ?Schedule
    {
        return $this->query()
            ->whereKey($scheduleId)
            ->where('start', '>=', now()->subHours(Schedule::DEFAULT_DURATION_HOURS))
            ->first();
    }

    /** Курс занятия: прямой course_id, иначе первый курс группы. */
    public function courseFor(Schedule $schedule): ?Course
    {
        if ($schedule->course !== null) {
            return $schedule->course;
        }

        return $schedule->group?->courses()->with(['teacher', 'teachers'])->orderBy('courses.id')->first();
    }

    /** Ссылка на подключение: Zoom-встреча занятия → link/описание → единая ссылка курса. */
    public function joinUrl(Schedule $schedule): ?string
    {
        foreach ([$schedule->zoom_join_url, $schedule->link, $this->courseFor($schedule)?->zoom_link] as $url) {
            if (is_string($url) && preg_match('~^https?://~i', $url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Трансляции эфира кнопками: Rutube / VK Видео / YouTube. Ссылки из админки
     * часто без схемы (rutube.ru/video/…) — дописываем https://, мусор пропускаем.
     *
     * @return list<array{label: string, url: string}>
     */
    public function streamLinks(Schedule $schedule): array
    {
        $links = [];
        foreach ((array) ($schedule->stream_links ?? []) as $raw) {
            $url = trim((string) $raw);
            if ($url === '') {
                continue;
            }
            if (! preg_match('~^https?://~i', $url)) {
                $url = 'https://'.ltrim($url, '/');
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            $links[] = ['label' => self::streamLabel($host), 'url' => $url];
        }

        return $links;
    }

    private static function streamLabel(string $host): string
    {
        $host = preg_replace('~^(www\.|m\.)~', '', $host);

        return match (true) {
            str_ends_with($host, 'rutube.ru') => '📺 Rutube',
            str_ends_with($host, 'vkvideo.ru'), str_ends_with($host, 'vk.com'), str_ends_with($host, 'vk.ru') => '📺 VK Видео',
            str_ends_with($host, 'youtube.com'), $host === 'youtu.be' => '📺 YouTube',
            default => '📺 Трансляция',
        };
    }
}
