<?php

declare(strict_types=1);

namespace App\Services\Promises;

use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\PaymentPromiseSuggestion;
use App\Models\User;
use App\Services\ConditionalAccessGranter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approve / dismiss PaymentPromiseSuggestion — shared by Filament queue (H2156).
 * Approve creates or updates active PaymentPromise; conditional access only if
 * grant_access=true and student is not flagged unreliable.
 */
class PaymentPromiseSuggestionService
{
    public function __construct(private readonly ConditionalAccessGranter $granter) {}

    /**
     * @param  array{
     *     promised_at: \DateTimeInterface|string,
     *     course_id: int,
     *     amount?: float|int|string|null,
     *     note?: string|null,
     *     grant_access?: bool,
     *     access_mode?: string,
     *     access_blocks?: list<int>|string|null,
     * }  $data
     */
    public function approve(PaymentPromiseSuggestion $suggestion, array $data, ?User $curator): PaymentPromise
    {
        if ($suggestion->status !== PaymentPromiseSuggestion::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Предложение уже обработано.',
            ]);
        }

        $courseId = (int) ($data['course_id'] ?? 0);
        if ($courseId < 1) {
            throw ValidationException::withMessages([
                'course_id' => 'Выберите курс.',
            ]);
        }

        $promisedAt = $data['promised_at'] ?? null;
        if ($promisedAt === null || $promisedAt === '') {
            throw ValidationException::withMessages([
                'promised_at' => 'Укажите дату обещанной оплаты.',
            ]);
        }

        $amount = $data['amount'] ?? null;
        if ($amount === '' || $amount === null) {
            $amount = null;
        }

        $note = $data['note'] ?? $suggestion->detected_text;
        $grantAccess = ! empty($data['grant_access']);

        if ($grantAccess) {
            $user = $suggestion->user ?? User::query()->find($suggestion->user_id);
            if ($user instanceof User && $user->isUnreliable()) {
                throw ValidationException::withMessages([
                    'grant_access' => 'Студент помечен как неблагонадёжный — conditional-доступ недоступен. '
                        .'Обещание можно сохранить без открытия доступа.',
                ]);
            }
        }

        return DB::transaction(function () use ($suggestion, $courseId, $promisedAt, $amount, $note, $grantAccess, $data, $curator) {
            $existing = PaymentPromise::query()
                ->where('user_id', $suggestion->user_id)
                ->where('course_id', $courseId)
                ->where('status', PaymentPromise::STATUS_ACTIVE)
                ->orderByDesc('promised_at')
                ->first();

            $payload = [
                'promised_at' => $promisedAt,
                'amount' => $amount,
                'note' => $note,
            ];

            if ($existing) {
                $existing->update($payload);
                $promise = $existing;
            } else {
                $promise = PaymentPromise::create(array_merge($payload, [
                    'user_id' => $suggestion->user_id,
                    'course_id' => $courseId,
                    'status' => PaymentPromise::STATUS_ACTIVE,
                ]));
            }

            if ($grantAccess) {
                $mode = (string) ($data['access_mode'] ?? ConditionalAccessGranter::MODE_BLOCKS);
                $blocks = $this->normalizeBlocks($data['access_blocks'] ?? []);
                $this->granter->grantForPromise($promise, $mode, $blocks);
            }

            $suggestion->update([
                'status' => PaymentPromiseSuggestion::STATUS_APPROVED,
                'resolved_by' => $curator?->id,
                'resolved_at' => now(),
                'payment_promise_id' => $promise->id,
                'course_id' => $courseId,
                'suggested_date' => $promisedAt,
                'suggested_amount' => $amount,
            ]);

            return $promise->fresh() ?? $promise;
        });
    }

    public function dismiss(PaymentPromiseSuggestion $suggestion, ?User $curator): void
    {
        $suggestion->update([
            'status' => PaymentPromiseSuggestion::STATUS_DISMISSED,
            'resolved_by' => $curator?->id,
            'resolved_at' => now(),
        ]);
    }

    /** Count conditional payments linked to a promise (for tests / diagnostics). */
    public function conditionalPaymentCount(PaymentPromise $promise): int
    {
        return Payment::query()
            ->where('user_id', $promise->user_id)
            ->where('course_id', $promise->course_id)
            ->where('is_conditional', true)
            ->count();
    }

    /** @param  list<int>|string|null  $blocks */
    private function normalizeBlocks(array|string|null $blocks): array
    {
        if (is_string($blocks)) {
            $parts = preg_split('/[,\s]+/', $blocks, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_values(array_map('intval', $parts));
        }

        if (! is_array($blocks)) {
            return [];
        }

        return array_values(array_map('intval', $blocks));
    }
}
