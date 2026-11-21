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
 *
 * H8527: мульти-перенос и сдвиг дня недели. Явные даты парятся по порядку
 * («перенести 24.12 и 31.12 на 26.12 и 28.12» → две пары), дни недели
 * («перенести четверг на пятницу») двигают все будущие занятия этого дня
 * недели группы; каждое движение — своя TelegramScheduleReschedule-строка
 * и свой чек с кнопкой отмены. Несовпадение числа дат, прошедший источник
 * или занятый целевой слот → уточнение и НОЛЬ мутаций (одна транзакция).
 */
final class AnnounceRescheduleService
{
    private const ANCHORED_DATE_PATTERN = '/(на\s+)?(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2,4}))?/u';

    private const TIME_PATTERN = '/\b([01]?\d|2[0-3])[.:]([0-5]\d)\b/u';

    private const CLARIFY_CLAIM_TTL_SECONDS = 86400;

    private const WEEKDAY_WORDS = [
        1 => ['понедельник', 'понедельника'],
        2 => ['вторник', 'вторника'],
        3 => ['среду', 'среды', 'среда'],
        4 => ['четверг', 'четверга'],
        5 => ['пятницу', 'пятницы', 'пятница'],
        6 => ['субботу', 'субботы', 'суббота'],
        0 => ['воскресенье', 'воскресенья'],
    ];

    public static function matches(array $message): bool
    {
        $text = self::normalize($message);

        // H8527: кроме «занятие/занят» распознаём списки дат и дни недели —
        // «перенести 24.12 и 31.12 на 26.12 и 28.12», «перенести четверг на пятницу».
        return $text !== ''
            && preg_match('/перенос|перенес/u', $text) === 1
            && preg_match('/занят|урок|\d{1,2}[.\/]\d{1,2}|понедельн|вторник|сред|четверг|пятниц|суббот|воскресень/u', $text) === 1;
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
        // Мульти-перенос применяется одной транзакцией, поэтому наличие
        // любой строки источника означает «всё уже применено».
        $alreadyHandled = TelegramScheduleReschedule::query()
            ->where('telegram_chat_id', $chatId)
            ->where('telegram_message_id', $messageId)
            ->exists();
        if ($alreadyHandled) {
            return;
        }

        $reference = Carbon::createFromTimestamp((int) $sentAt, (string) config('app.timezone', 'Europe/Moscow'));
        $plan = $this->plan(self::normalize($message), $reference);
        if ($plan === null) {
            $this->requestConfirmation($chatId, $messageId);

            return;
        }

        $reason = null;
        $moves = $this->resolvePlan($group, $plan, $reference, $reason);
        if ($moves === null || $moves === []) {
            $this->requestConfirmation($chatId, $messageId, $reason);

            return;
        }

        try {
            $actions = DB::transaction(function () use ($chatId, $messageId, $fromId, $group, $moves): array {
                $rows = [];
                foreach ($moves as $move) {
                    $exists = TelegramScheduleReschedule::query()
                        ->where('telegram_chat_id', $chatId)
                        ->where('telegram_message_id', $messageId)
                        ->where('schedule_id', $move['schedule']->id)
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    $schedule = $move['schedule'];
                    $oldStart = $schedule->start->copy();
                    $oldEnd = $schedule->end?->copy();
                    $moved = app(ScheduleMover::class)->reschedule($schedule, $move['newStart']);

                    $rows[] = TelegramScheduleReschedule::create([
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
                }

                return $rows;
            });
        } catch (InvalidArgumentException $exception) {
            Log::warning('AnnounceReschedule: move refused', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'error' => $exception->getMessage(),
            ]);
            $this->requestConfirmation($chatId, $messageId, 'Целевой слот уже занят или недоступен.');

            return;
        }

        if ($actions === []) {
            return;
        }

        foreach ($actions as $action) {
            $this->sendReceipt($action);
        }
    }

    public static function enabledForMessage(array $message): bool
    {
        if (! (bool) config('services.telegram_zapisi.announce_reschedule_detect', false)) {
            return false;
        }

        $chatId = $message['chat']['id'] ?? null;
        if (! is_numeric($chatId)) {
            return false;
        }

        $group = Group::query()->where('telegram_chat_id', (string) $chatId)->first(['id']);
        if ($group === null) {
            return false;
        }

        $allowed = (array) config('services.telegram_zapisi.announce_reschedule_group_ids', []);

        return in_array($group->id, array_map('intval', $allowed), true);
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
     * Разобрать текст в план пар (источник → цель). null = неоднозначно,
     * безопаснее уточнить и ничего не трогать.
     *
     * @return array{mode: string, pairs: list<array{source: ?Carbon, sourceDow: ?int, target: ?Carbon, targetDow: ?int}>, time: array{0: int, 1: int}|null}|null
     */
    private function plan(string $text, Carbon $reference): ?array
    {
        $tokens = $this->intentTokens($text, $reference);
        $targets = array_values(array_filter($tokens, fn (array $token): bool => $token['kind'] === 'target'));
        $sources = array_values(array_filter($tokens, fn (array $token): bool => $token['kind'] === 'source'));
        $targets = $this->dedupeTokens($targets);
        $sources = $this->dedupeTokens($sources);

        if ($targets === []) {
            return null;
        }

        $time = $this->targetTime($text, max(array_map(fn (array $token): int => $token['end'], $targets)));
        if ($time === false) {
            return null;
        }

        // Режим дня недели: ровно один source-день и одна цель.
        if (count($sources) === 1 && count($targets) === 1 && isset($sources[0]['dow'])) {
            return [
                'mode' => 'weekday',
                'pairs' => [[
                    'source' => null,
                    'sourceDow' => $sources[0]['dow'],
                    'target' => $targets[0]['date'] ?? null,
                    'targetDow' => $targets[0]['dow'] ?? null,
                ]],
                'time' => $time,
            ];
        }

        // Дат-режим: равные списки парятся по порядку упоминания.
        if (count($sources) === count($targets) && count($sources) >= 1 && ! isset($sources[0]['dow'])) {
            $today = $reference->copy()->startOfDay();
            $pairs = [];
            foreach ($sources as $index => $source) {
                if ($source['date']->lt($today)) {
                    // Прошедший источник не двигаем никогда (старый pin).
                    return null;
                }
                $pairs[] = [
                    'source' => $source['date'],
                    'sourceDow' => null,
                    'target' => $targets[$index]['date'] ?? null,
                    'targetDow' => $targets[$index]['dow'] ?? null,
                ];
            }

            return ['mode' => 'date', 'pairs' => $pairs, 'time' => $time];
        }

        // Единственная цель без явных источников: только явное «сегодня».
        if (count($sources) === 0 && count($targets) === 1) {
            if (preg_match('/\bсегодня\b/u', $text) !== 1) {
                return null;
            }
            $target = $targets[0];
            $targetDate = $target['date'] ?? $this->nextWeekdayDate($reference, (int) $target['dow']);

            return [
                'mode' => 'date',
                'pairs' => [['source' => $reference->copy()->startOfDay(), 'sourceDow' => null, 'target' => $targetDate, 'targetDow' => null]],
                'time' => $time,
            ];
        }

        // Число дат не совпало (или смешение дат и дней недели) — уточнение.
        return null;
    }

    /**
     * Токены намерения в порядке упоминания: явные даты, «на завтра» и дни
     * недели. Цель = после «на» (или «следующий …»); цепочка через «и»/запятую
     * наследует тип предыдущего токена («24.12 и 31.12 на 26.12 и 28.12»).
     *
     * @return list<array{offset: int, end: int, kind: string, date?: Carbon, dow?: int}>
     */
    private function intentTokens(string $text, Carbon $reference): array
    {
        $tokens = [];

        if (preg_match('/\bна\s+завтра\b/u', $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            $tokens[] = [
                'offset' => $match[0][1],
                'end' => $match[0][1] + strlen($match[0][0]),
                'kind' => 'target',
                'anchored' => true,
                'date' => $reference->copy()->addDay()->startOfDay(),
            ];
        }

        preg_match_all(self::ANCHORED_DATE_PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $date = $this->dateFromParts($match[2][0], $match[3][0], $match[4][0] ?? '', $reference);
            if ($date === null) {
                continue;
            }
            $anchored = ($match[1][0] ?? '') !== '';
            $tokens[] = [
                'offset' => $match[0][1],
                'end' => $match[0][1] + strlen($match[0][0]),
                'kind' => $anchored ? 'target' : 'source',
                'anchored' => $anchored,
                'date' => $date,
            ];
        }

        foreach (self::WEEKDAY_WORDS as $dayOfWeek => $words) {
            foreach ($words as $word) {
                if (preg_match('/\b(на\s+)?(следующ\w*\s+)?'.$word.'\b/u', $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                $anchored = ($match[1][0] ?? '') !== '' || ($match[2][0] ?? '') !== '';
                $tokens[] = [
                    'offset' => $match[0][1],
                    'end' => $match[0][1] + strlen($match[0][0]),
                    'kind' => $anchored ? 'target' : 'source',
                    'anchored' => $anchored,
                    'dow' => $dayOfWeek,
                ];
            }
        }

        usort($tokens, fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);

        // Цепочка «… и …» / «…, …» наследует тип предыдущего токена — но
        // никогда не перебивает явный анкор («на …»), иначе один пробел
        // между «31.12» и «на 26.12» превращал цель в источник.
        for ($index = 1, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index]['anchored']) {
                continue;
            }
            $gap = substr($text, $tokens[$index - 1]['end'], $tokens[$index]['offset'] - $tokens[$index - 1]['end']);
            if (preg_match('/^\s*(?:,|и)?\s*$/u', $gap) === 1) {
                $tokens[$index]['kind'] = $tokens[$index - 1]['kind'];
                $tokens[$index]['anchored'] = $tokens[$index - 1]['anchored'];
            }
        }

        return $tokens;
    }

    /**
     * Дедуп токенов: одна и та же дата/день недели, упомянутая дважды
     * («перенос на пятницу … перенос на пятницу»), — одна цель.
     *
     * @param  list<array{offset: int, end: int, kind: string, date?: Carbon, dow?: int}>  $tokens
     * @return list<array{offset: int, end: int, kind: string, date?: Carbon, dow?: int}>
     */
    private function dedupeTokens(array $tokens): array
    {
        $seen = [];
        $unique = [];
        foreach ($tokens as $token) {
            $key = isset($token['dow']) ? 'w'.$token['dow'] : 'd'.$token['date']->toDateString();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $token;
        }

        return $unique;
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

    /**
     * План → конкретные движения (расписание + новое время). null или пустой
     * список = уточнение, ни одной мутации до возврата.
     *
     * @param  array{mode: string, pairs: list<array{source: ?Carbon, sourceDow: ?int, target: ?Carbon, targetDow: ?int}>, time: array{0: int, 1: int}|null}  $plan
     * @return list<array{schedule: Schedule, newStart: Carbon}>|null
     */
    private function resolvePlan(Group $group, array $plan, Carbon $reference, ?string &$reason): ?array
    {
        $moves = [];
        if ($plan['mode'] === 'weekday') {
            return $this->resolveWeekdayPlan($group, $plan['pairs'][0], $plan['time'], $reference, $reason);
        }

        foreach ($plan['pairs'] as $pair) {
            $resolved = $pair['source'] !== null ? $this->resolveDatePair($group, $pair, $plan['time'], $reason) : null;
            if ($resolved === null) {
                return null;
            }
            $moves[] = $resolved;
        }

        return $moves;
    }

    /**
     * @param  array{source: ?Carbon, sourceDow: ?int, target: ?Carbon, targetDow: ?int}  $pair
     * @return array{schedule: Schedule, newStart: Carbon}|null
     */
    private function resolveDatePair(Group $group, array $pair, ?array $time, ?string &$reason): ?array
    {
        $candidates = Schedule::query()
            ->where('group_id', $group->id)
            ->whereBetween('start', [
                $pair['source']->copy()->startOfDay(),
                $pair['source']->copy()->endOfDay(),
            ])
            ->orderBy('start')
            ->limit(2)
            ->get();

        if ($candidates->count() !== 1 || $candidates->first()?->start === null) {
            return null;
        }

        /** @var Schedule $schedule */
        $schedule = $candidates->first();
        $newStart = $pair['target']->copy();
        if ($time !== null) {
            $newStart->setTime($time[0], $time[1], 0);
        } else {
            $newStart->setTime((int) $schedule->start->format('H'), (int) $schedule->start->format('i'), 0);
        }

        if ($newStart->lte(now()) || $newStart->eq($schedule->start)) {
            $reason = 'Новое время должно быть в будущем и отличаться от прежнего.';

            return null;
        }

        return ['schedule' => $schedule, 'newStart' => $newStart];
    }

    /**
     * «перенести четверг на пятницу»: двигаем ВСЕ будущие занятия источника-дня
     * недели на ближайший целевой день недели (вперёд, без возврата на неделю
     * назад). «перенести четверг на 28.11»: только одно будущее вхождение.
     *
     * @param  array{source: ?Carbon, sourceDow: ?int, target: ?Carbon, targetDow: ?int}  $pair
     * @return list<array{schedule: Schedule, newStart: Carbon}>|null
     */
    private function resolveWeekdayPlan(Group $group, array $pair, ?array $time, Carbon $reference, ?string &$reason): ?array
    {
        if ($pair['sourceDow'] === null) {
            return null;
        }

        $entries = Schedule::query()
            ->where('group_id', $group->id)
            ->where('start', '>=', $reference->copy()->startOfDay())
            ->orderBy('start')
            ->limit(120)
            ->get()
            ->filter(fn (Schedule $entry): bool => $entry->start !== null && (int) $entry->start->dayOfWeek === $pair['sourceDow'])
            ->values();

        if ($entries->isEmpty()) {
            return null;
        }

        $moves = [];
        foreach ($entries as $entry) {
            if ($pair['targetDow'] !== null) {
                $delta = (($pair['targetDow'] - (int) $entry->start->dayOfWeek) + 7) % 7;
                if ($delta === 0) {
                    $delta = 7;
                }
                $newStart = $entry->start->copy()->addDays($delta)->startOfDay();
            } else {
                if ($entries->count() !== 1 || $pair['target'] === null) {
                    // Несколько будущих вхождений и одна конкретная дата — неоднозначно.
                    return null;
                }
                $newStart = $pair['target']->copy();
            }

            if ($time !== null) {
                $newStart->setTime($time[0], $time[1], 0);
            } else {
                $newStart->setTime((int) $entry->start->format('H'), (int) $entry->start->format('i'), 0);
            }

            if ($newStart->lte(now()) || $newStart->eq($entry->start)) {
                $reason = 'Новое время должно быть в будущем и отличаться от прежнего.';

                return null;
            }

            $moves[] = ['schedule' => $entry, 'newStart' => $newStart];
        }

        return $moves;
    }

    private function nextWeekdayDate(Carbon $reference, int $dayOfWeek): Carbon
    {
        $date = $reference->copy()->startOfDay();
        while ((int) $date->dayOfWeek !== $dayOfWeek) {
            $date->addDay();
        }

        return $date;
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

    private function requestConfirmation(string $chatId, int $messageId, ?string $reason = null): void
    {
        if (! TelegramSendGuard::claimKey('tg:announce-reschedule-clarify:'.$chatId.':'.$messageId, self::CLARIFY_CLAIM_TTL_SECONDS)) {
            return;
        }

        $text = 'Похоже на перенос занятия, но я не могу безопасно определить все детали. Подтвердите в формате «Занятие 27.10 переносим на 30.10 в 08:00» или списком: «перенести 24.12 и 31.12 на 26.12 и 28.12».';
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
