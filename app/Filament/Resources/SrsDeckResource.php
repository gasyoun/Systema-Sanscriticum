<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\SrsDeckResource\Pages;
use App\Filament\Resources\SrsDeckResource\RelationManagers;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Teacher CRUD for SRS decks (Wave 2, H1487). Behind config('srs.enabled') + AdminOnly —
 * mirrors MessageTemplateResource's feature-flag gate pattern.
 */
class SrsDeckResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = SrsDeck::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Допматериалы';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'SRS — колоды';

    protected static ?string $modelLabel = 'колода SRS';

    protected static ?string $pluralModelLabel = 'SRS — колоды';

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('srs.enabled') && RoleGate::adminOnly();
    }

    public static function canViewAny(): bool
    {
        return (bool) config('srs.enabled') && RoleGate::adminOnly();
    }

    public static function canCreate(): bool
    {
        return (bool) config('srs.enabled') && RoleGate::adminOnly();
    }

    public static function canEdit($record): bool
    {
        return (bool) config('srs.enabled') && RoleGate::adminOnly();
    }

    public static function canDelete($record): bool
    {
        return (bool) config('srs.enabled') && RoleGate::adminOnly();
    }

    public static function canDeleteAny(): bool
    {
        return (bool) config('srs.enabled') && RoleGate::adminOnly();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Колода')->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Forms\Set $set, ?string $state, ?string $old, Forms\Get $get): void {
                        if (blank($get('slug')) || $get('slug') === Str::slug((string) $old)) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),

                Forms\Components\TextInput::make('slug')
                    ->label('Slug')
                    ->maxLength(255)
                    ->helperText('Необязательно. Для системных колод — стабильный идентификатор.'),

                Forms\Components\Select::make('note_type_id')
                    ->label('Тип карточки')
                    ->relationship('noteType', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('key')->label('Ключ')->required()->maxLength(64),
                        Forms\Components\TextInput::make('name')->label('Название')->required()->maxLength(255),
                        Forms\Components\Select::make('language')
                            ->label('Язык')
                            ->options(['sa' => 'Санскрит', 'hi' => 'Хинди'])
                            ->default('sa')
                            ->required(),
                        Forms\Components\TagsInput::make('fields')
                            ->label('Поля')
                            ->placeholder('devanagari, iast, …')
                            ->default(['devanagari', 'iast', 'cyrillic', 'translation'])
                            ->required(),
                    ])
                    ->createOptionUsing(function (array $data): int {
                        return SrsNoteType::create($data)->id;
                    }),

                Forms\Components\Select::make('language')
                    ->label('Язык')
                    ->options(['sa' => 'Санскрит', 'hi' => 'Хинди'])
                    ->default('sa')
                    ->required()
                    ->native(false),

                Forms\Components\Select::make('visibility')
                    ->label('Видимость')
                    ->options([
                        'system' => 'Системная',
                        'public' => 'Публичная',
                        'private' => 'Приватная',
                    ])
                    ->default('public')
                    ->required()
                    ->native(false)
                    ->helperText('system/public — видны студентам; private — только владельцу.'),

                Forms\Components\Select::make('user_id')
                    ->label('Владелец')
                    ->relationship('owner', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('NULL = системная колода без личного владельца.'),

                Forms\Components\Textarea::make('description')
                    ->label('Описание')
                    ->rows(3)
                    ->columnSpanFull(),
            ])->columns(2),

            Forms\Components\Section::make('Привязка к курсу / уроку')->schema([
                Forms\Components\Select::make('course_id')
                    ->label('Курс')
                    ->relationship('course', 'title')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->live(),

                Forms\Components\Select::make('lesson_id')
                    ->label('Урок')
                    ->relationship(
                        'lesson',
                        'title',
                        fn (Forms\Get $get, $query) => $get('course_id')
                            ? $query->where('course_id', $get('course_id'))
                            : $query,
                    )
                    ->searchable()
                    ->preload()
                    ->nullable(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),

                Tables\Columns\TextColumn::make('language')
                    ->label('Язык')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'hi' ? 'Хинди' : 'Санскрит'),

                Tables\Columns\TextColumn::make('visibility')
                    ->label('Видимость')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'system' => 'info',
                        'public' => 'success',
                        'private' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('noteType.name')
                    ->label('Тип')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('course.title')
                    ->label('Курс')
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('lesson.title')
                    ->label('Урок')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('cards_count')
                    ->counts('cards')
                    ->label('Карт')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Обновлена')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('language')
                    ->label('Язык')
                    ->options(['sa' => 'Санскрит', 'hi' => 'Хинди']),
                Tables\Filters\SelectFilter::make('visibility')
                    ->label('Видимость')
                    ->options([
                        'system' => 'Системная',
                        'public' => 'Публичная',
                        'private' => 'Приватная',
                    ]),
                Tables\Filters\SelectFilter::make('course_id')
                    ->label('Курс')
                    ->relationship('course', 'title')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CardsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSrsDecks::route('/'),
            'create' => Pages\CreateSrsDeck::route('/create'),
            'edit' => Pages\EditSrsDeck::route('/{record}/edit'),
        ];
    }
}
