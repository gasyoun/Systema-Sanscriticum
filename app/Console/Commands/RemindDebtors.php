<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CourseBlock;
use App\Models\DebtReminder;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\User;
use App\Services\DebtorReminderDispatcher;
use App\Services\DebtorsReport;
use App\Services\StudentDebtsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RemindDebtors extends Command
{
    protected $signature = 'debts:remind';

    protected $description = 'Авто-напоминания должникам (TG/VK/email): просрочка, не продлил, и предстоящий неоплаченный блок за N дней до начала.';

    private DebtorReminderDispatcher $dispatcher;

    private int $cadence;

    private bool $toTg;

    private bool $toVk;

    private bool $toEmail;

    private string $text;

    private string $subject;

    private Carbon $now;

    private int $sent = 0;

    private int $skipped = 0;

    public function handle(DebtorReminderDispatcher $dispatcher): int
    {
        $s = MarketingSetting::cached();
        if (! $s || ! $s->debt_reminders_enabled) {
            $this->info('Авто-напоминания должникам отключены — пропуск.');

            return self::SUCCESS;
        }

        $this->dispatcher = $dispatcher;
        $lead = max(0, (int) ($s->debt_reminder_lead_days ?? 7));
        $this->cadence = max(1, (int) ($s->debt_reminder_cadence_days ?? 7));
        $this->toTg = (bool) $s->debt_reminder_to_telegram;
        $this->toVk = (bool) $s->debt_reminder_to_vk;
        $this->toEmail = (bool) $s->debt_reminder_to_email;
        $this->text = $s->debt_reminder_text ?: DebtorReminderDispatcher::DEFAULT_TEXT;
        $this->subject = $s->debt_reminder_subject ?: DebtorReminderDispatcher::DEFAULT_SUBJECT;
        $this->now = now();

        $this->remindCurrentDebtors($lead);   // просрочка / не продлил / непокрытый ref-блок
        $this->remindUpcomingBlocks($lead);    // следующий блок скоро стартует, не оплачен

        $this->info("Напоминания должникам: отправлено {$this->sent}, пропущено {$this->skipped}.");

        return self::SUCCESS;
    }

    /**
     * Проход 1: текущие должники из DebtorsReport (та же выборка, что в разделе
     * «Должники»). Реф-блок уже идёт/просрочен — напоминаем; если реф-блок ещё
     * не начался и до старта дальше окна lead — рано, пропускаем.
     */
    private function remindCurrentDebtors(int $lead): void
    {
        $report = app(DebtorsReport::class)->forYears([]);
        $refBlocks = $report->referenceBlocks(); // course_id => CourseBlock

        // У одного студента может быть несколько строк (курсов) — get() корректнее
        // chunkById (тот по users.id рискует пропустить дубль-id на границе чанка).
        foreach ($report->query()->get() as $row) {
            $courseId = (int) $row->course_id;
            $startsAt = $refBlocks->get($courseId)?->starts_at;

            // Предстоящий реф-блок дальше окна lead и не просрочен → рано напоминать.
            if ($startsAt !== null && $startsAt->gt($this->now) && $this->now->diffInDays($startsAt) > $lead) {
                $this->skipped++;

                continue;
            }

            $block = $row->ref_block_number !== null ? (int) $row->ref_block_number : null;
            $this->deliver($row, $courseId, $block);
        }
    }

    /**
     * Проход 2: предстоящие блоки, стартующие в ближайшие lead дней, которые
     * студент ещё не оплатил. Покрывает кейс «текущий блок оплачен, следующий
     * скоро — пора платить» (его проход 1 не ловит, т.к. реф-блок = текущий).
     */
    private function remindUpcomingBlocks(int $lead): void
    {
        $upcoming = CourseBlock::query()
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [$this->now, $this->now->copy()->addDays($lead)])
            ->whereHas('course', fn ($q) => $q->where('is_active', true))
            ->get();

        foreach ($upcoming as $block) {
            $courseId = (int) $block->course_id;
            $number = (int) $block->number;

            // Студенты курса (через группы), не покинувшие/не льготники.
            $userIds = DB::table('group_user as gu')
                ->join('course_group as cg', 'cg.group_id', '=', 'gu.group_id')
                ->where('cg.course_id', $courseId)
                ->pluck('gu.user_id')
                ->unique()
                ->values();

            if ($userIds->isEmpty()) {
                continue;
            }

            $terminal = DB::table('course_user')
                ->where('course_id', $courseId)
                ->whereIn('user_id', $userIds)
                ->whereIn('status', DebtorsReport::NON_DEBT_STATUSES)
                ->pluck('user_id')
                ->flip();

            // Покрывающие оплаты этого курса (реальные, не conditional).
            $paymentsByUser = Payment::query()
                ->where('course_id', $courseId)
                ->whereIn('user_id', $userIds)
                ->whereIn('status', Payment::PAID_STATUSES)
                ->where('is_conditional', false)
                ->whereNotIn('tariff', ['Расход', 'salary_payout'])
                ->get(['user_id', 'start_block', 'end_block'])
                ->groupBy('user_id');

            $users = User::query()->whereIn('id', $userIds)->get()->keyBy('id');

            foreach ($userIds as $uid) {
                if ($terminal->has($uid)) {
                    continue;
                }
                $user = $users->get($uid);
                if (! $user) {
                    continue;
                }

                // Уже покрыт оплатой за этот блок? → не должник по нему.
                $covered = ($paymentsByUser->get($uid) ?? collect())
                    ->contains(fn ($p) => DebtorsReport::paymentCovers($p->start_block, $p->end_block, $number));
                if ($covered) {
                    continue;
                }

                $this->deliver($user, $courseId, $number);
            }
        }
    }

    /**
     * Дедуп по (user, course, block) в окне cadence + отправка + запись лога.
     */
    private function deliver(User $user, int $courseId, ?int $block): void
    {
        $recent = DebtReminder::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->when($block !== null, fn ($q) => $q->where('block_number', $block))
            ->when($block === null, fn ($q) => $q->whereNull('block_number'))
            ->where('sent_at', '>=', $this->now->copy()->subDays($this->cadence))
            ->exists();

        if ($recent) {
            $this->skipped++;

            return;
        }

        $paidUntilLabel = $this->paidUntilLabel($user, $courseId);
        $deadlineLabel = $this->deadlineLabel($courseId, $block);
        $ok = $this->dispatcher->send($user, $courseId, $block, $this->text, $this->subject, $this->toTg, $this->toVk, $this->toEmail, $paidUntilLabel, $deadlineLabel);

        if ($ok) {
            DebtReminder::create([
                'user_id' => $user->id,
                'course_id' => $courseId,
                'block_number' => $block,
                'sent_at' => $this->now,
            ]);
            $this->sent++;
        } else {
            $this->skipped++; // нет доступных каналов у студента
        }
    }

    /**
     * «Оплачено до» для {paid_until}: только у тех, кто хоть раз платил
     * реально (не conditional) — иначе нечего показывать («не продлил» это
     * не отличит от «никогда не платил» без этого расчёта). Готовое
     * предложение-фрагмент (ведущий пробел + точка) для прямой конкатенации
     * в DEFAULT_TEXT — см. MessagePlaceholders::forUser.
     */
    private function paidUntilLabel(User $user, int $courseId): ?string
    {
        $paidUntil = app(StudentDebtsService::class)
            ->paidUntilForUser($user, [$courseId])
            ->get($courseId);

        if ($paidUntil === null) {
            return null;
        }

        $label = ' Предыдущая оплата покрывала до блока №'.$paidUntil->block->number;
        if ($paidUntil->block->ends_at) {
            $label .= ' (до '.$paidUntil->block->ends_at->format('d.m.Y').')';
        }

        return $label.'.';
    }

    /**
     * Дедлайн следующего платежа для {deadline}: 00:00 по Москве в день
     * старта блока {$block}, за который сейчас напоминаем (MG rule: «до дня
     * старта следующего модуля, до 00:00 по Москве»). Берём starts_at именно
     * этого блока напрямую, а не next_block из paidUntilForUser — она
     * молчит, если у студента вообще не было ни одной реальной оплаты
     * (частый случай для дебиторов), а тут дедлайн есть всегда, раз есть
     * $block. Приложение работает в Europe/Moscow (config('app.timezone')),
     * поэтому Carbon уже в нужном поясе без ручной конвертации.
     */
    private function deadlineLabel(int $courseId, ?int $blockNumber): ?string
    {
        if ($blockNumber === null) {
            return null;
        }

        $block = CourseBlock::query()
            ->where('course_id', $courseId)
            ->where('number', $blockNumber)
            ->first(['starts_at']);

        if (! $block || ! $block->starts_at) {
            return null;
        }

        $deadline = $block->starts_at->copy()->startOfDay();

        return ' Оплатить нужно до '.$deadline->format('d.m.Y').', 00:00 (МСК).';
    }
}
