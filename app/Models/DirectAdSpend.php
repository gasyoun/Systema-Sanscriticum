<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Расход на рекламу в Яндекс.Директе за произвольный период [starts_at; ends_at].
 * Количество лидов и стоимость лида НЕ хранятся — считаются вживую из таблицы
 * leads за период записи.
 */
class DirectAdSpend extends Model
{
    use HasFactory;

    protected $fillable = [
        'starts_at',
        'ends_at',
        'specialist_salary',
        'ad_budget',
        'utm_source',
        'landing_page_id',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'specialist_salary' => 'decimal:2',
        'ad_budget' => 'decimal:2',
    ];

    public function landingPage(): BelongsTo
    {
        return $this->belongsTo(LandingPage::class);
    }

    /**
     * Лиды за период записи (с учётом необязательных фильтров) — вживую.
     */
    public function leadsCount(): int
    {
        if (! $this->starts_at || ! $this->ends_at) {
            return 0;
        }

        return Lead::countForPeriod(
            $this->starts_at,
            $this->ends_at,
            $this->utm_source,
            $this->landing_page_id,
        );
    }

    /**
     * Длительность периода в днях (включительно), минимум 1.
     */
    public function periodDays(): int
    {
        if (! $this->starts_at || ! $this->ends_at) {
            return 1;
        }

        return max(1, $this->starts_at->diffInDays($this->ends_at) + 1);
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

    /**
     * Часть бюджета записи, приходящаяся на окно [start; end], пропорционально
     * количеству дней пересечения. 0, если периоды не пересекаются.
     */
    public function budgetForWindow(Carbon $start, Carbon $end): float
    {
        if (! $this->starts_at || ! $this->ends_at) {
            return 0.0;
        }

        $overlapStart = $this->starts_at->copy()->startOfDay()->max($start->copy()->startOfDay());
        $overlapEnd = $this->ends_at->copy()->startOfDay()->min($end->copy()->startOfDay());

        if ($overlapEnd->lt($overlapStart)) {
            return 0.0;
        }

        $overlapDays = $overlapStart->diffInDays($overlapEnd) + 1;

        return $this->budgetTotal() * $overlapDays / $this->periodDays();
    }
}
