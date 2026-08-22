<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendMessengerAlerts;
use App\Models\Group;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOutreach;
use Illuminate\Support\Carbon;

/**
 * Обратная связь листу ожидания по ОДНОЙ группе курса (H3327).
 *
 * Словарь статусов исчерпывающий — подписчик видит его целиком и знает,
 * каких сообщений ждать, а каких не будет:
 *   1. Идёт набор (сколько ещё участников до запуска)
 *   2. Напоминание за 2 дня до предполагаемого старта
 *   3. Группа набрана
 *   4. Дата зафиксирована (точные день и час)
 *   5. Перенос даты (бывает несколько подряд; при уходе за месяц возможен
 *      сдвиг сезона/года — текст статуса «отложено» куратор пишет руками)
 *   6. Отложено на другой сезон
 *
 * Доставка: строки листа со сматченным кабинетом получают SendMessengerAlerts;
 * несматченные (гости без аккаунта) считаются ручным хвостом и попадают в
 * аудит-строку — email-канал подключится отдельным инфра-гейтом.
 */
class WaitlistNotifier
{
    public const KIND_MANUAL = WaitlistOutreach::KIND_MANUAL;

    public const KIND_TRANSFER = WaitlistOutreach::KIND_TRANSFER;

    public const KIND_AUTO_REMINDER = WaitlistOutreach::KIND_AUTO_REMINDER;

    /** Статусы листа, которые ещё ждут этот курс (не зачислен/не отказ). */
    private const ACTIVE_ENTRY_STATUSES = ['waiting', 'invited'];

    /** Исчерпывающий прейскурант для подписчика. */
    public static function vocabulary(): array
    {
        return [
            'recruiting' => 'Идёт набор — сколько ещё участников до запуска',
            'reminder' => 'Напоминание за 2 дня до предполагаемого старта',
            'recruited' => 'Группа набрана',
            'date_fixed' => 'Дата зафиксирована — точные день и час',
            'transfer' => 'Перенос даты (бывает несколько подряд)',
            'postponed' => 'Отложено на другой сезон',
        ];
    }

    /** Сколько участников осталось до min_size; null — порог не задан. */
    public static function remainingToLaunch(Group $group): ?int
    {
        if ($group->min_size === null) {
            return null;
        }

        return max(0, $group->min_size - $group->membersTowardMinSize());
    }

    /**
     * Текущий статус группы одной строкой — единый источник и для лендинга,
     * и для текстов рассылок. Дата всегда живая (effectiveStartDate), никаких
     * захардкоженных обещаний: любое количество переносов ничего не ломает.
     */
    public static function statusText(Group $group): string
    {
        $date = $group->effectiveStartDate()?->isoFormat('D MMMM YYYY');

        if (! $group->isRecruited()) {
            $line = 'Идёт набор'.($date ? ' · старт ориентировочно '.$date : '');

            if (($remaining = self::remainingToLaunch($group)) !== null && $remaining > 0) {
                $line .= ' · '.self::remainingPhrase($remaining);
            }

            return $line;
        }

        $next = \App\Models\Schedule::where('group_id', $group->id)
            ->where('start', '>=', now())
            ->orderBy('start')
            ->first();

        if ($date && $next) {
            return 'Группа набрана · старт '.$next->start->isoFormat('D MMMM').' в '.$next->start->format('H:i');
        }

        return 'Группа набрана'.($date ? ' · старт '.$date : '');
    }

    private static function remainingPhrase(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'до запуска нужен ещё '.$n.' участник';
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'до запуска нужно ещё '.$n.' участника';
        }

        return 'до запуска нужно ещё '.$n.' участников';
    }

    /**
     * Разошлет $text активным записям листа этой группы. Возвращает
     * [messengers => доставлено ботом/алертами, manual => осталось вручную].
     */
    public function notify(Group $group, string $kind, ?string $text = null, ?User $actor = null): array
    {
        $text ??= self::statusText($group);

        $entries = WaitlistEntry::query()
            ->where('group_id', $group->id)
            ->whereIn('status', self::ACTIVE_ENTRY_STATUSES)
            ->get();

        $messengers = 0;
        foreach ($entries as $entry) {
            if ($entry->user_id !== null && ($student = $entry->student) !== null) {
                SendMessengerAlerts::dispatch($student, $text);
                $messengers++;
            }

            $entry->forceFill(['last_outreach_at' => today()])->save();
        }

        WaitlistOutreach::create([
            'group_id' => $group->id,
            'kind' => $kind,
            'text' => $text,
            'actor_id' => $actor?->id,
            'messengers_count' => $messengers,
            'manual_count' => max(0, $entries->count() - $messengers),
        ]);

        return ['messengers' => $messengers, 'manual' => max(0, $entries->count() - $messengers)];
    }

    /** Авто-напоминание за N дней до старта; один прогон в день на группу. */
    public function autoReminder(Group $group): ?array
    {
        $alreadyToday = WaitlistOutreach::query()
            ->where('group_id', $group->id)
            ->where('kind', self::KIND_AUTO_REMINDER)
            ->whereDate('created_at', today())
            ->exists();

        if ($alreadyToday) {
            return null;
        }

        return $this->notify($group, self::KIND_AUTO_REMINDER);
    }

    /** Текст переноса для действия «Зафиксировать дату». */
    public static function transferText(Group $group, Carbon $newDate): string
    {
        return 'Перенос: старт «'.($group->courses()->first()?->title ?? $group->name).'» перенесён на '
            .$newDate->isoFormat('D MMMM YYYY').'. Следим за набором и сообщим точное время.';
    }
}
