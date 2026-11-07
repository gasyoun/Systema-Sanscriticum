<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PaymentAudit;
use App\Services\Promises\PromiseGrantClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * H7268 — append-only восстановление следа создания у строк «доступ под обещание».
 *
 * WHY. ConditionalAccessGranter создаёт строку через Payment::withoutEvents()
 * (иначе на каждый блок улетало бы отдельное TG-сообщение), поэтому у неё нет
 * `created`-снимка в payment_audits. Строка при этом читается как status=paid —
 * аудитор видит «оплачено» без объяснения. Пробел документирован в
 * docs/money-access-core-manual.md §640, но контракт P1 требует, чтобы у каждого
 * платёжного факта был след.
 *
 * WHAT. Пишется РОВНО ОДНА строка payment_audits на платёж: action=created,
 * снимок значимых полей + поле `audit_backfill` с основанием. Сама строка
 * payments НЕ обновляется (никакого UPDATE), доступ (group_user / course_user /
 * lesson_access_grants) НЕ трогается, деньги (суммы, deposit, salary) НЕ
 * двигаются. Поэтому операция безопасна и обратима только новой записью —
 * payment_audits append-only.
 *
 * WHEN NOT. Строки, у которых классификатор нашёл дефект или вопрос к человеку
 * (is_conditional потерян, статус не выдаёт доступ, сумма не 0, ссылка на
 * чужое обещание и т.п.), пропускаются: их починка меняет деньги или доступ и
 * требует человека. Команда никогда не «исправляет» их молча.
 *
 * Idempotent: повторный --apply не пишет второй след.
 */
class BackfillPromiseGrantAudits extends Command
{
    private const ACTOR = 'Система: promise_grant backfill (H7268)';

    protected $signature = 'payments:backfill-promise-grant-audits
        {--apply : записать снимки (по умолчанию — сухой прогон)}
        {--id=* : ограничить конкретными id платежей}';

    protected $description = 'H7268: восстановить append-only created-снимок у контрактных строк promise_grant_#<id>; деньги и доступ не меняются.';

    public function handle(PromiseGrantClassifier $classifier): int
    {
        $apply = (bool) $this->option('apply');
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        $rows = $classifier->candidates($ids !== [] ? $ids : null);

        $this->info($apply ? 'Режим: APPLY (только payment_audits)' : 'Режим: СУХОЙ ПРОГОН (без записи)');

        if ($rows->isEmpty()) {
            $this->info('Строк promise_grant_#<id> не найдено.');

            return self::SUCCESS;
        }

        $written = 0;
        $skipped = 0;
        $already = 0;

        foreach ($rows as $payment) {
            $result = $classifier->classify($payment);

            if ($result['repair'] !== 'audit_backfill') {
                if ($result['verdict'] === PromiseGrantClassifier::VERDICT_LEGIT) {
                    $already++;

                    continue;
                }

                $this->warn(sprintf(
                    '#%d пропущен (%s): %s — починка меняет деньги или доступ, нужен человек.',
                    $result['payment_id'],
                    $result['verdict'],
                    implode(', ', array_column($result['findings'], 'code')),
                ));
                $skipped++;

                continue;
            }

            if (! $apply) {
                $this->line(sprintf('#%d — будет записан created-снимок (dry-run).', $result['payment_id']));
                $written++;

                continue;
            }

            $created = DB::transaction(function () use ($payment): bool {
                $exists = PaymentAudit::query()
                    ->where('payment_id', $payment->getKey())
                    ->where('admin_name', self::ACTOR)
                    ->exists();

                if ($exists) {
                    return false;
                }

                PaymentAudit::create([
                    'payment_id' => $payment->getKey(),
                    'admin_id' => null,
                    'admin_name' => self::ACTOR,
                    'action' => PaymentAudit::ACTION_CREATED,
                    'amount' => $payment->amount,
                    'changes' => [
                        'user_id' => $payment->user_id,
                        'course_id' => $payment->course_id,
                        'amount' => (string) $payment->amount,
                        'tariff' => $payment->tariff,
                        'status' => $payment->status,
                        'start_block' => $payment->start_block,
                        'end_block' => $payment->end_block,
                        'transaction_id' => $payment->transaction_id,
                        'is_conditional' => (bool) $payment->is_conditional,
                        'audit_backfill' => 'H7268: снимок восстановлен задним числом; строка создана '
                            .'ConditionalAccessGranter через withoutEvents. Деньги и доступ не менялись.',
                    ],
                    'created_at' => now(),
                ]);

                return true;
            });

            if ($created) {
                $this->line(sprintf('#%d — created-снимок записан.', $result['payment_id']));
                $written++;
            } else {
                $this->line(sprintf('#%d — снимок уже есть, пропуск.', $result['payment_id']));
                $already++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: записано %d, уже было %d, пропущено к человеку %d. payments не обновлялись.',
            $apply ? 'APPLY' : 'DRY-RUN',
            $written,
            $already,
            $skipped,
        ));

        return self::SUCCESS;
    }
}
