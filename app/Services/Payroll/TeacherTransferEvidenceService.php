<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Teacher;
use App\Models\TeacherPayoutEvidenceLink;
use App\Models\TeacherTransferMatch;
use Illuminate\Support\Carbon;

final class TeacherTransferEvidenceService
{
    /** @return array<string,mixed>|null */
    public function lastForTeacher(Teacher $teacher, Carbon $cutoff): ?array
    {
        if (! (bool) config('features.money_tochka_teacher_transfers', false)) {
            return null;
        }

        $match = TeacherTransferMatch::query()
            ->where('teacher_id', $teacher->id)
            ->whereHas('transfer', fn ($q) => $q->whereDate('booked_on', '<=', $cutoff->toDateString()))
            ->with('transfer')
            ->get()
            ->sortByDesc(fn (TeacherTransferMatch $m): string => ($m->transfer?->booked_on?->format('Y-m-d') ?? '').str_pad((string) $m->transfer_id, 20, '0', STR_PAD_LEFT))
            ->first();
        $transfer = $match?->transfer;
        if ($transfer === null) {
            return null;
        }
        $link = TeacherPayoutEvidenceLink::query()->where('transfer_id', $transfer->id)->first();

        return [
            'source' => 'tochka_statement',
            'date' => $transfer->booked_on?->toDateString(),
            'days_since' => max(0, (int) $transfer->booked_on?->copy()->startOfDay()->diffInDays($cutoff->copy()->startOfDay())),
            'amount_rub' => $transfer->currency === 'RUB' ? round($transfer->amount_kopecks / 100, 2) : null,
            'amount_foreign' => $transfer->currency === 'RUB' ? null : round($transfer->amount_kopecks / 100, 2),
            'currency' => $transfer->currency,
            'channel' => 'tochka',
            'document_no' => $transfer->document_no,
            'provider_payment_id' => $transfer->provider_payment_id,
            'provider_transaction_id' => $transfer->provider_transaction_id,
            'evidence_reference' => 'tochka:'.$transfer->provider_transaction_id,
            'allocation_state' => $link === null ? 'unallocated' : 'linked_to_payout',
            'teacher_payout_id' => $link?->teacher_payout_id,
            'is_historical_backfill' => false,
        ];
    }
}
