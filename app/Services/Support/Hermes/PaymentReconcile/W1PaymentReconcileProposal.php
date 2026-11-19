<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\PaymentReconcile;

/**
 * H6188: READ-ONLY предложение сверки оплат, адресованное человеку.
 *
 * Инварианты (класс money, миссия H6188):
 *  - строго read-only: предложение не выполняет и не предлагает никаких
 *    денежных действий — {@see $actions} всегда пуст;
 *  - каждое действие по сводке — только с одобрения человека
 *    ({@see $requiresHumanApproval} всегда true);
 *  - разрешённый контур доставки — human-send (H6183, первые 30 дней
 *    отправляет человек); исходящих отправок роль не делает;
 *  - текст составляется детерминированно из структурных фактов сценария
 *    (суммы/счётчики/типы находок) БЕЗ свободного пользовательского текста
 *    и БЕЗ LLM-вызовов: финансовое сырьё не передаётся LLM.
 */
final readonly class W1PaymentReconcileProposal
{
    /** Разрешённый контур доставки предложения (H6183 human-send). */
    public const CONTOUR_HUMAN_SEND = 'human-send';

    /** Кому адресовано предложение. */
    public const ADDRESSED_TO_HUMAN = 'human';

    /**
     * @param  string  $summary  read-only сводка (детерминированная, без PII)
     * @param  list<W1PaymentReconcileFinding>  $findings  находки сверки
     */
    public function __construct(
        public string $summary,
        public array $findings,
        public string $addressedTo = self::ADDRESSED_TO_HUMAN,
        public string $contour = self::CONTOUR_HUMAN_SEND,
        public bool $readOnly = true,
        public bool $requiresHumanApproval = true,
        public array $actions = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'findings' => array_map(static fn (W1PaymentReconcileFinding $f): array => [
                'type' => $f->type,
                'detail' => $f->detail,
                'expectation_kind' => $f->expectationKind,
            ], $this->findings),
            'addressed_to' => $this->addressedTo,
            'contour' => $this->contour,
            'read_only' => $this->readOnly,
            'requires_human_approval' => $this->requiresHumanApproval,
            'actions' => $this->actions,
        ];
    }
}
