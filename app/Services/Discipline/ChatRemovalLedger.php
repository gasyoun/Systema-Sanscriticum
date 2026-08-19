<?php

declare(strict_types=1);

namespace App\Services\Discipline;

use App\Enums\ChatRemovalStatus;
use App\Models\CourseDebtChatRemoval;
use App\Models\CourseDebtChatRemovalEvent;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Единственная точка записи в реестр H2746. Всё, что меняет строку, проходит
 * здесь — и здесь же пишется аудит-событие. Прямой `->update()` из UI считается
 * дефектом: строка без события в журнале недоказуема.
 *
 * Порядок жёсткий и намеренно: quali[fy] → remove → (долг, взнос — в любом
 * порядке) → restore. Вернуть в чат раньше, чем закрыты И долг, И взнос за
 * ЭТОТ чат, метод откажется — это и есть правило, ради которого реестр
 * заведён.
 *
 * Wave 1: ни один метод не обращается к Telegram. `markRemoved()` фиксирует
 * то, что оператор уже сделал руками (кнопка «Исключить из TG-чата» в
 * «Должники»), а не выполняет исключение.
 */
class ChatRemovalLedger
{
    /**
     * Подтвердить кандидата: создать строку со снимком основания.
     * Отказывается, если кандидат правило не проходит или по чату уже открыт
     * эпизод — реестр не место для «на всякий случай».
     */
    public function qualify(ChatRemovalCandidate $candidate, ?int $actorId = null, ?Carbon $now = null): CourseDebtChatRemoval
    {
        if (! $candidate->isEligible()) {
            throw new RuntimeException(
                'Кандидат не проходит правило: '.implode(', ', array_map(
                    ChatRemovalEligibility::blockerLabel(...),
                    $candidate->blockers,
                ))
            );
        }

        $now ??= Carbon::now();

        return DB::transaction(function () use ($candidate, $actorId, $now): CourseDebtChatRemoval {
            $open = CourseDebtChatRemoval::query()
                ->open()
                ->where('user_id', $candidate->user->id)
                ->where('telegram_chat_id', $candidate->telegramChatId)
                ->lockForUpdate()
                ->first();

            if ($open !== null) {
                throw new RuntimeException(
                    "По чату {$candidate->telegramChatId} уже открыт эпизод #{$open->id}."
                );
            }

            $removal = CourseDebtChatRemoval::create([
                'user_id' => $candidate->user->id,
                'course_id' => $candidate->courseId,
                'group_id' => $candidate->group?->id,
                'telegram_chat_id' => $candidate->telegramChatId,
                'chat_label' => $candidate->chatLabel(),
                'debt_amount' => $candidate->debtAmount,
                'debt_block_numbers' => $candidate->debtBlockNumbers,
                'debt_reference_block' => $candidate->referenceBlock,
                'days_overdue' => $candidate->daysOverdue,
                'debt_basis_at' => $now,
                'contact_attempts' => $candidate->evidence->attempts,
                'unanswered_contacts' => $candidate->evidence->trailingUnanswered,
                'last_contact_at' => $candidate->evidence->lastContactAt,
                'silent_since' => $candidate->evidence->silentSince,
                'status' => ChatRemovalStatus::Qualified,
                'qualified_at' => $now,
                'reinstatement_fee' => $candidate->reinstatementFee,
                'fee_currency' => (string) config('chat_removal.currency', 'RUB'),
                'fee_status' => CourseDebtChatRemoval::FEE_PENDING,
            ]);

            $this->log($removal, CourseDebtChatRemovalEvent::QUALIFIED, $actorId, [
                'days_overdue' => $candidate->daysOverdue,
                'unanswered_contacts' => $candidate->evidence->trailingUnanswered,
                'debt_blocks' => $candidate->debtBlockNumbers,
            ], $now);

            return $removal;
        });
    }

    /**
     * Зафиксировать факт исключения (оператор уже нажал кнопку в «Должники»).
     */
    public function markRemoved(
        CourseDebtChatRemoval $removal,
        ?int $actorId = null,
        ?string $note = null,
        ?Carbon $now = null,
    ): CourseDebtChatRemoval {
        $this->assertStatus($removal, [ChatRemovalStatus::Qualified], 'зафиксировать исключение');
        $now ??= Carbon::now();

        $removal->forceFill([
            'status' => ChatRemovalStatus::Removed,
            'removed_at' => $now,
            'removed_by' => $actorId,
            'removal_method' => CourseDebtChatRemoval::METHOD_OPERATOR,
            'removal_note' => $note,
        ])->save();

        $this->log($removal, CourseDebtChatRemovalEvent::REMOVED, $actorId, [
            'method' => CourseDebtChatRemoval::METHOD_OPERATOR,
            'chat_id' => $removal->telegram_chat_id,
        ], $now);

        return $removal->refresh();
    }

    /** Курсовой долг по эпизоду погашен. Взнос этим НЕ закрывается. */
    public function markDebtSettled(
        CourseDebtChatRemoval $removal,
        ?int $actorId = null,
        ?Carbon $now = null,
    ): CourseDebtChatRemoval {
        $this->assertStatus(
            $removal,
            [ChatRemovalStatus::Removed, ChatRemovalStatus::DebtSettled, ChatRemovalStatus::FeeSettled],
            'отметить погашение долга',
        );
        $now ??= Carbon::now();

        $removal->forceFill(['debt_settled_at' => $removal->debt_settled_at ?? $now])->save();
        $this->recomputeStage($removal, $actorId, $now, CourseDebtChatRemovalEvent::DEBT_SETTLED);

        return $removal->refresh();
    }

    /**
     * Взнос за ЭТОТ чат оплачен. `$payment` — реальная строка оплаты, если
     * взнос провели через кассу; связь нужна, чтобы взнос не растворился в
     * курсовой выручке при разборе.
     */
    public function markFeePaid(
        CourseDebtChatRemoval $removal,
        ?Payment $payment = null,
        ?int $actorId = null,
        ?Carbon $now = null,
    ): CourseDebtChatRemoval {
        $this->assertStatus(
            $removal,
            [ChatRemovalStatus::Removed, ChatRemovalStatus::DebtSettled],
            'принять взнос',
        );
        $now ??= Carbon::now();

        $removal->forceFill([
            'fee_status' => CourseDebtChatRemoval::FEE_SETTLED,
            'fee_settled_at' => $now,
            'fee_payment_id' => $payment?->id,
        ])->save();

        $this->recomputeStage($removal, $actorId, $now, CourseDebtChatRemovalEvent::FEE_SETTLED, [
            'amount' => (float) $removal->reinstatement_fee,
            'payment_id' => $payment?->id,
        ]);

        return $removal->refresh();
    }

    /** Взнос прощён решением человека. Причина обязательна — иначе это дыра. */
    public function waiveFee(
        CourseDebtChatRemoval $removal,
        string $reason,
        ?int $actorId = null,
        ?Carbon $now = null,
    ): CourseDebtChatRemoval {
        if (trim($reason) === '') {
            throw new RuntimeException('Прощение взноса требует причины.');
        }

        $this->assertStatus(
            $removal,
            [ChatRemovalStatus::Removed, ChatRemovalStatus::DebtSettled],
            'простить взнос',
        );
        $now ??= Carbon::now();

        $removal->forceFill([
            'fee_status' => CourseDebtChatRemoval::FEE_WAIVED,
            'fee_settled_at' => $now,
            'fee_waived_reason' => $reason,
        ])->save();

        $this->recomputeStage($removal, $actorId, $now, CourseDebtChatRemovalEvent::FEE_WAIVED, [
            'reason' => $reason,
        ]);

        return $removal->refresh();
    }

    /**
     * Оператор вернул студента в чат. Разрешено только из FeeSettled: долг
     * закрыт И взнос за этот чат закрыт.
     */
    public function markRestored(
        CourseDebtChatRemoval $removal,
        ?int $actorId = null,
        ?string $note = null,
        ?Carbon $now = null,
    ): CourseDebtChatRemoval {
        if (! $removal->status->allowsRestoration()) {
            throw new RuntimeException(
                'Возврат в чат недопустим: '
                .($removal->debtIsSettled() ? '' : 'курсовой долг не погашен; ')
                .($removal->feeIsClosed() ? '' : 'взнос за этот чат не закрыт; ')
                .'текущая стадия — '.$removal->status->label().'.'
            );
        }

        $now ??= Carbon::now();

        $removal->forceFill([
            'status' => ChatRemovalStatus::Restored,
            'restored_at' => $now,
            'restored_by' => $actorId,
            'restoration_note' => $note,
        ])->save();

        $this->log($removal, CourseDebtChatRemovalEvent::RESTORED, $actorId, [
            'chat_id' => $removal->telegram_chat_id,
        ], $now);

        return $removal->refresh();
    }

    /** Основание отпало (ошибка оператора, спорный долг, чужой платёж). */
    public function cancel(
        CourseDebtChatRemoval $removal,
        string $reason,
        ?int $actorId = null,
        ?Carbon $now = null,
    ): CourseDebtChatRemoval {
        if (trim($reason) === '') {
            throw new RuntimeException('Отмена эпизода требует причины.');
        }
        if (! $removal->status->isOpen()) {
            throw new RuntimeException('Эпизод уже закрыт: '.$removal->status->label().'.');
        }

        $now ??= Carbon::now();

        $removal->forceFill(['status' => ChatRemovalStatus::Cancelled])->save();

        $this->log($removal, CourseDebtChatRemovalEvent::CANCELLED, $actorId, [
            'reason' => $reason,
        ], $now);

        return $removal->refresh();
    }

    /**
     * Итог по студенту: сколько чатов ждут возврата и на какую сумму взносов.
     *
     * @return array{chats: int, amount: float, currency: string}
     */
    public function outstandingFee(int $userId): array
    {
        $rows = CourseDebtChatRemoval::query()
            ->feeOutstanding()
            ->where('user_id', $userId)
            ->whereNotNull('removed_at')
            ->get(['reinstatement_fee', 'fee_currency']);

        return [
            'chats' => $rows->count(),
            'amount' => (float) $rows->sum(fn ($r) => (float) $r->reinstatement_fee),
            'currency' => (string) ($rows->first()?->fee_currency ?? config('chat_removal.currency', 'RUB')),
        ];
    }

    /**
     * Стадия после погашения долга/взноса. Отдельный метод, потому что порядок
     * «сначала долг» и «сначала взнос» оба допустимы, а FeeSettled наступает
     * ровно тогда, когда закрыты оба.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recomputeStage(
        CourseDebtChatRemoval $removal,
        ?int $actorId,
        Carbon $now,
        string $event,
        array $payload = [],
    ): void {
        $next = match (true) {
            $removal->debtIsSettled() && $removal->feeIsClosed() => ChatRemovalStatus::FeeSettled,
            $removal->debtIsSettled() => ChatRemovalStatus::DebtSettled,
            default => $removal->status,
        };

        if ($next !== $removal->status) {
            $removal->forceFill(['status' => $next])->save();
        }

        $this->log($removal, $event, $actorId, $payload + ['stage' => $next->value], $now);
    }

    /**
     * @param  list<ChatRemovalStatus>  $allowed
     */
    private function assertStatus(CourseDebtChatRemoval $removal, array $allowed, string $what): void
    {
        if (! in_array($removal->status, $allowed, true)) {
            throw new RuntimeException(
                "Нельзя {$what}: эпизод в стадии «{$removal->status->label()}»."
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function log(
        CourseDebtChatRemoval $removal,
        string $event,
        ?int $actorId,
        array $payload,
        Carbon $now,
    ): void {
        CourseDebtChatRemovalEvent::create([
            'removal_id' => $removal->id,
            'event' => $event,
            'actor_user_id' => $actorId,
            'payload' => $payload,
            'occurred_at' => $now,
        ]);
    }
}
