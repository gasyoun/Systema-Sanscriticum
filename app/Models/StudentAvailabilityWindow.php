<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Окно доступности ученика: недельное повторение (RRULE), время суток и TZID.
 * Одна строка = одно окно; суббота с двумя интервалами = две строки.
 */
class StudentAvailabilityWindow extends Model
{
    use HasFactory;

    public const SOURCE_STUDENT = 'student';

    public const SOURCE_CURATOR = 'curator';

    public const KIND_ALLOW = 'allow';

    public const KIND_DENY = 'deny';

    /** Поддерживаемый подвид RRULE (остальные отторгаются громко, не молча). */
    public const RRULE_PATTERN = '/^FREQ=WEEKLY;BYDAY=([A-Z]{2}(?:,[A-Z]{2})*)$/';

    public const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    protected $fillable = [
        'user_id', 'source', 'kind', 'rrule', 'start_time', 'end_time',
        'tzid', 'valid_from', 'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<string> */
    public function weekdays(): array
    {
        if (! preg_match(self::RRULE_PATTERN, strtoupper($this->rrule), $m)) {
            throw new InvalidArgumentException("Unsupported availability RRULE: {$this->rrule}");
        }

        return explode(',', $m[1]);
    }

    public function startMinutes(): int
    {
        return self::minutes($this->start_time);
    }

    public function endMinutes(): int
    {
        return self::minutes($this->end_time);
    }

    public function coversDayAndTime(string $weekday, int $minutes, ?string $date = null): bool
    {
        if (! in_array(strtoupper($weekday), $this->weekdays(), true)) {
            return false;
        }
        if ($date !== null) {
            if ($this->valid_from !== null && $date < $this->valid_from->toDateString()) {
                return false;
            }
            if ($this->valid_until !== null && $date > $this->valid_until->toDateString()) {
                return false;
            }
        }

        return $minutes >= $this->startMinutes() && $minutes <= $this->endMinutes();
    }

    public function coversInstant(CarbonInterface $at): bool
    {
        $local = $at->copy()->setTimezone($this->tzid ?: 'Europe/Moscow');
        $weekdays = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 0 => 'SU'];

        return $this->coversDayAndTime(
            $weekdays[$local->dayOfWeek],
            $local->hour * 60 + $local->minute,
            $local->toDateString(),
        );
    }

    private static function minutes(string $hhmm): int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
            throw new InvalidArgumentException("Invalid availability time: {$hhmm}");
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }
}
