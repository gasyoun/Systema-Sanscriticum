<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Teacher;
use App\Models\TeacherPayoutIdentity;
use App\Services\Payments\TeacherPaymentIdentity;
use Illuminate\Console\Command;

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
        TeacherPayoutIdentity::query()->firstOrCreate(
            ['provider' => 'tochka', 'identity_type' => $type, 'identity_hmac' => $digest],
            ['teacher_id' => $teacher->id, 'display_tail' => TeacherPaymentIdentity::tail($value), 'valid_from' => $this->option('from') ?: null, 'valid_to' => $this->option('to') ?: null, 'confirmed_by' => auth()->id(), 'confirmed_at' => now()],
        );

        return self::SUCCESS;
    }
}
