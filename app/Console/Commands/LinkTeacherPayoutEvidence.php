<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TeacherPayout;
use App\Models\TeacherPayoutEvidenceLink;
use App\Models\TeacherTransferMatch;
use App\Models\TochkaOutgoingTransfer;
use Illuminate\Console\Command;

final class LinkTeacherPayoutEvidence extends Command
{
    protected $signature = 'money:link-teacher-payout-evidence {provider-payment-id} {teacher-payout-id} {--apply}';

    protected $description = 'Human confirmation that one existing payout is evidenced by one Booked Tochka transfer';

    public function handle(): int
    {
        $transfer = TochkaOutgoingTransfer::query()->where('provider_payment_id', $this->argument('provider-payment-id'))->first();
        $payout = TeacherPayout::query()->find($this->argument('teacher-payout-id'));
        $match = $transfer === null ? null : TeacherTransferMatch::query()->where('transfer_id', $transfer->id)->first();
        if ($transfer === null || $payout === null || $match === null) {
            $this->error('Transfer, payout, or exact teacher match is missing');

            return self::INVALID;
        }
        $sameTeacher = (int) $match->teacher_id === (int) $payout->teacher_id;
        $sameAmount = $transfer->currency === 'RUB' && $transfer->amount_kopecks === (int) round((float) $payout->amount * 100);
        $sameDate = $payout->paid_at?->toDateString() === $transfer->booked_on?->toDateString();
        $this->line(sprintf('teacher=%s amount=%s date=%s', $sameTeacher ? 'same' : 'DIFF', $sameAmount ? 'same' : 'DIFF', $sameDate ? 'same' : 'DIFF'));
        if (! $sameTeacher || ! $sameAmount || ! $sameDate) {
            $this->error('Refusing: teacher, amount, and paid_at must exactly match the bank evidence');

            return self::FAILURE;
        }
        if (! (bool) $this->option('apply')) {
            $this->warn('dry-run; pass --apply after reviewing the frozen payout breakdown');

            return self::SUCCESS;
        }
        TeacherPayoutEvidenceLink::query()->create([
            'transfer_id' => $transfer->id,
            'teacher_payout_id' => $payout->id,
            'confirmed_by' => auth()->id(),
        ]);

        return self::SUCCESS;
    }
}
