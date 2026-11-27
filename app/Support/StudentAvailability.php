<?php

namespace App\Support;

use App\Models\StudentAvailabilityWindow as Window;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * OfferGate для новых офферов кабинета.
 *
 * Правила (рулинги 26-11-2026):
 *  — нет ни одной строки → фильтр не применяется (поведение как сейчас);
 *  — есть строки разрешённых окон → разрешено только внутри них;
 *  — строки куратора приоритетнее студенческих (deny → отказ, allow → доступ).
 */
final class StudentAvailability
{
    private const DEFAULT_TZ = 'Europe/Moscow';

    private const RU_WEEKDAYS = [
        'пн' => 'MO', 'вт' => 'TU', 'ср' => 'WE', 'чт' => 'TH',
        'пт' => 'FR', 'сб' => 'SA', 'вс' => 'SU',
    ];

    /** @return Collection<int, Window> */
    public function windowsFor(User $user): Collection
    {
        return Window::query()->where('user_id', $user->id)->get();
    }

    public function enabled(): bool
    {
        return (bool) config('features.availability_windows', false);
    }

    /** Разрешён ли конкретный момент для новых офферов. */
    public function allows(User $user, CarbonInterface|string $at): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        $windows = $this->windowsFor($user);
        if ($windows->isEmpty()) {
            return true;
        }

        $instant = $at instanceof CarbonInterface ? $at : Carbon::parse($at);

        // Deny куратора побеждает всегда, даже если где-то есть allow.
        foreach ($windows->where('source', Window::SOURCE_CURATOR) as $window) {
            if ($window->kind === Window::KIND_DENY && $window->coversInstant($instant)) {
                return false;
            }
        }
        foreach ($windows->where('source', Window::SOURCE_CURATOR) as $window) {
            if ($window->kind === Window::KIND_ALLOW && $window->coversInstant($instant)) {
                return true;
            }
        }
        // Студенческое «Неудобное время» перекрывает его же «Удобное время».
        foreach ($windows->where('source', Window::SOURCE_STUDENT) as $window) {
            if ($window->kind === Window::KIND_DENY && $window->coversInstant($instant)) {
                return false;
            }
        }
        foreach ($windows->where('source', Window::SOURCE_STUDENT) as $window) {
            if ($window->kind === Window::KIND_ALLOW && $window->coversInstant($instant)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Разрешён ли оффер с недельным слотом вида «пн 18:00» / «сб 13:00».
     * Слот без разбираемого времени не гейтится (нет конкретного времени — нечего проверять).
     */
    public function allowsSlot(User $user, ?string $slot): bool
    {
        if (! $this->enabled() || $slot === null || trim($slot) === '') {
            return true;
        }
        $windows = $this->windowsFor($user);
        if ($windows->isEmpty()) {
            return true;
        }
        if (! preg_match('/^([а-яё]{2})\s+(\d{1,2}:\d{2})/iu', mb_strtolower(trim($slot)), $m)) {
            return true;
        }
        $weekday = self::RU_WEEKDAYS[$m[1]] ?? null;
        if ($weekday === null) {
            return true;
        }
        [$h, $min] = explode(':', $m[2]);
        $minutes = ((int) $h) * 60 + (int) $min;
        // Для плавающего графика слот привязан к КОНКРЕТНОЙ дате — ближайшему
        // такому дню недели. Это консервативно: если ближайший повтор вне окна,
        // оффер не показываем.
        $date = Carbon::now($this->timezoneFor($user))
            ->next(Window::CARBON_WEEKDAYS[$weekday])
            ->toDateString();

        foreach ($windows->where('source', Window::SOURCE_CURATOR) as $window) {
            if ($window->kind === Window::KIND_DENY && $window->coversDayAndTime($weekday, $minutes, $date)) {
                return false;
            }
        }
        foreach ($windows->where('source', Window::SOURCE_CURATOR) as $window) {
            if ($window->kind === Window::KIND_ALLOW && $window->coversDayAndTime($weekday, $minutes, $date)) {
                return true;
            }
        }
        foreach ($windows->where('source', Window::SOURCE_STUDENT) as $window) {
            if ($window->kind === Window::KIND_DENY && $window->coversDayAndTime($weekday, $minutes, $date)) {
                return false;
            }
        }
        foreach ($windows->where('source', Window::SOURCE_STUDENT) as $window) {
            if ($window->kind === Window::KIND_ALLOW && $window->coversDayAndTime($weekday, $minutes, $date)) {
                return true;
            }
        }

        return false;
    }

    /** Таймзона ученика из уже существующего H4434-поля. */
    public function timezoneFor(User $user): string
    {
        return $user->effectiveTimezone() ?: self::DEFAULT_TZ;
    }
}
