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
        // «Должен зайти» = студенту реально выслали доступ И у него рабочий email.
        // Признак «доступ выслан» — штамп «[Доступ отправлен: …]» в note, который
        // ставит bulk-action отправки доступа в UserResource (а его фильтр
        // `access_sent` использует тот же LIKE). Реальный email — не заглушка
        // @no-email.com и не пустой (на момент штампа адрес уже прошёл валидацию).
        $base = User::query()
            ->where('is_admin', false)
            ->where('note', 'like', '%[Доступ отправлен%')
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->where('email', 'not like', '%@no-email.com');

        $accessSent = (clone $base)->count();
        $notEntered = (clone $base)->where('login_count', 0)->count();

        $sample = (clone $base)
            ->where('login_count', 0)
            ->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'name', 'email', 'phone', 'telegram_id']);

        $notifier->notEnteredDigest($accessSent, $notEntered, $sample);

        $this->info("Онбординг-сводка: {$notEntered} из {$accessSent} не заходили.");

        return self::SUCCESS;
    }
}
