<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Заявка интереса на курс (H5066): join — хочу в следующий набор; recording —
 * хочу купить запись; revive — возобновить занятия, если соберётся группа
 * (курс не повторяется). Пишется публичной формой /interest/{course} под
 * флагом features.course_interest_form; уведомление кураторам — через
 * CuratorNotifier::courseInterestReceived().
 */
class CourseInterestRequest extends Model
{
    public const INTENT_JOIN = 'join';

    public const INTENT_RECORDING = 'recording';

    public const INTENT_REVIVE = 'revive';

    /** @var list<string> */
    public const INTENTS = [
        self::INTENT_JOIN,
        self::INTENT_RECORDING,
        self::INTENT_REVIVE,
    ];

    public const STATUS_NEW = 'new';

    public const STATUS_DONE = 'done';

    protected $fillable = [
        'course_id',
        'course_title',
        'intent',
        'name',
        'email',
        'telegram',
        'comment',
        'status',
        'ip_address',
        'user_agent',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** Отображаемое имя курса: привязанный курс или свободный тайтл анонса. */
    public function courseLabel(): string
    {
        if ($this->course !== null) {
            return (string) $this->course->title;
        }

        return (string) $this->course_title !== '' ? (string) $this->course_title : '—';
    }

    /** @return array<string, string> */
    public static function intentLabels(): array
    {
        return [
            self::INTENT_JOIN => 'В следующий набор',
            self::INTENT_RECORDING => 'Купить запись',
            self::INTENT_REVIVE => 'Возобновить занятия',
        ];
    }

    /**
     * Счётчик интересов по курсу: [intent => count] — кураторская витрина
     * «спрос есть — запустим» в Filament и порог revive_threshold.
     *
     * @return array<string, int>
     */
    public static function countsForCourse(int $courseId): array
    {
        return static::query()
            ->where('course_id', $courseId)
            ->selectRaw('intent, count(*) as total')
            ->groupBy('intent')
            ->pluck('total', 'intent')
            ->map(fn ($total) => (int) $total)
            ->all();
    }
}
