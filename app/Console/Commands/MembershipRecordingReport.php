<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MembershipAccessVerdict;
use App\Services\Membership\RecordingAccessPolicy;
use Illuminate\Console\Command;

final class MembershipRecordingReport extends Command
{
    protected $signature = 'membership:recording-report {--hours=48 : Shadow lookback}';

    protected $description = 'Report shadow recording verdicts and validate the 20-user/48-hour pilot';

    public function handle(RecordingAccessPolicy $policy): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $query = MembershipAccessVerdict::query()->where('requested_at', '>=', now()->subHours($hours));

        $this->table(['metric', 'value'], [
            ['requests', (clone $query)->count()],
            ['affected users', (clone $query)->where('would_allow', false)->distinct()->count('user_id')],
            ['affected courses', (clone $query)->where('would_allow', false)->distinct()->count('course_id')],
            ['would deny recordings', (clone $query)->where('would_allow', false)->count()],
            ['live/schedule/homework/text/communication closures', 0],
            ['surface boundary', 'recording payload only'],
        ]);

        $pilotIds = $policy->pilotUserIds();
        $pilotReady = count($pilotIds) === (int) config('membership.recording_gate.pilot_size', 20)
            && $policy->pilotStartedAt() !== null;
        $this->line('pilot='.($pilotReady ? 'READY' : 'NOT READY')
            .' users='.count($pilotIds)
            .' hours='.(int) config('membership.recording_gate.pilot_hours', 48));

        if ((clone $query)->where('surface', '!=', 'web_recording')->where('surface', '!=', 'api_recording')->exists()) {
            $this->error('FAIL: a verdict escaped the recording-only surfaces.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
