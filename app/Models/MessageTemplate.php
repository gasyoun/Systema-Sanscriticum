<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MessagePlaceholders;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Общая библиотека шаблонов сообщений оператора. Один текст с плейсхолдерами
 * {name}/{course}/{block}/{pay_link} (см. {@see MessagePlaceholders}) питает три
 * поверхности: реактивацию, массовый follow-up лидов и канреплаи поддержки.
 * Строится один раз здесь, чтобы ассистент не сочинял текст с нуля. См. H221.
 */
class MessageTemplate extends Model
{
    use HasFactory;

    public const CATEGORY_LEAD = 'lead';

    public const CATEGORY_REACTIVATION = 'reactivation';

    public const CATEGORY_SUPPORT = 'support';

    /** @return array<string,string> [code => human label] */
    public static function categories(): array
    {
        return [
            self::CATEGORY_LEAD => 'Лиды (follow-up)',
            self::CATEGORY_REACTIVATION => 'Реактивация',
            self::CATEGORY_SUPPORT => 'Поддержка (канреплай)',
        ];
    }

    protected $fillable = [
        'title',
        'body',
        'category',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Активные шаблоны выбранной категории (для селекторов на поверхностях). */
    public function scopeForCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category)->where('is_active', true);
    }

    public function categoryLabel(): string
    {
        return self::categories()[$this->category] ?? $this->category;
    }

    /**
     * Подставить плейсхолдеры под конкретного получателя. Курс/блок
     * опциональны: без них {course}/{block} схлопываются в пустую строку, а
     * {pay_link} ведёт в личный кабинет (как в DebtorReminderDispatcher).
     */
    public function render(User $user, ?Course $course = null, ?int $blockNumber = null): string
    {
        return MessagePlaceholders::render(
            (string) $this->body,
            MessagePlaceholders::forUser($user, $course, $blockNumber),
        );
    }
}
