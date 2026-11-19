<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Routing;

/**
 * Решение общего слоя маршрутизации W1 (H6190, пакет hermes-24-01a10bca).
 *
 * Значения — только канонические цели ростера V2 (шесть ролей 13/19/20/23/65/85
 * либо `human`); слой НЕ создаёт новых канонических role ID. Каждое решение
 * однопроходно (`singlePass` всегда true) и несёт ровно те флаги, которые ждёт
 * контракт категорий golden-фикстуры H6181 (`tests/fixtures/hermes/w1-golden/
 * routing.json`):
 *
 *  - happy → `role:N`, флаг single_pass;
 *  - ambiguity → `human`, флаг no_role_loop (без прыжков между ролями);
 *  - forbidden → `human`, флаг no_llm_draft (ни одна роль-LLM не черновит);
 *  - escalation → `human`, флаг direct_escalation (деньги/конфликт по
 *    принятому runbook docs/RUNBOOK_SUPPORT_ESCALATION.md).
 */
final readonly class W1RoutingDecision
{
    public const CATEGORY_HAPPY = 'happy';

    public const CATEGORY_AMBIGUITY = 'ambiguity';

    public const CATEGORY_FORBIDDEN = 'forbidden';

    public const CATEGORY_ESCALATION = 'escalation';

    public function __construct(
        public string $route,
        public string $category,
        public bool $singlePass,
        public bool $noRoleLoop,
        public bool $noLlmDraft,
        public bool $directEscalation,
        public string $reason,
    ) {}

    public static function toRole(int $roleId, string $reason): self
    {
        return new self(
            route: 'role:'.$roleId,
            category: self::CATEGORY_HAPPY,
            singlePass: true,
            noRoleLoop: false,
            noLlmDraft: false,
            directEscalation: false,
            reason: $reason,
        );
    }

    public static function ambiguity(string $reason): self
    {
        return new self(
            route: 'human',
            category: self::CATEGORY_AMBIGUITY,
            singlePass: true,
            noRoleLoop: true,
            noLlmDraft: false,
            directEscalation: false,
            reason: $reason,
        );
    }

    public static function forbidden(string $reason, bool $alsoEscalated = false): self
    {
        return new self(
            route: 'human',
            category: self::CATEGORY_FORBIDDEN,
            singlePass: true,
            noRoleLoop: false,
            noLlmDraft: true,
            directEscalation: $alsoEscalated,
            reason: $reason,
        );
    }

    public static function escalation(string $reason): self
    {
        return new self(
            route: 'human',
            category: self::CATEGORY_ESCALATION,
            singlePass: true,
            noRoleLoop: false,
            noLlmDraft: false,
            directEscalation: true,
            reason: $reason,
        );
    }

    public function roleId(): ?int
    {
        return str_starts_with($this->route, 'role:')
            ? (int) substr($this->route, 5)
            : null;
    }

    public function isHuman(): bool
    {
        return $this->route === 'human';
    }

    /** Флаги в именах golden-контракта H6181 (для сверки с expected). */
    public function flags(): array
    {
        return [
            'single_pass' => $this->singlePass,
            'no_role_loop' => $this->noRoleLoop,
            'no_llm_draft' => $this->noLlmDraft,
            'direct_escalation' => $this->directEscalation,
        ];
    }

    /** Тот же маршрут и категория — «то же решение» для дедупликации. */
    public function sameOutcomeAs(self $other): bool
    {
        return $this->route === $other->route && $this->category === $other->category;
    }
}
