<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\RoleGate;
use Filament\Pages\Page;

/**
 * Планирование (Нескучные финансы) — гибридная сторона H207: то, что нельзя
 * авто-считать из данных LMS (сценарная финмодель, бюджет, план доходов/
 * расходов), остаётся ручными excel-workbooks. Здесь — ссылки на их скачивание.
 * Доступ — бухгалтер/супер-админ, как у остальных финансовых экранов.
 */
class FinancePlanning extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 84;

    protected static ?string $navigationLabel = 'Планирование (шаблоны)';

    protected static ?string $title = 'Финансовое планирование — шаблоны';

    protected static ?string $slug = 'finance-planning';

    protected static string $view = 'filament.pages.finance-planning';

    public static function canAccess(): bool
    {
        return RoleGate::accounting();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::accounting();
    }

    /**
     * Планировочные workbooks «Нескучных финансов», прикреплённые как скачивание.
     *
     * @return array<int,array{key:string,title:string,note:string}>
     */
    public function templates(): array
    {
        return [
            [
                'key' => 'finmodel',
                'title' => 'Финансовая модель',
                'note' => 'Сценарное планирование (unit-экономика, драйверы). Слишком forward-looking, чтобы считать автоматически — ведётся вручную.',
            ],
            [
                'key' => 'budget',
                'title' => 'Бюджет',
                'note' => 'Плановый бюджет по периодам. Кандидат в источник план-факт против живого ОПиУ.',
            ],
            [
                'key' => 'plan-income-expense',
                'title' => 'План доходов и расходов',
                'note' => 'Ближе к строкам ОПиУ. Второй кандидат на план-факт — канонический выберем позже.',
            ],
        ];
    }
}
