<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CourseResource\Pages;
use App\Models\Course;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';
    protected static ?string $navigationLabel = 'Курсы';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Информация о курсе')
                    ->schema([
                        // БЛОК 1: Название и Slug
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->required()
                                    ->label('Название курса')
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (string $operation, $state, Forms\Set $set) => 
                                        $operation === 'create' ? $set('slug', Str::slug($state)) : null
                                    ),

                                Forms\Components\TextInput::make('slug')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->label('URL-адрес (slug)'),
                            ]),

                        // БЛОК 2: Описание
                        Forms\Components\Textarea::make('description')
                            ->label('Описание')
                            ->columnSpanFull(),

                        // БЛОК 3: Статистика
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('lessons_count')
                                    ->numeric()
                                    ->label('Количество уроков')
                                    ->placeholder('Например: 12')
                                    ->default(12),

                                Forms\Components\TextInput::make('hours_count')
                                    ->numeric()
                                    ->label('Академических часов')
                                    ->placeholder('Например: 24')
                                    ->default(24),
                            ]),

                        // БЛОК 4: Доступ и Видимость
                        Forms\Components\Select::make('groups')
                            ->multiple()
                            ->relationship('groups', 'name')
                            ->preload()
                            ->searchable()
                            ->label('Доступ для групп')
                            ->helperText('Студенты из выбранных групп увидят этот курс у себя в кабинете.')
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('is_visible')
                            ->label('Показывать на сайте')
                            ->default(true)
                            ->onColor('success')
                            ->offColor('danger'),
                    ]),
                    
                // БЛОК ТАРИФОВ
                Forms\Components\Section::make('Тарифы и цены')
                    ->schema([
                        Forms\Components\Repeater::make('tariffs') 
                            ->relationship('tariffs') // Указываем связь напрямую
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->label('Название тарифа (например: Блок 1, Полный курс)')
                                    ->required(),
                                    
                                Forms\Components\Select::make('type')
                                    ->label('Тип доступа')
                                    ->options([
                                        'full' => 'Весь курс целиком',
                                        'block' => 'Отдельный блок',
                                    ])
                                    ->required(),

                                Forms\Components\TextInput::make('block_number')
                                    ->label('Номер блока')
                                    ->numeric(),

                                Forms\Components\TextInput::make('price')
                                    ->label('Цена (₽)')
                                    ->numeric()
                                    ->required(),
                                    
                                Forms\Components\TextInput::make('old_price')
                                    ->label('Старая цена (₽)')
                                    ->numeric(),

                                Forms\Components\Textarea::make('description')
                                    ->label('Описание тарифа')
                                    ->rows(2),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('Активен')
                                    ->default(true),
                            ])
                            ->columns(2)
                            ->addActionLabel('Добавить тариф')
                            ->reorderable()
                            ->collapsible(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Course $record) => Str::limit($record->description, 50))
                    ->label('Название'),

                Tables\Columns\TextColumn::make('groups.name')
                    ->label('Доступен группам')
                    ->badge()
                    ->color('info')
                    ->limitList(2),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_visible')
                    ->boolean()
                    ->label('Активен'),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourses::route('/'),
            'create' => Pages\CreateCourse::route('/create'),
            'edit' => Pages\EditCourse::route('/{record}/edit'),
        ];
    }
}