<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\ContentCalendarSlotResource\Pages;
use App\Models\ContentCalendarPublication;
use App\Models\ContentCalendarRubric;
use App\Models\ContentCalendarSlot;
use App\Services\Content\PostFormatter;
use App\Services\Content\RubricQueue;
use App\Support\RoleGate;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Table;
use FilamentTiptapEditor\Enums\TiptapOutput;
use FilamentTiptapEditor\TiptapEditor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

/**
 * Monthly content calendar ops surface (H1564). Публикацию делает не этот
 * экран, а content:publish-due → PublishCalendarSlotJob (кросспостинг в
 * TG-каналы и VK-сообщества, 22-10-2026); здесь — текст с разметкой,
 * вложение, выбор площадок и статус публикаций.
 */
class ContentCalendarSlotResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = ContentCalendarSlot::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 138;

    protected static ?string $navigationGroup = 'Маркетинг';

    protected static ?string $navigationLabel = 'Календарь контента';

    protected static ?string $pluralModelLabel = 'Слоты календаря';

    protected static ?string $modelLabel = 'Слот';

    public static function canViewAny(): bool
    {
        return config('features.content_calendar') === true && RoleGate::adminOnly();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return config('features.content_calendar') === true && RoleGate::adminOnly();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\DatePicker::make('slot_date')->label('Дата')->disabled()->hiddenOn('create'),
            Forms\Components\TextInput::make('slot_type')->label('Тип')->disabled()->hiddenOn('create'),
            Forms\Components\TextInput::make('status')->label('Статус')->disabled()->hiddenOn('create'),
            Forms\Components\Select::make('rubric_id')
                ->label('Рубрика')
                ->placeholder('без рубрики')
                ->options(fn (?ContentCalendarSlot $record): array => ContentCalendarRubric::query()
                    ->where(fn (Builder $q) => $q->where('is_active', true)->orWhere('id', $record?->rubric_id))
                    ->orderBy('name')->get()
                    ->mapWithKeys(fn (ContentCalendarRubric $r): array => [$r->id => $r->name.' · '.$r->scheduleLabel().($r->is_active ? '' : ' (выключена)')])
                    ->all())
                ->live()
                ->afterStateUpdated(function (Set $set, mixed $state, string $operation): void {
                    if ($operation === 'create' && filled($state)) {
                        $set('publish_at', null);
                    }
                    $keys = filled($state) ? ContentCalendarRubric::find($state)?->destinationKeys() : null;
                    if ($keys !== null) {
                        $set('destinations', $keys);
                    }
                })
                ->helperText('Пост рубрики без времени публикации встанет на ближайшее свободное время рубрики; '
                    .'галочки «Куда публиковать» встанут по каналам рубрики (их можно поправить); '
                    .'префикс рубрики добавится в начало текста, картинка для сторис соберётся из картинки поста.')
                ->columnSpanFull(),
            Forms\Components\DateTimePicker::make('publish_at')
                ->label('Публикация (MSK)')
                ->seconds(false)
                ->helperText(fn (Get $get): ?string => filled($get('rubric_id')) ? 'Пусто — ближайшее свободное время рубрики.' : null)
                ->required(fn (string $operation, Get $get): bool => $operation === 'create' && blank($get('rubric_id')))
                ->after(fn (string $operation): ?string => $operation === 'create' ? 'now' : null)
                ->default(fn (): string => now('Europe/Moscow')->addDay()->setTime(12, 0)->toDateTimeString()),
            Forms\Components\TextInput::make('title')
                ->label('Заголовок (для себя, в пост не идёт)')
                ->required(fn (string $operation): bool => $operation === 'create')
                ->maxLength(255)
                ->columnSpanFull(),
            TiptapEditor::make('body')
                ->label('Текст')
                ->required(fn (string $operation): bool => $operation === 'create')
                ->tools(['bold', 'italic', 'underline', 'strike', '|', 'link', 'blockquote', 'code', 'code-block', 'highlight'])
                ->output(TiptapOutput::Html)
                ->disableFloatingMenus()
                // Без live(): ответ Livewire заново кладёт state в редактор, а
                // updateEditorContent() делает editor.focus() — клик в другое
                // поле прыгал к редактору наверх и терялся (26-10-2026).
                ->helperText('Маркер = спойлер в Telegram. В VK форматирование не переносится: уходит простой текст, ссылка — «текст (url)». '
                    .'Без вложения — до 4096 символов, с картинкой или видео — до 1024.')
                ->rules([
                    fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        $length = self::postLength(self::bodyHtml($value), $get('rubric_id'));
                        if ($length > PostFormatter::TG_MESSAGE_LIMIT) {
                            $fail('Telegram допускает сообщение до '.PostFormatter::TG_MESSAGE_LIMIT." символов, сейчас {$length}.");
                        }
                    },
                ])
                ->columnSpanFull(),
            Forms\Components\FileUpload::make('media_path')
                ->label('Картинка или видео (необязательно)')
                ->helperText('Одно вложение: JPG/PNG/WebP/GIF или MP4/MOV/WebM, до 50 МБ (предел Telegram для бота). '
                    .'С вложением текст — не длиннее 1024 символов. '
                    .'В VK картинка уходит карточкой-ссылкой с этой картинкой, видео не уходит — '
                    .'для настоящего фото или видео в VK заполните «Готовое медиа VK».')
                ->disk(ContentCalendarSlot::MEDIA_DISK)
                ->directory('content-calendar')
                ->visibility('public')
                ->acceptedFileTypes([
                    'image/jpeg', 'image/png', 'image/webp', 'image/gif',
                    'video/mp4', 'video/quicktime', 'video/webm',
                ])
                ->maxSize(51200)
                // Лимит подписи 1024 проверяется ЗДЕСЬ, а не на тексте: ошибка
                // встаёт у кнопки загрузки. На поле текста Filament уводил
                // страницу к началу редактора, а сама ошибка оставалась под
                // длинным текстом за краем экрана (26-10-2026).
                ->rules([
                    fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        $length = self::postLength(self::bodyHtml($get('body')), $get('rubric_id'));
                        if ($length > PostFormatter::TG_CAPTION_LIMIT) {
                            $fail('С картинкой или видео Telegram допускает текст до '.PostFormatter::TG_CAPTION_LIMIT
                                ." символов, сейчас {$length}. Сократите текст или уберите вложение.");
                        }
                    },
                ])
                ->openable()
                ->downloadable()
                ->columnSpanFull(),
            Forms\Components\FileUpload::make('story_media_path')
                ->label('Картинка для сторис (необязательно)')
                ->helperText('Вертикальная, 1080×1920, JPG/PNG до 10 МБ. Уходит сторис вместе с постом — в отмеченные ниже '
                    .'«Сторис · …»; ссылка в сторис ведёт на samskrte.ru из текста или на вышедший пост в «Оповещении». '
                    .'Без картинки сторис не публикуется.')
                ->disk(ContentCalendarSlot::MEDIA_DISK)
                ->directory('content-calendar/stories')
                ->visibility('public')
                ->image()
                ->acceptedFileTypes(['image/jpeg', 'image/png'])
                ->maxSize(10240)
                ->openable()
                ->downloadable()
                ->columnSpanFull(),
            Forms\Components\Textarea::make('vk_attachments')
                ->label('Готовое медиа VK (необязательно)')
                ->helperText('Ссылки на фото или видео, уже загруженные в VK (vk.com/photo-…, vk.com/video-…, '
                    .'vk.com/clip-…), через пробел или с новой строки — до '.ContentCalendarSlot::VK_ATTACHMENT_LIMIT.'. '
                    .'Прикрепляются к постам в VK вместо файла выше; в Telegram не идут.')
                ->rows(2)
                ->formatStateUsing(fn (?string $state): ?string => blank($state) ? null : str_replace(',', "\n", $state))
                ->dehydrateStateUsing(fn (?string $state): ?string => implode(',', ContentCalendarSlot::parseVkAttachments($state)) ?: null)
                ->rules([
                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (blank($value)) {
                            return;
                        }
                        $ids = ContentCalendarSlot::parseVkAttachments((string) $value);
                        if ($ids === []) {
                            $fail('Не нашёл ссылок на фото или видео VK. Нужны ссылки вида vk.com/photo-236973776_457239017.');
                        } elseif (count($ids) > ContentCalendarSlot::VK_ATTACHMENT_LIMIT) {
                            $fail('VK прикрепляет к посту не больше '.ContentCalendarSlot::VK_ATTACHMENT_LIMIT.' вложений, сейчас '.count($ids).'.');
                        }
                    },
                ])
                ->columnSpanFull(),
            self::destinationsField()
                ->afterStateHydrated(function (Forms\Components\CheckboxList $component, mixed $state): void {
                    if ($state === null) {
                        $component->state(array_keys(ContentCalendarSlot::enabledDestinations()));
                    }
                })
                ->columnSpanFull(),
            Forms\Components\Placeholder::make('vk_preview')
                ->label('Как увидит VK (обновляется после сохранения)')
                ->hiddenOn('create')
                ->content(fn (Get $get): HtmlString => new HtmlString(
                    '<div style="white-space: pre-wrap">'.e(PostFormatter::toPlain(self::bodyHtml($get('body')))).'</div>'
                ))
                ->columnSpanFull(),
            Forms\Components\Placeholder::make('publications_report')
                ->label('Публикации')
                ->visible(fn (?ContentCalendarSlot $record): bool => $record?->publications()->exists() ?? false)
                ->content(fn (ContentCalendarSlot $record): HtmlString => self::publicationsReport($record))
                ->columnSpanFull(),
            Forms\Components\TextInput::make('source_ref')->label('Источник (id)')->disabled(),
        ]);
    }

    /**
     * Галочки площадок: видны все шесть из config/content_calendar.php,
     * ненастроенные (нет chat_id / group_id в .env) — серые, их не выбрать.
     */
    private static function destinationsField(): Forms\Components\CheckboxList
    {
        $enabled = ContentCalendarSlot::enabledDestinations();

        return Forms\Components\CheckboxList::make('destinations')
            ->label('Куда публиковать')
            ->options(array_map(
                static fn (array $d): string => (string) $d['label'],
                (array) config('content_calendar.destinations', []),
            ))
            ->descriptions(array_filter(array_map(
                static fn (array $d): ?string => match (true) {
                    ! in_array($d, $enabled, true) => 'не настроено',
                    ContentCalendarSlot::isStoryDestination($d) => 'только если загружена картинка для сторис',
                    default => null,
                },
                (array) config('content_calendar.destinations', []),
            )))
            ->disableOptionWhen(static fn (string $value): bool => ! isset($enabled[$value]))
            ->rules([
                static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($enabled): void {
                    $off = array_diff((array) $value, array_keys($enabled));
                    if ($off !== []) {
                        $fail('Площадка не настроена: '.implode(', ', $off).'.');
                    }
                },
            ])
            ->default(array_keys($enabled))
            ->helperText($enabled === []
                ? 'Площадки не настроены (CONTENT_CAL_* в .env) — публиковать пока некуда.'
                : null)
            ->columns(2);
    }

    /** Внутри формы TiptapEditor держит JSON-документ; в БД уходит HTML. */
    private static function bodyHtml(mixed $state): ?string
    {
        if (is_array($state)) {
            return tiptap_converter()->asHTML($state);
        }

        return is_string($state) ? $state : null;
    }

    /** Длина поста в Telegram вместе с префиксом рубрики, который добавится при сохранении. */
    private static function postLength(?string $html, mixed $rubricId): int
    {
        $rubric = filled($rubricId) ? ContentCalendarRubric::find($rubricId) : null;

        return PostFormatter::telegramLength($html) + ($rubric?->prefixLength($html) ?? 0);
    }

    private static function publicationsReport(ContentCalendarSlot $record): HtmlString
    {
        $labels = array_map(static fn (array $d): string => (string) $d['label'], (array) config('content_calendar.destinations', []));
        $icons = [
            ContentCalendarPublication::STATUS_PUBLISHED => '✅',
            ContentCalendarPublication::STATUS_PENDING => '⏳',
            ContentCalendarPublication::STATUS_FAILED => '❌',
        ];

        $rows = $record->publications()->orderBy('destination')->get()->map(function (ContentCalendarPublication $p) use ($labels, $icons): string {
            $line = ($icons[$p->status] ?? '').' '.e($labels[$p->destination] ?? $p->destination);
            if ($p->permalink) {
                $line .= ' — <a href="'.e($p->permalink).'" target="_blank" rel="noopener" class="underline">пост</a>';
            }
            if ($p->status !== ContentCalendarPublication::STATUS_PUBLISHED && $p->error) {
                $line .= ' — '.e($p->error).' (попыток: '.$p->attempts.')';
            }
            if ($p->warning) {
                $line .= ' — ⚠️ '.e($p->warning);
            }

            return $line;
        });

        return new HtmlString($rows->implode('<br>'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['publications', 'rubric']))
            ->defaultSort('slot_date')
            ->columns([
                Tables\Columns\TextColumn::make('slot_date')->label('Дата')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('slot_type')->label('Тип')->badge(),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge(),
                Tables\Columns\TextColumn::make('rubric.name')->label('Рубрика')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('title')->label('Заголовок')->limit(40)->wrap(),
                Tables\Columns\TextColumn::make('body')
                    ->label('Текст')
                    ->formatStateUsing(fn (?string $state): string => PostFormatter::toPlain($state))
                    ->limit(50)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('crosspost')
                    ->label('Куда')
                    ->state(function (ContentCalendarSlot $record): string {
                        $keys = $record->targetDestinations();
                        $where = ContentCalendarSlot::destinationSummary($keys) ?: 'никуда';
                        if ($record->publications->isEmpty()) {
                            return $where;
                        }
                        $done = $record->publications->where('status', ContentCalendarPublication::STATUS_PUBLISHED)->count();
                        $failed = $record->publications->where('status', ContentCalendarPublication::STATUS_FAILED)->count();

                        return $where.' — вышло '.$done.'/'.count($keys).($failed > 0 ? ", ошибок: {$failed}" : '');
                    })
                    ->wrap()
                    ->color(fn (ContentCalendarSlot $record): ?string => $record->publications->contains('status', ContentCalendarPublication::STATUS_FAILED) ? 'danger' : null)
                    ->tooltip(fn (ContentCalendarSlot $record): string => implode(', ', array_map(
                        static fn (string $key): string => (string) (config("content_calendar.destinations.{$key}.label") ?? $key),
                        $record->targetDestinations(),
                    )) ?: 'никуда')
                    ->toggleable(),
                Tables\Columns\ImageColumn::make('media_preview')
                    ->label('Обложка')
                    ->disk(ContentCalendarSlot::MEDIA_DISK)
                    ->getStateUsing(fn (ContentCalendarSlot $record): ?string => $record->mediaType() === ContentCalendarSlot::MEDIA_PHOTO ? $record->media_path : null)
                    ->width(72)
                    ->height(90)
                    ->extraImgAttributes(['class' => 'cursor-pointer'])
                    // Клик по ячейке — нативная модалка Filament поверх
                    // страницы: карточку слота не покидаем.
                    ->action(
                        Tables\Actions\Action::make('zoom_media_preview')
                            ->visible(fn (ContentCalendarSlot $record): bool => $record->mediaType() === ContentCalendarSlot::MEDIA_PHOTO)
                            ->modalWidth(MaxWidth::SevenExtraLarge)
                            ->modalHeading(fn (ContentCalendarSlot $record): string => (string) $record->title)
                            ->modalContent(fn (ContentCalendarSlot $record): HtmlString => new HtmlString(
                                // !important обязательны: глобальный хук панели
                                // (AdminPanelProvider) жмёт все картинки .fi-main до
                                // 150px с !important — инлайн без него проигрывает.
                                '<img src="'.e((string) $record->mediaUrl()).'" alt="Обложка слота"'
                                .' style="display:block;margin:0 auto;width:auto!important;height:auto!important;max-width:100%!important;max-height:80vh!important;border-radius:8px">'
                            ))
                            ->modalSubmitAction(false)
                            ->modalCancelAction(false),
                    )
                    ->toggleable(),
                Tables\Columns\IconColumn::make('media_path')
                    ->label('Медиа')
                    ->icon(fn (ContentCalendarSlot $record): ?string => match ($record->mediaType()) {
                        ContentCalendarSlot::MEDIA_PHOTO => 'heroicon-o-photo',
                        ContentCalendarSlot::MEDIA_VIDEO => 'heroicon-o-film',
                        default => null,
                    })
                    ->tooltip(fn (ContentCalendarSlot $record): ?string => $record->mediaType())
                    ->toggleable(),
                Tables\Columns\TextColumn::make('publish_at')->label('Publish at')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('rubric_id')->label('Рубрика')->relationship('rubric', 'name'),
                Tables\Filters\SelectFilter::make('status')->label('Статус')->options([
                    ContentCalendarSlot::STATUS_EMPTY => 'empty',
                    ContentCalendarSlot::STATUS_DRAFT => 'draft',
                    ContentCalendarSlot::STATUS_KEPT => 'kept',
                    ContentCalendarSlot::STATUS_SCHEDULED => 'scheduled',
                    ContentCalendarSlot::STATUS_PUBLISHED => 'published',
                    ContentCalendarSlot::STATUS_CANCELED => 'canceled',
                ]),
                Tables\Filters\SelectFilter::make('slot_type')->label('Тип')->options([
                    ContentCalendarSlot::TYPE_EVERGREEN => 'evergreen',
                    ContentCalendarSlot::TYPE_CLIP_TEASE => 'clip_tease',
                    ContentCalendarSlot::TYPE_FORWARD => 'forward',
                    ContentCalendarSlot::TYPE_EVENT => 'event',
                    ContentCalendarSlot::TYPE_TEACHER_SPOTLIGHT => 'teacher_spotlight',
                    ContentCalendarSlot::TYPE_STUDENT_STORY => 'student_story',
                    ContentCalendarSlot::TYPE_MANUAL => 'manual',
                    ContentCalendarSlot::TYPE_TG_CHANNEL => 'tg_channel',
                    ContentCalendarSlot::TYPE_EMPTY => 'empty',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('enqueue')
                    ->label('В очередь')
                    ->icon('heroicon-o-queue-list')
                    ->color('success')
                    ->visible(fn (ContentCalendarSlot $record): bool => RubricQueue::canEnqueue($record))
                    ->requiresConfirmation()
                    ->modalDescription('Пост встанет на ближайший свободный выход рубрики и будет опубликован автоматически.')
                    ->action(function (ContentCalendarSlot $record): void {
                        $result = app(RubricQueue::class)->enqueue($record);
                        $note = Notification::make()
                            ->title($result['ok'] ? 'Пост в очереди: '.$result['at']?->format('d.m.Y H:i') : 'Не поставлен в очередь')
                            ->body($result['note']);
                        ($result['ok'] ? $note->success() : $note->danger())->send();
                    }),
                Tables\Actions\Action::make('keep')
                    ->label('Keep')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (ContentCalendarSlot $record): void {
                        $record->markKept();
                        Notification::make()->title('Слот запланирован')->success()->send();
                    }),
                Tables\Actions\Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ContentCalendarSlot $record): bool => $record->canCancel())
                    ->action(function (ContentCalendarSlot $record): void {
                        if (! $record->canCancel()) {
                            Notification::make()->title('Отмена недоступна (менее 24 ч до публикации)')->warning()->send();

                            return;
                        }
                        $record->markCanceled();
                        Notification::make()->title('Слот отменён')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('enqueue')
                    ->label('В очередь рубрики')
                    ->icon('heroicon-o-queue-list')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Черновики рубрик встанут на ближайшие свободные выходы своих рубрик — по порядку даты слота. Остальные выбранные слоты не меняются.')
                    ->action(function (Collection $records): void {
                        $queue = app(RubricQueue::class);
                        $done = 0;
                        $notes = [];
                        foreach ($records->sortBy([['slot_date', 'asc'], ['id', 'asc']]) as $record) {
                            /** @var ContentCalendarSlot $record */
                            if (! RubricQueue::canEnqueue($record)) {
                                continue;
                            }
                            $result = $queue->enqueue($record);
                            $done += $result['ok'] ? 1 : 0;
                            if ($result['note'] !== null) {
                                $notes[] = $result['note'];
                            }
                        }
                        Notification::make()->title("Поставлено в очередь: {$done}")
                            ->body($notes === [] ? null : implode("\n", array_unique($notes)))
                            ->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
                Tables\Actions\BulkAction::make('keep')
                    ->label('Keep')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        foreach ($records as $record) {
                            /** @var ContentCalendarSlot $record */
                            $record->markKept();
                        }
                        Notification::make()->title('Выбранные слоты запланированы')->success()->send();
                    }),
                Tables\Actions\BulkAction::make('destinations')
                    ->label('Куда публиковать')
                    ->icon('heroicon-o-megaphone')
                    ->form([self::destinationsField()->required()])
                    ->action(function (Collection $records, array $data): void {
                        $n = 0;
                        foreach ($records as $record) {
                            /** @var ContentCalendarSlot $record */
                            if ($record->status !== ContentCalendarSlot::STATUS_PUBLISHED) {
                                $record->update(['destinations' => array_values(array_unique((array) $data['destinations']))]);
                                $n++;
                            }
                        }
                        Notification::make()->title("Площадки заданы слотам: {$n} (опубликованные не трогаем)")->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
                Tables\Actions\BulkAction::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $n = 0;
                        foreach ($records as $record) {
                            /** @var ContentCalendarSlot $record */
                            if ($record->canCancel()) {
                                $record->markCanceled();
                                $n++;
                            }
                        }
                        Notification::make()->title("Отменено: {$n}")->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContentCalendarSlots::route('/'),
            'create' => Pages\CreateContentCalendarSlot::route('/create'),
            'edit' => Pages\EditContentCalendarSlot::route('/{record}/edit'),
        ];
    }
}
