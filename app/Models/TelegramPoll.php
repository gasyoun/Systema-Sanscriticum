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
        'sent_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
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
