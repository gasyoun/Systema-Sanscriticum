<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChatRemovalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Одна строка реестра H2746: студента исключили из ОДНОГО учебного TG-чата за
 * курсовой долг ≥ N дней и молчание в ответ на два последних контакта.
 *
 * Что здесь иммутабельно и почему. Поля снимка (debt_*, contact_attempts,
 * unanswered_contacts, days_overdue, telegram_chat_id) описывают ОСНОВАНИЕ на
 * момент квалификации. Их нельзя переписывать: спор «за что меня выгнали»
 * разрешается этой строкой, а не пересчётом сегодняшних платежей. Меняются
 * только поля жизненного цикла (status, *_at, *_by, взнос).
 *
 * Взнос ≠ долг. reinstatement_fee — плата за возврат В ЭТОТ чат, ₽1 000 за
 * штуку; курсовой долг гасится отдельно и раньше (debt_settled_at).
 */
class CourseDebtChatRemoval extends Model
{
    use HasFactory;

    public const FEE_PENDING = 'pending';

    public const FEE_SETTLED = 'settled';

    public const FEE_WAIVED = 'waived';

    /** Wave 1: исключение выполняет человек кнопкой, контур Telegram не трогает. */
    public const METHOD_OPERATOR = 'operator_manual';

    /**
     * Поля снимка основания. Пишутся ровно один раз, при создании строки;
     * попытка изменить любое из них у существующей строки падает исключением
     * (см. booted()). Это не стилистика: спор «за что меня выгнали» разрешается
     * этой строкой, и переписываемое основание доказательством не является.
     *
     * @var list<string>
     */
    public const BASIS_COLUMNS = [
        'user_id',
        'course_id',
        'telegram_chat_id',
        'debt_amount',
        'debt_block_numbers',
        'debt_reference_block',
        'days_overdue',
        'debt_basis_at',
        'contact_attempts',
        'unanswered_contacts',
        'last_contact_at',
        'silent_since',
        'qualified_at',
    ];

    protected $fillable = [
        'user_id',
        'course_id',
        'group_id',
        'telegram_chat_id',
        'chat_label',
        'debt_amount',
        'debt_block_numbers',
        'debt_reference_block',
        'days_overdue',
        'debt_basis_at',
        'contact_attempts',
        'unanswered_contacts',
        'last_contact_at',
        'silent_since',
        'status',
        'qualified_at',
        'removed_at',
        'removed_by',
        'removal_method',
        'removal_note',
        'reinstatement_fee',
        'fee_currency',
        'fee_status',
        'fee_settled_at',
        'fee_payment_id',
        'fee_waived_reason',
        'debt_settled_at',
        'restored_at',
        'restored_by',
        'restoration_note',
    ];

    protected $casts = [
        'status' => ChatRemovalStatus::class,
        'debt_amount' => 'decimal:2',
        'debt_block_numbers' => 'array',
        'contact_attempts' => 'array',
        'days_overdue' => 'integer',
        'unanswered_contacts' => 'integer',
        'reinstatement_fee' => 'decimal:2',
        'debt_basis_at' => 'datetime',
        'last_contact_at' => 'datetime',
        'silent_since' => 'datetime',
        'qualified_at' => 'datetime',
        'removed_at' => 'datetime',
        'fee_settled_at' => 'datetime',
        'debt_settled_at' => 'datetime',
        'restored_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $removal): void {
            $frozen = array_intersect(array_keys($removal->getDirty()), self::BASIS_COLUMNS);
            if ($frozen !== []) {
                throw new RuntimeException(
                    'Снимок основания H2746 неизменяем; попытка изменить: '.implode(', ', $frozen)
                    .'. Ошибочный эпизод отменяется (cancel), а не переписывается.'
                );
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    public function feePayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'fee_payment_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CourseDebtChatRemovalEvent::class, 'removal_id')
            ->orderBy('occurred_at');
    }

    /** Эпизоды, которые ещё не закрыты возвратом или отменой. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ChatRemovalStatus::Qualified->value,
            ChatRemovalStatus::Removed->value,
            ChatRemovalStatus::DebtSettled->value,
            ChatRemovalStatus::FeeSettled->value,
        ]);
    }

    /** Эпизоды, по которым взнос ещё реально должен. */
    public function scopeFeeOutstanding(Builder $query): Builder
    {
        return $query->open()->where('fee_status', self::FEE_PENDING);
    }

    public function feeIsClosed(): bool
    {
        return in_array($this->fee_status, [self::FEE_SETTLED, self::FEE_WAIVED], true);
    }

    /**
     * Долг по этой строке погашен. Отдельный предикат, а не сравнение статусов:
     * порядок «сначала долг, потом взнос» и «сначала взнос, потом долг» оба
     * допустимы, а вот возврат — только когда закрыты оба.
     */
    public function debtIsSettled(): bool
    {
        return $this->debt_settled_at !== null;
    }
}
