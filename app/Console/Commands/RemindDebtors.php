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
use App\Support\DunningStage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RemindDebtors extends Command
{
    protected $signature = 'debts:remind';

    protected $description = 'Авто-напоминания должникам (TG/VK/email): просрочка, не продлил, и предстоящий неоплаченный блок за N дней до начала.';

    private DebtorReminderDispatcher $dispatcher;

    private MarketingSetting $settings;

    private int $cadence;

    /** Считать ли ручной контакт основанием пропустить авто-напоминание (H3156). */
    private bool $manualSuppressesAuto;

    private bool $toTg;

    private bool $toVk;

    private bool $toEmail;

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
        $this->settings = $s;
        $lead = max(0, (int) ($s->debt_reminder_lead_days ?? 7));
        $this->cadence = max(1, (int) ($s->debt_reminder_cadence_days ?? 7));
        $this->manualSuppressesAuto = (bool) ($s->debt_reminder_manual_suppresses_auto ?? false);
        $this->toTg = (bool) $s->debt_reminder_to_telegram;
        $this->toVk = (bool) $s->debt_reminder_to_vk;
        $this->toEmail = (bool) $s->debt_reminder_to_email;
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
     * Дедуп по (user, course, block) в окне cadence + выбор стадии лестницы
     * (H1289) + отправка + запись лога. Стадия считается на момент отправки по
     * дедлайну блока; частоту по-прежнему задаёт cadence — эскалация происходит
     * естественно, следующим напоминанием после пересечения порога.
     */
    private function deliver(User $user, int $courseId, ?int $block): void
    {
        // Какие строки считаются «мы уже писали» для анти-спама (H3156).
        // Ручное сообщение куратора глушит следующее авто-напоминание ТОЛЬКО
        // если это включено явно: до H3156 ручная отправка вообще не оставляла
        // строки, и молча замедлить лестницу (а с ней и эскалацию
        // DunningStage) починкой отчёта H2746 было бы подменой политики.
        $recent = DebtReminder::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->when(! $this->manualSuppressesAuto, fn ($q) => $q->where('source', DebtReminder::SOURCE_AUTO))
            ->when($block !== null, fn ($q) => $q->where('block_number', $block))
            ->when($block === null, fn ($q) => $q->whereNull('block_number'))
            ->where('sent_at', '>=', $this->now->copy()->subDays($this->cadence))
            ->exists();

        if ($recent) {
            $this->skipped++;

            return;
        }

        $deadline = $this->blockDeadline($courseId, $block);
        $stage = DunningStage::fromDeadline($deadline, $this->now);
        $textTpl = ($this->settings->{$stage->settingTextKey()} ?? null) ?: $stage->defaultText();
        $subjectTpl = ($this->settings->{$stage->settingSubjectKey()} ?? null) ?: $stage->defaultSubject();

        $paidUntilLabel = $this->paidUntilLabel($user, $courseId);
        $deadlineLabel = $this->deadlineLabel($deadline);
        $ok = $this->dispatcher->send($user, $courseId, $block, $textTpl, $subjectTpl, $this->toTg, $this->toVk, $this->toEmail, $paidUntilLabel, $deadlineLabel);

        if ($ok) {
            DebtReminder::create([
                'user_id' => $user->id,
                'course_id' => $courseId,
                'block_number' => $block,
                'sent_at' => $this->now,
                'source' => DebtReminder::SOURCE_AUTO,
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
     * в шаблон стадии — см. MessagePlaceholders::forUser.
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
        // Пропущенный блок не отменяет более поздних оплат («пропустил 64 —
        // оплатил 65») — не делаем вид, что их нет.
        if (! empty($paidUntil->extra_paid_blocks)) {
            $label .= ' Отдельно оплачены блоки '.$paidUntil->extra_paid_blocks_label.'.';
        }

        return $label.'.';
    }

    /**
     * Дедлайн платежа за блок: 00:00 по Москве в день старта блока (MG rule:
     * «до дня старта следующего модуля, до 00:00 по Москве»). Берём starts_at
     * именно этого блока напрямую, а не next_block из paidUntilForUser — та
     * молчит, если у студента вообще не было ни одной реальной оплаты
     * (частый случай для дебиторов), а тут дедлайн есть всегда, раз есть
     * $blockNumber. Приложение работает в Europe/Moscow
     * (config('app.timezone')), поэтому Carbon уже в нужном поясе.
     */
    private function blockDeadline(int $courseId, ?int $blockNumber): ?Carbon
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

        return $block->starts_at->copy()->startOfDay();
    }

    /**
     * Фрагмент {deadline}, честный по времени: будущий дедлайн — «оплатить
     * нужно до…», прошедший — «срок оплаты истек…» (писать должнику стадии
     * 3–4 «нужно до <прошедшая дата>» — бессмыслица, ломающая доверие).
     */
    private function deadlineLabel(?Carbon $deadline): ?string
    {
        if ($deadline === null) {
            return null;
        }

        if ($deadline->gt($this->now)) {
            return ' Оплатить нужно до '.$deadline->format('d.m.Y').', 00:00 (МСК).';
        }

        return ' Срок оплаты истек '.$deadline->format('d.m.Y').'.';
    }
}
