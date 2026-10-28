<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\ContentCalendarRubricResource\Pages;
use App\Models\ContentCalendarRubric;
use App\Models\ContentCalendarSlot;
use App\Services\Content\RubricScheduler;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Рубрики календаря контента (28-10-2026): расписание + очередь постов.
 * Посты рубрики — обычные слоты «Календаря контента» с выбранной рубрикой.
 */
class ContentCalendarRubricResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = ContentCalendarRubric::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 139;

    protected static ?string $navigationGroup = 'Маркетинг';

    protected static ?string $navigationLabel = 'Рубрики календаря';

    protected static ?string $pluralModelLabel = 'Рубрики календаря';

    protected static ?string $modelLabel = 'Рубрика';

    public static function canViewAny(): bool
    {
        return config('features.content_calendar') === true && RoleGate::adminOnly();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Название')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('prefix')
                ->label('Префикс первой строки (необязательно)')
                ->helperText('Добавится в начало текста каждого поста рубрики, если текст с него ещё не начинается. Например: «🗣 Анонс курсов · ».')
                ->maxLength(100),
            Forms\Components\Repeater::make('schedule')
                ->label('Расписание (MSK)')
                ->schema([
                    Forms\Components\CheckboxList::make('days')
                        ->label('Дни')
                        ->options(ContentCalendarRubric::DAY_LABELS)
                        ->columns(7)
                        ->required(),
                    Forms\Components\TimePicker::make('time')
                        ->label('Время')
                        ->seconds(false)
                        ->default('18:00')
                        ->required(),
                ])
                ->columns(2)
                ->defaultItems(1)
                ->minItems(1)
                ->addActionLabel('Добавить время')
                ->columnSpanFull(),
            Forms\Components\CheckboxList::make('channels')
                ->label('Каналы')
                ->options(fn (): array => (array) config('content_calendar.channels', []))
                ->helperText('Канал = его Telegram и VK. Посты рубрики сразу получат эти площадки (в посте их можно поправить). '
                    .'Ненастроенные площадки пропускаются.')
                ->columns(3)
                ->columnSpanFull(),
            Forms\Components\Toggle::make('with_story')
                ->label('Сторис к каждому посту')
                ->helperText('Если у поста нет картинки для сторис, она соберётся из картинки поста (1080×1920).')
                ->default(true),
            Forms\Components\Toggle::make('is_active')
                ->label('Активна')
                ->helperText('Выключенную рубрику нельзя выбрать для нового поста, и о пустой очереди она не предупреждает. Уже стоящие посты выйдут.')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Рубрика')->searchable(),
                Tables\Columns\TextColumn::make('schedule_label')
                    ->label('Расписание')
                    ->state(fn (ContentCalendarRubric $record): string => $record->scheduleLabel()),
                Tables\Columns\TextColumn::make('where')
                    ->label('Куда')
                    ->state(fn (ContentCalendarRubric $record): string => ($keys = $record->destinationKeys()) === null
                        ? 'по галочкам поста'
                        : (ContentCalendarSlot::destinationSummary($keys) ?: 'площадки не настроены'))
                    ->wrap(),
                Tables\Columns\TextColumn::make('queue')
                    ->label('В очереди')
                    ->state(fn (ContentCalendarRubric $record): int => self::queue($record)->count()),
                Tables\Columns\TextColumn::make('queue_until')
                    ->label('Постов хватит до')
                    ->state(fn (ContentCalendarRubric $record): ?string => self::queue($record)->max('publish_at')
                        ? Carbon::parse(self::queue($record)->max('publish_at'))->format('d.m.Y H:i')
                        : null)
                    ->placeholder('очередь пуста'),
                Tables\Columns\TextColumn::make('next_free')
                    ->label('Следующий пост встанет на')
                    ->state(fn (ContentCalendarRubric $record): ?string => app(RubricScheduler::class)->nextFreeAt($record)?->format('d.m.Y H:i')),
                Tables\Columns\IconColumn::make('with_story')->label('Сторис')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('Активна')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('reflow')
                    ->label('Пересобрать очередь')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription('Невышедшие посты рубрики встанут на ближайшие моменты расписания подряд, в прежнем порядке. Ручные даты тоже сдвинутся.')
                    ->action(function (ContentCalendarRubric $record): void {
                        $n = app(RubricScheduler::class)->reflow($record);
                        Notification::make()->title("Новое время получили постов: {$n}")->success()->send();
                    }),
            ]);
    }

    /** Запланированные, ещё не вышедшие посты рубрики. */
    private static function queue(ContentCalendarRubric $rubric): HasMany
    {
        return $rubric->slots()
            ->where('status', ContentCalendarSlot::STATUS_SCHEDULED)
            ->where('publish_at', '>', now());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContentCalendarRubrics::route('/'),
            'create' => Pages\CreateContentCalendarRubric::route('/create'),
            'edit' => Pages\EditContentCalendarRubric::route('/{record}/edit'),
        ];
    }
}
