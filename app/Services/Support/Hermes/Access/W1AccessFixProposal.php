<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Access;

/**
 * H6189 (Epic E026, Hermes W1): проект операции исправления доступа —
 * роль 85 `access-provisioner` (диагностика и предложение исправления).
 *
 * ПРОЕКТ — ДАННЫЕ для человека, НЕ исполнение (exclusions миссии H6189:
 * «НИКАКИХ реальных изменений доступов — access mutations запрещены
 * абсолютно»; миссия: «предложение как данные для человека»). Каждый
 * проект удовлетворяет трём инвариантам-константам:
 *  - requiresHumanApproval = true — операция применяется ТОЛЬКО прямым
 *    подтверждением человека (golden-контракт H6181: «исполнение — только
 *    подтверждение человеком», proposal_requires_human_approval);
 *  - autoApplied = false — роль не применяла, не применяет и не может
 *    применить операцию (no_auto_grant / must_not_auto_revoke);
 *  - preservePromisedDeadlines = true — «сохранять обещанные сроки
 *    записей»: проект не сдвигает уже обещанные сроки.
 *
 * Операции (каноникальны для роли 85; новых канонических role ID нет —
 * только ростер V2):
 *  - grant  — выдача отсутствующего права (только при подтверждённой оплате);
 *  - extend — продление истёкшего права (только при подтверждённой оплате,
 *             обещанные сроки записей сохраняются);
 *  - revoke — отзыв действующего права (ТОЛЬКО проект; спорная оплата НИКОГДА
 *             не повод автоматического отзыва);
 *  - rebind — перепривязка чужого права (ТОЛЬКО проект; владелец не
 *             раскрывается — PII-граница H6182);
 *  - none   — операция не предлагается: нет основания либо менять нечего.
 */
final readonly class W1AccessFixProposal
{
    public const OP_GRANT = 'grant';

    public const OP_EXTEND = 'extend';

    public const OP_REVOKE = 'revoke';

    public const OP_REBIND = 'rebind';

    public const OP_NONE = 'none';

    /** Все канонические операции проекта (для валидации). */
    public const OPERATIONS = [
        self::OP_GRANT,
        self::OP_EXTEND,
        self::OP_REVOKE,
        self::OP_REBIND,
        self::OP_NONE,
    ];

    /**
     * @param  string  $operation  одна из OPERATIONS
     * @param  string  $basis  почему предложена именно эта операция (данные
     *                         для человека; без персональных данных)
     * @param  bool  $requiresHumanApproval  инвариант: всегда true
     * @param  bool  $autoApplied  инвариант: всегда false (никаких реальных
     *                             изменений доступов)
     * @param  bool  $preservePromisedDeadlines  инвариант: всегда true
     *                                           (обещанные сроки записей)
     */
    public function __construct(
        public string $operation,
        public string $basis,
        public bool $requiresHumanApproval = true,
        public bool $autoApplied = false,
        public bool $preservePromisedDeadlines = true,
    ) {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException("W1AccessFixProposal: unknown operation {$operation}");
        }
    }

    /** Проект — только данные для человека; операция НЕ исполнена. */
    public function isDataOnly(): bool
    {
        return $this->requiresHumanApproval && ! $this->autoApplied;
    }

    /**
     * Наблюдение для golden-контракта H6181 (role_85_access_provisioner.json).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'basis' => $this->basis,
            'proposal_requires_human_approval' => $this->requiresHumanApproval,
            'no_auto_grant' => ! $this->autoApplied,
            'auto_applied' => $this->autoApplied,
            'preserve_promised_deadlines' => $this->preservePromisedDeadlines,
        ];
    }
}
