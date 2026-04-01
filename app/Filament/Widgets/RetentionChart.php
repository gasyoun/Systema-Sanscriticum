<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class RetentionChart extends ChartWidget
{
    protected static ?string $heading = 'Доходимость уроков (Retention)';
    protected static ?int $sort = 4;
    protected static ?string $maxHeight = '300px';
    
    public static function canView(): bool
    {
        return false; // Возвращаем false, и виджет полностью исчезает
    }
    // Растягиваем график на всю ширину страницы
    protected int | string | array $columnSpan = 'full'; 

    protected function getData(): array
    {
        // Берем данные прямо из таблицы-связки lesson_user, которую ты прописал в модели User
        $completions = DB::table('lesson_user')
            ->select('lesson_id', DB::raw('COUNT(DISTINCT user_id) as users_count'))
            ->groupBy('lesson_id')
            ->orderBy('lesson_id')
            ->take(20) // Берем первые 20 уроков, чтобы график не сплющило
            ->get();

        $labels = [];
        $data = [];

        foreach ($completions as $index => $item) {
            // Если у тебя в модели Lesson есть поле title, можно сделать запрос к ней.
            // Но для безопасности пока выводим просто порядковый номер урока.
            $labels[] = 'Урок ' . ($index + 1); 
            $data[] = $item->users_count;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Студентов прошло',
                    'data' => $data,
                    'borderColor' => '#ef4444', // Красная линия
                    'backgroundColor' => 'rgba(239, 68, 68, 0.2)', // Полупрозрачная заливка
                    'fill' => true,
                    'tension' => 0.4, // Плавный изгиб линии
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line'; // Линейный график идеально подходит для отслеживания падения доходимости
    }
}