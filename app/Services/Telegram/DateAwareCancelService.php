<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Group;
use App\Models\Schedule;
use App\Services\Schedule\ScheduleMover;
use App\Support\TelegramSendGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * H4253: ДАТИРОВАННАЯ отмена — «Отмена 23.09 и 30.10» — от преподавателя
 * (только своей группы) или staff (любой группы чата).
 *
 * Semantics (ruling MG 06-09): датированная отмена УБИРАЕТ занятие БЕЗ
 * сдвига цепочки (мягкое удаление строки) — календарь остаётся на месте.
 * Без dating reply «Отмена занятия» (H4199) продолжает работать по-старому:
 * каскад +7 дней. Даты — DD.MM[.YYYY] через запятую/«и»/пробел.
 *
 * Повторная команда гасится клеймом TelegramSendGuard на сутки; все отказы
 * (нет маппинга чата, чужая группа, прошедшая дата) — только в лог.
 */
final class DateAwareCancelService
{
    /** Текст должен начинаться с «отмена»/«отменяю» и НЕсти хотя бы одну дату. */
    private const LEAD_PATTERN = '/^отмен(?:а|яю|я)\s+(.+)$/u';

    private const DATE_PATTERN = '/(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2,4}))?/u';

    private const CLAIM_TTL_SECONDS = 86400;

    public function handle(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? null;
        $messageId = $message['message_id'] ?? null;
        if (! is_numeric($chatId) || ! isset($fromId, $messageId) || ! is_numeric($messageId)) {
            return;
        }

        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) ($message['text'] ?? ''))));
        if ($text === '' || preg_match(self::LEAD_PATTERN, $text, $lead) !== 1) {
            return;
        }

        $dates = $this->parseDates($lead[1]);
        if ($dates === []) {
            return;
        }

        $chatId = (string) $chatId;
        $messageId = (int) $messageId;

        $actor = app(TelegramCommandAcl::class)->resolve((int) $fromId);
        if ($actor === null) {
            return;
        }

        $group = Group::query()->where('telegram_chat_id', $chatId)->first();
        if ($group === null) {
            Log::info('DateAwareCancel: chat is not mapped to a group, ignored', ['chat_id' => $chatId]);

            return;
        }

        // teacher-роль правит только свои группы; staff — любые.
        if (! TelegramCommandAcl::managesAll($actor['role'])) {
            $teacher = $actor['teacher'];
            if ($teacher === null || ! Group::ledBy($teacher->id)->whereKey($group->id)->exists()) {
                Log::info('DateAwareCancel: sender does not lead this group, ignored', [
                    'chat_id' => $chatId,
                    'group_id' => $group->id,
                    'role' => $actor['role'],
                    'teacher_id' => $teacher?->id,
                ]);

                return;
            }
        }

        // Второй такой же пост за сутки — подавляем (перезаход в чат, ретрай webhook).
        if (! TelegramSendGuard::claimKey('tg:dated-cancel:'.$chatId.':'.$messageId, self::CLAIM_TTL_SECONDS)) {
            Log::info('DateAwareCancel: duplicate command suppressed', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);

            return;
        }

        $cancelled = [];
        $missing = [];

        foreach ($dates as $date) {
            $schedule = Schedule::query()
                ->where('group_id', $group->id)
                ->whereBetween('start', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
                ->orderBy('start')
                ->first();

            if ($schedule === null) {
                $missing[] = $date->format('d.m.Y');

                continue;
            }

            if ($schedule->start->isPast()) {
                Log::warning('DateAwareCancel: lesson already started, refusing', [
                    'schedule_id' => $schedule->id,
                    'start' => $schedule->start->toDateTimeString(),
                ]);
                $missing[] = $date->format('d.m.Y');

                continue;
            }

            app(ScheduleMover::class)->cancelSingle($schedule);
            $cancelled[] = $date->format('d.m.Y');
        }

        Log::info('DateAwareCancel: done', [
            'chat_id' => $chatId,
            'group_id' => $group->id,
            'cancelled' => $cancelled,
            'missing' => $missing,
            'role' => $actor['role'],
        ]);

        if ($cancelled === [] && $missing === []) {
            return;
        }

        SendZapisiBotMessageJob::dispatch($chatId, $this->notice($cancelled, $missing));
    }

    /**
     * @return array<int, Carbon>
     */
    private function parseDates(string $tail): array
    {
        if (preg_match_all(self::DATE_PATTERN, $tail, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $dates = [];
        foreach ($matches as $m) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
                continue;
            }

            if (isset($m[3]) && $m[3] !== '') {
                $year = (int) $m[3];
                $year = $year < 100 ? 2000 + $year : $year;
                $date = Carbon::create($year, $month, $day);
            } else {
                $date = Carbon::create((int) date('Y'), $month, $day);
                // Сегодня (даже 00:00) НЕ уезжает в следующий год — «отмена»
                // сегодняшнего занятия легитимна. Уезжает только строго прошлое.
                if ($date->toDateString() < now()->toDateString()) {
                    $date->addYear();
                }
            }

            // Отсекаем календарное переполнение (31.02) и дубли из текста.
            if ($date->day !== $day || $date->month !== $month) {
                continue;
            }

            $key = $date->toDateString();
            if (! isset($dates[$key])) {
                $dates[$key] = $date->startOfDay();
            }
        }

        return array_values($dates);
    }

    /**
     * @param  array<int, string>  $cancelled
     * @param  array<int, string>  $missing
     */
    private function notice(array $cancelled, array $missing): string
    {
        $parts = [];
        if ($cancelled !== []) {
            $parts[] = '❗ Занятия отменены: '.implode(', ', $cancelled).'.';
        }
        if ($missing !== []) {
            $parts[] = 'Не найдено занятий на: '.implode(', ', $missing).'.';
        }

        return implode(' ', $parts);
    }
}
