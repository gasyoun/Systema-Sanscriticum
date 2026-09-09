<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * H4457: таймкоды занятия (канон из n8n-контура). Одно видео = одна строка;
 * timings JSON = [{start: "00:00:00", end: "00:05:20"|null, label}].
 */
class KanvaTiming extends Model
{
    protected $fillable = [
        'course_id',
        'group_id',
        'video_url',
        'duration_seconds',
        'timings',
        'source',
        'valid_status',
        'last_ingested_at',
    ];

    protected $casts = [
        'timings' => 'array',
        'duration_seconds' => 'integer',
        'last_ingested_at' => 'datetime',
    ];

    /** Валідация набора: гэпы >5 минут между соседними стартами, минуты вне диапазона. */
    public static function validateTimings(array $items): string
    {
        $starts = [];
        foreach ($items as $item) {
            $seconds = self::toSeconds((string) ($item['start'] ?? ''));
            if ($seconds === null) {
                return 'gaps';
            }
            $starts[] = $seconds;
        }
        if (count($starts) < 2) {
            return 'gaps';
        }
        for ($i = 1; $i < count($starts); $i++) {
            if ($starts[$i] <= $starts[$i - 1] || 5 * 3600 < $starts[$i] - $starts[$i - 1]) {
                return 'gaps';
            }
        }

        return 'valid';
    }

    /** «00:05:20» | «1:15:00» | «10:05.500» → секунды. */
    public static function toSeconds(string $time): ?int
    {
        if (! preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?$/', trim($time), $m)) {
            return null;
        }
        $hours = (int) ($m[1] ?? 0);
        $minutes = (int) $m[2];
        $seconds = (int) $m[3];

        return $hours * 3600 + $minutes * 60 + $seconds;
    }
}
