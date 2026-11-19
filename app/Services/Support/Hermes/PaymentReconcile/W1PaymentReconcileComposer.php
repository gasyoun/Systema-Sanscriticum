<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\PaymentReconcile;

/**
 * H6188: композиция READ-ONLY предложения сверки оплат — детерминированная
 * сводка из структурных фактов (суммы/счётчики/типы находок) без свободного
 * пользовательского текста, без PII и без LLM-вызовов: финансовое сырьё не
 * передаётся LLM (вызовов нет вообще), PII не достигает ничего.
 */
final class W1PaymentReconcileComposer
{
    /** Финальная строка каждого предложения (утверждённый контур ДЕН). */
    public const APPROVAL_LINE = 'Любое действие (возврат, списание, изменение доступа) — только с одобрения человека; автоматических действий нет.';

    /**
     * Предложение по сценарным данным: события/записи/находки.
     *
     * @param  list<W1PaymentReconcileFinding>  $findings
     */
    public function fromScenario(W1PaymentReconcileScenario $scenario, array $findings): W1PaymentReconcileProposal
    {
        $lines = [
            'Сверка оплат (роль 65 payment-reconciler): read-only предложение.',
            'События: '.$this->eventsLine($scenario).'; записи Systema: '.count($scenario->records).'.',
        ];

        if ($findings === []) {
            $lines[] = 'Находки: расхождений не найдено.';
            $lines[] = 'Предложение человеку: принять сводку к сведению.';
        } else {
            $lines[] = 'Находки:';
            foreach ($findings as $finding) {
                $lines[] = '- '.$finding->type.': '.$finding->detail.'.';
            }
            $lines[] = 'Предложение человеку: передать находки на ручной разбор.';
        }

        $lines[] = self::APPROVAL_LINE;

        return new W1PaymentReconcileProposal(implode("\n", $lines), $findings);
    }

    /**
     * Предложение по текстовому обращению: только статус и маршрут, входной
     * текст в предложение не переносится (H6182 fail-closed).
     *
     * @param  list<W1PaymentReconcileFinding>  $findings
     */
    public function fromMessage(string $lead, array $findings): W1PaymentReconcileProposal
    {
        $summary = implode("\n", [
            'Сверка оплат (роль 65 payment-reconciler): read-only предложение.',
            $lead,
            'Входной текст замаскирован (H6182 fail-closed) и в предложение не перенесён; LLM-вызовов нет.',
            self::APPROVAL_LINE,
        ]);

        return new W1PaymentReconcileProposal($summary, $findings);
    }

    private function eventsLine(W1PaymentReconcileScenario $scenario): string
    {
        $payments = 0;
        $refunds = 0;
        foreach ($scenario->events as $event) {
            if ($event['kind'] === W1PaymentReconcileScenario::KIND_REFUND) {
                $refunds++;
            } else {
                $payments++;
            }
        }

        return 'платежей '.$payments.' ('.$this->sumLine($scenario->events, W1PaymentReconcileScenario::KIND_PAYMENT)
            .'), возвратов '.$refunds.' ('.$this->sumLine($scenario->events, W1PaymentReconcileScenario::KIND_REFUND).')';
    }

    /**
     * @param  list<array{kind: string, amount: int, currency: string}>  $events
     */
    private function sumLine(array $events, string $kind): string
    {
        $sums = [];
        foreach ($events as $event) {
            if ($event['kind'] === $kind) {
                $sums[$event['currency']] = ($sums[$event['currency']] ?? 0) + $event['amount'];
            }
        }
        if ($sums === []) {
            return '0';
        }
        $parts = [];
        foreach ($sums as $currency => $sum) {
            $parts[] = $sum.' '.$currency;
        }

        return implode(' + ', $parts);
    }
}
