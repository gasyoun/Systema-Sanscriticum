<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\OnboardingNotifier;
use Illuminate\Console\Command;

final class OnboardingWeeklyDigest extends Command
{
    protected $signature = 'onboarding:weekly-digest';

    protected $description = 'Еженедельная сводка в чат онбординга: % студентов с доступом, которые ни разу не заходили';

    public function handle(OnboardingNotifier $notifier): int
    {
        // «С доступом» = состоит хотя бы в одной группе (PaymentObserver::grantAccess
        // добавляет в группу при оплате). Админов не считаем — это не студенты.
        $base = User::query()
            ->where('is_admin', false)
            ->whereHas('groups');

        $withAccess = (clone $base)->count();
        $notEntered = (clone $base)->where('login_count', 0)->count();

        $sample = (clone $base)
            ->where('login_count', 0)
            ->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'name', 'email', 'phone', 'telegram_id']);

        $notifier->notEnteredDigest($withAccess, $notEntered, $sample);

        $this->info("Онбординг-сводка: {$notEntered} из {$withAccess} не заходили.");

        return self::SUCCESS;
    }
}
