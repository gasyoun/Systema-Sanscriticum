<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CourseBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'number',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'is_current',
        'is_active',
    ];

    protected $casts = [
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
        'is_current' => 'boolean',
        'is_active'  => 'boolean',
        'number'     => 'integer',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function tariff(): HasOne
    {
        return $this->hasOne(Tariff::class);
    }

    /**
     * Актуален ли блок прямо сейчас.
     * Ручной флаг is_current имеет приоритет над датами.
     * Границы дат считаются инклюзивными по календарному дню:
     *   starts_at — блок «уже начался», если starts_at <= конец сегодняшнего дня;
     *   ends_at   — блок «ещё не закончился», если ends_at >= начало сегодняшнего дня.
     */
    public function isCurrent(): bool
    {
        if ($this->is_current) {
            return true;
        }

        $startOfToday = now()->startOfDay();
        $endOfToday   = now()->endOfDay();

        if ($this->starts_at && $this->starts_at->gt($endOfToday)) {
            return false;
        }

        if ($this->ends_at && $this->ends_at->lt($startOfToday)) {
            return false;
        }

        return $this->starts_at !== null || $this->ends_at !== null;
    }

    public function scopeCurrent(Builder $query): Builder
    {
        $startOfToday = now()->startOfDay();
        $endOfToday   = now()->endOfDay();

        return $query->where(function (Builder $q) use ($startOfToday, $endOfToday) {
            $q->where('is_current', true)
              ->orWhere(function (Builder $sub) use ($startOfToday, $endOfToday) {
                  $sub->where(function (Builder $w) use ($endOfToday) {
                      $w->whereNull('starts_at')->orWhere('starts_at', '<=', $endOfToday);
                  })->where(function (Builder $w) use ($startOfToday) {
                      $w->whereNull('ends_at')->orWhere('ends_at', '>=', $startOfToday);
                  })->where(function (Builder $w) {
                      $w->whereNotNull('starts_at')->orWhereNotNull('ends_at');
                  });
              });
        });
    }
}
