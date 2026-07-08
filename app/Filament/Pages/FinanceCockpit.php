<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\FinanceCockpitReport;
use App\Support\RoleGate;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Финансовый штурвал — управленческие отчёты в стиле «Нескучных финансов»:
 * ОПиУ (P&L, первый живой отчёт), ДДС, Баланс и платёжный календарь. Считаются
 * из живых данных LMS через FinanceCockpitReport. Доступ — админ ИЛИ бухгалтер
 * (+ супер-админ), ruling MG в H116: страница раскрывает чистую прибыль и маржу.
 */
class FinanceCockpit extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 80;

    protected static ?string $navigationLabel = 'Финансовый штурвал';

    protected static ?string $title = 'Финансовый штурвал';

    protected static ?string $slug = 'finance-cockpit';

    protected static string $view = 'filament.pages.finance-cockpit';

    /** Выбранный период отчёта в формате 'YYYY-MM'. */
    public string $period = '';

    /** Горизонт платёжного календаря в днях. */
    public int $calendarDays = 30;

    public static function canAccess(): bool
    {
        return RoleGate::finance();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::finance();
    }

    public function mount(): void
    {
        if ($this->period === '') {
            $this->period = now()->format('Y-m');
        }
    }

    /**
     * Последние 12 месяцев для селектора периода: ['YYYY-MM' => 'Месяц YYYY'].
     *
     * @return array<string,string>
     */
    public function periodOptions(): array
    {
        $months = [];
        $cursor = now()->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $key = $cursor->format('Y-m');
            $months[$key] = $cursor->translatedFormat('LLLL Y');
            $cursor = $cursor->subMonth();
        }

        return $months;
    }

    /** @return array<string,mixed> */
    public function getOpiu(): array
    {
        return app(FinanceCockpitReport::class)->opiu($this->period);
    }

    /** ОПиУ по методу начисления (accrual): выручка = признания RevenueSchedule. @return array<string,mixed> */
    public function getOpiuAccrual(): array
    {
        return app(FinanceCockpitReport::class)->opiuAccrual($this->period);
    }

    /** Отложенная выручка на конец периода (оплачено − признано). @return array<string,mixed> */
    public function getDeferredRevenue(): array
    {
        return app(FinanceCockpitReport::class)->deferredRevenue($this->period);
    }

    /** @return array<string,mixed> */
    public function getDds(): array
    {
        return app(FinanceCockpitReport::class)->dds($this->period);
    }

    /** @return array<string,mixed> */
    public function getBalance(): array
    {
        return app(FinanceCockpitReport::class)->balance();
    }

    /** @return array<string,mixed> */
    public function getCalendar(): array
    {
        return app(FinanceCockpitReport::class)->calendar($this->calendarDays);
    }

    /** Читаемая подпись выбранного периода. */
    public function getPeriodLabel(): string
    {
        return Carbon::parse($this->period.'-01')->translatedFormat('LLLL Y');
    }
}
