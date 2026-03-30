<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class CourseEarningsChart extends ChartWidget
{
    protected static ?string $heading = 'Выручка по курсам';
    protected static ?int $sort = 2;
    protected static ?string $maxHeight = '300px';
    
    protected int | string | array $columnSpan = 'full';

    protected function getData(): array
    {
        // Группируем успешные платежи по курсам через БД для скорости
        $payments = Payment::whereIn('status', ['success', 'paid'])
            ->whereNotNull('course_id')
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