<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CourseDebtChatRemoval;
use App\Services\Discipline\ChatRemovalCandidate;
use App\Services\Discipline\ChatRemovalEligibility;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Сухой прогон правила H2746: кто сегодня подлежит исключению из учебного
 * TG-чата за курсовой долг и молчание, и на какой взнос он потом попадает.
 *
 * Команда НИЧЕГО не меняет — ни в Telegram, ни в реестре. Это отчёт, который
 * оператор читает перед тем, как нажать кнопку. Отдельной автоматической
 * ветки в Wave 1 нет и по флагу не появляется.
 *
 * Личные данные по умолчанию скрыты (`user#123` вместо имени): отчёт часто
 * уезжает в лог, в чат и в тикет, а фамилия рядом с суммой долга — это ровно
 * то, чего в таких местах быть не должно. `--reveal` осознанно включает имена
 * для работы в админке.
 */
class ReportCourseDebtChatRemovals extends Command
{
    protected $signature = 'debts:chat-removal-report
        {--user= : только этот user_id}
        {--course= : только этот course_id}
        {--all : показать и тех, кто правило НЕ проходит, с причинами}
        {--reveal : печатать имена вместо user#id (по умолчанию скрыты)}
        {--json : машинный вывод вместо таблиц}';

    protected $description = 'Сухой прогон: кандидаты на исключение из учебного TG-чата за курсовой долг + молчание и взносы за возврат (H2746).';

    public function handle(ChatRemovalEligibility $eligibility): int
    {
        $now = Carbon::now();
        $onlyUser = $this->option('user') !== null ? (int) $this->option('user') : null;
        $onlyCourse = $this->option('course') !== null ? (int) $this->option('course') : null;

        $candidates = $eligibility->candidates($onlyUser, $onlyCourse, $now);
        $eligible = $candidates->filter(fn (ChatRemovalCandidate $c) => $c->isEligible())->values();
        $rejected = $candidates->reject(fn (ChatRemovalCandidate $c) => $c->isEligible())->values();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'generated_at' => $now->toIso8601String(),
                'rule' => [
                    'min_days_overdue' => $eligibility->minDaysOverdue(),
                    'min_unanswered_contacts' => $eligibility->minUnansweredContacts(),
                    'reinstatement_fee' => $eligibility->reinstatementFee(),
                    'currency' => config('chat_removal.currency', 'RUB'),
                    'auto_telegram_mutation' => (bool) config('chat_removal.auto_telegram_mutation', false),
                ],
                'eligible' => $eligible->map(fn (ChatRemovalCandidate $c) => $this->row($c))->all(),
                'rejected' => $this->option('all')
                    ? $rejected->map(fn (ChatRemovalCandidate $c) => $this->row($c))->all()
                    : [],
                'fee_arithmetic' => $this->feeArithmetic($eligible, $eligibility),
                'open_ledger' => $this->ledgerSummary(),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Правило H2746 — сухой прогон на '.$now->format('d.m.Y H:i'));
        $this->line(sprintf(
            '  порог: просрочка ≥ %d дн · подряд без ответа ≥ %d · взнос за чат %d %s · мутации Telegram: %s',
            $eligibility->minDaysOverdue(),
            $eligibility->minUnansweredContacts(),
            $eligibility->reinstatementFee(),
            (string) config('chat_removal.currency', 'RUB'),
            config('chat_removal.auto_telegram_mutation') ? 'ВКЛЮЧЕНЫ' : 'выключены (Wave 1)',
        ));
        $this->newLine();

        if ($eligible->isEmpty()) {
            $this->line('Подлежащих исключению нет.');
        } else {
            $this->line('<comment>Подлежат исключению (оператор решает и нажимает сам):</comment>');
            $this->table(
                ['студент', 'курс', 'чат', 'дн. просрочки', 'долг', 'молчит с', 'без ответа'],
                $eligible->map(fn (ChatRemovalCandidate $c) => [
                    $this->subject($c),
                    $c->courseTitle,
                    $c->chatLabel(),
                    $c->daysOverdue,
                    $c->debtAmount !== null ? number_format($c->debtAmount, 2, ',', ' ') : '—',
                    $c->evidence->silentSince?->format('d.m.Y') ?? '—',
                    $c->evidence->trailingUnanswered,
                ])->all(),
            );
        }

        $this->newLine();
        $this->line('<comment>Взносы за возврат (₽'.$eligibility->reinstatementFee().' × число чатов):</comment>');
        $arithmetic = $this->feeArithmetic($eligible, $eligibility);
        if ($arithmetic === []) {
            $this->line('  —');
        } else {
            $this->table(
                ['студент', 'чатов', 'арифметика', 'итого'],
                array_map(fn (array $r) => [
                    $r['subject'],
                    $r['chats'],
                    $r['arithmetic'],
                    $r['total'],
                ], $arithmetic),
            );
        }

        if ($this->option('all') && $rejected->isNotEmpty()) {
            $this->newLine();
            $this->line('<comment>НЕ проходят правило:</comment>');
            $this->table(
                ['студент', 'курс', 'чат', 'дн.', 'почему нет'],
                $rejected->map(fn (ChatRemovalCandidate $c) => [
                    $this->subject($c),
                    $c->courseTitle,
                    $c->chatLabel(),
                    $c->daysOverdue,
                    implode('; ', array_map(
                        ChatRemovalEligibility::blockerLabel(...),
                        $c->blockers,
                    )),
                ])->all(),
            );
        }

        $ledger = $this->ledgerSummary();
        $this->newLine();
        $this->line(sprintf(
            'Реестр: открытых эпизодов %d, из них ждут взноса %d на сумму %s %s.',
            $ledger['open'],
            $ledger['fee_outstanding_chats'],
            number_format($ledger['fee_outstanding_amount'], 2, ',', ' '),
            (string) config('chat_removal.currency', 'RUB'),
        ));

        if (! $this->option('all') && $rejected->isNotEmpty()) {
            $this->line('Отсеяно строк: '.$rejected->count().' (причины — с флагом --all).');
        }

        return self::SUCCESS;
    }

    private function subject(ChatRemovalCandidate $c): string
    {
        return $this->option('reveal')
            ? ($c->user->name ?: $c->user->email ?: $c->redactedSubject())
            : $c->redactedSubject();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ChatRemovalCandidate $c): array
    {
        return [
            'subject' => $this->subject($c),
            'user_id' => (int) $c->user->id,
            'course_id' => $c->courseId,
            'course' => $c->courseTitle,
            'chat_id' => $c->telegramChatId,
            'chat' => $c->chatLabel(),
            'days_overdue' => $c->daysOverdue,
            'debt_amount' => $c->debtAmount,
            'debt_blocks' => $c->debtBlockNumbers,
            'unanswered_contacts' => $c->evidence->trailingUnanswered,
            'contact_attempts' => $c->evidence->attempts,
            'silent_since' => $c->evidence->silentSince?->toIso8601String(),
            'blockers' => $c->blockers,
        ];
    }

    /**
     * Арифметика взноса, выписанная в столбик — чтобы «почему 3000» читалось
     * с листа, а не пересчитывалось оператором в уме.
     *
     * @param  Collection<int, ChatRemovalCandidate>  $eligible
     * @return list<array{subject: string, chats: int, arithmetic: string, total: int}>
     */
    private function feeArithmetic($eligible, ChatRemovalEligibility $eligibility): array
    {
        $fee = $eligibility->reinstatementFee();
        $out = [];

        foreach ($eligible->groupBy(fn (ChatRemovalCandidate $c) => (int) $c->user->id) as $rows) {
            $first = $rows->first();
            // Один студент может числиться должником по двум курсам одной
            // группы — чат при этом ОДИН, и взнос за него тоже один.
            $chats = $rows->pluck('telegramChatId')->unique()->count();
            $out[] = [
                'subject' => $this->subject($first),
                'chats' => $chats,
                'arithmetic' => $fee.' × '.$chats,
                'total' => $fee * $chats,
            ];
        }

        return $out;
    }

    /**
     * @return array{open: int, fee_outstanding_chats: int, fee_outstanding_amount: float}
     */
    private function ledgerSummary(): array
    {
        $outstanding = CourseDebtChatRemoval::query()
            ->feeOutstanding()
            ->whereNotNull('removed_at')
            ->get(['reinstatement_fee']);

        return [
            'open' => CourseDebtChatRemoval::query()->open()->count(),
            'fee_outstanding_chats' => $outstanding->count(),
            'fee_outstanding_amount' => (float) $outstanding->sum(fn ($r) => (float) $r->reinstatement_fee),
        ];
    }
}
