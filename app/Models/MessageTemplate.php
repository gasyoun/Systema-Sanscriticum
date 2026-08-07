<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MessagePlaceholders;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Общая библиотека шаблонов сообщений оператора. Один текст с плейсхолдерами
 * {name}/{course}/{block}/{pay_link}/{email} (см. {@see MessagePlaceholders})
 * питает три поверхности: реактивацию, массовый follow-up лидов и канреплаи
 * поддержки. {login_link} — только ручная вставка (magic-link). См. H221 · H2339.
 */
class MessageTemplate extends Model
{
    use HasFactory;

    public const CATEGORY_LEAD = 'lead';

    public const CATEGORY_REACTIVATION = 'reactivation';

    public const CATEGORY_SUPPORT = 'support';

    public const CATEGORY_DOZHIM = 'dozhim';

    /** Шаги линейного дожим-дрипа (H2059, IMPLEMENTATION H-B п.5) — {@see MessageTemplate::$dozhim_step}. */
    public const DOZHIM_STEP_DAY0 = 'day0';

    public const DOZHIM_STEP_DAY3 = 'day3';

    public const DOZHIM_STEP_DAY7 = 'day7';

    /** @return array<string,string> [code => human label] */
    public static function categories(): array
    {
        return [
            self::CATEGORY_LEAD => 'Лиды (follow-up)',
            self::CATEGORY_REACTIVATION => 'Реактивация',
            self::CATEGORY_SUPPORT => 'Поддержка (канреплай)',
            self::CATEGORY_DOZHIM => 'Дожим (недожатые сделки)',
        ];
    }

    /**
     * Шаги дожим-дрипа для селекта в админке. Только шаблоны категории
     * {@see self::CATEGORY_DOZHIM} используют это поле; для остальных — null.
     *
     * @return array<string,string>
     */
    public static function dozhimSteps(): array
    {
        return [
            self::DOZHIM_STEP_DAY0 => 'День 0 (порог)',
            self::DOZHIM_STEP_DAY3 => 'День 3',
            self::DOZHIM_STEP_DAY7 => 'День 7',
        ];
    }

    /**
     * Категории FAQ-суггестера, к которым можно привязать шаблон (H1838, S9).
     * Только LLM-категории D/E/F: у A/B/C черновик и так строится из живых
     * фактов LMS без LLM — шаблону там нечего удешевлять.
     *
     * @return array<string,string> [code => human label]
     */
    public static function suggesterCategories(): array
    {
        return [
            SupportAnswerSuggestion::CATEGORY_PAYMENT => 'D — оплата / цена / тарифы',
            SupportAnswerSuggestion::CATEGORY_ACCESS => 'E — доступ / группа / кабинет',
            SupportAnswerSuggestion::CATEGORY_MATERIALS => 'F — материалы / ДЗ / сертификаты',
        ];
    }

    protected $fillable = [
        'title',
        'body',
        'category',
        'suggester_category',
        'dozhim_step',
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

    /** Активный шаблон, привязанный к категории FAQ-суггестера (H1838, S9). */
    public function scopeBoundToSuggesterCategory(Builder $query, string $category): Builder
    {
        return $query->where('suggester_category', $category)->where('is_active', true);
    }

    /** История правок шаблона — пишет MessageTemplateAuditObserver (H1932). */
    public function audits(): HasMany
    {
        return $this->hasMany(MessageTemplateAudit::class)->latest('created_at');
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
