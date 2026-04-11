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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ListSchedules extends ListRecords
{
    protected static string $resource = ScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 1. Стандартная кнопка
            Actions\CreateAction::make()->label('Добавить вручную'),

            // ==========================================
            // 2. ОБНОВЛЕННЫЙ ГЕНЕРАТОР ПОТОКА
            // ==========================================
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

                    // Даты, Время и Смещение
                    \Filament\Forms\Components\Grid::make(3)
                        ->schema([
                            DatePicker::make('start_date')
                                ->label('Дата занятия')
                                ->required()
                                ->default(now()),

                            TimePicker::make('start_time')
                                ->label('Время начала')
                                ->required()
                                ->default('19:00')
                                ->seconds(false),
                                
                            // --- НОВОЕ ПОЛЕ: Смещение ---
                            TextInput::make('start_index')
                                ->label('Начать с урока №')
                                ->numeric()
                                ->default(1)
                                ->required(),
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
                    
                    // Учитываем, с какого урока начинаем (минус 1, так как массивы начинаются с 0)
                    $startIndex = (int) $data['start_index'] - 1;
                    
                    $baseDescription = 'Автоматически создано по курсу ' . $course->title;
                    if (!empty($data['zoom_url'])) {
                        $baseDescription .= "\n\nСсылка на урок: " . $data['zoom_url'];
                    }

                    $createdSchedules = [];

                    Schedule::withoutEvents(function () use ($count, $lessons, $data, $startDate, $baseDescription, $startIndex, &$createdSchedules) {
                        
                        for ($i = 0; $i < $count; $i++) {
                            // Вычисляем реальный индекс урока
                            $currentLessonIndex = $startIndex + $i;
                            
                            $lessonTitle = isset($lessons[$currentLessonIndex]) 
                                ? $lessons[$currentLessonIndex]->title 
                                : 'Занятие ' . ($currentLessonIndex + 1);

                            $schedule = Schedule::create([
                                'title'       => $lessonTitle,
                                'group_id'    => $data['group_id'],
                                'course_id'   => $data['course_id'],
                                'start'       => $startDate->copy(),
                                'end'         => $startDate->copy()->addHours(2),
                                'color'       => '#3788d8',
                                'description' => $baseDescription, 
                            ]);

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

            // ==========================================
            // 3. НОВЫЙ ИНСТРУМЕНТ: МАССОВЫЙ ПЕРЕНОС
            // ==========================================
            Actions\Action::make('shift_schedule')
                ->label('Перенести расписание')
                ->icon('heroicon-o-calendar-days')
                ->color('warning')
                ->modalHeading('Сдвиг расписания группы')
                ->modalDescription('Если занятие отменилось, выберите группу и дату отмененного занятия. Система сдвинет это и все последующие занятия на указанное количество дней.')
                ->form([
                    Select::make('group_id')
                        ->label('Какую группу двигаем?')
                        ->relationship('group', 'name')
                        ->required(),

                    DatePicker::make('from_date')
                        ->label('Начиная с какой даты?')
                        ->required()
                        ->default(now()),

                    TextInput::make('shift_days')
                        ->label('На сколько дней сдвинуть?')
                        ->numeric()
                        ->default(7)
                        ->required()
                        ->helperText('Положительное число двигает вперед (напр: 7), отрицательное - назад (напр: -7).'),
                ])
                ->action(function (array $data) {
                    $shiftDays = (int) $data['shift_days'];
                    
                    // Находим все занятия этой группы, начиная с указанной даты (включая саму дату)
                    $schedules = Schedule::where('group_id', $data['group_id'])
                        ->where('start', '>=', Carbon::parse($data['from_date'])->startOfDay())
                        ->get();

                    $shiftedCount = 0;

                    // Отключаем ивенты, чтобы не спамить n8n по одному обновлению (если у тебя там висят триггеры)
                    Schedule::withoutEvents(function () use ($schedules, $shiftDays, &$shiftedCount) {
                        foreach ($schedules as $schedule) {
                            $schedule->update([
                                'start' => Carbon::parse($schedule->start)->addDays($shiftDays),
                                'end'   => Carbon::parse($schedule->end)->addDays($shiftDays),
                            ]);
                            $shiftedCount++;
                        }
                    });

                    Notification::make()
                        ->title("Успешно сдвинуто занятий: $shiftedCount")
                        ->success()
                        ->send();
                }),

            // 4. Кнопка очистки
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