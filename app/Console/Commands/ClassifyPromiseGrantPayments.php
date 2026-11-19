<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Promises\PromiseGrantClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * H7268 — отчёт по строкам «доступ под обещание» (`promise_grant_#<id>`).
 *
 * Только чтение: ни платежей, ни аудита, ни групп команда не трогает.
 * Ненулевой exit — сигнал, что строка нарушает контракт; починка, меняющая
 * деньги/доступ, в эту команду не входит (см. payments:backfill-promise-grant-audits
 * для безопасной части — восстановления append-only снимка).
 */
class ClassifyPromiseGrantPayments extends Command
{
    protected $signature = 'payments:classify-promise-grants
        {--id=* : ограничить конкретными id платежей}
        {--json= : записать полный отчёт в файл JSON}';

    protected $description = 'H7268: read-only классификация строк promise_grant_#<id> против контракта доступа под обещание.';

    public function handle(PromiseGrantClassifier $classifier): int
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        $rows = $classifier->candidates($ids !== [] ? $ids : null);

        if ($rows->isEmpty()) {
            $this->info('Строк promise_grant_#<id> не найдено.');

            return self::SUCCESS;
        }

        $before = $this->proofCounters();

        $report = [];
        $counts = [
            PromiseGrantClassifier::VERDICT_LEGIT => 0,
            PromiseGrantClassifier::VERDICT_LEGIT_SILENT => 0,
            PromiseGrantClassifier::VERDICT_HUMAN => 0,
            PromiseGrantClassifier::VERDICT_DEFECT => 0,
        ];

        $table = [];
        foreach ($rows as $payment) {
            $result = $classifier->classify($payment);
            $report[] = $result;
            $counts[$result['verdict']] = ($counts[$result['verdict']] ?? 0) + 1;

            $table[] = [
                $result['payment_id'],
                $result['linked_promise_id'] ?? '—',
                number_format((float) $payment->amount, 2, '.', ''),
                (string) $payment->status,
                $payment->is_conditional ? 'да' : 'НЕТ',
                $result['verdict'],
                implode(', ', array_column($result['findings'], 'code')) ?: '—',
            ];
        }

        $this->table(
            ['payment', 'promise', 'amount', 'status', 'conditional', 'verdict', 'findings'],
            $table,
        );

        $after = $this->proofCounters();

        $this->newLine();
        $this->line(sprintf(
            'Итог: контракт соблюдён %d, без следа создания %d, к человеку %d, дефектов %d.',
            $counts[PromiseGrantClassifier::VERDICT_LEGIT],
            $counts[PromiseGrantClassifier::VERDICT_LEGIT_SILENT],
            $counts[PromiseGrantClassifier::VERDICT_HUMAN],
            $counts[PromiseGrantClassifier::VERDICT_DEFECT],
        ));
        $this->line(sprintf(
            'Доказательство «только чтение»: payments=%d→%d, payment_audits=%d→%d, group_user=%d→%d, course_user=%d→%d.',
            $before['payments'], $after['payments'],
            $before['payment_audits'], $after['payment_audits'],
            $before['group_user'], $after['group_user'],
            $before['course_user'], $after['course_user'],
        ));

        foreach ($report as $result) {
            foreach ($result['findings'] as $finding) {
                if ($finding['severity'] === PromiseGrantClassifier::SEVERITY_INFO) {
                    continue;
                }
                $this->warn(sprintf(
                    '#%d [%s] %s — %s',
                    $result['payment_id'],
                    $finding['severity'],
                    $finding['code'],
                    $finding['message'],
                ));
            }
        }

        if (is_string($this->option('json')) && $this->option('json') !== '') {
            $path = (string) $this->option('json');
            file_put_contents($path, json_encode([
                'generated_at' => now()->toIso8601String(),
                'counts' => $counts,
                'read_only_proof' => ['before' => $before, 'after' => $after],
                'rows' => $report,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            $this->line('JSON: '.$path);
        }

        $failed = $before !== $after
            || $counts[PromiseGrantClassifier::VERDICT_DEFECT] > 0
            || $counts[PromiseGrantClassifier::VERDICT_HUMAN] > 0;

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Счётчики строк — необязательное доказательство отсутствия записи.
     *
     * @return array{payments: int, payment_audits: int, group_user: int, course_user: int}
     */
    private function proofCounters(): array
    {
        return [
            'payments' => (int) DB::table('payments')->count(),
            'payment_audits' => (int) DB::table('payment_audits')->count(),
            'group_user' => (int) DB::table('group_user')->count(),
            'course_user' => (int) DB::table('course_user')->count(),
        ];
    }
}
