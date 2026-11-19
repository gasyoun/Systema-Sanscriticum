<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Опрос @zapisi_ORSbot в чате группы (sendPoll, не анонимный). Создаёт
 * ZapisiPollService::create(), отправляет SendZapisiPollJob, голоса пишет
 * ZapisiPollService::recordAnswer() из апдейта poll_answer.
 *
 * @property array<int, string> $options
 */
class TelegramPoll extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Транспортный сбой без ответа Telegram — дошёл ли опрос, неизвестно. */
    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_CLOSED = 'closed';

    /** Ручной опрос куратора из админки. */
    public const KIND_MANUAL = 'manual';

    /** Автоопрос о присутствии перед занятием (classes:post-attendance-poll). */
    public const KIND_ATTENDANCE = 'attendance';

    /** Варианты автоопроса о присутствии; индексы — ATTENDANCE_OPTION_*. */
    public const ATTENDANCE_OPTIONS = ['Буду', 'Опоздаю', 'Не смогу'];

    public const ATTENDANCE_OPTION_COMING = 0;

    public const ATTENDANCE_OPTION_LATE = 1;

    public const ATTENDANCE_OPTION_ABSENT = 2;

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Отправляется',
        self::STATUS_SENT => 'Идёт',
        self::STATUS_FAILED => 'Ошибка отправки',
        self::STATUS_UNKNOWN => 'Не ясно, дошёл ли',
        self::STATUS_CLOSED => 'Закрыт',
    ];

    protected $fillable = [
        'group_id',
        'chat_id',
        'kind',
        'schedule_id',
        'schedule_start',
        'question',
        'options',
        'allows_multiple',
        'status',
        'tg_poll_id',
        'message_id',
        'created_by',
        'sent_at',
        'closed_at',
        'error',
    ];

    protected $casts = [
        'options' => 'array',
        'allows_multiple' => 'boolean',
        'schedule_start' => 'datetime',
        'sent_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** Занятие, о котором спрашивает автоопрос о присутствии (у ручных — null). */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /**
     * Голос в автоопросе о присутствии → статус предупреждения о занятии.
     * null — предупреждения нет: «Буду» или голос отозван.
     *
     * @param  array<int, int>  $optionIds
     */
    public static function attendanceStatusFor(array $optionIds): ?string
    {
        return match ($optionIds[0] ?? null) {
            self::ATTENDANCE_OPTION_LATE => ScheduleAttendanceNotice::STATUS_LATE,
            self::ATTENDANCE_OPTION_ABSENT => ScheduleAttendanceNotice::STATUS_ABSENT,
            default => null,
        };
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(TelegramPollAnswer::class);
    }

    /**
     * Итог по вариантам: [индекс => ['label', 'count', 'answers' => коллекция голосов]].
     * Отозванные голоса (пустой option_ids) в счёт не входят.
     *
     * @return array<int, array{label: string, count: int, answers: Collection<int, TelegramPollAnswer>}>
     */
    public function optionTally(): array
    {
        $answers = $this->answers()->with('user')->orderBy('answered_at')->get();

        $tally = [];
        foreach (array_values($this->options ?? []) as $index => $label) {
            $voters = $answers->filter(fn (TelegramPollAnswer $a): bool => in_array($index, $a->option_ids ?? [], true))->values();
            $tally[$index] = ['label' => (string) $label, 'count' => $voters->count(), 'answers' => $voters];
        }

        return $tally;
    }
}
