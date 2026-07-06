<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Аудит операций с лидом в CRM: кто (admin/manager) и что сделал с заявкой.
 * Зеркало PaymentAudit для воронки продаж — append-only, вручную не правится.
 *
 * Действия без авторизованного пользователя (входящая заявка с лендинга,
 * импорт, CLI) фиксируются как «Система».
 */
class LeadAudit extends Model
{
    use HasFactory;

    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DELETED = 'deleted';

    /** Только момент записи; updated_at не нужен — лог неизменяем. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'lead_id',
        'admin_id',
        'admin_name',
        'action',
        'changes',
        'created_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Человекочитаемые имена аудируемых полей — для сводки в админке.
     */
    public const FIELD_LABELS = [
        'status' => 'Статус',
        'assigned_to' => 'Ответственный (ID)',
        'next_contact_at' => 'Следующий контакт',
        'name' => 'Имя',
        'contact' => 'Контакт',
        'email' => 'Email',
    ];

    /**
     * Лид может быть уже удалён — связь без ограничений, может вернуть null.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_CREATED => 'Создал',
            self::ACTION_UPDATED => 'Изменил',
            self::ACTION_DELETED => 'Удалил',
            default => $this->action,
        };
    }

    /**
     * Человекочитаемая сводка изменений.
     * - created/deleted: «Статус: new, Имя: Иван …» (снимок полей)
     * - updated: «Статус: new → in_work» (было → стало)
     */
    public function summary(): string
    {
        $changes = $this->changes ?? [];
        if (empty($changes)) {
            return '—';
        }

        $parts = [];
        foreach ($changes as $field => $value) {
            $label = self::FIELD_LABELS[$field] ?? $field;

            // updated хранит пару [old, new]; created/deleted — скаляр-снимок.
            if (is_array($value) && array_key_exists(0, $value) && array_key_exists(1, $value)) {
                $parts[] = sprintf('%s: %s → %s', $label, $this->display($field, $value[0]), $this->display($field, $value[1]));
            } else {
                $parts[] = sprintf('%s: %s', $label, $this->display($field, $value));
            }
        }

        return implode("\n", $parts);
    }

    /** Нормализуем значение поля к строке для показа (статус — человекочитаемо). */
    private function display(string $field, mixed $value): string
    {
        if ($field === 'status' && is_string($value) && isset(Lead::STATUSES[$value])) {
            return Lead::STATUSES[$value];
        }

        return match (true) {
            $value === null || $value === '' => '∅',
            is_bool($value) => $value ? 'да' : 'нет',
            default => (string) $value,
        };
    }
}
