<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Аудит правок библиотеки шаблонов сообщений: кто, когда и что поменял
 * (H1932). Зеркало {@see LeadAudit} — append-only, вручную не правится.
 * Заведён вместе с открытием редактирования кураторам (manager): библиотека
 * питает суггестер (H1838) и рассылки, поэтому у каждого текста должна быть
 * история авторства.
 *
 * Действия без авторизованного пользователя (tinker, сиды, CLI) фиксируются
 * как «Система».
 */
class MessageTemplateAudit extends Model
{
    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DELETED = 'deleted';

    /** Только момент записи; updated_at не нужен — лог неизменяем. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'message_template_id',
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
        'title' => 'Название',
        'body' => 'Текст',
        'category' => 'Категория',
        'suggester_category' => 'Категория суггестера',
        'is_active' => 'Активен',
    ];

    /**
     * Шаблон может быть уже удалён — связь без ограничений, может вернуть null.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
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
     * - created/deleted: снимок полей;
     * - updated: «Поле: было → стало».
     */
    public function summary(): string
    {
        // ВАЖНО: именно getAttribute — `$this->changes` внутри класса попадает
        // в protected-свойство Eloquent HasAttributes::$changes (внутренний
        // трекинг dirty-синка), которое на перечитанной из БД модели пусто.
        $changes = $this->getAttribute('changes') ?? [];
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

    /** Нормализуем значение к строке для показа (категории — человекочитаемо, тело — усечённо). */
    private function display(string $field, mixed $value): string
    {
        if ($field === 'category' && is_string($value) && isset(MessageTemplate::categories()[$value])) {
            return MessageTemplate::categories()[$value];
        }

        if ($field === 'suggester_category' && is_string($value) && isset(MessageTemplate::suggesterCategories()[$value])) {
            return MessageTemplate::suggesterCategories()[$value];
        }

        if ($field === 'body' && is_string($value) && mb_strlen($value) > 120) {
            return mb_substr($value, 0, 120).'…';
        }

        return match (true) {
            $value === null || $value === '' => '∅',
            is_bool($value) => $value ? 'да' : 'нет',
            default => (string) $value,
        };
    }
}
