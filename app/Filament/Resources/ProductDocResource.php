<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ProductDocResource\Pages;
use App\Models\ProductDoc;
use App\Support\RoleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * CRUD строк каталога. Не в меню: админ читает страницу «Документация».
 * Весь ресурс — только супер-админ (H3243).
 */
class ProductDocResource extends Resource
{
    protected static ?string $model = ProductDoc::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?string $navigationLabel = 'Каталог документации';

    protected static ?string $modelLabel = 'книга каталога';

    protected static ?string $pluralModelLabel = 'Каталог документации';

    protected static ?string $slug = 'product-docs';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return RoleGate::isSuperAdmin();
    }

    public static function canCreate(): bool
    {
        return RoleGate::isSuperAdmin();
    }

    public static function canEdit($record): bool
    {
        return RoleGate::isSuperAdmin();
    }

    public static function canDelete($record): bool
    {
        return RoleGate::isSuperAdmin() && ! $record->is_seeded;
    }

    public static function canDeleteAny(): bool
    {
        return RoleGate::isSuperAdmin();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('Название')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Forms\Components\TextInput::make('slug')
                ->label('Slug')
                ->required()
                ->maxLength(64)
                ->disabled(fn (?ProductDoc $record): bool => (bool) $record?->is_seeded)
                ->dehydrated(),

            Forms\Components\Textarea::make('description')
                ->label('Зачем')
                ->rows(2)
                ->maxLength(1000)
                ->columnSpanFull(),

            Forms\Components\Select::make('audience')
                ->label('Аудитория')
                ->options(ProductDoc::AUDIENCES)
                ->required()
                ->native(false),

            Forms\Components\TextInput::make('route_name')
                ->label('Имя маршрута')
                ->maxLength(255),

            Forms\Components\TextInput::make('url_path')
                ->label('Путь, если маршрута нет')
                ->maxLength(255),

            Forms\Components\TextInput::make('faq_fragment')
                ->label('Якорь FAQ')
                ->maxLength(255),

            Forms\Components\TextInput::make('source_path')
                ->label('Файл docs/*.md')
                ->maxLength(255)
                ->helperText('Только относительный путь внутри docs/. Пусто — нет поиска по файлу.'),

            Forms\Components\Select::make('quiz_audience')
                ->label('Проверка')
                ->options([
                    'student' => 'Ученик',
                    'curator' => 'Куратор',
                    'teacher' => 'Преподаватель (волна 2)',
                    'accountant' => 'Бухгалтер (волна 2)',
                ])
                ->native(false),

            Forms\Components\TextInput::make('sort_order')
                ->label('Порядок')
                ->numeric()
                ->default(100),

            Forms\Components\Toggle::make('is_active')
                ->label('В каталоге')
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Название')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('slug')->label('Slug'),
                Tables\Columns\TextColumn::make('audience')->label('Аудитория'),
                Tables\Columns\IconColumn::make('is_seeded')->label('Посеяна')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('В каталоге')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductDocs::route('/'),
            'create' => Pages\CreateProductDoc::route('/create'),
            'edit' => Pages\EditProductDoc::route('/{record}/edit'),
        ];
    }
}
