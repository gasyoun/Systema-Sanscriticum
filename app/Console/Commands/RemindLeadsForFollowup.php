<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendTelegramMessageJob;
use App\Models\Lead;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Будит поле next_contact_at: раз в день напоминает КАЖДОМУ ответственному
 * менеджеру о его заявках, по которым наступил (или прошёл) намеченный срок
 * контакта и которые ещё в работе. Пинг уходит менеджеру в Telegram одним
 * списком; reminded_at не даёт слать повторно в тот же день.
 *
 * Гейт — фича-флаг crm_reminders (CRM_REMINDERS). Пока выключен — no-op,
 * чтобы можно было отладить каденс/текст прежде, чем начать слать людям.
 */
class RemindLeadsForFollowup extends Command
{
    protected $signature = 'leads:remind-followup';

    protected $description = 'Напоминает менеджерам о заявках с наступившим сроком следующего контакта (next_contact_at).';

    /** Статусы, по которым лид ещё «живой» и требует контакта. */
    private const ACTIONABLE_STATUSES = ['new', 'in_work', 'qualified'];

    public function handle(): int
    {
        if (! config('features.crm_reminders')) {
            $this->info('CRM-напоминания выключены (CRM_REMINDERS) — пропуск.');

            return self::SUCCESS;
        }

        $today = now()->startOfDay();

        $leads = Lead::query()
            ->whereNotNull('assigned_to')
            ->whereIn('status', self::ACTIONABLE_STATUSES)
            ->whereNotNull('next_contact_at')
            ->whereDate('next_contact_at', '<=', $today->toDateString())
            // Уже напоминали сегодня — не дублируем.
            ->where(function ($q) use ($today): void {
                $q->whereNull('reminded_at')
                    ->orWhereDate('reminded_at', '<', $today->toDateString());
            })
            ->with('assignee')
            ->orderBy('next_contact_at')
            ->get()
            ->groupBy('assigned_to');

        $managersPinged = 0;
        $leadsReminded = 0;
        $unreachable = 0;

        foreach ($leads as $group) {
            $assignee = $group->first()->assignee;

            // Менеджер без привязанного Telegram недостижим — не стампим, чтобы
            // напоминание всплыло снова, когда он подключит бота.
            if ($assignee === null || empty($assignee->telegram_id)) {
                $unreachable += $group->count();

                continue;
            }

            SendTelegramMessageJob::dispatch((int) $assignee->id, $this->buildMessage($group, $today));

            // Mass-update: без Eloquent-событий (аудит/blame не нужны на системный стамп).
            Lead::query()->whereIn('id', $group->pluck('id'))->update(['reminded_at' => now()]);

            $managersPinged++;
            $leadsReminded += $group->count();
        }

        $this->info("Напоминания: менеджеров {$managersPinged}, лидов {$leadsReminded}, недостижимо {$unreachable}.");

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Lead>  $group
     */
    private function buildMessage(\Illuminate\Support\Collection $group, Carbon $today): string
    {
        $lines = [
            '🔔 <b>Заявки на сегодня</b>',
            '',
            'Намасте! По этим заявкам наступил намеченный вами срок связаться:',
            '',
        ];

        foreach ($group as $lead) {
            /** @var Lead $lead */
            $name = e((string) ($lead->name ?: 'Без имени'));
            $contact = $lead->contact ? ' — '.e((string) $lead->contact) : '';
            $status = Lead::STATUSES[$lead->status] ?? $lead->status;
            $due = $lead->next_contact_at?->format('d.m.Y');
            $overdue = $lead->next_contact_at && $lead->next_contact_at->startOfDay()->lt($today)
                ? ' ⚠️ просрочено'
                : '';

            $lines[] = "• <b>{$name}</b>{$contact} · {$status} · до {$due}{$overdue}";
        }

        $lines[] = '';
        $lines[] = '👉 <a href="'.url('/admin/leads').'">Открыть заявки</a>';

        return implode("\n", $lines);
    }
}
