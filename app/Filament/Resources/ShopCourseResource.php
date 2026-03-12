<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopCourseResource\Pages;
use App\Models\Course;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\Layout\Split;
use Illuminate\Support\Str;

class ShopCourseResource extends Resource
{
    // Указываем ту же самую модель Course!
    protected static ?string $model = Course::class;

    // Настройки отображения в меню
    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?string $navigationGroup = 'Магазин';
    protected static ?string $navigationLabel = 'Витрина курсов';
    protected static ?string $modelLabel = 'Курс на витрине';
    protected static ?string $pluralModelLabel = 'Витрина курсов';
    
    // Сортировка в меню (чтобы витрина была повыше)
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Быстрое редактирование для витрины')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->required()
                            ->label('Название курса'),
                            
                        // НОВОЕ ПОЛЕ: Загрузка картинки с умным редактором
                        Forms\Components\FileUpload::make('image_path')
                            ->label('Обложка курса')
                            ->image()
                            ->directory('courses')
                            ->imageEditor() // Включает редактор
                            ->imageEditorAspectRatios([
                                '4:3',
                                '16:9',
                                '1:1',
                            ]) // Добавляет кнопки фиксированных пропорций
                            ->columnSpanFull(),
                            
                        Forms\Components\Textarea::make('description')
                            ->label('Описание')
                            ->rows(3)
                            ->columnSpanFull(),
                            
                        Forms\Components\Toggle::make('is_visible')
                            ->label('Показывать на сайте')
                            ->onColor('success'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // ВОТ ОНА МАГИЯ: Включаем отображение сеткой (карточками)
            ->contentGrid([
                'md' => 2,
                'xl' => 3, // 3 карточки в ряд на больших экранах
            ])
            ->columns([
                // Stack складывает элементы карточки друг под другом
                Stack::make([
                    // Блок с текстом карточки
                    Stack::make([
                        // Заголовок
                        Tables\Columns\TextColumn::make('title')
                            ->weight('bold')
                            ->size('xl')
                            ->searchable(),
                            
                        // Slug (ссылка)
                        Tables\Columns\TextColumn::make('slug')
                            ->color('gray')
                            ->size('sm')
                            ->prefix('/course/'),

                        // Описание
                        Tables\Columns\TextColumn::make('description')
                            ->color('gray')
                            ->limit(80)
                            ->extraAttributes(['class' => 'mt-2 text-sm h-10']),

                        // Плашки со статистикой в одну строку
                        Split::make([
                            Tables\Columns\TextColumn::make('lessons_count')
                                ->badge()
                                ->color('info')
                                ->icon('heroicon-m-document-text')
                                ->formatStateUsing(fn ($state) => $state . ' уроков'),
                                
                            Tables\Columns\TextColumn::make('hours_count')
                                ->badge()
                                ->color('warning')
                                ->icon('heroicon-m-clock')
                                ->formatStateUsing(fn ($state) => $state . ' часов'),
                                
                            Tables\Columns\IconColumn::make('is_visible')
                                ->boolean()
                                ->label('Видимость'),
                        ])->extraAttributes(['class' => 'mt-6']),
                        
                    ])->space(1)->extraAttributes(['class' => 'p-6 bg-white rounded-2xl shadow-sm border border-gray-100 hover:shadow-md transition-shadow']),
                ])->space(0),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_visible')
                    ->label('Видимость на сайте')
                    ->boolean()
                    ->trueLabel('Только активные')
                    ->falseLabel('Только скрытые'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->button()
                    ->outlined()
                    ->label('Настроить'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // Используем полный и точный путь к нашему новому файлу
            \App\Filament\Resources\ShopCourseResource\RelationManagers\TariffsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShopCourses::route('/'),
            'create' => Pages\CreateShopCourse::route('/create'),
            'edit' => Pages\EditShopCourse::route('/{record}/edit'),
        ];
    }
}