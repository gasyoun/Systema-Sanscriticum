<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Строка «таблицы набора» — заявка на будущий набор между интересом (Lead)
 * и оплатой (Payment). Ключевая идея: для возвратных студентов история групп,
 * блок отвала и платёжная надёжность НЕ хранятся копией, а ВЫВОДЯТСЯ из
 * связанного User (матч по telegram_username). Здесь дублируется только то,
 * чего в системе ещё нет (первичка без аккаунта).
 */
class WaitlistEntry extends Model
{
    use HasFactory;

    /** Уровень абитуриента (колонка «Уровень»). */
    public const LEVELS = [
        'novice' => 'Новичок',
        'continuing' => 'Продолжающий',
    ];

    /** Стадия работы с заявкой (единый источник для формы/бейджа/фильтра). */
    public const STATUSES = [
        'waiting' => 'Ждёт набора',
        'invited' => 'Приглашён на вебинар',
        'recording_sent' => 'Выслана запись',
        'enrolled' => 'Зачислен',
        'declined' => 'Отказ',
        'silent' => 'Молчит',
    ];

    protected $fillable = [
        'lead_id',
        'user_id',
        'intake_id',
        'group_id',
        'name',
        'telegram_username',
        'contact',
        'level',
        'preferred_schedule',
        'timezone_note',
        'status',
        'prior_group_note',
        'notes',
        'last_outreach_at',
        'last_reply_at',
    ];

    protected $casts = [
        'last_outreach_at' => 'date',
        'last_reply_at' => 'date',
    ];

    // Дефолты в памяти (зеркалят DB-default), чтобы status/level были
    // определены сразу после create() без перезагрузки из БД.
    protected $attributes = [
        'status' => 'waiting',
    ];

    protected static function booted(): void
    {
        // Нормализуем @username при сохранении и автоматически привязываем
        // возвратного студента, если аккаунт с таким username уже есть.
        static::saving(function (WaitlistEntry $entry): void {
            $entry->telegram_username = User::normalizeTelegramUsername($entry->telegram_username);

            if ($entry->user_id === null && $entry->telegram_username !== null) {
                $entry->user_id = self::resolveStudentId($entry->telegram_username);
            }
        });
    }

    // ================= Связи =================

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** Сматченный возвратный студент (источник истории/надёжности). */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    /**
     * Конкретная ещё не запущенная (или уже forming) группа — стабильный ID
     * (groups.id + slug) для листа желающих до старта (H1755 residual).
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    // ================= Матчинг по @username =================

    /** ID пользователя с данным (уже нормализованным) username, либо null. */
    public static function resolveStudentId(?string $normalizedUsername): ?int
    {
        if ($normalizedUsername === null || $normalizedUsername === '') {
            return null;
        }

        return User::query()
            ->where('telegram_username', $normalizedUsername)
            ->value('id');
    }

    public function isReturning(): bool
    {
        return $this->user_id !== null;
    }

    // ================= Выведенные данные (не хранятся) =================

    /**
     * Названия групп, которые студент покинул (left_at != null). Для несматченных
     * заявок берём свободный текст prior_group_note. Основа win-back-подсказки.
     */
    public function priorGroupNames(): Collection
    {
        if (! $this->relationLoaded('student') && $this->user_id) {
            $this->load('student.groups');
        }

        $student = $this->student;
        if (! $student) {
            return collect(array_filter([$this->prior_group_note]));
        }

        return $student->groups
            ->filter(fn ($g) => $g->pivot->left_at !== null)
            ->pluck('name')
            ->values();
    }

    /**
     * Блок, после которого студент отвалился в прошлый раз (макс left_after_block
     * по course_user). Это структурный сигнал «вышел после N-го блока» из истории —
     * не парсинг заметок. null, если не сматчен или не отваливался.
     */
    public function lastDropBlock(): ?int
    {
        $student = $this->student;
        if (! $student) {
            return null;
        }

        $max = $student->courses()
            ->wherePivotNotNull('left_after_block')
            ->max('course_user.left_after_block');

        return $max !== null ? (int) $max : null;
    }

    /**
     * Оценка платёжной надёжности из истории:
     *   risk     — есть неисполненное обещание оплаты (active/expired);
     *   watch    — студент уже отваливался из группы (есть left_at);
     *   reliable — есть оплаты, отвалов/долгов нет;
     *   unknown  — аккаунт не сматчен, судить не по чему.
     */
    public function reliability(): string
    {
        $student = $this->student;
        if (! $student) {
            return 'unknown';
        }

        $hasUnmetPromise = $student->paymentPromises
            ->contains(fn (PaymentPromise $p) => $p->isUnmet());
        if ($hasUnmetPromise) {
            return 'risk';
        }

        $droppedAnyGroup = $student->groups
            ->contains(fn ($g) => $g->pivot->left_at !== null);
        if ($droppedAnyGroup) {
            return 'watch';
        }

        return $student->payments()->paid()->exists() ? 'reliable' : 'unknown';
    }

    /** Подпись + цвет бейджа надёжности для админки. */
    public function reliabilityBadge(): array
    {
        return match ($this->reliability()) {
            'risk' => ['label' => 'Риск неоплаты', 'color' => 'danger'],
            'watch' => ['label' => 'Отваливался', 'color' => 'warning'],
            'reliable' => ['label' => 'Надёжный', 'color' => 'success'],
            default => ['label' => 'Нет данных', 'color' => 'gray'],
        };
    }

    public function levelLabel(): string
    {
        return self::LEVELS[$this->level] ?? ($this->level ?? '—');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ($this->status ?? '—');
    }
}
