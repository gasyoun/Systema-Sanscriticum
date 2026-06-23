<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DebtReminder;
use App\Models\MarketingSetting;
use App\Services\DebtorReminderDispatcher;
use App\Services\DebtorsReport;
use Illuminate\Console\Command;

class RemindDebtors extends Command
{
    protected $signature = 'debts:remind';

    protected $description = 'Авто-напоминания должникам (TG/VK/email): просрочка, не продлил, и блок за N дней до начала.';

    public function handle(DebtorReminderDispatcher $dispatcher): int
    {
        $s = MarketingSetting::cached();
        if (! $s || ! $s->debt_reminders_enabled) {
            $this->info('Авто-напоминания должникам отключены — пропуск.');

            return self::SUCCESS;
        }

        $lead = max(0, (int) ($s->debt_reminder_lead_days ?? 7));
        $cadence = max(1, (int) ($s->debt_reminder_cadence_days ?? 7));
        $toTg = (bool) $s->debt_reminder_to_telegram;
        $toVk = (bool) $s->debt_reminder_to_vk;
        $toEmail = (bool) $s->debt_reminder_to_email;
        $text = $s->debt_reminder_text ?: DebtorReminderDispatcher::DEFAULT_TEXT;
        $subject = $s->debt_reminder_subject ?: DebtorReminderDispatcher::DEFAULT_SUBJECT;

        $report = app(DebtorsReport::class)->forYears([]);
        $refBlocks = $report->referenceBlocks(); // course_id => CourseBlock

        $now = now();
        $sent = 0;
        $skipped = 0;

        // Должники (user+course) — та же выборка, что в разделе «Должники».
        // У одного студента может быть несколько строк (курсов) — get() корректнее
        // chunkById (тот по users.id рискует пропустить дубль-id на границе чанка).
        foreach ($report->query()->get() as $row) {
            $courseId = (int) $row->course_id;
            $block = $refBlocks->get($courseId);
            $startsAt = $block?->starts_at;

            // Предстоящий блок дальше окна lead и не просрочен → рано напоминать.
            if ($startsAt !== null && $startsAt->gt($now) && $now->diffInDays($startsAt) > $lead) {
                $skipped++;

                continue;
            }

            // Анти-спам: не чаще раза в cadence дней по паре (студент, курс).
            $recent = DebtReminder::query()
                ->where('user_id', $row->id)
                ->where('course_id', $courseId)
                ->where('sent_at', '>=', $now->copy()->subDays($cadence))
                ->exists();
            if ($recent) {
                $skipped++;

                continue;
            }

            $blockNumber = $row->ref_block_number !== null ? (int) $row->ref_block_number : null;

            $ok = $dispatcher->send($row, $courseId, $blockNumber, $text, $subject, $toTg, $toVk, $toEmail);

            if ($ok) {
                DebtReminder::create([
                    'user_id' => $row->id,
                    'course_id' => $courseId,
                    'sent_at' => $now,
                ]);
                $sent++;
            } else {
                $skipped++; // нет доступных каналов у студента
            }
        }

        $this->info("Напоминания должникам: отправлено {$sent}, пропущено {$skipped}.");

        return self::SUCCESS;
    }
}
