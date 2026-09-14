<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * H4434 — DST-движок (MG 09-09-2026).
 *
 * Смысл алертов — не «в стране перевели часы», а «локальное время ВАШЕГО
 * следующего занятия изменилось»: у Дели/Ростова переходов нет — алертов нет;
 * калифорнийский сдвиг «суббота → пятница» ловится тем же механизмом, потому
 * что правила берутся из IANA-зоны самого юзера, не из хардкода.
 *
 * Каденция привязана к занятию: T−7 дней, T−1 день вечером (по локальным
 * часам юзера), T−1 час (строка вшивается в существующее 60-минутное
 * напоминание занятий — отдельного пинга нет).
 */
class DstShiftAdvisor
{
    /** Сколько дней вперёд искать переходы. */
    private const HORIZON_DAYS = 40;

    /**
     * Следующий перевод часов в зоне юзера после $from (UTC-момент).
     *
     * @return array{date: Carbon, beforeOffset: int, afterOffset: int}|null
     */
    public static function nextTransition(string $tz, Carbon $from): ?array
    {
        try {
            $zone = new \DateTimeZone($tz);
        } catch (\Exception) {
            return null;
        }

        if ($tz === 'Europe/Moscow' || $zone->getOffset($from->toDateTimeImmutable()) === $zone->getOffset($from->copy()->addDays(self::HORIZON_DAYS)->toDateTimeImmutable())) {
            // Быстрый выход: зона без DST или переход не в горизонте.
            // getTransitions всё равно нужен для точной даты — идём дальше,
            // если зона вообще имеет переходы.
        }

        $transitions = $zone->getTransitions(
            $from->copy()->subDay()->getTimestamp(),
            $from->copy()->addDays(self::HORIZON_DAYS)->getTimestamp(),
        );

        if ($transitions === false || count($transitions) < 2) {
            return null;
        }

        // Первый переход строго после $from (index 0 — текущее состояние).
        foreach ($transitions as $i => $tr) {
            $ts = Carbon::createFromTimestamp($tr['ts'], 'UTC');
            if ($ts->lte($from)) {
                continue;
            }

            return [
                'date' => $ts->timezone($tz)->startOfDay(),
                'beforeOffset' => $transitions[$i - 1]['offset'] ?? $transitions[0]['offset'],
                'afterOffset' => $tr['offset'],
            ];
        }

        return null;
    }

    /**
     * Первое занятие юзера после даты перехода (его локальная дата).
     * Занятия ищем по его группам; переход, не задевающий занятие, молчит.
     */
    public static function firstSessionAfter(User $user, Carbon $transitionStartUtc): ?Schedule
    {
        $groupIds = $user->groups()->pluck('groups.id');

        return Schedule::query()
            ->where(function ($q) use ($groupIds) {
                $q->whereIn('group_id', $groupIds)->orWhereNull('group_id');
            })
            ->where('start', '>=', $transitionStartUtc)
            ->orderBy('start')
            ->first();
    }

    /**
     * Текст локального сдвига: «занятие 14 марта (суббота) в вашем времени
     * сместится с 14:00 на 13:00» — или null, если локальное время не изменилось.
     *
     * @return array{before: string, after: string, mskBefore: string, mskAfter: string}|null
     */
    public static function localShiftFor(User $user, Schedule $session): ?array
    {
        $tz = $user->effectiveTimezone();

        if ($tz === null || $tz === 'Europe/Moscow') {
            return null;
        }

        try {
            $zone = new \DateTimeZone($tz);
        } catch (\Exception) {
            return null;
        }

        $startUtc = $session->start->copy()->timezone('UTC');

        // Локальное wall-time занятия по правилам ЗОНЫ на дату занятия
        // (симуляция «до/после» перехода: смещаем момент на разницу оффсетов
        // вокруг даты перехода, смотрим, меняются ли часы).
        $transitions = $zone->getTransitions(
            $startUtc->copy()->subDays(10)->getTimestamp(),
            $startUtc->copy()->addDays(10)->getTimestamp(),
        );

        if ($transitions === false || count($transitions) < 2) {
            return null;
        }

        // Оффсет, действующий на момент занятия (последний переход <= start).
        $activeOffset = $transitions[0]['offset'];
        foreach ($transitions as $tr) {
            if ($tr['ts'] <= $startUtc->getTimestamp()) {
                $activeOffset = $tr['offset'];
            } else {
                break;
            }
        }

        // Wall-time занятия в активной зоне...
        $wallActive = (clone $startUtc)
            ->setTimezone($tz)
            ->format('H:i');

        // ...и wall-time, если бы действовал ДРУГОЙ оффсет (соседний переход ±1ч).
        // Это предсказывает, что увидит юзер после перевода часов.
        foreach ($transitions as $tr) {
            $other = $tr['offset'];
            if ($other === $activeOffset) {
                continue;
            }

            $shifted = $startUtc->copy()->getTimestamp() + ($other - $activeOffset);
            $wallOther = Carbon::createFromTimestamp($shifted, 'UTC')
                ->setTimezone($tz)
                ->format('H:i');

            if ($wallOther !== $wallActive) {
                $msk = $session->start->timezone('Europe/Moscow')->format('H:i');

                return [
                    'before' => $wallActive,
                    'after' => $wallOther,
                    'mskBefore' => $msk,
                    'mskAfter' => $msk, // МСК не меняется — занятие по-прежнему в то же МСК-время
                ];
            }
        }

        return null;
    }
}
