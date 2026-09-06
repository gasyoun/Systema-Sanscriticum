<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Group;
use App\Support\TelegramGroupAcl;
use App\Support\TelegramSendGuard;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * H4253: TG-команда «Каникулы с ДД.ММ по ДД.ММ» / «отпуск с … по …» в чате
 * группы — ставит teacher-level окно отпуска (Teacher.on_vacation_from/until)
 * всем преподавателям курсов этой группы (основной teacher() + со-преподы
 * teachers()). Обратная команда «занятия возобновляются» / «вышел(-ла) из
 * отпуска» очищает окно.
 *
 * ACL — {@see TelegramGroupAcl}: super_admin/admin/manager управляют любой
 * группой, teacher — только своей (через User.teacher_id). Не reply-команда:
 * читается любое сообщение чата группы. Отдельно от zapisi_cancel_admin_ids
 * whitelist (H4199) — та гейтит только «Отмена занятия».
 */
final class TeacherVacationCommandService
{
    private const CLAIM_TTL_SECONDS = 3600;

    private const RESUME_PHRASES = [
        'занятия возобновляются',
        'вышел из отпуска',
        'вышла из отпуска',
        'вышли из отпуска',
        'отпуск закончился',
        'каникулы закончились',
    ];

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
        if ($text === '') {
            return;
        }

        $group = Group::query()->where('telegram_chat_id', $chatId)->first();
        if ($group === null) {
            return;
        }

        if (in_array($text, self::RESUME_PHRASES, true)) {
            $this->apply($group, $chatId, $fromId, null, null, 'Занятия возобновляются — отпуск снят.');

            return;
        }

        if (preg_match(
            '/^(?:каникулы|отпуск)\s+с\s+(\d{1,2}\.\d{1,2}(?:\.\d{4})?)\s+по\s+(\d{1,2}\.\d{1,2}(?:\.\d{4})?)$/u',
            $text,
            $m
        ) !== 1) {
            return;
        }

        try {
            $from = $this->parseDate($m[1]);
            $until = $this->parseDate($m[2]);
        } catch (Throwable $e) {
            Log::info('TeacherVacationCommand: unparsable dates, ignored', [
                'chat_id' => $chatId,
                'raw' => $text,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($until->lt($from)) {
            Log::info('TeacherVacationCommand: end date before start date, ignored', [
                'chat_id' => $chatId,
                'from' => $from->toDateString(),
                'until' => $until->toDateString(),
            ]);

            return;
        }

        $this->apply(
            $group,
            $chatId,
            $fromId,
            $from,
            $until,
            'Отпуск оформлен: с <b>'.$from->format('d.m.Y').'</b> по <b>'.$until->format('d.m.Y').'</b>.'
        );
    }

    private function apply(Group $group, string $chatId, int $fromId, ?Carbon $from, ?Carbon $until, string $confirmation): void
    {
        if (! TelegramGroupAcl::canManageGroup($fromId, (int) $group->id)) {
            return;
        }

        if (! TelegramSendGuard::claimKey('tg:vacation:'.$chatId.':'.($from?->toDateString() ?? 'resume').':'.($until?->toDateString() ?? ''), self::CLAIM_TTL_SECONDS)) {
            Log::info('TeacherVacationCommand: duplicate command, suppressed', ['chat_id' => $chatId]);

            return;
        }

        $group->loadMissing(['courses.teacher', 'courses.teachers']);

        $teacherIds = [];
        foreach ($group->courses as $course) {
            if ($course->teacher !== null) {
                $teacherIds[$course->teacher->id] = $course->teacher;
            }
            foreach ($course->teachers as $coTeacher) {
                $teacherIds[$coTeacher->id] = $coTeacher;
            }
        }

        if ($teacherIds === []) {
            Log::warning('TeacherVacationCommand: group has no teachers to apply vacation to', ['group_id' => $group->id]);

            return;
        }

        foreach ($teacherIds as $teacher) {
            $teacher->update([
                'on_vacation_from' => $from,
                'on_vacation_until' => $until,
            ]);
        }

        SendZapisiBotMessageJob::dispatch($chatId, $confirmation);

        Log::info('TeacherVacationCommand: applied', [
            'group_id' => $group->id,
            'teacher_ids' => array_keys($teacherIds),
            'telegram_user_id' => $fromId,
            'from' => $from?->toDateString(),
            'until' => $until?->toDateString(),
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
