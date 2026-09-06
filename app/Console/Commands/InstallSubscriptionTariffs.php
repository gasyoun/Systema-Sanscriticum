<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MembershipTier;
use App\Models\Course;
use App\Models\Tariff;
use Illuminate\Console\Command;

/**
 * H3916: установить ратифицированную сетку подписки «в записи» на
 * курсе-членстве и вывести из продажи старую клубную линию.
 *
 * Сетка A (MG, chat-vote 06-09-2026): Standard 20 000 ₽/год,
 * Professional 35 000 ₽/год, пробный квартал Standard 5 500 ₽.
 * Старая линия клуба (1500 ₽/мес + 4000 ₽/кв + 15 000 ₽/год) деактивируется:
 * оставлять 15 000 ₽/год рядом со Standard 20 000 ₽/год — ценовая
 * несовместимость, а 1500 ₽/мес снята с продажи прямым пунктом миссии.
 *
 * Идемпотентно: новые тарифы ищутся по ключу membership_*, а не по id.
 * Активных членов на момент запуска — 0 (аудит 06-09-2026), риск по
 * существующим подпискам отсутствует.
 */
class InstallSubscriptionTariffs extends Command
{
    protected $signature = 'membership:install-subscription-tariffs {--dry-run : показать план без записи}';

    protected $description = 'H3916: тарифы подписки «в записи» (20 000/35 000/5 500) + снятие старой клубной линии';

    /** @return array<int, array{tier: MembershipTier, months: int, price: int, title: string}> */
    public static function grid(): array
    {
        return [
            ['tier' => MembershipTier::Standard, 'months' => 3, 'price' => 5500, 'title' => 'Подписка «в записи» — пробный квартал (Стандарт)'],
            ['tier' => MembershipTier::Standard, 'months' => 12, 'price' => 20000, 'title' => 'Подписка «в записи» — Стандарт (год)'],
            ['tier' => MembershipTier::Professional, 'months' => 12, 'price' => 35000, 'title' => 'Подписка «в записи» — Профессионал (год)'],
        ];
    }

    public function handle(): int
    {
        $course = Course::query()
            ->where('slug', (string) config('membership.club.course_slug', 'club'))
            ->first();

        if ($course === null) {
            $this->error('membership: club course not found (config membership.club.course_slug)');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        // 1. Деактивация старой клубной линии: любые АКТИВНЫЕ membership-тарифы,
        //    чей ключ не входит в новую сетку.
        $active = Tariff::query()
            ->where('course_id', $course->id)
            ->where('is_active', true)
            ->whereNotNull('membership_months')
            ->get();

        $newKeys = collect(self::grid())
            ->map(fn (array $row) => 'membership_'.$row['tier']->value.'_'.$row['months'].'m')
            ->all();

        foreach ($active as $tariff) {
            if (in_array($tariff->accessKey(), $newKeys, true)) {
                continue;
            }

            $this->line("retire: tariff {$tariff->id} [{$tariff->accessKey()}] price={$tariff->price}");
            if (! $dryRun) {
                $tariff->forceFill(['is_active' => false])->save();
            }
        }

        // 2. Установка новой сетки (upsert по ключу).
        foreach (self::grid() as $row) {
            $key = 'membership_'.$row['tier']->value.'_'.$row['months'].'m';

            $existing = Tariff::query()
                ->where('course_id', $course->id)
                ->whereNotNull('membership_months')
                ->where('membership_months', $row['months'])
                ->where('membership_tier', $row['tier']->value)
                ->first();

            $payload = [
                'title' => $row['title'],
                'type' => 'full',
                'price' => $row['price'],
                'is_active' => true,
                'membership_months' => $row['months'],
                'membership_tier' => $row['tier'],
            ];

            if ($existing === null) {
                $this->line("create: {$key} price={$row['price']}");
                if (! $dryRun) {
                    $tariff = new Tariff;
                    $tariff->course_id = $course->id;
                    $tariff->forceFill($payload)->save();
                }
            } else {
                $this->line("update: {$key} price={$existing->price} -> {$row['price']} (id {$existing->id})");
                if (! $dryRun) {
                    $existing->forceFill($payload)->save();
                }
            }
        }

        $this->info($dryRun ? 'DRY-RUN done (nothing written)' : 'subscription tariff grid installed');

        return self::SUCCESS;
    }
}
