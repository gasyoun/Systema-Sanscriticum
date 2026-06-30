<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GroupMembershipManager;
use Illuminate\Console\Command;

/**
 * Бэкфилл/реконсиляция «активного состава» групп по текущим курсовым статусам
 * студентов. Идемпотентно; ручные пометки (left_reason='manual') не трогаются.
 * Нужна при первичном развёртывании фичи и как страховочный пересчёт.
 */
class SyncActiveGroupMembership extends Command
{
    protected $signature = 'groups:sync-active-membership';

    protected $description = 'Пересчитать активный состав групп (left_at) по курсовым статусам студентов.';

    public function handle(GroupMembershipManager $manager): int
    {
        $count = 0;

        User::query()
            ->whereHas('groups')
            ->with(['groups.courses', 'courses'])
            ->chunkById(200, function ($users) use ($manager, &$count): void {
                foreach ($users as $user) {
                    $manager->syncAllForUser($user);
                    $count++;
                }
            });

        $this->info("Active group membership synced for {$count} student(s).");

        return self::SUCCESS;
    }
}
