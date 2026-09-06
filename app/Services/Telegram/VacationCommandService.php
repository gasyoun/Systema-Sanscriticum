<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Group;
use App\Models\Teacher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * H4253: команда «Каникулы/отпуск» в чате с @zapisi_ORSbot.
 *
 * Преподаватель (User с teacher_id, роль teacher) ставит окно СЕБЕ — оно
 * покрывает все его группы. super_admin/admin/manager тем же текстом ставят
 * ГРУППОВОЙ флаг (is_on_vacation/vacation_resume_date, H3790) группе чата.
 *
 * Распознаваемые тексты (после нормализации):
 *  - «Каникулы с 23.09 по 06.10» / «отпуск с 23.09 по 06.10» / «нет занятий с … по …»;
 *  - «Каникулы с 23.09» — отпуск без известной даты выхода;
 *  - «занятия возобновляются» / «вышел из отпуска» / «вышла из отпуска» /
 *    «каникулы окончены» / «отпуск окончен» / «снять каникулы» — снятие окна.
 *
 * Год можно не писать: текущий, а если дата уже прошла — следующий.
 * Всё неуспешное — только в лог (в чат группы бот пишет лишь подтверждение).
 */
final class VacationCommandService
{
    /** «Каникулы с D по D2» / «отпуск D–D2» / «нет занятий с … по …». */
    private const SET_RANGE_PATTERN = '/^(?:каникулы|отпуск|нет занятий)\s*(?:с)?\s*(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2,4}))?\s*(?:по|—|–|-)\s*(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2,4}))?$/u';

    /** «Каникулы с D» — открытый отпуск без даты выхода. */
    private const SET_OPEN_PATTERN = '/^(?:каникулы|отпуск)\s*(?:с)?\s*(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2,4}))?$/u';

    private const CLEAR_PATTERN = '/^(?:занятия возобновляются|вышел из отпуска|вышла из отпуска|каникулы окончены|отпуск окончен|отпуск закончился|снять каникулы|снять отпуск)$/u';

    public function handle(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? null;
        if (! is_numeric($chatId) || ! isset($fromId)) {
            return;
        }

        $text = $this->normalize((string) ($message['text'] ?? ''));
        if ($text === '') {
            return;
        }

        if (preg_match(self::CLEAR_PATTERN, $text) === 1) {
            $this->apply(clear: true, chatId: (string) $chatId, fromId: (int) $fromId);

            return;
        }

        $window = $this->parseWindow($text);
        if ($window === null) {
            return;
        }

        $this->apply(clear: false, chatId: (string) $chatId, fromId: (int) $fromId, window: $window);
    }

    /**
     * @param  array{from: Carbon, until: ?Carbon}|null  $window
     */
    private function apply(bool $clear, string $chatId, int $fromId, ?array $window = null): void
    {
        $actor = app(TelegramCommandAcl::class)->resolve($fromId);
        if ($actor === null) {
            return;
        }

        // Преподаватель правит своё окно; staff правит группу чата.
        if ($actor['teacher'] !== null && ! TelegramCommandAcl::managesAll($actor['role'])) {
            $this->applyToTeacher($actor['teacher'], $clear, $window, $chatId, $actor['role']);

            return;
        }

        if (TelegramCommandAcl::managesAll($actor['role'])) {
            $this->applyToChatGroup($clear, $window, $chatId, $actor['role']);

            return;
        }

        Log::info('VacationCommand: panel user without teacher link and without staff role, ignored', [
            'chat_id' => $chatId,
            'telegram_user_id' => $fromId,
            'role' => $actor['role'],
        ]);
    }

    /**
     * @param  array{from: Carbon, until: ?Carbon}  $window
     */
    private function applyToTeacher(Teacher $teacher, bool $clear, ?array $window, string $chatId, string $role): void
    {
        if ($clear) {
            $teacher->forceFill(['on_vacation_from' => null, 'on_vacation_until' => null])->save();
            Log::info('VacationCommand: teacher window cleared', ['teacher_id' => $teacher->id, 'role' => $role]);
            SendZapisiBotMessageJob::dispatch($chatId, '✅ Отметка о каникулах снята, расписание снова в обычном режиме.');

            return;
        }

        $teacher->forceFill([
            'on_vacation_from' => $window['from']->toDateString(),
            'on_vacation_until' => $window['until']?->toDateString(),
        ])->save();
        Log::info('VacationCommand: teacher window set', [
            'teacher_id' => $teacher->id,
            'from' => $window['from']->toDateString(),
            'until' => $window['until']?->toDateString(),
            'role' => $role,
        ]);
        SendZapisiBotMessageJob::dispatch($chatId, $this->confirmationText($window));
    }

    /**
     * @param  array{from: Carbon, until: ?Carbon}  $window
     */
    private function applyToChatGroup(bool $clear, ?array $window, string $chatId, string $role): void
    {
        $group = Group::query()->where('telegram_chat_id', $chatId)->first();
        if ($group === null) {
            Log::info('VacationCommand: chat is not mapped to a group, ignored', [
                'chat_id' => $chatId,
                'role' => $role,
            ]);

            return;
        }

        if ($clear) {
            $group->forceFill(['is_on_vacation' => false, 'vacation_resume_date' => null])->save();
            Log::info('VacationCommand: group vacation cleared', ['group_id' => $group->id, 'role' => $role]);
            SendZapisiBotMessageJob::dispatch($chatId, '✅ Отметка о каникулах группы снята, расписание снова в обычном режиме.');

            return;
        }

        $group->forceFill([
            'is_on_vacation' => true,
            'vacation_resume_date' => $window !== null ? $window['until']?->toDateString() : null,
        ])->save();
        Log::info('VacationCommand: group vacation set', [
            'group_id' => $group->id,
            'until' => $window['until']?->toDateString() ?? null,
            'role' => $role,
        ]);
        SendZapisiBotMessageJob::dispatch($chatId, $this->confirmationText($window, groupName: $group->name));
    }

    /**
     * @param  array{from: Carbon, until: ?Carbon}  $window
     */
    private function confirmationText(array $window, ?string $groupName = null): string
    {
        $prefix = $groupName !== null ? '🌙 Каникулы группы «'.$groupName.'»' : '🌙 Отпуск отмечен';
        $until = $window['until'] !== null
            ? 'по '.$window['until']->format('d.m.Y')
            : 'дата выхода уточняется';

        return $prefix.': с '.$window['from']->format('d.m.Y').' '.$until
            .'. Напоминания о занятиях в этот период приходить не будут.';
    }

    /**
     * @return array{from: Carbon, until: ?Carbon}|null
     */
    private function parseWindow(string $text): ?array
    {
        if (preg_match(self::SET_RANGE_PATTERN, $text, $m) === 1) {
            $from = $this->resolveDate((int) $m[1], (int) $m[2], $m[3] ?? null);
            $until = $this->resolveDate((int) $m[4], (int) $m[5], $m[6] ?? null);
            if ($from === null || $until === null) {
                Log::warning('VacationCommand: unparsable range dates', ['text' => $text]);

                return null;
            }
            if ($until->lt($from)) {
                Log::warning('VacationCommand: until is before from, refused', ['text' => $text]);

                return null;
            }

            return ['from' => $from, 'until' => $until];
        }

        if (preg_match(self::SET_OPEN_PATTERN, $text, $m) === 1) {
            $from = $this->resolveDate((int) $m[1], (int) $m[2], $m[3] ?? null);
            if ($from === null) {
                Log::warning('VacationCommand: unparsable open date', ['text' => $text]);

                return null;
            }

            return ['from' => $from, 'until' => null];
        }

        return null;
    }

    private function resolveDate(int $day, int $month, ?string $year): ?Carbon
    {
        // Неотматченная опциональная группа preg_match даёт '' , а не null.
        if ($year === '') {
            $year = null;
        }

        if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
            return null;
        }

        $parsed = $year !== null && $year !== ''
            ? Carbon::createFromFormat('d.m.Y', sprintf('%02d.%02d.%04d', $day, $month, $this->normalizeYear((int) $year)))
            : Carbon::create((int) date('Y'), $month, $day);

        if ($parsed === false || $parsed->day !== $day || $parsed->month !== $month) {
            // day/month-сравнение отсекает календарное переполнение
            // (31.02 → Carbon молча дал бы 03.03).
            return null;
        }

        // Дата без года УЖЕ ПРОШЛА — имелся в виду следующий год. Сравнение
        // по Y-m-d: startOfDay сегодня в 00:00 isPast()=true, но это «сегодня».
        if ($year === null && $parsed->toDateString() < now()->toDateString()) {
            $parsed->addYear();
        }

        return $parsed->startOfDay();
    }

    private function normalizeYear(int $year): int
    {
        return $year < 100 ? 2000 + $year : $year;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));

        return (string) preg_replace('/[!.?…]+$/u', '', $text);
    }
}
