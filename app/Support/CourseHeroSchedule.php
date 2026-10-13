<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * H6570: ручное расписание ещё не стартовавшего курса в хиро страницы
 * /k/<slug> — слева сверху, свёрнутый блок, полный список дат по клику.
 * Данные — контент-файл resources/data/course-hero-schedules.json
 * (ключ = slug курса); даты считаются здесь же: старт + N недель, без
 * перескока праздников (канон формата — блок «Расписание курса …» из
 * рассылок MG). Курсу, у которого расписание уже живёт в БД, блок гасится
 * в show.blade.php ( cadence-строка не должна задваиваться) — JSON для
 * ждун-курсов, у которых расписаний в БД ещё нет.
 */
class CourseHeroSchedule
{
    public static function for(string $slug): ?array
    {
        $file = resource_path('data/course-hero-schedules.json');
        if (! is_file($file)) {
            return null;
        }

        $all = json_decode((string) file_get_contents($file), true);
        $entry = is_array($all) ? ($all[$slug] ?? null) : null;
        if (! is_array($entry) || empty($entry['start']) || empty($entry['lessons'])) {
            return null;
        }

        $start = Carbon::parse($entry['start']);
        $count = (int) $entry['lessons'];
        $time = (string) ($entry['time'] ?? '18:00');

        $rows = [];
        foreach (range(1, $count) as $n) {
            $rows[] = [
                'n' => $n,
                'date' => $start->copy()->addWeeks($n - 1),
                'time' => $time,
            ];
        }

        return [
            'summary' => (string) ($entry['summary'] ?? ''),
            'cadence' => (string) ($entry['cadence'] ?? ''),
            'start' => $start,
            'rows' => $rows,
        ];
    }

    /** «19 октября 2026 (понедельник)» — без зависимости от setlocale. */
    public static function ruDate(Carbon $date): string
    {
        $months = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        $weekdays = [1 => 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота', 'воскресенье'];

        return $date->format('j').' '.$months[(int) $date->format('n')].' '.$date->format('Y')
            .' ('.$weekdays[(int) $date->format('N')].')';
    }
}
