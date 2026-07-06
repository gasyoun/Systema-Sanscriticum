<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Проставляет «кто создал / кто изменил последним» на модель:
 * created_by_user_id при вставке, updated_by_user_id при вставке и правке.
 *
 * Источник — auth()->id(). Действия без авторизованного пользователя
 * (webhook Точки, импорт, CLI, очередь) оставляют поле null = «система».
 *
 * Колонки НЕ входят в $fillable намеренно: менеджер не должен подменить
 * авторство через mass-assignment. Значение ставится напрямую в событии.
 *
 * ВАЖНО: хуки creating/updating — read-only относительно денежной логики.
 * updated_by_user_id не входит ни в один dirty-чек денежного ядра
 * (Payment::booted, PaymentObserver, PaymentAuditObserver), поэтому
 * стамп не порождает лишних sheet-sync/аудит-строк и не задваивает fireOnPaid.
 */
trait TracksBlame
{
    public static function bootTracksBlame(): void
    {
        static::creating(function ($model): void {
            $id = auth()->id();
            // Не перетираем явно заданное авторство (импорт/сиды могут его знать).
            if ($id !== null && $model->created_by_user_id === null) {
                $model->created_by_user_id = $id;
            }
            if ($id !== null && $model->updated_by_user_id === null) {
                $model->updated_by_user_id = $id;
            }
        });

        static::updating(function ($model): void {
            $id = auth()->id();
            if ($id !== null) {
                $model->updated_by_user_id = $id;
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
