<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Models\Category;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon  = 'heroicon-o-tag';
    protected static ?string $navigationGroup = 'Обучение';
    protected static ?int    $navigationSort  = 15;
    protected static ?string $navigationLabel = 'Категории курсов';
    protected static ?string $modelLabel      = 'Категория';
    protected static ?string $pluralModelLabel = 'Категории';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Основное')->schema([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Название')
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (string $operation, $state, Forms\Set $set) =>
                            $operation === 'create' ? $set('slug', Str::slug($state)) : null
                        ),
                    Forms\Components\TextInput::make('slug')
                        ->label('URL-адрес')
                        ->required()
                        ->unique(ignoreRecord: true),
                ]),

                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\TextInput::make('icon')
                        ->label('Иконка (FontAwesome)')
                        ->placeholder('fa-om')
                        ->helperText('Без префикса fa-solid, просто fa-om'),
                    Forms\Components\ColorPicker::make('color')
                        ->label('Цвет бейджа'),
                    Forms\Components\TextInput::make('sort_order')
                        ->label('Порядок')
                        ->numeric()
                        ->default(0),
                ]),

                Forms\Components\Toggle::make('is_visible')
                    ->label('Показывать в фильтре')
                    ->default(true)
                    ->onColor('success'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Название')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('slug')->label('Slug')->color('gray'),
                Tables\Columns\TextColumn::make('courses_count')
                    ->counts('courses')
                    ->label('Курсов')
                    ->badge()
                    ->color('info'),
                Tables\Columns\ColorColumn::make('color')->label('Цвет'),
                Tables\Columns\IconColumn::make('is_visible')->boolean()->label('Видна'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->reorderable('sort_order'); // drag-and-drop сортировка
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit'   => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}