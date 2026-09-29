<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Teacher;
use App\Models\TeacherPayoutIdentity;
use App\Models\TeacherTransferMatch;
use App\Models\TochkaOutgoingTransfer;
use App\Services\Payments\TeacherPaymentIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RegisterTeacherPayoutIdentity extends Command
{
    protected $signature = 'money:register-teacher-payout-identity {teacher} {type : inn|account} {value} {--from=} {--to=} {--apply}';

    protected $description = 'Register a human-confirmed HMAC identity for exact Tochka transfer matching';

    public function handle(): int
    {
        $teacher = Teacher::query()->find($this->argument('teacher'));
        $type = (string) $this->argument('type');
        $value = (string) $this->argument('value');
        if ($teacher === null || ! in_array($type, ['inn', 'account'], true) || trim($value) === '') {
            $this->error('Unknown teacher, identity type, or empty value');

            return self::INVALID;
        }
        $digest = TeacherPaymentIdentity::digest($value);
        $this->line("teacher #{$teacher->id} {$teacher->name}; {$type} …".TeacherPaymentIdentity::tail($value));
        $this->line('digest '.$digest);
        if (! (bool) $this->option('apply')) {
            $this->warn('dry-run; pass --apply after verifying the teacher');

            return self::SUCCESS;
        }
        [$matched, $alreadyMatched, $conflicts] = DB::transaction(function () use ($teacher, $type, $value, $digest): array {
            $identity = TeacherPayoutIdentity::query()->firstOrCreate(
                ['provider' => 'tochka', 'identity_type' => $type, 'identity_hmac' => $digest],
                ['teacher_id' => $teacher->id, 'display_tail' => TeacherPaymentIdentity::tail($value), 'valid_from' => $this->option('from') ?: null, 'valid_to' => $this->option('to') ?: null, 'confirmed_by' => auth()->id(), 'confirmed_at' => now()],
            );
            if ((int) $identity->teacher_id !== (int) $teacher->id) {
                return [0, 0, 1];
            }

            $column = $type === 'inn' ? 'recipient_inn_hmac' : 'recipient_account_hmac';
            $transfers = TochkaOutgoingTransfer::query()
                ->where($column, $digest)
                ->when($identity->valid_from, fn ($q) => $q->whereDate('booked_on', '>=', $identity->valid_from))
                ->when($identity->valid_to, fn ($q) => $q->whereDate('booked_on', '<=', $identity->valid_to))
                ->get();
            $matched = 0;
            $alreadyMatched = 0;
            $conflicts = 0;
            foreach ($transfers as $transfer) {
                $existing = TeacherTransferMatch::query()->where('transfer_id', $transfer->id)->first();
                if ($existing !== null) {
                    if ((int) $existing->teacher_id === (int) $teacher->id) {
                        $alreadyMatched++;
                    } else {
                        $conflicts++;
                    }

                    continue;
                }
                TeacherTransferMatch::query()->create([
                    'transfer_id' => $transfer->id,
                    'teacher_id' => $teacher->id,
                    'identity_id' => $identity->id,
                    'match_basis' => $type.'_hmac',
                ]);
                $matched++;
            }

            return [$matched, $alreadyMatched, $conflicts];
        });
        $this->line("historical transfers matched: {$matched}; already matched: {$alreadyMatched}; conflicts: {$conflicts}");
        if ($conflicts > 0) {
            $this->error('Identity or transfer is already assigned to another teacher; review required');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
