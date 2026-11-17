<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Jobs\SendZapisiPollJob;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\ScheduleAttendanceNotice;
use App\Models\TelegramPoll;
use App\Models\TelegramPollAnswer;
use App\Models\User;
use App\Services\AttendanceNoticeService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Опросы @zapisi_ORSbot в чатах групп: создание (куратор в админке), приём
 * голосов (апдейт poll_answer из ProcessTelegramZapisiUpdate), закрытие.
 * Опросы НЕ анонимные — результаты нужны поимённо; участники чата тоже видят,
 * кто как голосовал (так устроен Telegram).
 */
final class ZapisiPollService
{
    /** Лимиты Bot API sendPoll. */
    public const QUESTION_MAX = 300;

    public const OPTION_MAX = 100;

    public const OPTIONS_MIN = 2;

    public const OPTIONS_MAX = 10;

    /**
     * @param  array<int, mixed>  $options
     * @param  array<string, mixed>  $attributes  доп. поля опроса (kind, schedule_id, schedule_start)
     */
    public function create(Group $group, string $question, array $options, bool $multiple, ?User $by = null, array $attributes = []): TelegramPoll
    {
        $chatId = (string) ($group->telegram_chat_id ?? '');
        if ($chatId === '') {
            throw new InvalidArgumentException('У группы не привязан Telegram-чат.');
        }

        $question = trim($question);
        if ($question === '' || mb_strlen($question) > self::QUESTION_MAX) {
            throw new InvalidArgumentException('Вопрос: от 1 до '.self::QUESTION_MAX.' символов.');
        }

        $options = array_values(array_filter(
            array_map(fn ($o): string => trim((string) (is_array($o) ? ($o['text'] ?? '') : $o)), $options),
            fn (string $o): bool => $o !== '',
        ));
        if (count($options) < self::OPTIONS_MIN || count($options) > self::OPTIONS_MAX) {
            throw new InvalidArgumentException('Вариантов ответа: от '.self::OPTIONS_MIN.' до '.self::OPTIONS_MAX.'.');
        }
        foreach ($options as $option) {
            if (mb_strlen($option) > self::OPTION_MAX) {
                throw new InvalidArgumentException('Вариант ответа длиннее '.self::OPTION_MAX." символов: «{$option}».");
            }
        }

        $poll = TelegramPoll::create($attributes + [
            'group_id' => $group->id,
            'chat_id' => $chatId,
            'question' => $question,
            'options' => $options,
            'allows_multiple' => $multiple,
            'status' => TelegramPoll::STATUS_PENDING,
            'created_by' => $by?->id,
        ]);

        SendZapisiPollJob::dispatch($poll->id);

        return $poll;
    }

    /**
     * Автоопрос о присутствии на занятии (classes:post-attendance-poll): один
     * ответ, варианты TelegramPoll::ATTENDANCE_OPTIONS. Опрос помнит занятие и
     * его время начала на момент отправки — по ним команда не шлёт дубль, а
     * голос после переноса занятия не пишется в предупреждения.
     */
    public function createAttendancePoll(Schedule $schedule, Group $group): TelegramPoll
    {
        return $this->create($group, $this->attendanceQuestion($schedule), TelegramPoll::ATTENDANCE_OPTIONS, false, null, [
            'kind' => TelegramPoll::KIND_ATTENDANCE,
            'schedule_id' => $schedule->id,
            'schedule_start' => $schedule->start,
        ]);
    }

    private const WEEKDAYS = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];

    /**
     * «Занятие №2 · Грамматика по Кочергиной гр.64 · сб 21.11.2026 в 11:00 (МСК). Будете?»
     * Номер — из названия в расписании («(#2, 21.11.26)» / «(урок 9, 17.11.26)»,
     * хвост из названия убираем, чтобы дата не повторялась); если в названии
     * номера нет — порядковый номер занятия группы по времени начала.
     */
    public function attendanceQuestion(Schedule $schedule): string
    {
        $title = trim((string) ($schedule->title ?: ($schedule->course?->title ?? '')));
        $number = null;
        if (preg_match('/\s*\((?:#|№|урок\s*)(\d+)\s*(?:,[^)]*)?\)\s*$/iu', $title, $m) === 1) {
            $number = (int) $m[1];
            $title = trim(mb_substr($title, 0, mb_strlen($title) - mb_strlen($m[0])));
        } elseif ($schedule->group_id !== null) {
            $number = Schedule::query()
                ->where('group_id', $schedule->group_id)
                ->where('start', '<=', $schedule->start)
                ->distinct()
                ->count('start');
        }

        $title = $title !== '' ? mb_strimwidth($title, 0, 180, '…') : '';
        $start = $schedule->start;
        $date = self::WEEKDAYS[$start->dayOfWeek].' '.$start->format('d.m.Y');
        if ($start->isToday()) {
            $date = 'сегодня, '.$date;
        } elseif ($start->isTomorrow()) {
            $date = 'завтра, '.$date;
        }

        $head = $number ? "Занятие №{$number}" : 'Занятие';
        $parts = array_filter([$head, $title, "{$date} в {$start->format('H:i')} (МСК)"], fn (string $p): bool => $p !== '');

        return implode(' · ', $parts).'. Будете?';
    }

    /**
     * Апдейт poll_answer: {poll_id, user{id,…} | voter_chat, option_ids[]}.
     * Чата в апдейте нет — опрос ищем по tg_poll_id. Пустой option_ids — голос
     * отозван (строку оставляем, чтобы в админке было видно «передумал»).
     *
     * @param  array<string, mixed>  $pollAnswer
     */
    public function recordAnswer(array $pollAnswer): void
    {
        $tgPollId = (string) ($pollAnswer['poll_id'] ?? '');
        $tgUser = $pollAnswer['user'] ?? null;

        // Голос от имени канала/чата (voter_chat) не привязать к человеку.
        if ($tgPollId === '' || ! is_array($tgUser) || ! isset($tgUser['id'])) {
            return;
        }

        $poll = TelegramPoll::query()->where('tg_poll_id', $tgPollId)->first();
        if ($poll === null) {
            return; // чужой опрос (не нашим ботом из админки)
        }

        $tgUserId = (int) $tgUser['id'];
        $student = TelegramCommandAcl::findUser($tgUserId);
        $optionIds = array_values(array_map('intval', (array) ($pollAnswer['option_ids'] ?? [])));
        $name = trim(((string) ($tgUser['first_name'] ?? '')).' '.((string) ($tgUser['last_name'] ?? '')));

        TelegramPollAnswer::updateOrCreate(
            ['telegram_poll_id' => $poll->id, 'telegram_user_id' => $tgUserId],
            [
                'user_id' => $student?->id,
                'tg_username' => isset($tgUser['username']) ? (string) $tgUser['username'] : null,
                'tg_name' => $name !== '' ? $name : null,
                'option_ids' => $optionIds,
                'answered_at' => now(),
            ],
        );

        $this->applyAttendanceAnswer($poll, $student, $optionIds);

        Log::info('ZapisiPoll: answer recorded', [
            'poll_id' => $poll->id,
            'telegram_user_id' => $tgUserId,
            'options' => $pollAnswer['option_ids'] ?? [],
        ]);
    }

    /**
     * Голос в автоопросе о присутствии → предупреждение о занятии, которое видит
     * преподаватель: «Опоздаю» / «Не смогу» записываются, «Буду» и отозванный
     * голос снимают предупреждение (последнее слово ученика главнее прежнего).
     * Молча ничего не делает для ручных опросов, непривязанного Telegram,
     * выключенных предупреждений (features.attendance_notices), ученика не из
     * состава занятия, перенесённого или уже прошедшего занятия.
     *
     * @param  array<int, int>  $optionIds
     */
    private function applyAttendanceAnswer(TelegramPoll $poll, ?User $student, array $optionIds): void
    {
        if ($poll->kind !== TelegramPoll::KIND_ATTENDANCE || $poll->schedule_id === null || $student === null) {
            return;
        }

        $notices = app(AttendanceNoticeService::class);
        if (! $notices->enabled()) {
            return;
        }

        $schedule = Schedule::find($poll->schedule_id);
        if ($schedule === null || $schedule->start === null) {
            return;
        }

        // Опрос был про другое время (занятие перенесли) или занятие уже идёт давно.
        if ($poll->schedule_start !== null && ! $schedule->start->equalTo($poll->schedule_start)) {
            return;
        }
        if ($schedule->start->copy()->addHour()->isPast()) {
            return;
        }

        $status = TelegramPoll::attendanceStatusFor($optionIds);

        try {
            if ($status === null) {
                $notices->clear($student, $schedule);
            } else {
                $notices->upsert($student, $schedule, $status, ScheduleAttendanceNotice::SOURCE_TELEGRAM_POLL);
            }
        } catch (InvalidArgumentException) {
            // Голосовал участник чата не из состава занятия — голос остаётся в опросе.
        }
    }

    /** Закрыть опрос в чате (stopPoll — бот-опрос в группе может остановить только бот). */
    public function close(TelegramPoll $poll): void
    {
        if ($poll->status !== TelegramPoll::STATUS_SENT || $poll->message_id === null) {
            throw new InvalidArgumentException('Закрыть можно только отправленный идущий опрос.');
        }

        $token = (string) (MarketingSetting::cached()?->zapisi_bot_token ?? '');
        if ($token === '') {
            throw new RuntimeException('Не задан токен бота @zapisi_ORSbot.');
        }

        $response = Http::connectTimeout(5)->timeout(15)->post("https://api.telegram.org/bot{$token}/stopPoll", [
            'chat_id' => $poll->chat_id,
            'message_id' => $poll->message_id,
        ]);

        $description = (string) ($response->json('description') ?? '');
        $alreadyClosed = str_contains(mb_strtolower($description), 'already been closed');

        if ((! $response->successful() || ! ($response->json('ok') ?? false)) && ! $alreadyClosed) {
            throw new RuntimeException('Telegram не закрыл опрос: '.($description !== '' ? $description : $response->body()));
        }

        $poll->update(['status' => TelegramPoll::STATUS_CLOSED, 'closed_at' => now()]);
    }
}
