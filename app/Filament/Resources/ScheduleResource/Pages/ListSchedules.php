<?php

namespace App\Filament\Resources\ScheduleResource\Pages;

use App\Filament\Resources\ScheduleResource;
use App\Models\Course;
use App\Models\Schedule;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http; // <--- Нужно для отправки вебхука
use Illuminate\Support\Facades\Log;  // <--- Нужно для логов

class ListSchedules extends ListRecords
{
    protected static string $resource = ScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 1. Стандартная кнопка
            Actions\CreateAction::make()->label('Добавить вручную'),

            // 2. Наш Генератор
            Actions\Action::make('generate')
                ->label('Сгенерировать поток')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('success')
                ->form([
                    // Группа и Курс
                    \Filament\Forms\Components\Grid::make(2)
                        ->schema([
                            Select::make('group_id')
                                ->label('Для группы')
                                ->relationship('group', 'name')
                                ->required(),

                            Select::make('course_id')
                                ->label('По курсу')
                                ->options(Course::all()->pluck('title', 'id'))
                                ->searchable()
                                ->required()
                                ->reactive(),
                        ]),

                    // Дата и Время
                    \Filament\Forms\Components\Grid::make(2)
                        ->schema([
                            DatePicker::make('start_date')
                                ->label('Дата первого занятия')
                                ->required()
                                ->default(now()),

                            TimePicker::make('start_time')
                                ->label('Время начала')
                                ->required()
                                ->default('19:00')
                                ->seconds(false),
                        ]),
                    
                    // Ссылка на Zoom
                    TextInput::make('zoom_url')
                        ->label('Ссылка на Zoom / Google Meet')
                        ->placeholder('https://zoom.us/j/...')
                        ->url()
                        ->suffixIcon('heroicon-m-video-camera')
                        ->columnSpanFull(),

                    // Количество
                    TextInput::make('count')
                        ->label('Количество занятий')
                        ->numeric()
                        ->default(16)
                        ->required()
                        ->helperText('Создастся столько событий с интервалом в 1 неделю'),
                ])
                ->action(function (array $data) {
                    $startDate = Carbon::parse($data['start_date'])
                        ->setTimeFromTimeString($data['start_time']);

                    $course = Course::with('lessons')->find($data['course_id']);
                    $lessons = $course ? $course->lessons : collect();
                    $count = (int) $data['count'];
                    
                    // Формируем описание с Zoom ссылкой
                    $baseDescription = 'Автоматически создано по курсу ' . $course->title;
                    if (!empty($data['zoom_url'])) {
                        $baseDescription .= "\n\nСсылка на урок: " . $data['zoom_url'];
                    }

                    // Массив для сбора всех созданных уроков (для n8n)
                    $createdSchedules = [];

                    // === ГЛАВНАЯ МАГИЯ: ОТКЛЮЧАЕМ OBSERVER НА ВРЕМЯ ЦИКЛА ===
                    // Это нужно, чтобы Laravel не отправлял 16 отдельных запросов
                    Schedule::withoutEvents(function () use ($count, $lessons, $data, $startDate, $baseDescription, &$createdSchedules) {
                        
                        for ($i = 0; $i < $count; $i++) {
                            $lessonTitle = isset($lessons[$i]) 
                                ? $lessons[$i]->title 
                                : 'Занятие ' . ($i + 1);

                            // Создаем запись в БД
                            $schedule = Schedule::create([
                                'title'     => $lessonTitle,
                                'group_id'  => $data['group_id'],
                                'course_id' => $data['course_id'],
                                'start'     => $startDate->copy(),
                                'end'       => $startDate->copy()->addHours(2),
                                'color'     => '#3788d8',
                                'description' => $baseDescription, 
                            ]);

                            // Сохраняем данные в массив
                            $createdSchedules[] = [
                                'id' => $schedule->id,
                                'title' => $schedule->title,
                                'start' => $schedule->start->format('d.m.Y H:i'),
                                'group' => $schedule->group ? $schedule->group->name : 'Все',
                                'description' => $schedule->description,
                            ];

                            $startDate->addDays(7);
                        }
                    });

                    // === ОТПРАВЛЯЕМ ОДИН БОЛЬШОЙ ЗАПРОС В n8n ===
                    try {
                        Http::timeout(5)->post('https://context-ai.ru/webhook-test/6a4e0703-4059-47ba-8bad-c3c3d51447ff', [
                            'action' => 'bulk_create',
                            'schedules' => $createdSchedules
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Bulk n8n error: ' . $e->getMessage());
                    }

                    Notification::make()
                        ->title('Успешно создано ' . $count . ' занятий')
                        ->success()
                        ->send();
                }),

            // 3. Кнопка очистки (оставил на всякий случай, если нужна)
            Actions\Action::make('clear_group')
                ->label('Очистить расписание')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Удаление расписания')
                ->form([
                    Select::make('group_id')
                        ->label('Какую группу очистить?')
                        ->relationship('group', 'name')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $count = Schedule::where('group_id', $data['group_id'])
                        ->where('start', '>=', now())
                        ->delete();

                    Notification::make()
                        ->title("Удалено занятий: $count")
                        ->warning()
                        ->send();
                }),
        ];
    }
}