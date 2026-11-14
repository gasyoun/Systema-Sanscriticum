<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Access;

use Carbon\Carbon;

/**
 * H6189 (Epic E026, Hermes W1): таблица решений диагностики доступа —
 * роль 85 `access-provisioner`.
 *
 * Чистая логика без I/O: состояние права (действующее/истёкшее/чужое/
 * отсутствующее — семантика предикатов доступа CourseAccessWindow H4456)
 * × класс основания (оплата) → исход + проект операции. Проект — ТОЛЬКО
 * данные для человека ({@see W1AccessFixProposal}): никаких реальных
 * изменений доступов («access mutations запрещены абсолютно»).
 *
 * Правила (миссия H6189 + golden-контракт H6181 ap-001..ap-004):
 *  - чужое право → requires_human + проект rebind (владелец не раскрывается);
 *  - действующее + подтверждённая оплата → drafted, проект none;
 *  - действующее + спорная оплата → requires_human, проект none,
 *    must_not_auto_revoke (ap-002: спорная оплата не повод отзыва);
 *  - действующее без основания → requires_human + проект revoke (только
 *    проект, никогда не автоматически);
 *  - отсутствующее/истёкшее + подтверждённая оплата → drafted + проект
 *    grant/extend (ap-001: «диагноз + проект операции выдачи»);
 *  - спорная оплата без действующего права → requires_human, проект none;
 *  - неподтверждённая оплата → refused, проект none («роль не предлагает
 *    операцию без основания»);
 *  - неизвестное состояние/основание → requires_human, без проекта
 *    («без уверенного угадывания»).
 */
final class W1AccessDecisionTable
{
    /**
     * Состояние права (миссия H6189; семантика — предикаты доступа
     * CourseAccessWindow H4456: активное/истёкшее окно, отсутствие строки,
     * чужой владелец).
     */
    public function state(bool $courseAccess, mixed $expiresAt, bool $ownerMatches): string
    {
        if (! $courseAccess) {
            return W1AccessDiagnosticsResult::STATE_MISSING;
        }

        if (! $ownerMatches) {
            // Чужое право: владелец не раскрывается (PII-граница H6182).
            return W1AccessDiagnosticsResult::STATE_FOREIGN;
        }

        if ($expiresAt !== null && $expiresAt !== '') {
            try {
                if (Carbon::parse((string) $expiresAt)->isPast()) {
                    return W1AccessDiagnosticsResult::STATE_EXPIRED;
                }
            } catch (\Throwable) {
                // Неразборчивый срок — без уверенного угадывания.
                return W1AccessDiagnosticsResult::STATE_UNKNOWN;
            }
        }

        return W1AccessDiagnosticsResult::STATE_ACTIVE;
    }

    /**
     * Класс основания по статусу оплаты снимка. Значения вне контракта —
     * без угадывания и без эха сырых данных (injection — данные, риск №7).
     *
     * @return array{0: string, 1: string}
     */
    public function basis(string $paymentStatus): array
    {
        return match ($paymentStatus) {
            'confirmed_paid' => [
                W1AccessDiagnosticsResult::BASIS_CONFIRMED,
                'основание: подтверждённая оплата (confirmed_paid)',
            ],
            'pending_disputed' => [
                W1AccessDiagnosticsResult::BASIS_DISPUTED,
                'основание спорное: оплата оспаривается (pending_disputed)',
            ],
            'pending', 'failed', 'none' => [
                W1AccessDiagnosticsResult::BASIS_UNCONFIRMED,
                'основание отсутствует: оплата не подтверждена',
            ],
            default => [
                W1AccessDiagnosticsResult::BASIS_UNKNOWN,
                'основание неизвестно: статус оплаты вне контракта',
            ],
        };
    }

    /**
     * Таблица решений (состояние × основание). Проект revoke/rebind — ТОЛЬКО
     * данные для человека (никогда не исполняется ролью).
     *
     * @return array{0: string, 1: ?W1AccessFixProposal, 2: bool, 3: bool}
     */
    public function decide(string $state, string $basis, string $basisExplanation): array
    {
        $active = W1AccessDiagnosticsResult::STATE_ACTIVE;
        $expired = W1AccessDiagnosticsResult::STATE_EXPIRED;
        $foreign = W1AccessDiagnosticsResult::STATE_FOREIGN;
        $missing = W1AccessDiagnosticsResult::STATE_MISSING;
        $confirmed = W1AccessDiagnosticsResult::BASIS_CONFIRMED;
        $disputed = W1AccessDiagnosticsResult::BASIS_DISPUTED;
        $unknown = W1AccessDiagnosticsResult::BASIS_UNKNOWN;

        return match (true) {
            // Чужое право — sensitive: перепривязка только проектом, человеком.
            $state === $foreign => [
                W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN,
                new W1AccessFixProposal(
                    W1AccessFixProposal::OP_REBIND,
                    'право числится за другим пользователем (чужое право); владелец не раскрывается — проект перепривязки',
                ),
                true,
                false,
            ],

            // Действующее право.
            $state === $active && $basis === $confirmed => [
                W1AccessDiagnosticsResult::OUTCOME_DRAFTED,
                new W1AccessFixProposal(W1AccessFixProposal::OP_NONE, 'действующее право при подтверждённой оплате — менять нечего'),
                false,
                false,
            ],
            $state === $active && $basis === $disputed => [
                // ap-002: спорная оплата — не повод автоматического отзыва.
                W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN,
                new W1AccessFixProposal(W1AccessFixProposal::OP_NONE, 'спорная оплата не повод автоматического отзыва доступа'),
                true,
                true,
            ],
            $state === $active && $basis === $unknown => [
                W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN,
                null,
                true,
                false,
            ],
            $state === $active => [
                W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN,
                new W1AccessFixProposal(W1AccessFixProposal::OP_REVOKE, 'действующее право без подтверждённой оплаты — проект отзыва (никогда не автоматически)'),
                true,
                false,
            ],

            // Отсутствующее право.
            $state === $missing && $basis === $confirmed => [
                // ap-001: диагноз + проект операции выдачи.
                W1AccessDiagnosticsResult::OUTCOME_DRAFTED,
                new W1AccessFixProposal(W1AccessFixProposal::OP_GRANT, 'подтверждённая оплата есть, право отсутствует — проект выдачи'),
                false,
                false,
            ],
            $state === $missing && $basis === $disputed => [
                W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN,
                new W1AccessFixProposal(W1AccessFixProposal::OP_NONE, 'спорная оплата — не основание для выдачи; решение человеком'),
                true,
                false,
            ],
            $state === $missing && $basis === $unknown => [
                W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN,
                null,
                true,
                false,
            ],
            $state === $missing => [
                W1AccessDiagnosticsResult::OUTCOME_REFUSED,
                new W1AccessFixProposal(W1AccessFixProposal::OP_NONE, 'неподтверждённая оплата — не основание для выдачи доступа; роль не предлагает операцию без основания'),
                true,
                false,
            ],

            // Истёкшее право.
            $state === $expired && $basis === $confirmed => [
                W1AccessDiagnosticsResult::OUTCOME_DRAFTED,
                new W1AccessFixProposal(W1AccessFixProposal::OP_EXTEND, 'подтверждённая оплата есть, право истекло — проект продления; обещанные сроки записей сохраняются'),
                false,
                false,
            ],
            $state === $expired && $basis === $disputed => [
                W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN,
                new W1AccessFixProposal(W1AccessFixProposal::OP_NONE, 'спорная оплата — не основание для продления; решение человеком'),
                true,
                false,
            ],
            $state === $expired && $basis === $unknown => [
                W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN,
                null,
                true,
                false,
            ],
            $state === $expired => [
                W1AccessDiagnosticsResult::OUTCOME_REFUSED,
                new W1AccessFixProposal(W1AccessFixProposal::OP_NONE, 'неподтверждённая оплата — не основание для продления; роль не предлагает операцию без основания'),
                true,
                false,
            ],

            default => [W1AccessDiagnosticsResult::OUTCOME_REQUIRES_HUMAN, null, true, false],
        };
    }
}
