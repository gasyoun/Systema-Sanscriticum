<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use App\Support\RoleGate;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class CourseEarningsChart extends ChartWidget
{
    protected static ?string $heading = 'Выручка по курсам';

    protected static ?int $sort = 2;

    // H4663 (аудит периметра 14-09, п.3): выручка по ВСЕМ курсам —
    // управленческая цифра, а не витрина куратора. Виджет был единственным
    // из дашборд-набора без canView(): менеджер видел школьную выручку,
    // тогда как его собственная страница продаж сужена до своих сделок
    // (RoleGate::managerSalesReport). Выравниваю на finance()-гейт.
    public static function canView(): bool
    {
        return RoleGate::finance();
    }

    protected static ?string $maxHeight = '300px';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        // Группируем успешные платежи по курсам через БД для скорости.
        // Выплаты ЗП преподавателям — бухгалтерский отток, не выручка курса.
        $payments = Payment::paid()
            ->whereNotNull('course_id')
            ->where('tariff', '!=', 'salary_payout')
            ->select('course_id', DB::raw('SUM(amount) as total'))
            ->groupBy('course_id')
            ->with('course') // Подтягиваем названия курсов
            ->get();

        $labels = [];
        $data = [];

        foreach ($payments as $payment) {
            $labels[] = $payment->course ? $payment->course->title : 'Удаленный курс';
            $data[] = $payment->total;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Выручка (₽)',
                    'data' => $data,
                    'backgroundColor' => '#3b82f6', // Синий цвет
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar'; // Столбчатая диаграмма
    }
}
