<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Помесячный расход на рекламу в Яндекс.Директе. Количество лидов и стоимость
 * лида НЕ хранятся — считаются вживую из таблицы leads за месяц записи.
 */
class DirectAdSpend extends Model
{
    use HasFactory;

    protected $fillable = [
        'month',
        'specialist_salary',
        'ad_budget',
        'utm_source',
        'landing_page_id',
    ];

    protected $casts = [
        'month' => 'date',
        'specialist_salary' => 'decimal:2',
        'ad_budget' => 'decimal:2',
    ];

    public function landingPage(): BelongsTo
    {
        return $this->belongsTo(LandingPage::class);
    }

    /**
     * Лиды за месяц записи (с учётом необязательных фильтров) — вживую.
     */
    public function leadsCount(): int
    {
        if (! $this->month) {
            return 0;
        }

        return Lead::countForPeriod(
            $this->month->copy()->startOfMonth(),
            $this->month->copy()->endOfMonth(),
            $this->utm_source,
            $this->landing_page_id,
        );
    }

    public function budgetTotal(): float
    {
        return (float) $this->specialist_salary + (float) $this->ad_budget;
    }

    public function costPerLead(): ?float
    {
        $leads = $this->leadsCount();

        return $leads > 0 ? $this->budgetTotal() / $leads : null;
    }
}
