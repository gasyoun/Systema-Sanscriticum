<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\ContentCalendarSlotResource\Pages;
use App\Models\ContentCalendarPublication;
use App\Models\ContentCalendarSlot;
use App\Services\Content\PostFormatter;
use App\Support\RoleGate;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
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
            Forms\Components\DateTimePicker::make('publish_at')
                ->label('Публикация (MSK)')
                ->seconds(false)
                ->required(fn (string $operation): bool => $operation === 'create')
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
                ->live(onBlur: true)
                ->helperText('Маркер = спойлер в Telegram. В VK форматирование не переносится: уходит простой текст, ссылка — «текст (url)».')
                ->rules([
                    fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        $length = PostFormatter::telegramLength(self::bodyHtml($value));
                        $limit = filled($get('media_path')) ? PostFormatter::TG_CAPTION_LIMIT : PostFormatter::TG_MESSAGE_LIMIT;
                        if ($length > $limit) {
                            $fail(filled($get('media_path'))
                                ? "С фото/видео Telegram допускает подпись до {$limit} символов, сейчас {$length}. Сократите текст или уберите вложение."
                                : "Telegram допускает сообщение до {$limit} символов, сейчас {$length}.");
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
                ->live()
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
                ->label('Как увидит VK')
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
            ->descriptions(array_map(
                static fn (): string => 'не настроено',
                array_diff_key((array) config('content_calendar.destinations', []), $enabled),
            ))
            ->disableOptionWhen(static fn (string $value): bool => ! isset($enabled[$value]))
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('publications'))
            ->defaultSort('slot_date')
            ->columns([
                Tables\Columns\TextColumn::make('slot_date')->label('Дата')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('slot_type')->label('Тип')->badge(),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge(),
                Tables\Columns\TextColumn::make('title')->label('Заголовок')->limit(40)->wrap(),
                Tables\Columns\TextColumn::make('body')
                    ->label('Текст')
                    ->formatStateUsing(fn (?string $state): string => PostFormatter::toPlain($state))
                    ->limit(50)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('crosspost')
                    ->label('Площадки')
                    ->state(function (ContentCalendarSlot $record): string {
                        $targets = count($record->targetDestinations());
                        $done = $record->publications->where('status', ContentCalendarPublication::STATUS_PUBLISHED)->count();
                        $failed = $record->publications->where('status', ContentCalendarPublication::STATUS_FAILED)->count();

                        return $record->publications->isEmpty()
                            ? (string) $targets
                            : "{$done}/{$targets}".($failed > 0 ? " · ошибок: {$failed}" : '');
                    })
                    ->color(fn (ContentCalendarSlot $record): ?string => $record->publications->contains('status', ContentCalendarPublication::STATUS_FAILED) ? 'danger' : null)
                    ->tooltip(fn (ContentCalendarSlot $record): string => implode(', ', array_map(
                        static fn (string $key): string => (string) (config("content_calendar.destinations.{$key}.label") ?? $key),
                        $record->targetDestinations(),
                    )) ?: 'никуда')
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
                                $record->update(['destinations' => array_values($data['destinations'])]);
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
