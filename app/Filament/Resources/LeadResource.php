<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LeadResource\Pages;
use App\Models\Lead;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

// --- ИМПОРТЫ ДЛЯ EXCEL (ВАЖНО!) ---
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;
use pxlrbt\FilamentExcel\Columns\Column;

class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';
    
    protected static ?string $navigationLabel = 'Лиды';
    protected static ?string $pluralModelLabel = 'Заявки (Лиды)';

    public static function canCreate(): bool
    {
        return false; 
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Основная информация')
                    ->schema([
                        Forms\Components\TextInput::make('name')->label('Имя'),
                        Forms\Components\TextInput::make('contact')->label('Телефон / TG'),
                        Forms\Components\TextInput::make('email')->label('Email')->email(),
                        Forms\Components\Select::make('landing_page_id')
                            ->relationship('landingPage', 'title')
                            ->label('Лендинг'),
                        Forms\Components\Toggle::make('is_promo_agreed')
                            ->label('Согласие на рассылку'),
                    ])->columns(2),

                Forms\Components\Section::make('Маркетинг и Аналитика')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\Grid::make(3)->schema([
                            Forms\Components\TextInput::make('utm_source')->label('Source'),
                            Forms\Components\TextInput::make('utm_medium')->label('Medium'),
                            Forms\Components\TextInput::make('utm_campaign')->label('Campaign'),
                            Forms\Components\TextInput::make('utm_content')->label('Content'),
                            Forms\Components\TextInput::make('utm_term')->label('Term'),
                            Forms\Components\TextInput::make('click_id')->label('Click ID'),
                        ]),
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('ip_address')->label('IP'),
                            Forms\Components\TextInput::make('referrer')->label('Referrer'),
                        ]),
                        Forms\Components\Textarea::make('user_agent')->label('UA')->columnSpanFull(),
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('landingPage.title')
                    ->label('Лендинг')
                    ->searchable()
                    ->limit(20),
                    
                Tables\Columns\TextColumn::make('name')
                    ->label('Имя')
                    ->searchable(),
                    
                Tables\Columns\TextColumn::make('contact')
                    ->label('Контакты')
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->icon('heroicon-m-envelope')
                    ->copyable()
                    ->searchable(),
                    
                // В LeadResource.php -> table() -> columns([...])
                Tables\Columns\IconColumn::make('is_promo_agreed')
                    ->label('Рассылка')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->sortable(),    

                Tables\Columns\TextColumn::make('utm_source')
                    ->label('Источник')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'yandex' => 'warning',
                        'vk', 'vkontakte' => 'info',
                        'google' => 'success',
                        'tg', 'telegram' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    
                    // === НАСТРОЙКА ПОЛНОЙ ВЫГРУЗКИ ===
                    ExportBulkAction::make()
                        ->label('Скачать полный отчет (Excel)')
                        ->exports([
                            ExcelExport::make()
                                ->withFilename('leads_full_' . date('Y-m-d'))
                                ->withColumns([
                                    // 1. Основное
                                    Column::make('id')->heading('ID'),
                                    Column::make('created_at')->heading('Дата создания'),
                                    Column::make('landingPage.title')->heading('Лендинг'),
                                    Column::make('name')->heading('Имя'),
                                    Column::make('contact')->heading('Телефон'),
                                    Column::make('email')->heading('Email'),
                                    
                                    // 2. Галочки (Форматируем True/False в Да/Нет)
                                    Column::make('is_promo_agreed')
                                        ->heading('Рассылка?')
                                        ->formatStateUsing(fn ($state) => $state ? 'Да' : 'Нет'),

                                    // 3. Маркетинг (UTM)
                                    Column::make('utm_source')->heading('Источник (Source)'),
                                    Column::make('utm_medium')->heading('Тип (Medium)'),
                                    Column::make('utm_campaign')->heading('Кампания'),
                                    Column::make('utm_content')->heading('Объявление'),
                                    Column::make('utm_term')->heading('Ключевое слово'),
                                    
                                    // 4. Технические данные
                                    Column::make('click_id')->heading('Click ID'),
                                    Column::make('ip_address')->heading('IP адрес'),
                                    Column::make('referrer')->heading('Пришел с сайта'),
                                    Column::make('user_agent')->heading('Информация об устройстве'),
                                ])
                        ]),
                ]),
            ]);
    }

    public static function getRelations(): array { return []; }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeads::route('/'),
        ];
    }
}