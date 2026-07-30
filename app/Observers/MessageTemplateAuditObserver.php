<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\MessageTemplate;
use App\Models\MessageTemplateAudit;
use App\Models\User;

/**
 * Пишет аудит правок библиотеки шаблонов: кто и что поменял (H1932).
 * Зеркало {@see LeadAuditObserver} — заведён вместе с открытием
 * редактирования кураторам (manager): библиотека питает FAQ-суггестер (H1838)
 * и рассылки, поэтому каждое изменение текста должно иметь автора и историю.
 *
 * Действия без авторизованного пользователя (tinker, сиды, CLI)
 * фиксируются как «Система».
 */
class MessageTemplateAuditObserver
{
    /** Все содержательные поля шаблона — аудируем целиком (шума в модели нет). */
    private const AUDITED = [
        'title',
        'body',
        'category',
        'suggester_category',
        'is_active',
    ];

    public function created(MessageTemplate $template): void
    {
        $this->record($template, MessageTemplateAudit::ACTION_CREATED, $this->snapshot($template));
    }

    public function updated(MessageTemplate $template): void
    {
        $diff = [];

        foreach (self::AUDITED as $field) {
            if (! $template->wasChanged($field)) {
                continue;
            }
            $diff[$field] = [
                $this->scalar($template->getOriginal($field)),
                $this->scalar($template->getAttribute($field)),
            ];
        }

        // Поменялся только updated_at (touch) — лог не засоряем.
        if (empty($diff)) {
            return;
        }

        $this->record($template, MessageTemplateAudit::ACTION_UPDATED, $diff);
    }

    public function deleted(MessageTemplate $template): void
    {
        $this->record($template, MessageTemplateAudit::ACTION_DELETED, $this->snapshot($template));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function record(MessageTemplate $template, string $action, array $changes): void
    {
        $admin = auth()->user();

        MessageTemplateAudit::create([
            'message_template_id' => $template->getKey(),
            'admin_id' => $admin instanceof User ? $admin->getKey() : null,
            'admin_name' => $admin instanceof User ? $admin->name : 'Система',
            'action' => $action,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }

    /**
     * Снимок значимых полей шаблона (для created/deleted).
     *
     * @return array<string, scalar|null>
     */
    private function snapshot(MessageTemplate $template): array
    {
        $data = [];
        foreach (self::AUDITED as $field) {
            $data[$field] = $this->scalar($template->getAttribute($field));
        }

        return $data;
    }

    /** Приводим значение к скаляру, пригодному для JSON. */
    private function scalar(mixed $value): string|int|float|bool|null
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_scalar($value) || $value === null ? $value : (string) $value;
    }
}
