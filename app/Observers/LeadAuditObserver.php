<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Lead;
use App\Models\LeadAudit;
use App\Models\User;

/**
 * Пишет аудит операций с лидом: кто и что сделал с заявкой.
 * Зеркало PaymentAuditObserver для CRM-воронки.
 *
 * Действия без авторизованного пользователя (входящая заявка, импорт, CLI)
 * фиксируются как «Система».
 */
class LeadAuditObserver
{
    /**
     * Поля, изменения которых имеют смысл для воронки и попадают в лог.
     * Технические/маркетинговые (utm_*, ip, magnet_*) — шум, не аудируем.
     */
    private const AUDITED = [
        'status',
        'assigned_to',
        'next_contact_at',
        'name',
        'contact',
        'email',
    ];

    public function created(Lead $lead): void
    {
        $this->record($lead, LeadAudit::ACTION_CREATED, $this->snapshot($lead));
    }

    public function updated(Lead $lead): void
    {
        $diff = [];

        foreach (self::AUDITED as $field) {
            if (! $lead->wasChanged($field)) {
                continue;
            }
            $diff[$field] = [
                $this->scalar($lead->getOriginal($field)),
                $this->scalar($lead->getAttribute($field)),
            ];
        }

        // Поменялось только что-то незначимое (utm/updated_at) — лог не засоряем.
        if (empty($diff)) {
            return;
        }

        $this->record($lead, LeadAudit::ACTION_UPDATED, $diff);
    }

    public function deleted(Lead $lead): void
    {
        $this->record($lead, LeadAudit::ACTION_DELETED, $this->snapshot($lead));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function record(Lead $lead, string $action, array $changes): void
    {
        $admin = auth()->user();

        LeadAudit::create([
            'lead_id' => $lead->getKey(),
            'admin_id' => $admin instanceof User ? $admin->getKey() : null,
            'admin_name' => $admin instanceof User ? $admin->name : 'Система',
            'action' => $action,
            'changes' => $changes,
            'created_at' => now(),
        ]);
    }

    /**
     * Снимок значимых полей лида (для created/deleted).
     *
     * @return array<string, scalar|null>
     */
    private function snapshot(Lead $lead): array
    {
        $data = [];
        foreach (self::AUDITED as $field) {
            $data[$field] = $this->scalar($lead->getAttribute($field));
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
