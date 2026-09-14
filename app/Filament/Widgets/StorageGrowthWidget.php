<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\StorageUsageService;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

/**
 * Дашборд-виджет «Хранилище: рост и прогноз» (H4298, MG 07-09-2026:
 * «к какому месяцу исчерпается место? Это должно быть видно в админке»).
 *
 * Ответ на вопрос — в колонке «Прогноз»: месяц, когда каталог дойдёт до
 * своего потолка при темпе ПОСЛЕДНИХ 30 дней (mtime-разбивка файлов).
 * Это оценка, не гарантия: перезапись файла mtime-метод считает за рост.
 *
 * Данные — StorageUsageService (та же точка правды, что у ежедневного
 * `storage:check`); обход IO-тяжёлый, поэтому кэш на 6 часов: дашборд
 * не должен бить по диску на каждый рендер, daily-алерты остаются
 * в команде.
 *
 * @phpstan-type Row array{path: string, used: string, limit: string, ratio: string, level: string, growth: string, eta: string}
 */
class StorageGrowthWidget extends Widget
{
    protected static string $view = 'filament.widgets.storage-growth';

    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Хранилище: рост и прогноз';

    public static function canView(): bool
    {
        // Тот же гейт, что у «Застрявших студентов»: админ-подобные роли.
        return RoleGate::any(Roles::ADMIN, Roles::ACCOUNTANT);
    }

    /**
     * RU-месяц для прогноза — детерминированно, без зависимости от app locale.
     */
    private const RU_MONTHS = [
        1 => 'янв', 2 => 'фев', 3 => 'мар', 4 => 'апр',
        5 => 'май', 6 => 'июн', 7 => 'июл', 8 => 'авг',
        9 => 'сен', 10 => 'окт', 11 => 'ноя', 12 => 'дек',
    ];

    public function getViewData(): array
    {
        $payload = Cache::remember('storage_growth_widget.v1', now()->addHours(6), function (): array {
            $service = app(StorageUsageService::class);
            $snapshot = $service->snapshot();
            $growth = [];

            foreach ($snapshot['directories'] as $dir) {
                $growth[$dir['path']] = $service->growth($dir['path']);
            }

            return ['snapshot' => $snapshot, 'growth' => $growth];
        });

        $snapshot = $payload['snapshot'];
        $growth = $payload['growth'];
        $service = app(StorageUsageService::class);
        $recentDays = $growth[$snapshot['directories'][0]['path'] ?? '']['recent_days'] ?? 30;

        $rows = [];
        $watchedRecentSum = 0;

        foreach ($snapshot['directories'] as $dir) {
            $g = $growth[$dir['path']] ?? null;
            $recentBytes = $g['recent_bytes'] ?? 0;
            $watchedRecentSum += $recentBytes;
            $rows[] = [
                'path' => $dir['path'],
                'used' => $service->megabytes((int) $dir['bytes']),
                'limit' => number_format((float) $dir['limit_mb'], 0, '.', ' ').' МБ',
                'ratio' => ((int) round($dir['ratio'] * 100)).'%',
                'level' => $dir['level'],
                'growth' => $recentBytes > 0
                    ? '+'.$service->megabytes($recentBytes).' / '.$recentDays.' дн.'
                    : 'не растёт',
                'eta' => $g === null
                    ? '—'
                    : $this->etaLabel((int) $dir['bytes'], (int) $dir['limit_mb'], $recentBytes, $recentDays),
            ];
        }

        return [
            'rows' => $rows,
            'total' => [
                'used' => $service->megabytes((int) $snapshot['total_bytes']),
                'limit' => number_format((float) $snapshot['total_limit_mb'], 0, '.', ' ').' МБ',
                'ratio' => ((int) round(
                    $snapshot['total_limit_mb'] > 0
                        ? $snapshot['total_bytes'] / ($snapshot['total_limit_mb'] * 1048576)
                        : 0
                ) * 100).'%',
                'level' => $snapshot['total_level'],
                'eta' => $this->etaLabel(
                    (int) $snapshot['total_bytes'],
                    (int) $snapshot['total_limit_mb'],
                    $watchedRecentSum,
                    $recentDays
                ),
            ],
            'freeDisk' => $snapshot['free_disk_bytes'] !== null
                ? $service->megabytes((int) $snapshot['free_disk_bytes'])
                : null,
            'recentDays' => $recentDays,
        ];
    }

    /**
     * Месяц достижения потолка при текущем темпе. Ceil — сознательно:
     * показываем раннюю (пессимистичную) границу, виджет не имеет права
     * успокаивать раньше времени.
     */
    public function etaLabel(int $bytes, int $limitMb, int $recentBytes, int $recentDays = 30): string
    {
        if ($limitMb <= 0) {
            return '—';
        }

        $limitBytes = $limitMb * 1048576;

        if ($bytes >= $limitBytes) {
            return 'превышен';
        }

        if ($recentBytes <= 0) {
            return 'не растёт';
        }

        $perMonth = $recentBytes / max(1, $recentDays) * 30.4;
        $monthsLeft = ($limitBytes - $bytes) / $perMonth;

        if ($monthsLeft > 60) {
            return '> 5 лет';
        }

        $eta = now()->addMonths((int) max(1, ceil($monthsLeft)));

        return '~'.self::RU_MONTHS[(int) $eta->format('n')].' '.$eta->format('Y');
    }
}
