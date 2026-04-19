<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ArticleCategoryResource\Pages;
use App\Models\ArticleCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ArticleCategoryResource extends Resource
{
    protected static ?string $model = ArticleCategory::class;

    // Иконка в сайдбаре Filament
    protected static ?string $navigationIcon = 'heroicon-o-folder';

    // Группировка в сайдбаре — создадим новую группу "Блог"
    protected static ?string $navigationGroup = 'Блог';
    protected static ?int $navigationSort = 10; // выше, чем статьи

    // Человеческие подписи
    protected static ?string $navigationLabel = 'Рубрики статей';
    protected static ?string $modelLabel = 'Рубрика';
    protected static ?string $pluralModelLabel = 'Рубрики';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true) // при потере фокуса — обновит поле slug
                    ->afterStateUpdated(function (string $operation, ?string $state, Forms\Set $set): void {
                        // Автогенерация slug только при создании, чтобы не ломать уже работающие URL
                        if ($operation === 'create' && !empty($state)) {
                            $set('slug', \Illuminate\Support\Str::slug($state));
                        }
                    }),

                Forms\Components\TextInput::make('slug')
                    ->label('URL (slug)')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true) // уникален, но при редактировании игнорируем текущую запись
                    ->helperText('Латиницей. Заполняется автоматически из названия, можно править вручную.'),

                Forms\Components\TextInput::make('sort_order')
                    ->label('Порядок сортировки')
                    ->numeric()
                    ->default(0)
                    ->helperText('Меньше — выше в списке. Одинаковые значения — по алфавиту.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('slug')
                    ->label('URL')
                    ->badge()
                    ->color('gray')
                    ->copyable(), // клик копирует в буфер

                // Счётчик статей в рубрике — withCount экономит N+1
                Tables\Columns\TextColumn::make('articles_count')
                    ->label('Статей')
                    ->counts('articles')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создана')
                    ->dateTime('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Рубрик пока нет')
            ->emptyStateDescription('Создайте первую рубрику, чтобы группировать статьи.')
            ->emptyStateIcon('heroicon-o-folder-plus');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListArticleCategories::route('/'),
            'create' => Pages\CreateArticleCategory::route('/create'),
            'edit'   => Pages\EditArticleCategory::route('/{record}/edit'),
        ];
    }
}