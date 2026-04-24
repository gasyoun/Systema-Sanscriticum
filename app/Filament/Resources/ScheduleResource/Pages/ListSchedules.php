<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScheduleResource\Pages;

use App\Filament\Resources\ScheduleResource;
use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Services\Schedule\DTO\GeneratorConfig;
use App\Services\Schedule\ScheduleGenerator;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ListSchedules extends ListRecords
{
    protected static string $resource = ScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Добавить вручную'),

            // ==========================================
            // НОВЫЙ ГЕНЕРАТОР ПОТОКА (по образу GAS-скрипта)
            // ==========================================
            Actions\Action::make('generate')
                ->label('Сгенерировать поток')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('success')
                ->modalHeading('Генератор расписания')
                ->modalDescription('Создаёт серию занятий по выбранным дням недели с учётом пропусков и переносов.')
                ->modalWidth('5xl')
                ->form([

                    Section::make('Основное')
                        ->columns(2)
                        ->schema([
                            Select::make('group_id')
                                ->label('Группа')
                                ->relationship('group', 'name')
                                ->required()
                                ->reactive()
                                ->afterStateUpdated(function ($state, callable $set): void {
                                    if ($state) {
                                        $set('title', Group::find($state)?->name);
                                    }
                                }),

                            Select::make('course_id')
                                ->label('Курс (опционально, для привязки)')
                                ->options(Course::orderBy('title')->pluck('title', 'id'))
                                ->searchable()
                                ->placeholder('Без привязки'),

                            TextInput::make('title')
                                ->label('Название потока {TITLE}')
                                ->required()
                                ->columnSpanFull()
                                ->helperText('Подставляется в плейсхолдер {TITLE} шаблона. По умолчанию = название группы.'),
                        ]),

                    Section::make('Даты и время')
                        ->columns(4)
                        ->schema([
                            DatePicker::make('start_date')
                                ->label('Дата старта')
                                ->required()
                                ->default(now())
                                ->displayFormat('d.m.Y'),

                            TimePicker::make('start_time')
                                ->label('Время начала')
                                ->seconds(false)
                                ->default('19:00')
                                ->required(),

                            TextInput::make('duration_minutes')
                                ->label('Длительность (мин)')
                                ->numeric()
                                ->minValue(15)
                                ->maxValue(480)
                                ->default(120)
                                ->required(),

                            TextInput::make('total')
                                ->label('Всего занятий')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(500)
                                ->default(60)
                                ->required(),
                        ]),

                    Section::make('Дни и нумерация')
                        ->schema([
                            CheckboxList::make('weekdays')
                                ->label('Дни недели')
                                ->options([
                                    1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт',
                                    5 => 'Пт', 6 => 'Сб', 0 => 'Вс',
                                ])
                                ->columns(7)
                                ->required()
                                ->helperText('Можно выбрать несколько (например, Пн+Ср+Пт).'),

                            Forms\Components\Grid::make(2)->schema([
                                TextInput::make('start_number')
                                    ->label('Начать с № (для {N})')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->helperText('Подставится в плейсхолдер {N}. Удобно при добавлении после переноса.'),

                                TextInput::make('start_lesson_index')
                                    ->label('Начать с урока № (для блоков)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->helperText('Влияет на {BLOCK} и {BN}. Обычно совпадает с предыдущим полем.'),
                            ]),

                            Toggle::make('preserve')
                                ->label('Не трогать прошедшие занятия')
                                ->default(true)
                                ->helperText('При включении прошлые занятия группы остаются как есть, перегенерируются только будущие.'),
                        ]),

                    Section::make('Шаблон темы')
                        ->schema([
                            Textarea::make('template')
                                ->label('Шаблон')
                                ->required()
                                ->rows(3)
                                ->default('{TITLE} (#{N}, {DATE}) | {BN}-е занятие {BLOCK}-го блока')
                                ->helperText(new \Illuminate\Support\HtmlString(
                                    'Доступные плейсхолдеры: '
                                    . '<code>{N}</code> <code>{DATE}</code> <code>{TITLE}</code> '
                                    . '<code>{BLOCK}</code> <code>{BN}</code>. '
                                    . 'Разделитель <code>|</code> режет результат на части: '
                                    . '<b>Название</b> | <b>Описание</b> | <b>Тег</b>.'
                                )),
                        ]),

                    Section::make('Исключения и переносы')
                        ->columns(2)
                        ->schema([
                            Repeater::make('skip_dates')
                                ->label('Пропуски')
                                ->simple(
                                    DatePicker::make('date')
                                        ->required()
                                        ->displayFormat('d.m.Y')
                                )
                                ->defaultItems(0)
                                ->addActionLabel('+ Пропуск')
                                ->reorderable(false)
                                ->helperText('Эти даты будут пропущены, даже если попадают на день недели.'),

                            Repeater::make('add_dates')
                                ->label('Доп. занятия')
                                ->simple(
                                    DatePicker::make('date')
                                        ->required()
                                        ->displayFormat('d.m.Y')
                                )
                                ->defaultItems(0)
                                ->addActionLabel('+ Доп. занятие')
                                ->reorderable(false)
                                ->helperText('Эти даты будут добавлены, даже если не попадают на день недели.'),
                        ]),

                    Section::make('Zoom')
                        ->schema([
                            TextInput::make('link')
                                ->label('Ссылка на Zoom / Google Meet (общая для потока)')
                                ->url()
                                ->maxLength(1024)
                                ->prefixIcon('heroicon-m-video-camera')
                                ->placeholder('https://zoom.us/j/...'),
                        ]),
                ])
                ->action(function (array $data): void {
                    $this->runGeneration($data);
                }),

            // ==========================================
            // СУЩЕСТВУЮЩИЙ: МАССОВЫЙ ПЕРЕНОС
            // ==========================================
            // (оставлено как было — блок shift_schedule не трогаем)
        ];
    }

    /**
     * Запуск генератора + bulk-вебхук в n8n.
     */
    private function runGeneration(array $data): void
    {
        $weekdays = array_map('intval', $data['weekdays'] ?? []);
        if ($weekdays === []) {
            Notification::make()
                ->title('Не выбрано ни одного дня недели')
                ->danger()
                ->send();
            return;
        }

        $config = new GeneratorConfig(
            groupId:           (int) $data['group_id'],
            courseId:          !empty($data['course_id']) ? (int) $data['course_id'] : null,
            title:             (string) $data['title'],
            startDate:         Carbon::parse($data['start_date']),
            startTime:         (string) $data['start_time'],
            durationMinutes:   (int) $data['duration_minutes'],
            totalLessons:      (int) $data['total'],
            startNumber:       (int) $data['start_number'],
            startLessonIndex:  (int) $data['start_lesson_index'],
            weekdays:          $weekdays,
            template:          (string) $data['template'],
            skipDates:         collect($data['skip_dates'] ?? [])->pluck('date')->filter()->values()->all(),
            addDates:          collect($data['add_dates']  ?? [])->pluck('date')->filter()->values()->all(),
            link:              !empty($data['link']) ? (string) $data['link'] : null,
            preserve:          (bool) ($data['preserve'] ?? true),
        );

        try {
            /** @var ScheduleGenerator $generator */
            $generator = app(ScheduleGenerator::class);
            $created = $generator->generate($config);
        } catch (\Throwable $e) {
            Log::error('Schedule generator failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            Notification::make()
                ->title('Ошибка генерации')
                ->body($e->getMessage())
                ->danger()
                ->send();
            return;
        }

        if ($created->isEmpty()) {
            Notification::make()
                ->title('Не удалось создать ни одного занятия')
                ->body('Проверьте дни недели, пропуски и общее количество.')
                ->warning()
                ->send();
            return;
        }

        $this->sendBulkWebhook($created);

        Notification::make()
            ->title('Готово')
            ->body("Создано занятий: {$created->count()}")
            ->success()
            ->send();
    }

    /**
     * Один пакетный вебхук в n8n (вместо 60 отдельных через Observer).
     */
    private function sendBulkWebhook(\Illuminate\Support\Collection $schedules): void
    {
        try {
            Http::timeout(5)->post(
                'https://context-ai.ru/webhook-test/6a4e0703-4059-47ba-8bad-c3c3d51447ff',
                [
                    'action'    => 'bulk_create',
                    'schedules' => $schedules->map(fn (Schedule $s) => [
                        'id'          => $s->id,
                        'title'       => $s->title,
                        'start'       => $s->start->format('d.m.Y H:i'),
                        'group'       => $s->group?->name ?? 'Все',
                        'description' => $s->description,
                        'link'        => $s->link,
                    ])->all(),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Bulk n8n webhook error: ' . $e->getMessage());
        }
    }
}