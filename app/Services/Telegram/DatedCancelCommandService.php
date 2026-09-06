<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Group;
use App\Models\Schedule;
use App\Support\TelegramGroupAcl;
use App\Support\TelegramSendGuard;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * H4253: date-aware «Отмена ДД.ММ[ и ДД.ММ …]» в чате группы — отменяет
 * занятия НА УКАЗАННЫЕ ДАТЫ, БЕЗ каскадного сдвига +7 дней (в отличие от
 * reply-команды «Отмена занятия», H4199, {@see CancelClassCommandService}).
 *
 * Зачем отдельно от reply-команды: препод не всегда отвечает на конкретный
 * пост-напоминание, особенно для дальних дат («Отмена 20.09 и 27.09») — там
 * реплай на ещё не отправленное напоминание невозможен физически. Дата в
 * тексте — прямая адресация без reply.
 *
 * ACL — {@see TelegramGroupAcl} (роль/своя группа), НЕ zapisi_cancel_admin_ids
 * whitelist (та не трогается и продолжает гейтить старую reply-команду).
 * Дедуп — TelegramSendGuard клейм на (chat_id, дата), а не на message_id поста:
 * команда не привязана к посту.
 */
final class DatedCancelCommandService
{
    private const CLAIM_TTL_SECONDS = 86400;

    /**
     * @param  array<string, mixed>  $message
     */
    public function handle(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? null;

        if ($chatId === null || $fromId === null) {
            return;
        }

        $chatId = (string) $chatId;
        $fromId = (int) $fromId;

        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) ($message['text'] ?? ''))));

        if (preg_match('/^отмена\s+(\d{1,2}\.\d{1,2}(?:\.\d{4})?(?:\s+и\s+\d{1,2}\.\d{1,2}(?:\.\d{4})?)*)$/u', $text, $m) !== 1) {
            return;
        }

        $group = Group::query()->where('telegram_chat_id', $chatId)->first();
        if ($group === null) {
            return;
        }

        if (! TelegramGroupAcl::canManageGroup($fromId, (int) $group->id)) {
            return;
        }

        $rawDates = preg_split('/\s+и\s+/u', $m[1]) ?: [];

        foreach ($rawDates as $rawDate) {
            $this->cancelDate($group, $chatId, $fromId, $rawDate);
        }
    }

    private function cancelDate(Group $group, string $chatId, int $fromId, string $rawDate): void
    {
        try {
            $date = $this->parseDate($rawDate);
        } catch (Throwable $e) {
            Log::info('DatedCancelCommand: unparsable date, skipped', ['chat_id' => $chatId, 'raw' => $rawDate]);

            return;
        }

        if (! TelegramSendGuard::claimKey('tg:dated-cancel:'.$chatId.':'.$date->toDateString(), self::CLAIM_TTL_SECONDS)) {
            Log::info('DatedCancelCommand: duplicate command for this date, suppressed', [
                'chat_id' => $chatId,
                'date' => $date->toDateString(),
            ]);

            return;
        }

        $schedules = Schedule::query()
            ->where('group_id', $group->id)
            ->whereBetween('start', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->get();

        if ($schedules->isEmpty()) {
            Log::info('DatedCancelCommand: no schedule on this date, nothing to cancel', [
                'group_id' => $group->id,
                'date' => $date->toDateString(),
            ]);

            return;
        }

        // Без каскада: только строки на эту дату мягко удаляются (Schedule::SoftDeletes),
        // последующие занятия группы НЕ сдвигаются — в отличие от ScheduleMover::cancelAndShiftWeek.
        foreach ($schedules as $schedule) {
            $schedule->delete();
        }

        SendZapisiBotMessageJob::dispatch(
            $chatId,
            '❗ <b>Занятие отменено</b>: <b>'.$date->format('d.m.Y').'</b> (без переноса, дата не сдвигается).'
        );

        Log::info('DatedCancelCommand: schedules cancelled without shift', [
            'group_id' => $group->id,
            'date' => $date->toDateString(),
            'schedule_ids' => $schedules->pluck('id')->all(),
            'telegram_user_id' => $fromId,
        ]);
    }

    /** DD.MM или DD.MM.YYYY -> Carbon; без года — текущий, если дата уже прошла — следующий. */
    private function parseDate(string $raw): Carbon
    {
        $parts = explode('.', $raw);

        if (count($parts) === 3) {
            return Carbon::createFromFormat('d.m.Y', $raw)->startOfDay();
        }

        $day = (int) $parts[0];
        $month = (int) $parts[1];
        $year = (int) now()->format('Y');

        $date = Carbon::createFromDate($year, $month, $day)->startOfDay();
        if ($date->lt(now()->startOfDay()->subMonths(6))) {
            $date = $date->addYear();
        }

        return $date;
    }
}
