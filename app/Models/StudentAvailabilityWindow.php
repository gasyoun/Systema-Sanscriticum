<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Окно доступности ученика: RRULE (неделя) или цикл «N можно / M нельзя» с якорной
 * датой, время суток и TZID. Одна строка = одно окно; суббота с двумя интервалами
 * (или плавающий график) = несколько строк.
 */
class StudentAvailabilityWindow extends Model
{
    use HasFactory;

    public const SOURCE_STUDENT = 'student';

    public const SOURCE_CURATOR = 'curator';

    public const KIND_ALLOW = 'allow';

    public const KIND_DENY = 'deny';

    public const REPEAT_WEEKLY = 'weekly';

    public const REPEAT_CYCLE = 'cycle';

    /** Поддерживаемый подвид RRULE (остальные отторгаются громко, не молча). */
    public const RRULE_PATTERN = '/^FREQ=WEEKLY;BYDAY=([A-Z]{2}(?:,[A-Z]{2})*)$/';

    public const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    /** Carbon: 0 = воскресенье. */
    public const CARBON_WEEKDAYS = ['SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6];

    protected $fillable = [
        'user_id', 'source', 'kind', 'rrule', 'repeat_mode',
        'cycle_on_days', 'cycle_off_days', 'anchor_date',
        'start_time', 'end_time', 'tzid', 'valid_from', 'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'anchor_date' => 'date',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCycle(): bool
    {
        return $this->repeat_mode === self::REPEAT_CYCLE;
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

    /**
     * Позиция дня в цикле «N можно / M нельзя» (0 = первый день).
     * null — дата раньше якоря или цикл сконфигурирован некорректно (fail-closed).
     */
    public function cyclePosition(CarbonInterface $at): ?int
    {
        $on = (int) ($this->cycle_on_days ?? 0);
        $off = (int) ($this->cycle_off_days ?? 0);
        if ($on < 1 || $off < 1 || $this->anchor_date === null) {
            return null;
        }

        $tz = $this->tzid ?: 'Europe/Moscow';
        $anchor = Carbon::parse($this->anchor_date->toDateString(), $tz)->startOfDay();
        $local = $at->copy()->setTimezone($tz)->startOfDay();

        if ($local->lessThan($anchor)) {
            return null;
        }

        return $anchor->diffInDays($local) % ($on + $off);
    }

    public function coversDayAndTime(string $weekday, int $minutes, ?string $date = null): bool
    {
        $weekday = strtoupper($weekday);
        if (! in_array($weekday, $this->weekdays(), true)) {
            return false;
        }
        $tz = $this->tzid ?: 'Europe/Moscow';

        if ($date !== null) {
            if ($this->valid_from !== null && $date < $this->valid_from->toDateString()) {
                return false;
            }
            if ($this->valid_until !== null && $date > $this->valid_until->toDateString()) {
                return false;
            }
            if ($this->isCycle()) {
                $position = $this->cyclePosition(Carbon::parse($date, $tz));

                return $position !== null
                    && $position < (int) $this->cycle_on_days
                    && $minutes >= $this->startMinutes()
                    && $minutes <= $this->endMinutes();
            }
        }

        return $minutes >= $this->startMinutes() && $minutes <= $this->endMinutes();
    }

    public function coversInstant(CarbonInterface $at): bool
    {
        $local = $at->copy()->setTimezone($this->tzid ?: 'Europe/Moscow');
        $minutes = $local->hour * 60 + $local->minute;
        $date = $local->toDateString();

        if ($this->valid_from !== null && $date < $this->valid_from->toDateString()) {
            return false;
        }
        if ($this->valid_until !== null && $date > $this->valid_until->toDateString()) {
            return false;
        }
        if ($minutes < $this->startMinutes() || $minutes > $this->endMinutes()) {
            return false;
        }

        if ($this->isCycle()) {
            $position = $this->cyclePosition($local);

            return $position !== null && $position < (int) $this->cycle_on_days;
        }

        $weekdays = array_flip(self::CARBON_WEEKDAYS);

        return in_array($weekdays[$local->dayOfWeek], $this->weekdays(), true);
    }

    private static function minutes(string $hhmm): int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
            throw new InvalidArgumentException("Invalid availability time: {$hhmm}");
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }
}
