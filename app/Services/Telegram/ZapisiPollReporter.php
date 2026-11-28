<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Schedule;
use App\Models\TelegramPoll;
use App\Models\TelegramPollAnswer;
use App\Services\Access\TelegramAdminNotifier;
use Illuminate\Support\Facades\Log;

/**
 * Итоги автоопроса о присутствии (@zapisi_ORSbot) в личку админам.
 *
 * Вызывается из ZapisiPollService::close() сразу после stopPoll — и при
 * ручном закрытии в админке, и при авто-закрытии
 * (classes:close-attendance-polls). Ручные опросы (kind != attendance) итоги
 * админам не шлют — только автоопрос «Буду / Опоздаю / Не смогу» о занятии.
 *
 * Правила:
 *  - «буду» = вариант TelegramPoll::ATTENDANCE_OPTION_COMING (индекс 0) у
 *    неотозванных голосов;
 *  - если «буду» меньше features.attendance_poll_cancel_threshold и занятие
 *    ещё впереди — к итогам добавляются inline-кнопки [Отменить занятие /
 *    Оставить] (PollCancelCallbackService), отмена идёт каскадом +7 дней.
 */
final class ZapisiPollReporter
{
    public function __construct(private readonly TelegramAdminNotifier $notifier) {}

    public function report(TelegramPoll $poll): void
    {
        if ($poll->kind !== TelegramPoll::KIND_ATTENDANCE) {
            return;
        }

        if (! (bool) config('features.attendance_poll_report_admins', true)) {
            return;
        }

        $lesson = $poll->schedule;
        $tally = $poll->optionTally();
        $coming = $this->comingCount($poll);
        $threshold = (int) config('features.attendance_poll_cancel_threshold', 5);

        $keyboard = null;
        if ($lesson !== null && $lesson->start !== null && $lesson->start->isFuture() && $coming < $threshold) {
            $keyboard = [[
                ['text' => 'Отменить занятие', 'callback_data' => PollCancelCallbackService::CANCEL_PREFIX.$lesson->id],
                ['text' => 'Оставить', 'callback_data' => PollCancelCallbackService::KEEP_PREFIX],
            ]];
        }

        $this->notifier->notifyAdmins($this->buildText($poll, $tally, $coming, $lesson, $threshold), $keyboard);

        Log::info('ZapisiPoll: attendance results reported to admins', [
            'poll_id' => $poll->id,
            'schedule_id' => $lesson?->id,
            'coming' => $coming,
            'threshold' => $threshold,
            'cancel_offered' => $keyboard !== null,
        ]);
    }

    /** Сколько неотозванных голосов выбрали «Буду» (ATTENDANCE_OPTION_COMING). */
    public function comingCount(TelegramPoll $poll): int
    {
        return $poll->answers()
            ->get()
            ->reject(fn (TelegramPollAnswer $a): bool => $a->isRetracted())
            ->filter(fn (TelegramPollAnswer $a): bool => in_array(TelegramPoll::ATTENDANCE_OPTION_COMING, $a->option_ids ?? [], true))
            ->count();
    }

    /**
     * @param  array<int, array{label: string, count: int}>  $tally
     */
    private function buildText(TelegramPoll $poll, array $tally, int $coming, ?Schedule $lesson, int $threshold): string
    {
        $escape = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        $lines = [
            '📊 <b>Итоги опроса о присутствии</b>',
            '«'.$escape($poll->question).'»',
            'Группа: '.($poll->group ? $escape($poll->group->name) : '—'),
        ];

        foreach ($tally as $option) {
            $lines[] = $escape((string) $option['label']).' — '.$option['count'];
        }

        $lines[] = 'Проголосовало: '.$this->votersCount($poll);

        if ($lesson !== null && $lesson->start !== null && $lesson->start->isFuture() && $coming < $threshold) {
            $lines[] = '';
            $lines[] = sprintf(
                '⚠️ На занятие %s «буду» ответили %d из %d — кворума может не хватить.',
                $lesson->start->format('d.m H:i'),
                $coming,
                $threshold,
            );
        }

        return implode("\n", $lines);
    }

    private function votersCount(TelegramPoll $poll): int
    {
        return $poll->answers()
            ->get()
            ->reject(fn (TelegramPollAnswer $a): bool => $a->isRetracted())
            ->count();
    }
}
