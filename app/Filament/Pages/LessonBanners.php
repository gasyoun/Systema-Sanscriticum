<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Course;
use App\Models\LessonBanner;
use App\Models\LessonBannerTemplate;
use App\Services\Banners\LessonBannerRenderer;
use App\Services\Banners\LessonBannerService;
use App\Services\Banners\LessonBannerTemplateStore;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * «Плашки занятий» — шаблоны курсов и плашки ближайших занятий.
 *
 * Шаблон заводится здесь: фон и spec готовит scripts/banner_template_from_psd.py
 * из PSD курса; превью рисуется тем же рендером, что и ночной прогон, — что
 * видно здесь, то и уйдёт на Диск. Доставку в папки групп делает n8n
 * «Плашки занятий»; её статус пишется обратно и виден в таблице.
 *
 * Гейт — admin + manager, как у «Дизайна курсов».
 */
class LessonBanners extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Плашки занятий';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 36;

    protected static ?string $title = 'Плашки занятий';

    protected static ?string $slug = 'lesson-banners';

    protected static string $view = 'filament.pages.lesson-banners';

    public ?string $previewDataUri = null;

    public ?string $previewCaption = null;

    public static function canAccess(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public function isEnabled(): bool
    {
        return (bool) config('features.lesson_banners', false);
    }

    /** @return Collection<int, LessonBannerTemplate> */
    public function templates(): Collection
    {
        return LessonBannerTemplate::query()
            ->with(['course:id,title', 'group:id,name'])
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get();
    }

    public function preview(int $templateId): void
    {
        $service = app(LessonBannerService::class);
        $renderer = app(LessonBannerRenderer::class);
        $template = LessonBannerTemplate::query()->find($templateId);
        if ($template === null) {
            return;
        }

        // Пробная дата и двузначный номер — самый широкий случай для fit.
        $start = Carbon::tomorrow()->setTime(19, 0);
        try {
            $jpeg = $renderer->render($template, $service->textsFor($template, $start, 12, false));
        } catch (\Throwable $e) {
            Notification::make()->title('Превью не отрисовалось')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->previewDataUri = 'data:image/jpeg;base64,'.base64_encode($jpeg);
        $this->previewCaption = ($template->course?->title ?? 'Курс').($template->group ? ' · '.$template->group->name : '')
            .' · v'.$template->version.' · пробные «'.$start->locale('ru')->isoFormat('D MMMM').'», №12';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newTemplate')
                ->label('Новый шаблон')
                ->icon('heroicon-o-plus')
                ->modalHeading('Шаблон плашки')
                ->modalDescription('Фон и spec получаются из PSD курса скриптом scripts/banner_template_from_psd.py. Новый шаблон заменяет прежний для того же курса/группы и перерисовывает будущие плашки.')
                ->form([
                    Forms\Components\Select::make('course_id')
                        ->label('Курс')
                        ->options(fn (): array => Course::query()->orderBy('title')->pluck('title', 'id')->all())
                        ->searchable()
                        ->required()
                        ->live(),
                    Forms\Components\Select::make('group_id')
                        ->label('Только для группы (необязательно)')
                        ->options(fn (Forms\Get $get): array => $get('course_id')
                            ? Course::query()->find($get('course_id'))?->groups()->orderBy('name')->pluck('name', 'groups.id')->all() ?? []
                            : [])
                        ->helperText('Пусто — шаблон на весь курс. Шаблон группы важнее шаблона курса.'),
                    Forms\Components\FileUpload::make('background')
                        ->label('Фон (background.png)')
                        ->acceptedFileTypes(['image/png', 'image/jpeg'])
                        ->storeFiles(false)
                        ->maxSize((int) config('lesson_banners.max_background_kb'))
                        ->required(),
                    Forms\Components\FileUpload::make('overlay')
                        ->label('Верхний слой (overlay.png) — только если скрипт его сделал')
                        ->acceptedFileTypes(['image/png'])
                        ->storeFiles(false)
                        ->maxSize((int) config('lesson_banners.max_background_kb'))
                        ->helperText('Нужен макетам с водяным номером под фото (например, Кочергина). Нет файла overlay.png в папке — поле пустое.'),
                    Forms\Components\FileUpload::make('spec')
                        ->label('Поля (template.json)')
                        ->acceptedFileTypes(['application/json', 'text/plain'])
                        ->storeFiles(false)
                        ->maxSize(256)
                        ->required(),
                    Forms\Components\FileUpload::make('fonts')
                        ->label('Файлы шрифтов (TTF/OTF)')
                        ->multiple()
                        ->storeFiles(false)
                        ->maxSize((int) config('lesson_banners.max_font_kb'))
                        ->helperText('Имена файлов — как в template.json (скрипт их печатает). Шрифты хранятся на сервере вне репозитория; уже загруженные повторно не нужны.'),
                    Forms\Components\FileUpload::make('psd')
                        ->label('PSD-исходник (необязательно, для учёта)')
                        ->storeFiles(false)
                        ->maxSize((int) config('lesson_banners.max_psd_kb')),
                ])
                ->action(function (array $data, LessonBannerTemplateStore $store): void {
                    $background = self::file($data['background'] ?? null);
                    $spec = self::file($data['spec'] ?? null);
                    if ($background === null || $spec === null) {
                        Notification::make()->title('Нужны фон и template.json')->danger()->send();

                        return;
                    }

                    try {
                        $store->storeFonts(array_values(array_filter(
                            array_map(fn ($f) => self::file($f), (array) ($data['fonts'] ?? [])),
                        )));
                        $template = $store->store(
                            (int) $data['course_id'],
                            filled($data['group_id'] ?? null) ? (int) $data['group_id'] : null,
                            $background,
                            (string) file_get_contents($spec->getRealPath()),
                            self::file($data['psd'] ?? null),
                            self::file($data['overlay'] ?? null),
                        );
                    } catch (\Throwable $e) {
                        Notification::make()->title('Шаблон не сохранён')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    $missing = LessonBannerTemplateStore::missingFonts($template);
                    $note = Notification::make()->title('Шаблон v'.$template->version.' сохранён')->success();
                    if ($missing !== []) {
                        $note->warning()->body('Нет шрифтов: '.implode(', ', $missing).' — поля нарисуются запасным шрифтом, пока их не загрузят.');
                    }
                    $note->send();
                    $this->preview($template->id);
                }),

            Action::make('renderNow')
                ->label('Отрисовать сейчас')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Отрисовать плашки на '.(int) config('lesson_banners.lead_days', 7).' дн. вперёд. Неизменившиеся не трогаются.')
                ->disabled(fn (): bool => ! $this->isEnabled())
                ->action(function (LessonBannerService $service): void {
                    $c = $service->run((int) config('lesson_banners.lead_days', 7));
                    Notification::make()
                        ->title('Готово')
                        ->body("Отрисовано {$c['rendered']}, без изменений {$c['unchanged']}, нет шаблона {$c['no_template']}, нет номера {$c['no_number']}, ошибок {$c['failed']}.")
                        ->color($c['failed'] > 0 ? 'danger' : 'success')
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => LessonBanner::query()
                ->with(['schedule.group:id,name'])
                ->whereHas('schedule', fn ($q) => $q->where('start', '>=', now()->subDay())))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('schedule.start')
                    ->label('Занятие')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('schedule.group.name')
                    ->label('Группа')
                    ->searchable(),
                TextColumn::make('lesson_number')
                    ->label('№')
                    ->placeholder('—'),
                ImageColumn::make('preview')
                    ->label('Плашка')
                    ->getStateUsing(fn (LessonBanner $record): ?string => $record->imageUrl())
                    ->height(48),
                TextColumn::make('drive_filename')
                    ->label('Файл на Диске')
                    ->placeholder('—'),
                TextColumn::make('render_status')
                    ->label('Отрисовка')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        LessonBanner::RENDERED => 'готова',
                        LessonBanner::NO_TEMPLATE => 'нет шаблона',
                        LessonBanner::NO_NUMBER => 'нет номера в титуле',
                        default => $state,
                    })
                    ->color(fn (string $state): string => $state === LessonBanner::RENDERED ? 'success' : 'warning'),
                TextColumn::make('delivery_status')
                    ->label('Диск')
                    ->badge()
                    ->placeholder('ждёт n8n')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        LessonBanner::DELIVERED => 'в папке группы',
                        LessonBanner::NO_FOLDER => 'нет папки группы',
                        LessonBanner::DELIVERY_ERROR => 'ошибка',
                        default => (string) $state,
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        LessonBanner::DELIVERED => 'success',
                        null => 'gray',
                        default => 'danger',
                    })
                    ->tooltip(fn (LessonBanner $record): ?string => $record->delivery_error),
            ])
            ->actions([
                Tables\Actions\Action::make('rerender')
                    ->label('Перерисовать')
                    ->icon('heroicon-o-arrow-path')
                    ->disabled(fn (): bool => ! $this->isEnabled())
                    ->action(function (LessonBanner $record, LessonBannerService $service): void {
                        $schedule = $record->schedule;
                        if ($schedule === null) {
                            return;
                        }
                        try {
                            $outcome = $service->syncSchedule($schedule, true);
                        } catch (\Throwable $e) {
                            Notification::make()->title('Не отрисовалась')->body($e->getMessage())->danger()->send();

                            return;
                        }
                        Notification::make()->title('Плашка: '.$outcome)->success()->send();
                    }),
            ])
            ->emptyStateHeading('Плашек пока нет')
            ->emptyStateDescription('Заведите шаблон курса и нажмите «Отрисовать сейчас» (или дождитесь ночного прогона в 04:40).');
    }

    /**
     * Файл из состояния FileUpload при storeFiles(false): массив с ключом-uuid
     * (тот же разбор, что в CourseDesignAssets::file()).
     */
    private static function file(mixed $state): ?UploadedFile
    {
        $file = is_array($state) ? Arr::first($state) : $state;

        return $file instanceof UploadedFile ? $file : null;
    }
}
