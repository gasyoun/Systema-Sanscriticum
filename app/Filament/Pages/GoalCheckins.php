<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Goal;
use App\Services\GoalCheckinService;
use App\Support\RoleGate;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * «Check-in целей» — standup-петля прогресса делегированных лидов (H376,
 * младший брат KPI делегирования / фондов прибыли H259). Каждая активная цель
 * — карточка с темпом (факт против ожидаемого по дедлайну) и кнопкой ручного
 * check-in'а; еженедельный автоматический прогон — goals:record-checkins.
 *
 * Доступ — RoleGate::finance() (та же группа, что видит остальные KPI-панели).
 */
class GoalCheckins extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 79;

    protected static ?string $navigationLabel = 'Check-in целей';

    protected static ?string $title = 'Check-in целей — standup-петля прогресса';

    protected static ?string $slug = 'goal-checkins';

    protected static string $view = 'filament.pages.goal-checkins';

    public static function canAccess(): bool
    {
        return RoleGate::finance();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::finance();
    }

    public static function getNavigationBadge(): ?string
    {
        // Из кэша, без синхронного пересчёта на каждой загрузке админки — см.
        // DelegationKpi::getNavigationBadge (тяжёлый snapshot ронял панель в 500).
        return \Illuminate\Support\Facades\Cache::get('nav_badge.goal_checkins');
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * Значение поля ручного ввода per-goal, ключ — goal_id. Bind'ится
     * wire:model из blade; проще выделенной Filament-формы для единственного
     * поля на карточку.
     *
     * @var array<int, string>
     */
    public array $checkinValue = [];

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $snap = app(GoalCheckinService::class)->snapshot();

        \Illuminate\Support\Facades\Cache::put(
            'nav_badge.goal_checkins',
            $snap['ok'] ? null : '!',
            now()->addMinutes(30),
        );

        return [
            'snap' => $snap,
        ];
    }

    /**
     * Ручной check-in: берёт введённое значение метрики из $checkinValue и
     * фиксирует его как GoalCheckin-строку. Единственное действие на этой
     * странице помимо чтения, поэтому обходимся без отдельного ресурса/формы.
     */
    public function recordCheckin(int $goalId): void
    {
        $raw = $this->checkinValue[$goalId] ?? null;

        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            Notification::make()->title('Введите числовое значение')->danger()->send();

            return;
        }

        $goal = Goal::query()->findOrFail($goalId);

        app(GoalCheckinService::class)->recordCheckin($goal, (float) $raw);

        Notification::make()
            ->title('Check-in записан')
            ->body($goal->title.': '.$raw.' ('.$goal->target_metric.')')
            ->success()
            ->send();

        unset($this->checkinValue[$goalId]);
    }
}
