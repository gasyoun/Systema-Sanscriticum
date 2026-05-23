<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\UnreliabilityAuditor;
use Illuminate\Console\Command;

class AuditUnreliableUsers extends Command
{
    protected $signature = 'unreliable:recount';

    protected $description = 'Пересчитать флаг «неблагонадёжный» и подсветку «поведение исправилось» по правилам UnreliabilityAuditor.';

    public function handle(UnreliabilityAuditor $auditor): int
    {
        $userIds = PaymentPromise::query()
            ->select('user_id')
            ->distinct()
            ->pluck('user_id');

        $flagged = 0;
        $improved = 0;

        User::query()->whereIn('id', $userIds)->chunkById(200, function ($chunk) use ($auditor, &$flagged, &$improved): void {
            foreach ($chunk as $user) {
                $wasUnreliable = $user->isUnreliable();
                if ($auditor->recountFor($user)) {
                    $flagged++;
                }
                $auditor->recountDisciplineImproved($user->fresh() ?? $user);

                $fresh = $user->fresh();
                if ($wasUnreliable && $fresh && $fresh->discipline_improved_since !== null) {
                    $improved++;
                }
            }
        });

        $this->info("Помечено новых: {$flagged}. Подсветка «поведение исправилось»: {$improved}.");

        return self::SUCCESS;
    }
}
