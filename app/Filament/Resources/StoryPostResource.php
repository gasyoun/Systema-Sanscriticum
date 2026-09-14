<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StoryPostResource\Pages;
use App\Models\StoryPost;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Очередь публикаций канала @rusamskrtam / сториз (H3930, Phase 1 + H3964
 * Phase 2). Черновики импортируются stories:import-queue /
 * stories:from-harvest или создаются вручную; публикация — только по
 * approved+due в СВОЕЙ полосе: lane=channel → stories:publish-due (Bot API),
 * lane=persona → stories:publish-story (MadelineProto user-сториз).
 * Роль: только ADMIN — публичный контент академии, не учительская поверхность.
 */
class StoryPostResource extends Resource
{
    protected static ?string $model = StoryPost::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Контент';

    protected static ?int $navigationSort = 26;

    protected static ?string $navigationLabel = 'Очередь сториз';

    protected static ?string $modelLabel = 'публикация';

    protected static ?string $pluralModelLabel = 'Очередь сториз и постов';

    public static function canViewAny(): bool
    {
        return RoleGate::any(Roles::ADMIN);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('kind')
                    ->label('Тип')
                    ->options([
                        StoryPost::KIND_TEXT => 'Текст',
                        StoryPost::KIND_PHOTO => 'Фото (сториз)',
                        StoryPost::KIND_VIDEO => 'Видео (сториз)',
                    ])
                    ->default(StoryPost::KIND_TEXT)
                    ->required()
                    ->live(),
                Forms\Components\Select::make('lane')
                    ->label('Полоса')
                    ->options([
                        StoryPost::LANE_CHANNEL => 'Канал (пост, магнит-бот)',
                        StoryPost::LANE_PERSONA => 'Сториз персоны @rusamskrtam (MadelineProto)',
                    ])
                    ->default(StoryPost::LANE_CHANNEL)
                    ->required()
                    ->helperText('Текст в persona-полосе выходит текстовой сториз своего профиля, а не постом канала.'),
                Forms\Components\Textarea::make('payload')
                    ->label('Текст поста / подпись')
                    ->rows(8)
                    ->required(fn (Forms\Get $get): bool => $get('kind') === StoryPost::KIND_TEXT)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('media_path')
                    ->label('Путь к медиа-файлу (для сториз)')
                    ->visible(fn (Forms\Get $get): bool => in_array($get('kind'), [StoryPost::KIND_PHOTO, StoryPost::KIND_VIDEO], true)),
                Forms\Components\Grid::make(2)
                    ->schema([
                        Forms\Components\TextInput::make('repeat_rule.every_days')
                            ->numeric()
                            ->minValue(1)
                            ->label('Повтор: каждые N дней')
                            ->helperText('После публикации перепланировать копию. Пусто = без повтора.'),
                        Forms\Components\TextInput::make('repeat_rule.times')
                            ->numeric()
                            ->minValue(0)
                            ->label('Повтор: всего M публикаций')
                            ->helperText('Общее число публикаций серии, включая первую.'),
                    ]),
                Forms\Components\Select::make('source')
                    ->label('Источник')
                    ->options([
                        StoryPost::SOURCE_QUEUE => 'Очередь (content/queue)',
                        StoryPost::SOURCE_HARVEST => 'Харвест групп',
                        StoryPost::SOURCE_DM => 'Личные сообщения',
                        StoryPost::SOURCE_HOMEWORK => 'Домашние работы',
                        StoryPost::SOURCE_MANUAL => 'Вручную',
                    ])
                    ->default(StoryPost::SOURCE_MANUAL)
                    ->required(),
                Forms\Components\Select::make('status')
                    ->label('Статус')
                    ->options([
                        StoryPost::STATUS_DRAFT => 'Черновик',
                        StoryPost::STATUS_APPROVED => 'Одобрено',
                        StoryPost::STATUS_PUBLISHED => 'Опубликовано',
                        StoryPost::STATUS_SKIPPED => 'Пропущено',
                    ])
                    ->default(StoryPost::STATUS_DRAFT)
                    ->required(),
                Forms\Components\DateTimePicker::make('publish_at')
                    ->label('Когда публиковать')
                    ->seconds(false),
                Forms\Components\TextInput::make('telegram_message_id')
                    ->label('Telegram message id')
                    ->disabled()
                    ->dehydrated(false),
                Forms\Components\Textarea::make('journal')
                    ->label('Журнал издателя')
                    ->rows(3)
                    ->disabled()
                    ->dehydrated(false)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('kind')
                    ->label('Тип')
                    ->badge(),
                Tables\Columns\TextColumn::make('lane')
                    ->label('Полоса')
                    ->badge()
                    ->colors([
                        'gray' => StoryPost::LANE_CHANNEL,
                        'info' => StoryPost::LANE_PERSONA,
                    ])
                    ->toggleable(),
                Tables\Columns\TextColumn::make('source')
                    ->label('Источник')
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->colors([
                        'gray' => StoryPost::STATUS_DRAFT,
                        'warning' => StoryPost::STATUS_APPROVED,
                        'success' => StoryPost::STATUS_PUBLISHED,
                        'danger' => StoryPost::STATUS_SKIPPED,
                    ]),
                Tables\Columns\TextColumn::make('payload')
                    ->label('Текст')
                    ->limit(60),
                Tables\Columns\TextColumn::make('publish_at')
                    ->label('Когда')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('posted_at')
                    ->label('Опубликовано')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        StoryPost::STATUS_DRAFT => 'Черновик',
                        StoryPost::STATUS_APPROVED => 'Одобрено',
                        StoryPost::STATUS_PUBLISHED => 'Опубликовано',
                        StoryPost::STATUS_SKIPPED => 'Пропущено',
                    ]),
                Tables\Filters\SelectFilter::make('source')
                    ->label('Источник')
                    ->options([
                        StoryPost::SOURCE_QUEUE => 'Очередь (content/queue)',
                        StoryPost::SOURCE_HARVEST => 'Харвест групп',
                        StoryPost::SOURCE_DM => 'Личные сообщения',
                        StoryPost::SOURCE_HOMEWORK => 'Домашние работы',
                        StoryPost::SOURCE_MANUAL => 'Вручную',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Одобрить')
                    ->color('warning')
                    ->icon('heroicon-o-check')
                    ->visible(fn (StoryPost $record): bool => $record->status === StoryPost::STATUS_DRAFT)
                    ->requiresConfirmation()
                    ->action(function (StoryPost $record): void {
                        $record->forceFill(['status' => StoryPost::STATUS_APPROVED])->save();
                        Notification::make()->title('Одобрено — издатель заберёт по publish_at')->success()->send();
                    }),
                Tables\Actions\Action::make('skip')
                    ->label('Пропустить')
                    ->color('danger')
                    ->icon('heroicon-o-x-mark')
                    ->visible(fn (StoryPost $record): bool => in_array($record->status, [StoryPost::STATUS_DRAFT, StoryPost::STATUS_APPROVED], true))
                    ->requiresConfirmation()
                    ->action(function (StoryPost $record): void {
                        $record->forceFill(['status' => StoryPost::STATUS_SKIPPED])->save();
                        Notification::make()->title('Пропущено')->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('publish_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStoryPosts::route('/'),
            'create' => Pages\CreateStoryPost::route('/create'),
            'edit' => Pages\EditStoryPost::route('/{record}/edit'),
        ];
    }
}
