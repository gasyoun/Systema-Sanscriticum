<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\TelegramScheduleReschedule;
use App\Services\Schedule\ScheduleMover;
use App\Support\TelegramSendGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Explicit teacher/staff announcement → one-off class move.
 *
 * Only a fully resolved source and target may mutate. Everything ambiguous gets
 * a clarification reply and no schedule write. Source dates are interpreted at
 * the Telegram message timestamp so a later replay keeps «сегодня» honest.
 */
final class AnnounceRescheduleService
{
    private const DATE_PATTERN = '/(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2,4}))?/u';

    private const TIME_PATTERN = '/\b([01]?\d|2[0-3])[.:]([0-5]\d)\b/u';

    private const CLARIFY_CLAIM_TTL_SECONDS = 86400;

    public static function matches(array $message): bool
    {
        $text = self::normalize($message);

        return $text !== ''
            && preg_match('/перенос|перенес/u', $text) === 1
            && preg_match('/занят|урок/u', $text) === 1;
    }

    public function handle(array $message): void
    {
        if (! $this->enabled() || ! self::matches($message)) {
            return;
        }

        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? null;
        $messageId = $message['message_id'] ?? null;
        $sentAt = $message['date'] ?? null;
        if (! is_numeric($chatId) || ! is_numeric($fromId) || ! is_numeric($messageId) || ! is_numeric($sentAt)) {
            return;
        }

        $chatId = (string) $chatId;
        $fromId = (string) $fromId;
        $messageId = (int) $messageId;

        $group = Group::query()->where('telegram_chat_id', $chatId)->first();
        if ($group === null || ! $this->groupAllowed($group)) {
            return;
        }

        $actor = app(TelegramCommandAcl::class)->resolve((int) $fromId);
        if ($actor === null || ! $this->managesGroup($actor, $group)) {
            return;
        }

        // Повтор уже применённого сообщения не должен превращаться в запрос
        // уточнения после того, как исходная дата исчезла из расписания.
        $alreadyHandled = TelegramScheduleReschedule::query()
            ->where('telegram_chat_id', $chatId)
            ->where('telegram_message_id', $messageId)
            ->exists();
        if ($alreadyHandled) {
            return;
        }

        $reference = Carbon::createFromTimestamp((int) $sentAt, (string) config('app.timezone', 'Europe/Moscow'));
        $parsed = $this->parse(self::normalize($message), $reference);
        if ($parsed === null) {
            $this->requestConfirmation($chatId, $messageId);

            return;
        }

        $candidates = Schedule::query()
            ->where('group_id', $group->id)
            ->whereBetween('start', [
                $parsed['source']->copy()->startOfDay(),
                $parsed['source']->copy()->endOfDay(),
            ])
            ->orderBy('start')
            ->limit(2)
            ->get();

        if ($candidates->count() !== 1 || $candidates->first()?->start === null) {
            $this->requestConfirmation($chatId, $messageId);

            return;
        }

        /** @var Schedule $schedule */
        $schedule = $candidates->first();
        $newStart = $parsed['target']->copy();
        if ($parsed['time'] !== null) {
            $newStart->setTime($parsed['time'][0], $parsed['time'][1], 0);
        } else {
            $newStart->setTime((int) $schedule->start->format('H'), (int) $schedule->start->format('i'), 0);
        }

        if ($newStart->lte(now()) || $newStart->eq($schedule->start)) {
            $this->requestConfirmation($chatId, $messageId, 'Новое время должно быть в будущем и отличаться от прежнего.');

            return;
        }

        try {
            $action = DB::transaction(function () use ($chatId, $messageId, $fromId, $group, $schedule, $newStart): ?TelegramScheduleReschedule {
                $exists = TelegramScheduleReschedule::query()
                    ->where('telegram_chat_id', $chatId)
                    ->where('telegram_message_id', $messageId)
                    ->exists();
                if ($exists) {
                    return null;
                }

                $oldStart = $schedule->start->copy();
                $oldEnd = $schedule->end?->copy();
                $moved = app(ScheduleMover::class)->reschedule($schedule, $newStart);

                return TelegramScheduleReschedule::create([
                    'telegram_chat_id' => $chatId,
                    'telegram_message_id' => $messageId,
                    'schedule_id' => $schedule->id,
                    'group_id' => $group->id,
                    'actor_telegram_id' => $fromId,
                    'old_start' => $oldStart,
                    'old_end' => $oldEnd,
                    'new_start' => $moved->start,
                    'new_end' => $moved->end,
                    'status' => TelegramScheduleReschedule::STATUS_APPLIED,
                    'undo_expires_at' => now()->addHour(),
                ]);
            });
        } catch (InvalidArgumentException $exception) {
            Log::warning('AnnounceReschedule: move refused', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'schedule_id' => $schedule->id,
                'error' => $exception->getMessage(),
            ]);
            $this->requestConfirmation($chatId, $messageId, 'Целевой слот уже занят или недоступен.');

            return;
        }

        if ($action === null) {
            return;
        }

        $this->sendReceipt($action);
    }

    private function enabled(): bool
    {
        return (bool) config('services.telegram_zapisi.announce_reschedule_detect', false);
    }

    private function groupAllowed(Group $group): bool
    {
        $allowed = (array) config('services.telegram_zapisi.announce_reschedule_group_ids', []);

        return in_array($group->id, array_map('intval', $allowed), true);
    }

    /** @param array{role: string, teacher: mixed} $actor */
    private function managesGroup(array $actor, Group $group): bool
    {
        if (TelegramCommandAcl::managesAll($actor['role'])) {
            return true;
        }

        $teacher = $actor['teacher'];

        return $teacher !== null && Group::ledBy($teacher->id)->whereKey($group->id)->exists();
    }

    /**
     * @return array{source: Carbon, target: Carbon, time: array{0: int, 1: int}|null}|null
     */
    private function parse(string $text, Carbon $reference): ?array
    {
        $target = $this->target($text, $reference);
        if ($target === null) {
            return null;
        }

        $source = null;
        $sourceDates = array_values(array_filter(
            $this->explicitDates($text, $reference),
            fn (array $item): bool => ! isset($target['offset']) || $item['offset'] !== $target['offset'],
        ));
        if (count($sourceDates) > 1) {
            return null;
        }
        if (count($sourceDates) === 1) {
            $source = $sourceDates[0]['date'];
        }

        if (preg_match('/\bсегодня\b/u', $text) === 1) {
            $today = $reference->copy()->startOfDay();
            if ($source !== null && ! $source->isSameDay($today)) {
                return null;
            }
            $source = $today;
        }

        if ($source === null || ! $source->isSameDay($reference)) {
            return null;
        }

        $time = $this->targetTime($text, $target['end']);

        return $time === false ? null : ['source' => $source, 'target' => $target['date'], 'time' => $time];
    }

    /** @return array{date: Carbon, offset: int|null, end: int}|null */
    private function target(string $text, Carbon $reference): ?array
    {
        $candidates = [];

        if (preg_match('/\bна\s+завтра\b/u', $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            $candidates[] = [
                'date' => $reference->copy()->addDay()->startOfDay(),
                'offset' => $match[0][1],
                'end' => $match[0][1] + strlen($match[0][0]),
            ];
        }

        if (preg_match('/\bна\s+'.self::datePatternBody().'/u', $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            $date = $this->dateFromParts($match[1][0], $match[2][0], $match[3][0] ?? '', $reference);
            if ($date !== null) {
                $candidates[] = [
                    'date' => $date,
                    'offset' => $match[1][1],
                    'end' => $match[0][1] + strlen($match[0][0]),
                ];
            }
        }

        $weekdays = [
            1 => ['понедельник', 'понедельника'],
            2 => ['вторник', 'вторника'],
            3 => ['среду', 'среды', 'среда'],
            4 => ['четверг', 'четверга'],
            5 => ['пятницу', 'пятницы', 'пятница'],
            6 => ['субботу', 'субботы', 'суббота'],
            0 => ['воскресенье', 'воскресенья'],
        ];
        foreach ($weekdays as $dayOfWeek => $words) {
            foreach ($words as $word) {
                if (preg_match('/\bна\s+(следующ\w*\s+)?'.$word.'\b/u', $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                $date = $reference->copy()->startOfDay();
                while ($date->dayOfWeek !== $dayOfWeek) {
                    $date->addDay();
                }
                if (($match[1][0] ?? '') !== '') {
                    $date->addWeek();
                }
                $candidates[] = [
                    'date' => $date,
                    'offset' => null,
                    'end' => $match[0][1] + strlen($match[0][0]),
                ];
            }
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /** @return array{0: int, 1: int}|null|false */
    private function targetTime(string $text, int $targetEnd): array|null|false
    {
        preg_match_all(self::TIME_PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $times = [];
        foreach ($matches as $match) {
            if ($match[0][1] < $targetEnd) {
                continue;
            }
            $times[] = [(int) $match[1][0], (int) $match[2][0]];
        }

        if (count($times) > 1) {
            return false;
        }

        return $times[0] ?? null;
    }

    /** @return list<array{date: Carbon, offset: int}> */
    private function explicitDates(string $text, Carbon $reference): array
    {
        preg_match_all(self::DATE_PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $dates = [];
        foreach ($matches as $match) {
            $date = $this->dateFromParts($match[1][0], $match[2][0], $match[3][0] ?? '', $reference);
            if ($date !== null) {
                $dates[] = ['date' => $date, 'offset' => $match[0][1]];
            }
        }

        return $dates;
    }

    private function dateFromParts(string $dayRaw, string $monthRaw, string $yearRaw, Carbon $reference): ?Carbon
    {
        $day = (int) $dayRaw;
        $month = (int) $monthRaw;
        if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
            return null;
        }

        $year = $yearRaw !== '' ? (int) $yearRaw : (int) $reference->format('Y');
        if ($year < 100) {
            $year += 2000;
        }
        $date = Carbon::create($year, $month, $day, 0, 0, 0, $reference->getTimezone());
        if ($date === null || $date->day !== $day || $date->month !== $month) {
            return null;
        }
        if ($yearRaw === '' && $date->toDateString() < $reference->toDateString()) {
            $date->addYear();
        }

        return $date->startOfDay();
    }

    private static function datePatternBody(): string
    {
        return '(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2,4}))?';
    }

    private function requestConfirmation(string $chatId, int $messageId, ?string $reason = null): void
    {
        if (! TelegramSendGuard::claimKey('tg:announce-reschedule-clarify:'.$chatId.':'.$messageId, self::CLARIFY_CLAIM_TTL_SECONDS)) {
            return;
        }

        $text = "Похоже на перенос занятия, но я не могу безопасно определить все детали. Подтвердите в формате «Занятие 27.10 переносим на 30.10 в 08:00».";
        if ($reason !== null) {
            $text = $reason."\n".$text;
        }
        $this->sendMessage($chatId, $text, $messageId);
    }

    private function sendReceipt(TelegramScheduleReschedule $action): void
    {
        $text = sprintf(
            'Перенесено: %s → %s (МСК). Остальные занятия не изменены.',
            $action->old_start->format('d.m.Y H:i'),
            $action->new_start->format('d.m.Y H:i'),
        );
        $keyboard = [[['text' => 'Отменить перенос (1 ч)', 'callback_data' => 'arsu:'.$action->id]]];
        $receiptId = $this->sendMessage(
            $action->telegram_chat_id,
            $text,
            (int) $action->telegram_message_id,
            $keyboard,
        );

        if ($receiptId !== null) {
            $action->receipt_message_id = $receiptId;
            $action->save();
        }
    }

    /** @param list<list<array{text: string, callback_data: string}>>|null $keyboard */
    private function sendMessage(string $chatId, string $text, int $replyTo, ?array $keyboard = null): ?int
    {
        $token = $this->botToken();
        if ($token === '') {
            Log::warning('AnnounceReschedule: bot token missing');

            return null;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_to_message_id' => $replyTo,
        ];
        if ($keyboard !== null) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
        }

        try {
            $response = Http::connectTimeout(5)->timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
        } catch (\Throwable $exception) {
            Log::warning('AnnounceReschedule: Telegram transport failed', ['error' => $exception->getMessage()]);

            return null;
        }

        if (! $response->successful() || ! ($response->json('ok') ?? false)) {
            Log::warning('AnnounceReschedule: Telegram send failed', ['status' => $response->status()]);

            return null;
        }

        $id = $response->json('result.message_id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function botToken(): string
    {
        $zapisi = (string) (MarketingSetting::cached()?->zapisi_bot_token ?? '');

        return $zapisi !== '' ? $zapisi : (string) (config('services.telegram.student_bot_token')
            ?: config('services.telegram.bot_token') ?: '');
    }

    private static function normalize(array $message): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) ($message['text'] ?? ''))));
    }
}
