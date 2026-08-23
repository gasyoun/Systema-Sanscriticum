<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\CourseDesignAssets\CourseDesignStatsWidget;
use App\Models\Course;
use App\Models\CourseDesignAsset;
use App\Services\Design\CourseDesignAssetService;
use App\Services\Design\CoverImportOutcome;
use App\Services\Design\CoverToBannerImporter;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * «Дизайн курсов» — учёт работы дизайнера: матрица курс × формат баннера.
 *
 * Форма страницы (Page + HasTable) взята с LessonMaterials — это единственный
 * готовый в проекте паттерн «матрица готовности по сущности с файлами».
 *
 * Гейт — admin + manager (super_admin проходит через RoleGate::any). Роль
 * «designer» сознательно не заводилась: заказчик решил обойтись существующими.
 */
class CourseDesignAssets extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Дизайн курсов';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 35;

    protected static ?string $title = 'Дизайн курсов';

    protected static ?string $slug = 'course-design';

    protected static string $view = 'filament.pages.course-design-assets';

    /**
     * Сколько курсов переносим за один массовый заход.
     *
     * Кадрирование синхронное и упирается в max_execution_time; выбрать «все»
     * при сотне курсов — это гарантированный обрыв на середине без внятного
     * сообщения. Потолок мягкий: выборку просят разбить, а не режут молча.
     */
    private const BULK_LIMIT = 100;

    public static function canAccess(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    /**
     * Число незакрытых слотов. Из кэша: пересчитывать снапшот на каждый хит
     * админки нельзя — то же правило соблюдают DelegationKpi,
     * ReceivablesGovernance и WorkQueue.
     */
    public static function getNavigationBadge(): ?string
    {
        $missing = Cache::remember(
            'course_design:missing_slots',
            now()->addMinutes(15),
            fn (): int => app(CourseDesignAssetService::class)->snapshot()['missingSlots'],
        );

        return $missing > 0 ? (string) $missing : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    protected function getHeaderWidgets(): array
    {
        return [CourseDesignStatsWidget::class];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Course::query()
                ->with(['designAssets.uploader'])
                // Суммы считает БД, а не PHP по загруженной связи: так колонка
                // «Объём» ещё и сортируется, и это не ломается на большом списке.
                ->withSum('designAssets as design_image_bytes', 'size')
                ->withSum('designAssets as design_psd_bytes', 'psd_size'))
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')->label('Курс')->searchable()->wrap()->limit(70),

                ...$this->formatColumns(),

                TextColumn::make('psd_state')->label('PSD')->alignCenter()->badge()
                    ->getStateUsing(fn (Course $record): string => self::psdCount($record).'/'.count(self::formats()))
                    ->color(fn (Course $record): string => self::psdCount($record) === count(self::formats()) ? 'success' : 'warning')
                    ->tooltip('Слотов, у которых учтён исходник (ссылка или файл)'),

                TextColumn::make('readiness')->label('Готовность')->alignCenter()->badge()
                    ->getStateUsing(function (Course $record): string {
                        $r = $record->designReadiness();

                        return $r['done'].'/'.$r['total'];
                    })
                    ->color(fn (Course $record): string => $record->designReadiness()['missing'] === [] ? 'success' : 'danger'),

                // Объём картинок курса. Исходники PSD в цифру не входят —
                // спрошено было про картинки, а исходник может быть в разы
                // тяжелее и перекрыл бы смысл колонки. Он показан в подсказке,
                // и он же учтён в карточке «Занято на диске».
                TextColumn::make('design_image_bytes')->label('Объём')->alignRight()->sortable()
                    ->getStateUsing(fn (Course $record): string => CourseDesignAssetService::formatBytes((int) $record->design_image_bytes))
                    ->tooltip(function (Course $record): ?string {
                        $psd = (int) $record->design_psd_bytes;

                        return $psd > 0
                            ? 'Плюс исходники PSD: '.CourseDesignAssetService::formatBytes($psd)
                            : 'Залитых файлов PSD нет';
                    }),

                TextColumn::make('last_uploader')->label('Кто')->toggleable()
                    ->getStateUsing(fn (Course $record): string => self::latestAsset($record)?->uploader?->name ?? '—'),

                TextColumn::make('last_uploaded_at')->label('Когда')->toggleable()
                    ->getStateUsing(fn (Course $record): ?string => self::latestAsset($record)?->updated_at?->format('d.m.Y H:i'))
                    ->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Активность курса')
                    ->placeholder('Все')->trueLabel('Активные')->falseLabel('Неактивные')
                    ->default(true),

                TernaryFilter::make('incomplete')
                    ->label('Комплектность')
                    ->placeholder('Все')
                    ->trueLabel('Только неукомплектованные')
                    ->falseLabel('Только укомплектованные')
                    ->queries(
                        // Отдельного подзапроса на формат ровно столько, сколько
                        // форматов в конфиге (сейчас три) — зато без having/raw и
                        // одинаково работает на MySQL и SQLite.
                        true: fn (Builder $query): Builder => $query->where(function (Builder $q): void {
                            foreach (self::formats() as $format) {
                                $q->orWhereDoesntHave('designAssets', fn (Builder $a) => $a->where('format', $format)->whereNotNull('path'));
                            }
                        }),
                        false: fn (Builder $query): Builder => $query->where(function (Builder $q): void {
                            foreach (self::formats() as $format) {
                                $q->whereHas('designAssets', fn (Builder $a) => $a->where('format', $format)->whereNotNull('path'));
                            }
                        }),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->actions([
                $this->uploadAction(),
                $this->downloadZipAction(),
                Tables\Actions\ActionGroup::make([
                    $this->importCoverAction(),
                    ...$this->openImageActions(),
                    ...$this->openPsdActions(),
                    $this->clearSlotAction(),
                ])->label('Файлы')->icon('heroicon-m-ellipsis-horizontal'),
            ])
            ->bulkActions([
                $this->importCoversBulkAction(),
            ])
            ->defaultPaginationPageOption(50)
            ->paginated([25, 50, 100, 'all']);
    }

    /** @return list<string> */
    private static function formats(): array
    {
        /** @var list<string> $formats */
        $formats = (array) config('design_assets.formats', []);

        return $formats;
    }

    private static function assetFor(Course $course, string $format): ?CourseDesignAsset
    {
        return $course->designAssets->firstWhere('format', $format);
    }

    /** Сколько требуемых слотов курса имеют учтённый исходник. */
    private static function psdCount(Course $course): int
    {
        return $course->designAssets
            ->filter(fn (CourseDesignAsset $a): bool => in_array($a->format, self::formats(), true) && $a->hasPsd())
            ->count();
    }

    private static function latestAsset(Course $course): ?CourseDesignAsset
    {
        return $course->designAssets->sortByDesc('updated_at')->first();
    }

    /** Колонка-галочка на каждый требуемый формат — состав берётся из конфига. */
    private function formatColumns(): array
    {
        return array_map(
            fn (string $format): IconColumn => IconColumn::make('design_'.CourseDesignAsset::slugForFormat($format))
                ->label($format)
                ->alignCenter()
                ->boolean()
                ->getStateUsing(fn (Course $record): bool => filled(self::assetFor($record, $format)?->path))
                ->color(function (Course $record) use ($format): string {
                    $asset = self::assetFor($record, $format);
                    if (! $asset || blank($asset->path)) {
                        return 'danger';
                    }

                    // Пропорция не сошлась — жёлтый: файл есть, но, возможно, не тот.
                    return $asset->ratioMatches() ? 'success' : 'warning';
                })
                ->tooltip(fn (Course $record): ?string => self::slotTooltip($record, $format)),
            self::formats(),
        );
    }

    private static function slotTooltip(Course $course, string $format): ?string
    {
        $asset = self::assetFor($course, $format);
        if (! $asset || blank($asset->path)) {
            return 'Не загружено';
        }

        $parts = [];
        if ($asset->width && $asset->height) {
            $parts[] = $asset->width.'×'.$asset->height.($asset->ratioMatches() ? '' : ' — пропорция не совпадает с '.$format);
        }
        $parts[] = $asset->uploader?->name ?? 'автор неизвестен';
        $parts[] = $asset->updated_at?->format('d.m.Y H:i') ?? '';
        $parts[] = $asset->hasPsd() ? 'исходник учтён' : 'исходник НЕ учтён';

        return implode(' · ', array_filter($parts));
    }

    private function uploadAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('upload')
            ->label('Загрузить')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('primary')
            ->modalHeading(fn (Course $record): string => 'Баннер курса «'.$record->title.'»')
            ->modalSubmitActionLabel('Сохранить')
            ->form(fn (Course $record): array => [
                Forms\Components\Select::make('format')
                    ->label('Формат')
                    ->options(fn (): array => collect(self::formats())
                        ->mapWithKeys(fn (string $f): array => [
                            $f => $f.(filled(self::assetFor($record, $f)?->path) ? ' — уже загружен, будет заменён' : ' — пусто'),
                        ])->all())
                    ->default(fn (): ?string => $record->designReadiness()['missing'][0] ?? (self::formats()[0] ?? null))
                    ->required()
                    ->live()
                    // Ссылку на исходник подставляем из уже сохранённого слота:
                    // присланное форма считает желаемым состоянием, и без
                    // предзаполнения смена картинки затирала бы прежнюю ссылку.
                    ->afterStateUpdated(fn (Forms\Set $set, ?string $state) => $set(
                        'psd_url',
                        $state ? self::assetFor($record, $state)?->psd_url : null,
                    )),

                Forms\Components\FileUpload::make('image')
                    ->label('Картинка')
                    ->image()
                    // Точный список вместо широкого ->image(): тот пускает и
                    // SVG, а он исполняем в браузере. Серверный джейл в
                    // CourseDesignAssetService остаётся главной защитой.
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                    ->storeFiles(false)
                    ->maxSize((int) config('design_assets.max_image_kb'))
                    ->helperText('Оставьте пустым, чтобы поменять только исходник у уже загруженного слота.'),

                // Ссылка необязательна: картинку часто заливают раньше, чем
                // дизайнер выложил исходник в облако, и требование ссылки
                // блокировало саму загрузку баннера. Незаполненный исходник
                // видно по колонке PSD и подписи «исходник НЕ учтён» —
                // сигнал остаётся, запрет снят.
                Forms\Components\TextInput::make('psd_url')
                    ->label('Ссылка на исходник PSD')
                    ->url()
                    ->maxLength(255)
                    ->default(fn (): ?string => self::assetFor($record, $record->designReadiness()['missing'][0] ?? (self::formats()[0] ?? ''))?->psd_url)
                    ->helperText('Необязательна: облачная папка (Яндекс.Диск, Drive) с исходником этого баннера. Без неё слот считается без учтённого исходника.'),

                Forms\Components\FileUpload::make('psd')
                    ->label('Файл исходника (необязательно)')
                    ->storeFiles(false)
                    ->maxSize((int) config('design_assets.max_psd_kb'))
                    ->helperText('Только если PSD проходит по лимиту — на проде это 100 МБ. Тяжёлые исходники учитываются ссылкой.'),
            ])
            ->action(function (Course $record, array $data, CourseDesignAssetService $service): void {
                try {
                    $asset = $service->store(
                        $record,
                        (string) $data['format'],
                        self::file($data['image'] ?? null),
                        filled($data['psd_url'] ?? null) ? (string) $data['psd_url'] : null,
                        self::file($data['psd'] ?? null),
                        auth()->user(),
                    );
                } catch (\Throwable $e) {
                    Notification::make()->title('Не сохранили')->body($e->getMessage())->danger()->send();

                    return;
                }

                $this->refreshStats();

                $note = Notification::make()->title('Слот '.$asset->format.' сохранён')->success();
                if (! $asset->ratioMatches()) {
                    $note->body('Пропорция картинки ('.$asset->width.'×'.$asset->height.') не совпадает с '.$asset->format.' — файл сохранён, но проверьте.');
                    $note->warning();
                }
                $note->send();
            });
    }

    /**
     * Ссылка, а не ->action(): собирать архив внутри действия и редиректить на
     * выдачу нельзя — Filament оставляет после этого пустую модалку «ZIP» с
     * Submit/Cancel (поймано прокликиванием). Сборку делает сам маршрут.
     */
    private function downloadZipAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('downloadZip')
            ->label('ZIP')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (Course $record): bool => $record->designAssets->contains(
                fn (CourseDesignAsset $a): bool => filled($a->path),
            ))
            ->url(fn (Course $record): string => route('course-design.archive', $record));
    }

    /** @return list<Tables\Actions\Action> */
    private function openImageActions(): array
    {
        return array_map(
            fn (string $format): Tables\Actions\Action => Tables\Actions\Action::make('img_'.CourseDesignAsset::slugForFormat($format))
                ->label('Картинка '.$format)
                ->icon('heroicon-o-photo')
                ->visible(fn (Course $record): bool => filled(self::assetFor($record, $format)?->path))
                ->url(fn (Course $record): ?string => self::assetFor($record, $format)?->imageUrl(), shouldOpenInNewTab: true),
            self::formats(),
        );
    }

    /** @return list<Tables\Actions\Action> */
    private function openPsdActions(): array
    {
        return array_map(
            fn (string $format): Tables\Actions\Action => Tables\Actions\Action::make('psd_'.CourseDesignAsset::slugForFormat($format))
                ->label('PSD '.$format)
                ->icon('heroicon-o-paper-clip')
                ->visible(fn (Course $record): bool => filled(self::assetFor($record, $format)?->psd_path))
                ->url(function (Course $record) use ($format): ?string {
                    $asset = self::assetFor($record, $format);

                    return $asset ? route('course-design.psd', $asset) : null;
                }, shouldOpenInNewTab: true),
            self::formats(),
        );
    }

    private function clearSlotAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('clearSlot')
            ->label('Очистить слот')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (Course $record): bool => $record->designAssets->isNotEmpty())
            ->requiresConfirmation()
            ->modalHeading('Очистить слот')
            ->modalDescription('Картинка и залитый исходник будут удалены с диска безвозвратно.')
            ->form(fn (Course $record): array => [
                Forms\Components\Select::make('format')
                    ->label('Формат')
                    ->options(fn (): array => $record->designAssets
                        ->pluck('format', 'format')
                        ->all())
                    ->required(),
            ])
            ->action(function (Course $record, array $data, CourseDesignAssetService $service): void {
                $asset = self::assetFor($record, (string) $data['format']);
                if (! $asset) {
                    Notification::make()->title('Слот уже пуст')->warning()->send();

                    return;
                }

                $service->delete($asset);
                $this->refreshStats();

                Notification::make()->title('Слот '.$data['format'].' очищен')->success()->send();
            });
    }

    /**
     * Перенос обложки витрины в пустой слот 4:3.
     *
     * В группе «Файлы», а не четвёртой кнопкой в строке: строка уже несёт
     * «Загрузить» и «ZIP», а это разовая операция по курсу — массовый сценарий
     * закрывает bulk-действие ниже.
     */
    private function importCoverAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('importCover')
            ->label('Обложка → '.CoverToBannerImporter::FORMAT)
            ->icon('heroicon-o-scissors')
            ->visible(fn (Course $record): bool => filled($record->image_path))
            ->disabled(fn (Course $record): bool => filled(self::assetFor($record, CoverToBannerImporter::FORMAT)?->path))
            ->tooltip(fn (Course $record): string => filled(self::assetFor($record, CoverToBannerImporter::FORMAT)?->path)
                ? 'Слот '.CoverToBannerImporter::FORMAT.' уже занят — переносим только в пустой'
                : 'Кадрируем обложку витрины по центру; на витрине она останется прежней')
            ->requiresConfirmation()
            ->modalHeading('Перенести обложку в слот '.CoverToBannerImporter::FORMAT)
            ->modalDescription('Обложку витрины кадрируем по центру до пропорции '.CoverToBannerImporter::FORMAT.' и кладём в пустой слот. Картинка на витрине не меняется, слот с уже загруженным баннером не трогаем.')
            ->modalSubmitActionLabel('Перенести')
            ->action(function (Course $record, CoverToBannerImporter $importer): void {
                $result = $importer->import($record, auth()->user());

                if ($result->isImported()) {
                    $this->refreshStats();

                    Notification::make()
                        ->title('Слот '.$result->asset->format.' заполнен')
                        ->body('Обложка кадрирована до '.$result->asset->width.'×'.$result->asset->height.'. Картинка на витрине не изменилась.')
                        ->success()
                        ->send();

                    return;
                }

                $failed = $result->outcome === CoverImportOutcome::Failed;

                $note = Notification::make()
                    ->title($failed ? 'Не перенесли' : 'Переносить нечего')
                    ->body(trim(Str::ucfirst($result->outcome->label()).($failed ? '. Подробности в журнале.' : '.')));

                $failed ? $note->danger() : $note->warning();
                $note->send();
            });
    }

    /**
     * То же самое для выделенных курсов.
     *
     * Отчёт агрегированный по конструкции: при полусотне выделенных поимённый
     * список превратился бы в простыню, а «пропустили 12 (слот уже занят — 9,
     * нет обложки витрины — 3)» человек читает за секунду и понимает, что
     * делать дальше.
     */
    private function importCoversBulkAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('importCovers')
            ->label('Обложки → слот '.CoverToBannerImporter::FORMAT)
            ->icon('heroicon-o-scissors')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Перенести обложки в слот '.CoverToBannerImporter::FORMAT)
            ->modalDescription('У выделенных курсов возьмём обложку витрины, кадрируем её по центру и положим в пустой слот. Курсы без обложки и уже занятые слоты пропустим, картинки на витрине останутся прежними.')
            ->modalSubmitActionLabel('Перенести')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, CoverToBannerImporter $importer): void {
                if ($records->count() > self::BULK_LIMIT) {
                    Notification::make()
                        ->title('Выделено слишком много курсов')
                        ->body('За один заход переносим не больше '.self::BULK_LIMIT.' курсов — иначе запрос не успеет отработать. Разбейте выборку.')
                        ->warning()
                        ->send();

                    return;
                }

                $actor = auth()->user();
                $done = 0;
                $failed = 0;
                /** @var array<string, int> $skips причина → сколько курсов */
                $skips = [];

                foreach ($records as $course) {
                    $outcome = $importer->import($course, $actor)->outcome;

                    if ($outcome === CoverImportOutcome::Imported) {
                        $done++;
                    } elseif ($outcome === CoverImportOutcome::Failed) {
                        $failed++;
                    } else {
                        $skips[$outcome->label()] = ($skips[$outcome->label()] ?? 0) + 1;
                    }
                }

                if ($done > 0) {
                    $this->refreshStats();
                }

                $body = [];
                if ($skips !== []) {
                    $parts = [];
                    foreach ($skips as $why => $count) {
                        $parts[] = $why.' — '.$count;
                    }
                    $body[] = 'Пропустили: '.array_sum($skips).' ('.implode(', ', $parts).')';
                }
                if ($failed > 0) {
                    $body[] = 'Ошибок: '.$failed.', подробности в журнале.';
                }

                $note = Notification::make()
                    ->title('Перенесли обложек: '.$done)
                    ->body($body === [] ? null : implode(' · ', $body));

                if ($failed > 0) {
                    $note->danger()->persistent();
                } elseif ($done > 0) {
                    $note->success();
                } else {
                    $note->warning()->persistent();
                }

                $note->send();
            });
    }

    /**
     * Из состояния FileUpload достаём именно загруженный файл.
     * При storeFiles(false) Filament держит его в массиве с ключом-uuid, поэтому
     * разворачиваем массив — Livewire\...\TemporaryUploadedFile наследует
     * Illuminate\Http\UploadedFile, и сервис принимает его как обычный файл.
     */
    private static function file(mixed $state): ?UploadedFile
    {
        $file = is_array($state) ? Arr::first($state) : $state;

        return $file instanceof UploadedFile ? $file : null;
    }

    /**
     * Сбросить кэш бейджа и перерисовать виджет-шапку.
     *
     * Без явного события карточки показывали бы прежние цифры («Пустых слотов 6»
     * при пяти оставшихся) до перезагрузки страницы — таблица-то обновляется
     * сама, а виджет живёт отдельным Livewire-компонентом.
     */
    private function refreshStats(): void
    {
        Cache::forget('course_design:missing_slots');
        $this->dispatch('design-assets-updated');
    }
}
